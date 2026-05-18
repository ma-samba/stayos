<?php

namespace App\Platform\Auth\Infrastructure\Security;

use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Platform\Auth\Infrastructure\Doctrine\StaffUserRepository;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Charge le StaffUser depuis le schema hotel_{uuid} courant.
 * Le search_path est positionné par TenantMiddleware avant ce provider.
 *
 * @implements UserProviderInterface<StaffUser>
 */
class TenantUserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly StaffUserRepository $staffUserRepository,
    ) {}

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        $user = $this->staffUserRepository->findByEmail($identifier);

        if (null === $user || !$user->isActive()) {
            throw new UserNotFoundException(
                sprintf("Utilisateur '%s' introuvable.", $identifier)
            );
        }

        return $user;
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return StaffUser::class === $class;
    }
}
