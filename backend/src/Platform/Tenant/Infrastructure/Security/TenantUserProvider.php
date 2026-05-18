<?php

namespace App\Platform\Tenant\Infrastructure\Security;

use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Provider JWT pour le staff hôtelier (schema hotel_{uuid}).
 *
 * @implements UserProviderInterface<UserInterface>
 *
 * @todo Sprint 2 — Implémenter avec StaffUser + JWT claims (tenant, role, plan)
 */
class TenantUserProvider implements UserProviderInterface
{
    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        throw new \LogicException(
            'TenantUserProvider::loadUserByIdentifier() not yet implemented — Sprint 2 (JWT auth).'
        );
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        throw new UnsupportedUserException(
            \sprintf('User class "%s" is not supported.', $user::class)
        );
    }

    public function supportsClass(string $class): bool
    {
        return false;
    }
}
