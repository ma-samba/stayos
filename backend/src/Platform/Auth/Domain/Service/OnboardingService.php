<?php

namespace App\Platform\Auth\Domain\Service;

use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Platform\Subscription\Domain\Entity\Plan;
use App\Platform\Subscription\Domain\Entity\Subscription;
use App\Platform\Subscription\Infrastructure\Doctrine\SubscriptionRepository;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Platform\Tenant\Domain\Service\TenantProvisioner;
use App\Platform\Tenant\Infrastructure\Doctrine\TenantRepository;
use App\Shared\Exception\AlreadyExistsException;
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

        // 5. Créer la Subscription TRIAL (14 jours)
        $tz = new \DateTimeZone('Africa/Dakar');

        $subscription = new Subscription();
        $subscription->setTenant($tenant);
        $subscription->setPlan($plan);
        $subscription->setStatus('trial');
        $subscription->setTrialEndsAt(new \DateTimeImmutable('+14 days', $tz));

        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

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
}
