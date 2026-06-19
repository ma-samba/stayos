<?php

declare(strict_types=1);

namespace App\Shared\Redis;

/**
 * Factory pour le service `\Redis` brut injecté dans HealthController.
 *
 * Pourquoi une factory et pas un `calls: connect` dans services.yaml :
 *   En dev (docker-compose) on a REDIS_HOST=redis + REDIS_PORT=6379 et
 *   pas de TLS, pas de password.
 *   En prod (Heroku Data for Redis) on n'a que REDIS_URL au format
 *   `rediss://:PASSWORD@HOST:PORT` :
 *     - schéma `rediss` = TLS obligatoire (Heroku Redis n'accepte que TLS
 *       depuis Heroku Data v8)
 *     - mot de passe à passer via auth() après connect()
 *     - certif Heroku self-signed → désactiver la vérif peer côté SSL
 *       (même approche que pour MESSENGER_TRANSPORT_DSN)
 *   Le `calls: Redis::connect(host, port)` du services.yaml ne couvrait
 *   que le cas dev : en prod, REDIS_HOST n'existe pas → connexion au host
 *   littéral "redis" → getaddrinfo failure → /api/health en 500.
 *
 * La factory teste REDIS_URL d'abord (chemin prod) avec fallback
 * REDIS_HOST/REDIS_PORT (chemin dev). Pas de logique dans services.yaml
 * autre que le câblage des 3 valeurs d'env.
 *
 * Note : le pool cache Symfony (kpi.cache) et Messenger consomment déjà
 * REDIS_URL directement via Symfony Cache / Symfony Messenger qui
 * parsent l'URL et gèrent TLS/auth nativement — cette factory ne
 * concerne QUE le service `\Redis` brut utilisé par HealthController.
 */
final class RedisConnectionFactory
{
    /**
     * @param string $redisUrl  Vide si non posé (cas dev sans REDIS_URL)
     * @param string $redisHost Vide si non posé (cas prod où on utilise REDIS_URL)
     * @param string $redisPort Vide si non posé (idem)
     */
    public function __construct(
        private readonly string $redisUrl = '',
        private readonly string $redisHost = '',
        private readonly string $redisPort = '',
    ) {}

    public function create(): \Redis
    {
        $parts = $this->resolveConnectionParts();

        $redis = new \Redis();

        // ext-redis 6.x : pour TLS, le host doit être préfixé `tls://`
        // et le 7e param `$context` reçoit la config SSL stream.
        // Signature : connect(host, port, timeout, persistent_id,
        //                     retry_interval, read_timeout, context).
        $host = $parts['tls'] ? 'tls://' . $parts['host'] : $parts['host'];

        $context = $parts['tls']
            ? ['stream' => ['verify_peer' => false, 'verify_peer_name' => false]]
            : null;

        // Timeout court : un Redis lent ne doit pas faire pendre /api/health.
        // UptimeRobot a 30s, on ne veut pas en consommer plus de 2s.
        $connected = $redis->connect(
            $host,
            $parts['port'],
            2.0,
            null,
            0,
            2.0,
            $context,
        );

        if (!$connected) {
            throw new \RuntimeException(sprintf(
                'Redis connection failed: %s:%d (tls=%s)',
                $parts['host'],
                $parts['port'],
                $parts['tls'] ? 'yes' : 'no',
            ));
        }

        if (null !== $parts['password'] && '' !== $parts['password']) {
            if (!$redis->auth($parts['password'])) {
                throw new \RuntimeException('Redis AUTH failed');
            }
        }

        return $redis;
    }

    /**
     * Parsing pur de REDIS_URL — extrait host, port, password, tls.
     * Méthode statique pour testabilité sans connexion réelle.
     *
     * Heroku format : `rediss://:PASSWORD@HOST:PORT` (user vide, pass
     * présent). On accepte aussi `rediss://USER:PASSWORD@HOST:PORT`
     * (forme Heroku historique avec user `h`), où USER est ignoré et
     * seul PASSWORD est utilisé (auth simple, pas ACL Redis 6).
     *
     * @return array{host: string, port: int, password: ?string, tls: bool}
     */
    public static function parseUrl(string $url): array
    {
        $parsed = parse_url($url);
        if (false === $parsed || !isset($parsed['host'])) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid REDIS_URL (parse_url failed or no host): %s',
                $url,
            ));
        }

        $scheme = $parsed['scheme'] ?? 'redis';
        $tls    = 'rediss' === $scheme;

        // parse_url ne url-decode pas pass — Heroku peut générer des
        // mots de passe avec des caractères réservés.
        $password = isset($parsed['pass']) ? urldecode($parsed['pass']) : null;

        return [
            'host'     => $parsed['host'],
            'port'     => $parsed['port'] ?? 6379,
            'password' => $password,
            'tls'      => $tls,
        ];
    }

    /**
     * @return array{host: string, port: int, password: ?string, tls: bool}
     */
    private function resolveConnectionParts(): array
    {
        if ('' !== $this->redisUrl) {
            return self::parseUrl($this->redisUrl);
        }

        // Fallback dev : REDIS_HOST/REDIS_PORT, pas de TLS, pas de password.
        return [
            'host'     => '' !== $this->redisHost ? $this->redisHost : '127.0.0.1',
            'port'     => '' !== $this->redisPort ? (int) $this->redisPort : 6379,
            'password' => null,
            'tls'      => false,
        ];
    }
}
