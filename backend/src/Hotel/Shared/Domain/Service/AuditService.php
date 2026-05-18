<?php

namespace App\Hotel\Shared\Domain\Service;

use App\Hotel\Shared\Domain\Entity\AuditLog;
use App\Hotel\Shared\Infrastructure\Repository\AuditLogRepository;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Shared\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class AuditService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogRepository     $auditLogRepository,
        private readonly TenantContext          $tenantContext,
        private readonly RequestStack           $requestStack,
    ) {}

    public function log(
        string     $action,
        string     $entityType,
        string     $entityId,
        ?array     $before    = null,
        ?array     $after     = null,
        ?StaffUser $staffUser = null,
    ): void {
        // Ne pas logger si pas de contexte tenant (commandes console, etc.)
        if (!$this->tenantContext->has()) {
            return;
        }

        $request = $this->requestStack->getCurrentRequest();

        $log = new AuditLog();
        $log->setAction($action);
        $log->setEntityType($entityType);
        $log->setEntityId($entityId);
        $log->setBefore($before);
        $log->setAfter($after);
        $log->setIpAddress($request?->getClientIp());
        $log->setUserAgent($request?->headers->get('User-Agent'));

        if ($staffUser !== null) {
            $log->setStaffUserEmail($staffUser->getEmail());
            $log->setStaffUserRole($staffUser->getRole());
        }

        $this->entityManager->persist($log);
        // PAS de flush ici — le flush est fait par le service appelant
    }

    /**
     * Retourne l'historique complet d'une entité.
     */
    public function getHistory(string $entityType, string $entityId): array
    {
        return $this->auditLogRepository->findByEntity($entityType, $entityId);
    }

    /**
     * Retourne les actions d'un staff sur les N derniers jours.
     */
    public function getStaffActivity(string $staffEmail, int $days = 30): array
    {
        $since = new \DateTimeImmutable(
            "-{$days} days",
            new \DateTimeZone('Africa/Dakar')
        );

        return $this->auditLogRepository->createQueryBuilder('a')
            ->where('a.staffUserEmail = :email')
            ->andWhere('a.createdAt >= :since')
            ->setParameter('email', $staffEmail)
            ->setParameter('since', $since)
            ->orderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
