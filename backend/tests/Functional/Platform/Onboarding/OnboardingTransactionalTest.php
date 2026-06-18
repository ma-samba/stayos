<?php

namespace App\Tests\Functional\Platform\Onboarding;

use App\Hotel\Property\Domain\Entity\HotelProfile;
use App\Platform\Admin\Domain\Service\TenantSeedService;
use App\Platform\Auth\Domain\Service\OnboardingService;
use App\Platform\Auth\Domain\Service\OtpService;
use App\Platform\Subscription\Domain\Service\AbonnementService;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Platform\Tenant\Domain\Service\TenantProvisioner;
use App\Platform\Tenant\Infrastructure\Doctrine\TenantRepository;
use App\Shared\Exception\BusinessRuleException;
use App\Shared\Security\TempPasswordGenerator;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tests qui forcent un échec à différents points du flow onboarding
 * et vérifient l'absence de résidus (tenant en public.tenants ou
 * schema hotel_{uuid} orphelin en information_schema).
 *
 * Pattern : on instancie OnboardingService directement avec un mock
 * sur la dépendance qui doit throw. Les autres deps sont les vraies
 * du container — la transaction Doctrine et le DROP SCHEMA défensif
 * sont donc bien exercés en BDD.
 */
class OnboardingTransactionalTest extends ApiTestCase
{
    private EntityManagerInterface $em;
    private Connection             $conn;

    /** @var list<string> Snapshot des schemas orphelins déjà présents au setUp (résidus d'autres tests) */
    private array $baselineOrphanSchemas = [];

    private const SLUG_PREFIX = 'rollback-test-';

    protected function setUp(): void
    {
        parent::setUp();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();

        // Snapshot des orphelins préexistants (autres tests qui ne nettoient pas
        // toujours leur schema). On ne vérifie qu'on n'en a pas créé de NOUVEAUX.
        $this->baselineOrphanSchemas = $this->fetchOrphanSchemas();
    }

    protected function tearDown(): void
    {
        // Filet de sécurité : si les rollbacks ont bien fonctionné,
        // ces requêtes ne droppent rien. Sinon, on évite que les
        // résidus polluent les tests suivants.
        $this->conn->executeStatement(
            "DELETE FROM tenants WHERE slug LIKE :prefix",
            ['prefix' => self::SLUG_PREFIX . '%']
        );

        $rows = $this->conn->fetchAllAssociative(
            "SELECT schema_name FROM information_schema.schemata
             WHERE schema_name LIKE 'hotel_%'
               AND schema_name NOT IN (
                   SELECT 'hotel_' || REPLACE(id::text, '-', '_')
                   FROM tenants
               )"
        );
        foreach ($rows as $row) {
            $name = (string) $row['schema_name'];
            if (preg_match('/^hotel_[0-9a-f_]+$/i', $name)) {
                $this->conn->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', $name));
            }
        }

        parent::tearDown();
    }

    /**
     * Force un échec après tenant + schema créés, avant commit.
     * Vérifie qu'aucun résidu ne reste en BDD.
     */
    public function testRegisterRollsBackWhenAbonnementServiceFails(): void
    {
        $slug = self::SLUG_PREFIX . 'register-abo-' . uniqid();

        $abonnementMock = $this->createMock(AbonnementService::class);
        $abonnementMock->method('createTrial')->willThrowException(
            new \RuntimeException('Simulated failure for rollback test')
        );

        $service = $this->buildService(abonnementService: $abonnementMock);

        $thrown = null;
        try {
            $service->register([
                'hotel_name' => 'Hotel Rollback',
                'slug'       => $slug,
                'email'      => 'admin@' . $slug . '.sn',
                'password'   => 'MotDePasse123!',
                'first_name' => 'Test',
                'last_name'  => 'Rollback',
            ]);
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'Expected exception not thrown');
        self::assertSame('Simulated failure for rollback test', $thrown->getMessage());

        $this->assertNoResidueForSlug($slug);
    }

    /**
     * Force un échec dans AbonnementService::createActive() lors de
     * provision(initialStatus: 'active'), après que tenant + schema
     * ont été créés. Vérifie le rollback complet via la voie
     * provision() (et non plus register()).
     *
     * Nota : on aurait voulu mocker TenantSeedService::seed() pour
     * échouer encore plus tard dans le flow (étape 6), mais la classe
     * est `final` et PHPUnit 11 refuse de la doubler. Le mock sur
     * createActive() couvre la même garantie transactionnelle : rollback
     * complet quand une étape post-CREATE-SCHEMA échoue.
     */
    public function testProvisionRollsBackWhenSubscriptionFails(): void
    {
        $slug = self::SLUG_PREFIX . 'provision-sub-' . uniqid();

        $abonnementMock = $this->createMock(AbonnementService::class);
        $abonnementMock->method('createActive')->willThrowException(
            new \RuntimeException('Simulated active subscription failure')
        );

        $service = $this->buildService(abonnementService: $abonnementMock);

        $thrown = null;
        try {
            $service->provision(
                data: [
                    'hotel_name' => 'Hotel Sub Rollback',
                    'slug'       => $slug,
                    'email'      => 'admin@' . $slug . '.sn',
                    'first_name' => 'Test',
                    'last_name'  => 'Sub',
                ],
                initialStatus: 'active',
            );
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertNotNull($thrown, 'Expected exception not thrown');
        self::assertSame('Simulated active subscription failure', $thrown->getMessage());

        $this->assertNoResidueForSlug($slug);
    }

    /**
     * Hotfix Sprint 14-A.2 : register() doit créer un HotelProfile par
     * défaut. Sans ce profil, ReservationEngine::resolveHotelId throw
     * "Profil hôtel introuvable" à la 1re création de résa.
     */
    public function testRegisterCreatesHotelProfile(): void
    {
        $slug = self::SLUG_PREFIX . 'register-profile-' . uniqid();
        $service = $this->buildService();

        $result = $service->register([
            'hotel_name' => 'Hotel Test Register',
            'slug'       => $slug,
            'email'      => 'admin@' . $slug . '.sn',
            'password'   => 'MotDePasse123!',
            'first_name' => 'Jean',
            'last_name'  => 'Test',
        ]);

        /** @var Tenant $tenant */
        $tenant = $result['tenant'];
        $tenantId = (string) $tenant->getId();

        // Vérifier dans le schema tenant
        $schemaName = $tenant->getSchemaName();
        try {
            $this->conn->executeStatement(
                sprintf('SET search_path TO %s, public', $schemaName)
            );
            $this->em->clear();

            $profile = $this->em->getRepository(HotelProfile::class)->findOneBy([]);
            self::assertNotNull(
                $profile,
                'HotelProfile doit être créé après register()',
            );
            self::assertSame(
                'Hotel Test Register',
                $profile->getName(),
                'HotelProfile.name doit correspondre au hotel_name fourni',
            );
            self::assertSame('SN', $profile->getCountry());
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }

        $this->cleanupTenant($tenantId, $schemaName);
    }

    /**
     * Hotfix Sprint 14-A.2 : provision() (chemin SuperAdmin) doit aussi
     * créer un HotelProfile par défaut, indépendamment du template seed.
     */
    public function testProvisionCreatesHotelProfile(): void
    {
        $slug = self::SLUG_PREFIX . 'provision-profile-' . uniqid();
        $service = $this->buildService();

        $result = $service->provision(
            data: [
                'hotel_name' => 'Hotel Test Provision',
                'slug'       => $slug,
                'email'      => 'admin@' . $slug . '.sn',
                'first_name' => 'Marie',
                'last_name'  => 'Test',
            ],
            initialStatus: 'trial',
        );

        /** @var Tenant $tenant */
        $tenant = $result['tenant'];
        $tenantId = (string) $tenant->getId();

        $schemaName = $tenant->getSchemaName();
        try {
            $this->conn->executeStatement(
                sprintf('SET search_path TO %s, public', $schemaName)
            );
            $this->em->clear();

            $profile = $this->em->getRepository(HotelProfile::class)->findOneBy([]);
            self::assertNotNull(
                $profile,
                'HotelProfile doit être créé après provision()',
            );
            self::assertSame('Hotel Test Provision', $profile->getName());
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }

        $this->cleanupTenant($tenantId, $schemaName);
    }

    /**
     * Cas réel sans mock : plan inexistant → BusinessRuleException
     * levée APRES que tenantProvisioner->provision() a déjà créé le
     * schema. Vérifie que le schema n'existe plus après le rollback.
     */
    public function testProvisionWithUnknownPlanDoesNotProvisionSchema(): void
    {
        $slug = self::SLUG_PREFIX . 'provision-plan-' . uniqid();

        $service = $this->buildService();

        $thrown = null;
        try {
            $service->provision(
                data: [
                    'hotel_name' => 'Hotel Unknown Plan',
                    'slug'       => $slug,
                    'email'      => 'admin@' . $slug . '.sn',
                    'first_name' => 'Test',
                    'last_name'  => 'Plan',
                    'plan'       => 'INEXISTANT_PLAN_XYZ',
                ],
            );
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(BusinessRuleException::class, $thrown);
        self::assertStringContainsString('INEXISTANT_PLAN_XYZ', $thrown->getMessage());

        $this->assertNoResidueForSlug($slug);
    }

    // ─────────────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────────────

    /**
     * Construit un OnboardingService avec les services réels du
     * container, sauf ceux passés explicitement (mocks).
     */
    private function buildService(
        ?AbonnementService $abonnementService = null,
        ?TenantSeedService $tenantSeedService = null,
    ): OnboardingService {
        $container = static::getContainer();

        return new OnboardingService(
            $container->get(TenantRepository::class),
            $container->get(TenantProvisioner::class),
            $container->get(OtpService::class),
            $this->em,
            $container->get(UserPasswordHasherInterface::class),
            $abonnementService ?? $container->get(AbonnementService::class),
            $container->get(TempPasswordGenerator::class),
            $tenantSeedService ?? $container->get(TenantSeedService::class),
            $container->get(LoggerInterface::class),
        );
    }

    /**
     * Nettoyage d'un onboarding réussi : drop subscriptions (pas de
     * CASCADE sur FK tenant), tenant (CASCADE saas_invoices) et schema
     * tenant. Évite que les tenants de tests success polluent les
     * compteurs SuperAdmin et fassent planter la tearDown sur FK.
     */
    private function cleanupTenant(string $tenantId, string $schemaName): void
    {
        $this->em->clear();

        $this->conn->executeStatement(
            'DELETE FROM subscriptions WHERE tenant_id = :id',
            ['id' => $tenantId]
        );
        $this->conn->executeStatement(
            'DELETE FROM tenants WHERE id = :id',
            ['id' => $tenantId]
        );

        if (preg_match('/^hotel_[0-9a-f_]+$/i', $schemaName)) {
            $this->conn->executeStatement(
                sprintf('DROP SCHEMA IF EXISTS %s CASCADE', $schemaName)
            );
        }
    }

    private function assertNoResidueForSlug(string $slug): void
    {
        $tenantCount = (int) $this->conn->fetchOne(
            'SELECT COUNT(*) FROM tenants WHERE slug = :slug',
            ['slug' => $slug]
        );
        self::assertSame(
            0,
            $tenantCount,
            sprintf("Tenant '%s' persisté en BDD malgré le rollback", $slug)
        );

        // Comparer au snapshot du setUp : seuls les NOUVEAUX orphelins
        // sont la responsabilité de CE test (d'autres tests laissent
        // parfois des résidus qu'on ne veut pas s'attribuer à tort).
        $currentOrphans = $this->fetchOrphanSchemas();
        $newOrphans = array_values(
            array_diff($currentOrphans, $this->baselineOrphanSchemas)
        );

        self::assertCount(
            0,
            $newOrphans,
            sprintf(
                "Schema(s) orphelin(s) NOUVEAU(X) détecté(s) après rollback : %s",
                implode(', ', $newOrphans)
            )
        );
    }

    /**
     * @return list<string>
     */
    private function fetchOrphanSchemas(): array
    {
        $rows = $this->conn->fetchAllAssociative(
            "SELECT schema_name FROM information_schema.schemata
             WHERE schema_name LIKE 'hotel_%'
               AND schema_name NOT IN (
                   SELECT 'hotel_' || REPLACE(id::text, '-', '_')
                   FROM tenants
               )"
        );

        return array_values(array_map(
            static fn (array $row): string => (string) $row['schema_name'],
            $rows,
        ));
    }
}
