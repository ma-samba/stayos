<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Auth;

use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Vérifie que le `AuthenticationSuccessListener` met à jour
 * `lastLoginAt` après un login JWT réussi (Sprint 13bis correctif).
 *
 * @group integration
 */
class LoginUpdatesLastLoginAtTest extends ApiTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();
    }

    public function testSuccessfulLoginUpdatesLastLoginAt(): void
    {
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'savana']);
        $schema = $tenant->getSchemaName();

        // Forcer lastLoginAt à NULL pour avoir un point de référence
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            $this->conn->executeStatement(
                'UPDATE staff_users SET last_login_at = NULL WHERE email = ?',
                ['admin@savana-hotel.sn'],
            );
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }

        $beforeLogin = new \DateTimeImmutable('now', new \DateTimeZone('Africa/Dakar'));

        $token = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');
        self::assertNotEmpty($token);

        // Relire en BDD
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            $staff = $this->em->getRepository(StaffUser::class)
                ->findOneBy(['email' => 'admin@savana-hotel.sn']);
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
        }

        self::assertNotNull($staff->getLastLoginAt(), 'lastLoginAt doit être renseigné après login');

        $delta = $staff->getLastLoginAt()->getTimestamp() - $beforeLogin->getTimestamp();
        self::assertGreaterThanOrEqual(0, $delta);
        self::assertLessThan(10, $delta, 'lastLoginAt doit être proche du moment du login');
    }

    public function testFailedLoginDoesNotUpdateLastLoginAt(): void
    {
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'savana']);
        $schema = $tenant->getSchemaName();

        $marker = new \DateTimeImmutable('2020-01-01 12:00:00');

        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            $this->conn->executeStatement(
                'UPDATE staff_users SET last_login_at = ? WHERE email = ?',
                [$marker->format('Y-m-d H:i:s'), 'reception@savana-hotel.sn'],
            );
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }

        // Login avec mauvais password
        $this->apiRequest(
            'POST',
            '/api/auth/login',
            'savana.localhost',
            body: ['email' => 'reception@savana-hotel.sn', 'password' => 'wrong'],
        );
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        // Vérifier que lastLoginAt n'a pas bougé
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $schema));
        try {
            $staff = $this->em->getRepository(StaffUser::class)
                ->findOneBy(['email' => 'reception@savana-hotel.sn']);
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
        }

        self::assertNotNull($staff->getLastLoginAt());
        self::assertSame(
            $marker->format('Y-m-d H:i:s'),
            $staff->getLastLoginAt()->format('Y-m-d H:i:s'),
            'lastLoginAt ne doit PAS être mis à jour sur un login échoué',
        );
    }
}
