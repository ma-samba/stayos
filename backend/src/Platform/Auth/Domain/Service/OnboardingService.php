<?php

namespace App\Platform\Auth\Domain\Service;

use App\Hotel\Property\Domain\Entity\HotelProfile;
use App\Platform\Admin\Domain\Service\TenantSeedService;
use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Platform\Subscription\Domain\Entity\Plan;
use App\Platform\Subscription\Domain\Service\AbonnementService;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Platform\Tenant\Domain\Service\TenantProvisioner;
use App\Platform\Tenant\Infrastructure\Doctrine\TenantRepository;
use App\Shared\Exception\AlreadyExistsException;
use App\Shared\Exception\BusinessRuleException;
use App\Shared\Security\TempPasswordGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class OnboardingService
{
    public function __construct(
        private readonly TenantRepository           $tenantRepository,
        private readonly TenantProvisioner          $tenantProvisioner,
        private readonly OtpService                 $otpService,
        private readonly EntityManagerInterface     $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AbonnementService          $abonnementService,
        private readonly TempPasswordGenerator      $tempPasswordGenerator,
        private readonly TenantSeedService          $tenantSeedService,
        private readonly LoggerInterface            $logger,
    ) {}

    /**
     * Orchestre l'inscription d'un nouvel hôtel.
     *
     * Toutes les étapes (tenant, schema, plan, subscription, staff_user)
     * sont enveloppées dans une transaction Doctrine. En cas d'échec,
     * rollback + DROP SCHEMA défensif pour éviter tout résidu en BDD.
     * L'OTP est envoyé APRÈS le commit pour ne pas annuler l'inscription
     * si Mailjet est temporairement indisponible.
     *
     * @param array{hotel_name: string, slug: string, email: string, password: string, first_name: string, last_name: string} $data
     * @return array{tenant: Tenant, message: string}
     */
    public function register(array $data): array
    {
        // Vérification du slug HORS transaction (lecture seule)
        if (null !== $this->tenantRepository->findBySlug($data['slug'])) {
            throw new AlreadyExistsException(
                sprintf("Le slug '%s' est déjà utilisé.", $data['slug'])
            );
        }

        $this->entityManager->beginTransaction();
        $schemaName = null;

        try {
            // 1. Créer le Tenant
            $tenant = new Tenant();
            $tenant->setSlug($data['slug']);
            $tenant->setName($data['hotel_name']);
            $tenant->setSubdomain($data['slug']);

            $this->entityManager->persist($tenant);
            $this->entityManager->flush();

            $schemaName = $tenant->getSchemaName();

            // 2. Créer le schema PostgreSQL + table staff_users de base
            $this->tenantProvisioner->provision($tenant);

            // 3. Trouver ou créer le Plan STARTER
            $plan = $this->entityManager
                ->getRepository(Plan::class)
                ->findOneBy(['name' => 'STARTER', 'isActive' => true]);

            if (null === $plan) {
                $plan = new Plan();
                $plan->setName('STARTER');
                $plan->setPriceXof('15000.00');
                $plan->setMaxRooms(20);
                $plan->setMaxUsers(5);
                $plan->setFeatures([]);
                $this->entityManager->persist($plan);
                $this->entityManager->flush();
            }

            // 4. Subscription TRIAL (14 jours) via AbonnementService —
            //    point d'entrée unique pour la création de subscriptions.
            $this->abonnementService->createTrial($tenant, $plan);

            // 5. StaffUser MANAGER + HotelProfile par défaut dans le schema tenant
            $conn = $this->entityManager->getConnection();

            try {
                $conn->executeStatement(
                    sprintf('SET search_path TO %s, public', $schemaName)
                );

                $staffUser = new StaffUser();
                $staffUser->setEmail($data['email']);
                $staffUser->setFirstName($data['first_name']);
                $staffUser->setLastName($data['last_name']);
                $staffUser->setRole('MANAGER');
                $staffUser->setPassword(
                    $this->passwordHasher->hashPassword($staffUser, $data['password'])
                );

                $this->entityManager->persist($staffUser);

                // HotelProfile par défaut — le manager peut le modifier
                // ensuite via UI. Critique : sans HotelProfile,
                // ReservationEngine::resolveHotelId throw "Profil hôtel
                // introuvable" à la 1re création de résa. Bug découvert
                // pendant le smoke test du Sprint 14-A.2 sur balladin.
                $hotelProfile = new HotelProfile();
                $hotelProfile->setName($data['hotel_name']);
                $this->entityManager->persist($hotelProfile);

                $this->entityManager->flush();
            } finally {
                // Toujours remettre sur public — même en cas d'exception
                $conn->executeStatement('SET search_path TO public');
            }

            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->dropSchemaSafely($schemaName, $e);
            throw $e;
        }

        // 6. OTP — APRÈS le commit : un échec Mailjet ne doit pas
        //    annuler l'inscription. L'utilisateur pourra demander un
        //    renvoi via l'UI.
        $this->otpService->generate($data['email'], 'email_verification');

        return [
            'tenant'  => $tenant,
            'message' => 'Inscription réussie. Vérifiez votre email pour activer votre compte.',
        ];
    }

    /**
     * Variante de register() pour le SuperAdmin (Sprint 13bis-B).
     * Provisionne un tenant SANS OTP (identité déjà vérifiée hors
     * système) et permet de choisir entre essai 14j ou actif
     * immédiat (geste commercial / migration depuis autre PMS).
     *
     * Toutes les étapes sont transactionnelles avec DROP SCHEMA
     * défensif en cas d'échec.
     *
     * Le password manager est généré aléatoirement (16 chars) et
     * retourné en clair — UNE SEULE FOIS — pour transmission
     * directe par l'opérateur.
     *
     * @param array{
     *   hotel_name: string,
     *   slug: string,
     *   email: string,
     *   first_name: string,
     *   last_name: string,
     *   plan?: string,
     * } $data
     * @param 'trial'|'active' $initialStatus
     * @param string $seedTemplate Cf. TenantSeedService::ALLOWED_TEMPLATES.
     *                             'empty' par défaut — le manager configure
     *                             ensuite l'hôtel via /api/configuration.
     * @return array{tenant: Tenant, password: string}
     */
    public function provision(
        array $data,
        string $initialStatus = 'trial',
        string $seedTemplate  = TenantSeedService::TEMPLATE_EMPTY,
    ): array {
        // Validations HORS transaction
        if (!in_array($initialStatus, ['trial', 'active'], true)) {
            throw new BusinessRuleException(
                "initialStatus doit être 'trial' ou 'active'."
            );
        }

        if (!in_array($seedTemplate, TenantSeedService::ALLOWED_TEMPLATES, true)) {
            throw new BusinessRuleException(
                sprintf("Template '%s' inconnu.", $seedTemplate),
            );
        }

        if (null !== $this->tenantRepository->findBySlug($data['slug'])) {
            throw new AlreadyExistsException(
                sprintf("Le slug '%s' est déjà utilisé.", $data['slug'])
            );
        }

        $this->entityManager->beginTransaction();
        $schemaName = null;

        try {
            // 1. Tenant
            $tenant = new Tenant();
            $tenant->setSlug($data['slug']);
            $tenant->setName($data['hotel_name']);
            $tenant->setSubdomain($data['slug']);

            $this->entityManager->persist($tenant);
            $this->entityManager->flush();

            $schemaName = $tenant->getSchemaName();

            // 2. Schema + tables tenant
            $this->tenantProvisioner->provision($tenant);

            // 3. Plan : valeur du payload (default STARTER)
            $planName = $data['plan'] ?? 'STARTER';
            $plan = $this->entityManager->getRepository(Plan::class)
                ->findOneBy(['name' => $planName, 'isActive' => true]);
            if ($plan === null) {
                throw new BusinessRuleException(
                    sprintf("Plan '%s' introuvable ou inactif.", $planName)
                );
            }

            // 4. Subscription : trial ou active selon le mode
            if ($initialStatus === 'trial') {
                $this->abonnementService->createTrial($tenant, $plan);
            } else {
                $this->abonnementService->createActive($tenant, $plan);
            }

            // 5. StaffUser MANAGER + HotelProfile par défaut + seed dans le schema tenant
            $password = $this->tempPasswordGenerator->generate();
            $conn     = $this->entityManager->getConnection();

            try {
                $conn->executeStatement(
                    sprintf('SET search_path TO %s, public', $schemaName)
                );

                $staffUser = new StaffUser();
                $staffUser->setEmail($data['email']);
                $staffUser->setFirstName($data['first_name']);
                $staffUser->setLastName($data['last_name']);
                $staffUser->setRole('MANAGER');
                $staffUser->setPassword(
                    $this->passwordHasher->hashPassword($staffUser, $password)
                );

                $this->entityManager->persist($staffUser);

                // HotelProfile par défaut — AVANT le seed pour que les
                // services métier (qui peuvent être appelés par le seed)
                // aient toujours un profil disponible. Le manager peut
                // ensuite le modifier via UI. Critique : sans HotelProfile,
                // ReservationEngine::resolveHotelId throw "Profil hôtel
                // introuvable" à la 1re création de résa.
                $hotelProfile = new HotelProfile();
                $hotelProfile->setName($data['hotel_name']);
                $this->entityManager->persist($hotelProfile);

                $this->entityManager->flush();

                // 6. Template seed (Sprint 13ter)
                if ($seedTemplate !== TenantSeedService::TEMPLATE_EMPTY) {
                    $this->tenantSeedService->seed($tenant, $seedTemplate);
                }
            } finally {
                $conn->executeStatement('SET search_path TO public');
            }

            $this->entityManager->commit();
        } catch (\Throwable $e) {
            $this->entityManager->rollback();
            $this->dropSchemaSafely($schemaName, $e);
            throw $e;
        }

        return [
            'tenant'   => $tenant,
            'password' => $password,
        ];
    }

    /**
     * DROP SCHEMA défensif appelé en cas de rollback.
     *
     * PostgreSQL rollback annule en théorie le CREATE SCHEMA, mais
     * la pratique (24 schemas orphelins observés en Sprint 13ter) a
     * démontré au moins un cas où ce mécanisme ne suffit pas. Défense
     * en profondeur : on combine transaction Doctrine + DROP explicite.
     *
     * Validation regex avant exécution — défense en profondeur
     * anti-injection : si un jour le générateur de nom de schema
     * change, le helper reste sûr.
     *
     * En cas d'échec du DROP lui-même, log mais NE rethrow PAS pour
     * ne pas masquer l'exception originale qui a déclenché le rollback.
     * Le nettoyage curatif via stayos:tenant:cleanup-orphans reste
     * disponible comme filet de sécurité.
     */
    private function dropSchemaSafely(?string $schemaName, \Throwable $original): void
    {
        if ($schemaName === null) {
            return;
        }

        if (!preg_match('/^hotel_[0-9a-f_]+$/i', $schemaName)) {
            $this->logger->error('Onboarding rollback: schema name failed validation, refused to DROP', [
                'schemaName' => $schemaName,
                'class'      => $original::class,
            ]);
            return;
        }

        try {
            $this->entityManager->getConnection()->executeStatement(
                sprintf('DROP SCHEMA IF EXISTS %s CASCADE', $schemaName)
            );
            $this->logger->warning('Onboarding rollback: schema dropped', [
                'schemaName' => $schemaName,
                'reason'     => $original->getMessage(),
                'class'      => $original::class,
            ]);
        } catch (\Throwable $dropError) {
            $this->logger->error('Onboarding rollback: schema DROP failed (will require cleanup-orphans)', [
                'schemaName'    => $schemaName,
                'dropError'     => $dropError->getMessage(),
                'dropClass'     => $dropError::class,
                'originalError' => $original->getMessage(),
                'originalClass' => $original::class,
            ]);
        }
    }
}
