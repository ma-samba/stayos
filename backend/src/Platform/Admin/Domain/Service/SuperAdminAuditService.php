<?php

declare(strict_types=1);

namespace App\Platform\Admin\Domain\Service;

use App\Platform\Admin\Domain\Entity\SuperAdminAuditLog;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Platform\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Trace les actions sensibles d'un SuperAdmin dans
 * `public.superadmin_audit_log`.
 *
 * À distinguer de `Hotel\Shared\Domain\Service\AuditService` qui
 * loggue les actions des StaffUser dans le schema tenant :
 *  - Acteur ici = User platform (pas StaffUser)
 *  - Stockage public (pas tenant)
 *  - IP/UA capturés systématiquement
 */
class SuperAdminAuditService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    /**
     * @param array<string, mixed>|null $payload
     */
    public function log(
        User    $actor,
        ?Tenant $tenant,
        string  $action,
        ?array  $payload = null,
        ?Request $request = null,
    ): void {
        $log = new SuperAdminAuditLog();
        $log->setActorEmail($actor->getEmail());
        $log->setTenantSlug($tenant?->getSlug());
        $log->setAction($action);
        $log->setPayload($payload);

        if ($request !== null) {
            $log->setIpAddress($request->getClientIp());
            $log->setUserAgent($request->headers->get('User-Agent'));
        }

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }
}
