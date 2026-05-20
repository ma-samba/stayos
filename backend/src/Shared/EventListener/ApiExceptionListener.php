<?php

namespace App\Shared\EventListener;

use App\Shared\Exception\AlreadyExistsException;
use App\Shared\Exception\BusinessRuleException;
use App\Shared\Exception\ConflictException;
use App\Shared\Exception\FeatureNotAvailableException;
use App\Shared\Exception\OtpInvalidException;
use App\Shared\Exception\TenantNotFoundException;
use App\Shared\Exception\TenantSuspendedException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::EXCEPTION)]
class ApiExceptionListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();

        // N'intercepter que les routes /api et /superadmin
        if (!str_starts_with($request->getPathInfo(), '/api')
            && !str_starts_with($request->getPathInfo(), '/superadmin')
        ) {
            return;
        }

        $exception = $event->getThrowable();

        [$status, $code, $message] = match (true) {
            $exception instanceof TenantNotFoundException      => [404, 'NOT_FOUND',       $exception->getMessage()],
            $exception instanceof TenantSuspendedException     => [402, 'TENANT_SUSPENDED', $exception->getMessage()],
            $exception instanceof OtpInvalidException          => [422, 'VALIDATION_ERROR', $exception->getMessage()],
            $exception instanceof AlreadyExistsException       => [409, 'ALREADY_EXISTS',   $exception->getMessage()],
            $exception instanceof ConflictException              => [409, 'CONFLICT',          $exception->getMessage()],
            $exception instanceof BusinessRuleException          => [422, 'BUSINESS_RULE',    $exception->getMessage()],
            $exception instanceof FeatureNotAvailableException => [403, 'PLAN_LIMIT',        $exception->getMessage()],
            $exception instanceof AccessDeniedHttpException    => [403, 'ACCESS_DENIED',     'Accès refusé.'],
            $exception instanceof NotFoundHttpException        => [404, 'NOT_FOUND',         'Ressource introuvable.'],
            $exception instanceof TooManyRequestsHttpException => [429, 'RATE_LIMITED',      'Trop de requêtes. Réessayez dans un moment.'],
            $exception instanceof HttpExceptionInterface       => [$exception->getStatusCode(), 'HTTP_ERROR', $exception->getMessage()],
            default                                            => [500, 'INTERNAL_ERROR',    'Une erreur interne est survenue.'],
        };

        // Logger les erreurs 500 inattendues
        if ($status === 500) {
            $this->logger->error('Unexpected API error', [
                'exception' => $exception->getMessage(),
                'class'     => $exception::class,
                'path'      => $request->getPathInfo(),
            ]);
        }

        $event->setResponse(new JsonResponse([
            'error'  => $message,
            'code'   => $code,
            'status' => $status,
        ], $status));
    }
}
