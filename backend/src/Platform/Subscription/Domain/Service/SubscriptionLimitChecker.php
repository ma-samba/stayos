<?php

declare(strict_types=1);

namespace App\Platform\Subscription\Domain\Service;

use App\Hotel\Room\Domain\Entity\Room;
use App\Platform\Auth\Domain\Entity\StaffInvitation;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Platform\Auth\Domain\Enum\InvitationStatus;
use App\Platform\Subscription\Infrastructure\Doctrine\SubscriptionRepository;
use App\Shared\Exception\BusinessRuleException;
use App\Shared\TenantContext;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Vérifie les limites quantitatives du plan d'abonnement du tenant
 * courant.
 *
 * Sprint 13bis-A : `assertCanAddUser()` couvre la limite maxUsers
 * (StaffUser actifs + StaffInvitation PENDING).
 *
 * Sprint 13ter : `assertCanAddRoom()` couvre la limite maxRooms
 * (rooms.isActive=true). Une chambre désactivée libère sa place.
 *
 * Pour un plan ENTERPRISE (`maxRooms`/`maxUsers` = null) les
 * limites sont illimitées : la méthode retourne immédiatement.
 */
class SubscriptionLimitChecker
{
    public function __construct(
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext          $tenantContext,
    ) {}

    public function assertCanAddUser(): void
    {
        $tenant       = $this->tenantContext->get();
        $subscription = $this->subscriptionRepository->findActiveByTenant($tenant);

        if ($subscription === null) {
            throw new BusinessRuleException('Aucun abonnement actif pour ce tenant.');
        }

        $maxUsers = $subscription->getPlan()->getMaxUsers();
        if ($maxUsers === null) {
            return; // Plan Enterprise = illimité
        }

        $consumed = $this->countUserConsumption();

        if ($consumed >= $maxUsers) {
            throw new BusinessRuleException(sprintf(
                "Limite du plan %s atteinte (%d utilisateurs). "
                . "Désactivez un employé ou upgradez votre plan.",
                $subscription->getPlan()->getName(),
                $maxUsers,
            ));
        }
    }

    /**
     * Vérifie qu'on peut ajouter UNE chambre active supplémentaire.
     *
     * Appelée :
     *  - avant POST /api/rooms (création unitaire)
     *  - pour CHAQUE chambre dans POST /api/rooms/bulk (rollback
     *    si la N-ième dépasse la limite)
     *  - avant POST /api/rooms/{id}/reactivate
     */
    public function assertCanAddRoom(): void
    {
        $tenant       = $this->tenantContext->get();
        $subscription = $this->subscriptionRepository->findActiveByTenant($tenant);

        if ($subscription === null) {
            throw new BusinessRuleException('Aucun abonnement actif pour ce tenant.');
        }

        $maxRooms = $subscription->getPlan()->getMaxRooms();
        if ($maxRooms === null) {
            return; // Plan Enterprise = illimité
        }

        $active = $this->countActiveRooms();

        if ($active >= $maxRooms) {
            throw new BusinessRuleException(sprintf(
                "Limite du plan %s atteinte (%d chambres). "
                . "Désactivez une chambre ou upgradez votre plan.",
                $subscription->getPlan()->getName(),
                $maxRooms,
            ));
        }
    }

    /**
     * Décompte utilisateurs (utile à l'UI pour afficher la jauge).
     *
     * @return array{used:int, max:int|null, plan:string|null}
     */
    public function getUserUsage(): array
    {
        $tenant       = $this->tenantContext->get();
        $subscription = $this->subscriptionRepository->findActiveByTenant($tenant);

        return [
            'used' => $this->countUserConsumption(),
            'max'  => $subscription?->getPlan()->getMaxUsers(),
            'plan' => $subscription?->getPlan()->getName(),
        ];
    }

    /**
     * Décompte chambres (utile à l'UI pour afficher la jauge).
     *
     * @return array{used:int, max:int|null, plan:string|null}
     */
    public function getRoomUsage(): array
    {
        $tenant       = $this->tenantContext->get();
        $subscription = $this->subscriptionRepository->findActiveByTenant($tenant);

        return [
            'used' => $this->countActiveRooms(),
            'max'  => $subscription?->getPlan()->getMaxRooms(),
            'plan' => $subscription?->getPlan()->getName(),
        ];
    }

    private function countUserConsumption(): int
    {
        $activeStaff = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(StaffUser::class, 's')
            ->where('s.active = true')
            ->getQuery()
            ->getSingleScalarResult();

        $pendingInvitations = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(i.id)')
            ->from(StaffInvitation::class, 'i')
            ->where('i.status = :pending')
            ->setParameter('pending', InvitationStatus::PENDING->value)
            ->getQuery()
            ->getSingleScalarResult();

        return $activeStaff + $pendingInvitations;
    }

    private function countActiveRooms(): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(r.id)')
            ->from(Room::class, 'r')
            ->where('r.isActive = true')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
