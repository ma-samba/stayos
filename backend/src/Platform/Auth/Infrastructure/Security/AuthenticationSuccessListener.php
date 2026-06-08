<?php

declare(strict_types=1);

namespace App\Platform\Auth\Infrastructure\Security;

use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Platform\User\Domain\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Met à jour `lastLoginAt` après chaque login JWT réussi.
 *
 * Couvre les deux types d'utilisateurs :
 *  - `StaffUser` (schema tenant) : login sur /api/auth/login.
 *    Le `search_path` est posé par `JWTDecodedListener` pour les
 *    appels API ultérieurs ; ici on est dans le flux json_login
 *    initial, donc le provider a déjà chargé le user via le
 *    `TenantUserProvider` qui pose le `search_path` lui-même.
 *  - `User` platform (table `public.users`) : login sur
 *    /superadmin/auth/login.
 *
 * Le flush est appelé ici (le service appelant — la chaîne
 * d'auth Symfony — ne flush pas par lui-même).
 */
#[AsEventListener(event: Events::AUTHENTICATION_SUCCESS, method: 'onAuthenticationSuccess')]
class AuthenticationSuccessListener
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof StaffUser && !$user instanceof User) {
            return;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar'));
        $user->setLastLoginAt($now);

        $this->entityManager->flush();
    }
}
