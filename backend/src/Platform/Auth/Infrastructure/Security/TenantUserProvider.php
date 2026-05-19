<?php

namespace App\Platform\Auth\Infrastructure\Security;

use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Platform\Auth\Infrastructure\Doctrine\StaffUserRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
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
        // Ne pas recharger depuis la BDD sur chaque requête —
        // le JWT contient déjà toutes les infos nécessaires.
        // Le rechargement BDD se fait uniquement au login via
        // loadUserByIdentifier() où le search_path est déjà positionné.
        if (!$user instanceof StaffUser) {
            throw new UnsupportedUserException(
                sprintf('Expected %s, got %s', StaffUser::class, $user::class)
            );
        }

        return $user;
    }

    public function supportsClass(string $class): bool
    {
        return StaffUser::class === $class;
    }
}
