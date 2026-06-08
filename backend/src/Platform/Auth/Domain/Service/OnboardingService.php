<?php

namespace App\Platform\Auth\Domain\Service;

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
    ) {}

    /**
     * Orchestre l'inscription d'un nouvel hôtel.
     *
     * @param array{hotel_name: string, slug: string, email: string, password: string, first_name: string, last_name: string} $data
     * @return array{tenant: Tenant, message: string}
     */
    public function register(array $data): array
    {
        // 1. Vérifier que le slug n'existe pas déjà
        if (null !== $this->tenantRepository->findBySlug($data['slug'])) {
            throw new AlreadyExistsException(
                sprintf("Le slug '%s' est déjà utilisé.", $data['slug'])
            );
        }

        // 2. Créer le Tenant
        $tenant = new Tenant();
        $tenant->setSlug($data['slug']);
        $tenant->setName($data['hotel_name']);
        $tenant->setSubdomain($data['slug']);

        $this->entityManager->persist($tenant);
        $this->entityManager->flush();

        // 3. Créer le schema PostgreSQL + table staff_users de base
        $this->tenantProvisioner->provision($tenant);

        // 4. Trouver ou créer le Plan STARTER
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

        // 5. Créer la Subscription TRIAL (14 jours) via AbonnementService —
        //    point d'entrée unique pour la création de subscriptions, garantit
        //    que le statut/dates restent cohérents avec le scheduler.
        $this->abonnementService->createTrial($tenant, $plan);

        // 6-8. Créer le StaffUser dans le schema tenant
        // Le finally garantit que search_path est toujours réinitialisé
        $conn       = $this->entityManager->getConnection();
        $schemaName = $tenant->getSchemaName();

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
            $this->entityManager->flush();

        } finally {
            // Toujours remettre sur public — même en cas d'exception
            $conn->executeStatement('SET search_path TO public');
        }

        // 9. Envoyer l'OTP de vérification email
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

        // 1. Tenant
        $tenant = new Tenant();
        $tenant->setSlug($data['slug']);
        $tenant->setName($data['hotel_name']);
        $tenant->setSubdomain($data['slug']);

        $this->entityManager->persist($tenant);
        $this->entityManager->flush();

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

        // 5. StaffUser MANAGER avec password généré
        $password = $this->tempPasswordGenerator->generate();

        $conn       = $this->entityManager->getConnection();
        $schemaName = $tenant->getSchemaName();

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
            $this->entityManager->flush();

            // 6. Template seed (Sprint 13ter) — toujours dans le search_path
            //    tenant. Crée Floor + RoomType + Room initiaux selon le
            //    template choisi.
            if ($seedTemplate !== TenantSeedService::TEMPLATE_EMPTY) {
                $this->tenantSeedService->seed($tenant, $seedTemplate);
            }
        } finally {
            $conn->executeStatement('SET search_path TO public');
        }

        // ⚠️ Pas d'OTP : le SuperAdmin a déjà vérifié l'identité.

        return [
            'tenant'   => $tenant,
            'password' => $password,
        ];
    }
}
