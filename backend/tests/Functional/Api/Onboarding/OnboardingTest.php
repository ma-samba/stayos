<?php

namespace App\Tests\Functional\Api\Onboarding;

use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tests d'onboarding — POST /api/onboarding/register
 * Route publique (pas de tenant context ni JWT requis).
 */
class OnboardingTest extends ApiTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        // Nettoyer les tenants de test créés par les runs précédents
        $this->cleanupTestTenants();
    }

    private function cleanupTestTenants(): void
    {
        $conn = $this->em->getConnection();

        foreach (['test-hotel-reg', 'test-hotel-dup', 'test-hotel-weak'] as $slug) {
            $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => $slug]);
            if ($tenant) {
                $schema = $tenant->getSchemaName();
                $conn->executeStatement(sprintf('DROP SCHEMA IF EXISTS %s CASCADE', $schema));
                $this->em->remove($tenant);
            }
        }

        // Nettoyer les subscriptions et plans orphelins potentiels
        $this->em->flush();
    }

    public function testRegisterCreatesNewTenant(): void
    {
        $response = $this->apiRequest(
            'POST',
            '/api/onboarding/register',
            'localhost',
            [
                'hotel_name' => 'Hôtel Test Inscription',
                'slug'       => 'test-hotel-reg',
                'email'      => 'admin@test-hotel-reg.sn',
                'password'   => 'MotDePasse123!',
                'first_name' => 'Amadou',
                'last_name'  => 'Diallo',
            ],
        );

        $this->assertApiSuccess($response, 201);
        self::assertEquals('test-hotel-reg', $response['data']['tenant_slug']);
        self::assertStringContainsString('email', strtolower($response['data']['message']));

        // Vérifier que le tenant a bien été créé en BDD
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'test-hotel-reg']);
        self::assertNotNull($tenant);
        self::assertEquals('Hôtel Test Inscription', $tenant->getName());
    }

    public function testRegisterWithDuplicateSlug(): void
    {
        $payload = [
            'hotel_name' => 'Hôtel Doublon',
            'slug'       => 'test-hotel-dup',
            'email'      => 'admin@test-hotel-dup.sn',
            'password'   => 'MotDePasse123!',
            'first_name' => 'Fatou',
            'last_name'  => 'Ndiaye',
        ];

        // Premier enregistrement — succès
        $this->apiRequest('POST', '/api/onboarding/register', 'localhost', $payload);
        self::assertResponseStatusCodeSame(201);

        // Deuxième avec le même slug — doublon.
        // OnboardingService::register() lève AlreadyExistsException
        // (cf. src/Platform/Auth/Domain/Service/OnboardingService.php:36),
        // mappée par ApiExceptionListener en ALREADY_EXISTS / 409
        // (cf. .claude/docs/errors.md : doublon = ALREADY_EXISTS).
        $payload['email'] = 'admin2@test-hotel-dup.sn';
        $this->apiRequest('POST', '/api/onboarding/register', 'localhost', $payload);

        $this->assertApiError('ALREADY_EXISTS', 409);
    }

    public function testRegisterWithInvalidEmail(): void
    {
        $this->apiRequest(
            'POST',
            '/api/onboarding/register',
            'localhost',
            [
                'hotel_name' => 'Hôtel Email Invalide',
                'slug'       => 'test-hotel-email',
                'email'      => 'pas-un-email',
                'password'   => 'MotDePasse123!',
                'first_name' => 'Mamadou',
                'last_name'  => 'Sow',
            ],
        );

        $this->assertApiError('VALIDATION_ERROR', 422);
    }

    public function testRegisterWithWeakPassword(): void
    {
        $this->apiRequest(
            'POST',
            '/api/onboarding/register',
            'localhost',
            [
                'hotel_name' => 'Hôtel Mot de Passe Faible',
                'slug'       => 'test-hotel-weak',
                'email'      => 'admin@test-hotel-weak.sn',
                'password'   => 'court',
                'first_name' => 'Pierre',
                'last_name'  => 'Martin',
            ],
        );

        $this->assertApiError('VALIDATION_ERROR', 422);
    }
}
