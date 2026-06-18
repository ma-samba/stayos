<?php

declare(strict_types=1);

namespace App\Shared\Sentry;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Nettoie les données sensibles des événements Sentry avant
 * envoi.
 *
 * Sentry capture par défaut les headers, query params, et
 * body POST. Ces données peuvent contenir :
 * - JWT (header Authorization)
 * - Password en clair (body POST login)
 * - Tokens Paydunya (query param IPN, body webhook)
 * - Email OTP / mots de passe temporaires (body POST staff
 *   creation, reset password)
 *
 * Sentry a un scrubbing par défaut sur les clés `password`,
 * `secret`, etc. mais on durcit avec une liste explicite.
 *
 * Pattern : remplacer la valeur par '[Filtered]' plutôt que
 * de retirer la clé, pour garder visible qu'il y avait
 * quelque chose là.
 *
 * Branché via `sentry.options.before_send` dans
 * `config/packages/sentry.yaml`. Le callable est invoqué
 * juste avant l'envoi HTTP de l'event vers ingest.sentry.io.
 */
final class SensitiveDataScrubber
{
    /**
     * Clés à scrubber dans tous les contextes (headers, query,
     * body). Match insensible à la casse + partiel
     * (`my_password` aussi scrubbé).
     */
    private const SENSITIVE_KEYS = [
        'password',
        'secret',
        'token',
        'authorization',
        'api_key',
        'access_token',
        'refresh_token',
        'jwt',
        'private_key',
        'master_key',
        'paydunya',     // tout ce qui contient paydunya
        'otp',
    ];

    public function __invoke(Event $event, ?EventHint $hint = null): ?Event
    {
        $request = $event->getRequest();
        if ($request !== []) {
            $event->setRequest($this->scrubRequest($request));
        }

        return $event;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    private function scrubRequest(array $request): array
    {
        // Headers
        if (isset($request['headers']) && is_array($request['headers'])) {
            $request['headers'] = $this->scrubArray($request['headers']);
        }

        // Query string (déjà sous forme `query_string` chez Sentry)
        if (isset($request['query_string'])) {
            $request['query_string'] = $this->scrubQueryString(
                (string) $request['query_string'],
            );
        }

        // Cookies
        if (isset($request['cookies']) && is_array($request['cookies'])) {
            $request['cookies'] = $this->scrubArray($request['cookies']);
        }

        // Body (POST data)
        if (isset($request['data']) && is_array($request['data'])) {
            $request['data'] = $this->scrubArray($request['data']);
        }

        return $request;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function scrubArray(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $data[$key] = '[Filtered]';
            } elseif (is_array($value)) {
                $data[$key] = $this->scrubArray($value);
            }
        }
        return $data;
    }

    private function scrubQueryString(string $queryString): string
    {
        parse_str($queryString, $params);
        $scrubbed = $this->scrubArray($params);
        return http_build_query($scrubbed);
    }

    private function isSensitiveKey(string $key): bool
    {
        $lowerKey = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if (str_contains($lowerKey, $sensitive)) {
                return true;
            }
        }
        return false;
    }
}
