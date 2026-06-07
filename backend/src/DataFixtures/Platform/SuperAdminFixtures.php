<?php

declare(strict_types=1);

namespace App\DataFixtures\Platform;

use App\Platform\User\Domain\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Compte SuperAdmin plateforme pour le dev.
 *
 * En production, utiliser la commande `stayos:superadmin:create`.
 */
class SuperAdminFixtures extends Fixture
{
    public const SUPER_ADMIN = 'super-admin';

    private const EMAIL    = 'admin@stayos.sn';
    private const NAME     = 'Admin StayOS';
    private const PASSWORD = 'superadmin123';

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {}

    public function load(ObjectManager $manager): void
    {
        $user = new User();
        $user->setEmail(self::EMAIL);
        $user->setName(self::NAME);
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setActive(true);
        $user->setPassword($this->passwordHasher->hashPassword($user, self::PASSWORD));

        $manager->persist($user);
        $manager->flush();

        $this->addReference(self::SUPER_ADMIN, $user);
    }
}
