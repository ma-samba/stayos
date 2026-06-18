<?php

declare(strict_types=1);

namespace App\Platform\Auth\Infrastructure\EventListener;

use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;

/**
 * Mappe TooManyLoginAttemptsAuthenticationException (Symfony
 * login_throttling) en HTTP 429 au lieu du 401 par défaut émis
 * par Lexik\AuthenticationFailureHandler.
 *
 * Bug latent identifié au Sprint 11 (test
 * `LoginTest::testLoginRateLimitAfterFiveAttempts` skippé),
 * résolu au Sprint 14-A.3 C.1.
 */
#[AsEventListener(event: 'lexik_jwt_authentication.on_authentication_failure')]
final class LoginRateLimitListener
{
    public function __invoke(AuthenticationFailureEvent $event): void
    {
        $exception = $event->getException();
        if (!$exception->getPrevious() instanceof TooManyLoginAttemptsAuthenticationException
            && !$exception instanceof TooManyLoginAttemptsAuthenticationException) {
            return;
        }

        $event->setResponse(new JsonResponse(
            [
                'error'  => 'Trop de tentatives de connexion. Réessayez plus tard.',
                'code'   => 'RATE_LIMITED',
                'status' => 429,
            ],
            429,
        ));
    }
}
