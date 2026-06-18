<?php

declare(strict_types=1);

namespace App\Shared\Env;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * Normalise une URL Postgres injectée par Heroku Data for Postgres pour
 * la rendre consommable par Doctrine 4 + DBAL.
 *
 * Sprint 14-C.2 — fix bloquant pour le déploiement prod.
 *
 * Pourquoi un processeur custom plutôt qu'un patch dans index.php ou
 * bin/console : la transformation reste DÉCLARATIVE dans `doctrine.yaml`
 * (`%env(heroku_db:resolve:DATABASE_URL)%`), elle est isolée, idempotente,
 * testée unitairement et n'a aucun effet de bord sur dev/test (uniquement
 * câblée via `when@prod`).
 *
 * Trois normalisations idempotentes :
 *   1. Schéma de tête `postgres://` → `postgresql://` (Doctrine 4 refuse
 *      `postgres://`, Heroku injecte historiquement ce préfixe).
 *   2. Ajout de `sslmode=require` si absent (Heroku Postgres force SSL).
 *   3. Ajout de `serverVersion=16` si absent (Doctrine en a besoin pour
 *      éviter une introspection coûteuse au boot).
 *
 * Idempotent : appliqué 2× = même résultat.
 *
 * Fallback de sécurité : si `parse_url()` échoue (mot de passe avec
 * caractères spéciaux non URL-encodés notamment), on se limite au
 * remplacement de schéma `postgres://` → `postgresql://`. Pas d'ajout
 * de params query — on préfère une URL imparfaite mais fonctionnelle à
 * une URL réécrite agressivement et corrompue. L'opérateur peut alors
 * URL-encoder son mot de passe et les params ajoutés viendront au
 * prochain redéploiement.
 */
final class HerokuDatabaseUrlProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, \Closure $getEnv): mixed
    {
        $value = $getEnv($name);

        if (!is_string($value) || '' === $value) {
            return $value;
        }

        // 1) Schéma de tête.
        $url = preg_replace('#^postgres://#', 'postgresql://', $value, 1);
        if (!is_string($url)) {
            return $value;
        }

        // 2) + 3) Ajout sslmode + serverVersion via parse_url. parse_url
        // n'est PAS RFC-strict sur les passwords : un `@` ou `?` non
        // encodé peut le faire échouer ou produire un résultat tronqué.
        // On détecte ce cas et on retourne juste l'URL avec schéma
        // corrigé (cf. fallback documenté en docblock).
        $parts = @parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        // Idempotence stricte : on n'écrase JAMAIS une valeur déjà
        // posée par l'opérateur. Permet à un Massamba de surcharger
        // serverVersion=17 si Heroku passe à PG 17 sans nous attendre.
        if (!array_key_exists('sslmode', $query)) {
            $query['sslmode'] = 'require';
        }
        if (!array_key_exists('serverVersion', $query)) {
            $query['serverVersion'] = '16';
        }

        $parts['query'] = http_build_query($query);

        return $this->buildUrl($parts);
    }

    public static function getProvidedTypes(): array
    {
        return ['heroku_db' => 'string'];
    }

    /**
     * Reconstruit une URL à partir du tableau renvoyé par parse_url.
     * Inspiré de la RFC 3986 + comportement de http_build_url (ext_pecl
     * non disponible dans l'image FrankenPHP).
     *
     * @param array<string, mixed> $parts
     */
    private function buildUrl(array $parts): string
    {
        $url = ($parts['scheme'] ?? 'postgresql').'://';

        if (isset($parts['user'])) {
            $url .= $parts['user'];
            if (isset($parts['pass'])) {
                $url .= ':'.$parts['pass'];
            }
            $url .= '@';
        }

        $url .= $parts['host'] ?? '';

        if (isset($parts['port'])) {
            $url .= ':'.$parts['port'];
        }

        if (isset($parts['path'])) {
            $url .= $parts['path'];
        }

        if (isset($parts['query']) && '' !== $parts['query']) {
            $url .= '?'.$parts['query'];
        }

        return $url;
    }
}
