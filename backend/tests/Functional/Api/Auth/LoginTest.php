<?php

namespace App\Tests\Functional\Api\Auth;

use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Platform\Tenant\Domain\Enum\TenantStatus;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Tests du login JWT — POST /api/auth/login
 *
 * Prérequis : docker compose up (PostgreSQL + Redis)
 * Lancer : php bin/phpunit tests/Functional/Api/Auth/LoginTest.php
 */
class LoginTest extends ApiTestCase
{
    private EntityManagerInterface $em;

    private const HOST     = 'test-login.localhost';
    private const EMAIL    = 'manager@test-login.sn';
    private const PASSWORD = 'Password123!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Réinitialise le bucket du rate-limiter login (filesystem en env
        // test depuis Sprint 14-A.3 C.1) — chaque test démarre avec un
        // compteur vierge sur cette IP, pour éviter qu'un test antérieur
        // ayant consommé des tentatives ne déclenche l'IP-throttling.
        $cache = static::getContainer()->get('cache.rate_limiter');
        if ($cache instanceof CacheInterface) {
            $cache->clear();
        }

        $this->setUpTestTenant();
    }

    private function setUpTestTenant(): void
    {
        // Créer un tenant de test s'il n'existe pas déjà
        $tenantRepo = $this->em->getRepository(Tenant::class);
        $tenant     = $tenantRepo->findOneBy(['slug' => 'test-login']);

        if (null === $tenant) {
            $tenant = new Tenant();
            $tenant->setSlug('test-login');
            $tenant->setName('Hôtel Test Login');
            $tenant->setSubdomain('test-login');

            $this->em->persist($tenant);
            $this->em->flush();

            // Créer le schema + table staff_users
            $conn       = $this->em->getConnection();
            $schemaName = $tenant->getSchemaName();

            $conn->executeStatement(sprintf('CREATE SCHEMA IF NOT EXISTS %s', $schemaName));
            $conn->executeStatement(sprintf('
                CREATE TABLE IF NOT EXISTS %s.staff_users (
                    id UUID NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL,
                    first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL,
                    role VARCHAR(20) NOT NULL DEFAULT \'RECEPTIONIST\',
                    phone VARCHAR(20) DEFAULT NULL, active BOOLEAN NOT NULL DEFAULT TRUE,
                    locale VARCHAR(5) NOT NULL DEFAULT \'fr\',
                    last_login_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                    created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                    PRIMARY KEY (id)
                )
            ', $schemaName));
        }

        // Insérer le StaffUser dans le schema du tenant
        $schemaName = $tenant->getSchemaName();
        $conn       = $this->em->getConnection();

        $conn->executeStatement(sprintf('SET search_path TO %s, public', $schemaName));

        $existing = $conn->fetchOne(
            'SELECT id FROM staff_users WHERE email = ?',
            [self::EMAIL]
        );

        if (!$existing) {
            /** @var UserPasswordHasherInterface $hasher */
            $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

            $staffUser = new StaffUser();
            $staffUser->setEmail(self::EMAIL);
            $staffUser->setFirstName('Manager');
            $staffUser->setLastName('Test');
            $staffUser->setRole('MANAGER');
            $staffUser->setPassword($hasher->hashPassword($staffUser, self::PASSWORD));

            $this->em->persist($staffUser);
            $this->em->flush();
        }

        $conn->executeStatement('SET search_path TO public');
    }

    public function testLoginWithValidCredentials(): void
    {
        $response = $this->apiRequest(
            'POST',
            '/api/auth/login',
            self::HOST,
            ['email' => self::EMAIL, 'password' => self::PASSWORD],
        );

        self::assertResponseStatusCodeSame(200);
        self::assertArrayHasKey('token', $response);
        self::assertNotEmpty($response['token']);
    }

    public function testLoginWithWrongPassword(): void
    {
        $this->apiRequest(
            'POST',
            '/api/auth/login',
            self::HOST,
            ['email' => self::EMAIL, 'password' => 'WrongPassword!'],
        );

        self::assertResponseStatusCodeSame(401);
    }

    public function testLoginRateLimitAfterFiveAttempts(): void
    {
        // login_throttling configuré à max_attempts=5 / 1 minute. Le
        // listener LoginRateLimitListener (Sprint 14-A.3 C.1) mappe
        // la TooManyLoginAttemptsAuthenticationException levée par
        // Symfony en HTTP 429 (vs 401 par défaut de Lexik).
        //
        // ⚠️ En env test, le cache app est en `array` (in-memory) et
        // KernelBrowser reboote le kernel entre chaque requête, ce qui
        // réinitialiserait les compteurs du rate-limiter. On désactive
        // le reboot pour que les 6 tentatives partagent le même cache.
        $this->client->disableReboot();

        $email = 'ratelimit@test-login.sn';

        for ($i = 0; $i < 5; $i++) {
            $this->apiRequest(
                'POST',
                '/api/auth/login',
                self::HOST,
                ['email' => $email, 'password' => 'WrongPwd!'],
            );
            self::assertResponseStatusCodeSame(401, "Tentative $i doit échouer en 401");
        }

        // 6e tentative → rate limit → 429
        $this->apiRequest(
            'POST',
            '/api/auth/login',
            self::HOST,
            ['email' => $email, 'password' => 'WrongPwd!'],
        );
        self::assertResponseStatusCodeSame(429);
    }
}
