<?php

declare(strict_types=1);

namespace App\Platform\Tenant\Application\Command;

use App\Hotel\Property\Domain\Entity\HotelProfile;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Platform\Tenant\Domain\Enum\TenantStatus;
use App\Platform\Tenant\Infrastructure\Doctrine\TenantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Outil de réparation : pour chaque tenant non-CHURNED, vérifie qu'un
 * HotelProfile existe dans son schema. Sinon, en crée un par défaut
 * avec name = tenant.name.
 *
 * Pourquoi cette commande existe : le bug "Profil hôtel introuvable"
 * a été découvert pendant le smoke test du Sprint 14-A.2 sur balladin.
 * `OnboardingService::register/provision` ne créaient pas de
 * HotelProfile (créé seulement par HotelDataFixtures dev). Le fix
 * structurel est dans OnboardingService — cette commande traite le
 * legacy + reste disponible en outil de réparation V2.
 *
 * Idempotente : peut être relancée sans risque. Affiche pour chaque
 * tenant : OK (déjà présent), CREATED (créé), ou SKIPPED (CHURNED).
 *
 * Pattern try/finally sur SET search_path identique à
 * OnboardingService::provision pour garantir la restauration même en
 * cas d'exception. `entityManager->clear()` après chaque tenant pour
 * éviter que des entités fuient au tenant suivant (cf.
 * CheckSubscriptionsHandler, Sprint 12).
 */
#[AsCommand(
    name: 'stayos:tenant:ensure-hotel-profile',
    description: 'Crée un HotelProfile par défaut pour chaque tenant qui n\'en a pas.',
)]
final class EnsureHotelProfileCommand extends Command
{
    public function __construct(
        private readonly TenantRepository       $tenantRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'N\'écrit rien, affiche seulement ce qui SERAIT fait.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $io->note('Mode dry-run : aucune écriture en BDD.');
        }

        $tenants = $this->tenantRepository->findAll();
        $conn    = $this->entityManager->getConnection();

        $countOk      = 0;
        $countCreated = 0;
        $countSkipped = 0;
        $rows         = [];

        foreach ($tenants as $tenant) {
            /** @var Tenant $tenant */
            if ($tenant->getStatus() === TenantStatus::CHURNED) {
                $countSkipped++;
                $rows[] = [$tenant->getSlug(), $tenant->getName(), 'SKIPPED (CHURNED)'];
                continue;
            }

            $schemaName = $tenant->getSchemaName();

            try {
                $conn->executeStatement(
                    sprintf('SET search_path TO %s, public', $schemaName)
                );

                $existing = $this->entityManager
                    ->getRepository(HotelProfile::class)
                    ->findOneBy([]);

                if ($existing !== null) {
                    $countOk++;
                    $rows[] = [$tenant->getSlug(), $tenant->getName(), 'OK (déjà présent)'];
                } else {
                    if (!$dryRun) {
                        $hotelProfile = new HotelProfile();
                        $hotelProfile->setName($tenant->getName());
                        // Tous les autres champs prennent les defaults entité
                        // (country='SN', checkInTime='14:00', checkOutTime='11:00',
                        //  totalRooms=0, autres nullables = null).
                        $this->entityManager->persist($hotelProfile);
                        $this->entityManager->flush();
                    }
                    $countCreated++;
                    $rows[] = [
                        $tenant->getSlug(),
                        $tenant->getName(),
                        $dryRun ? 'WOULD CREATE' : 'CREATED',
                    ];
                }
            } finally {
                $conn->executeStatement('SET search_path TO public');
                // Vider l'UoW Doctrine pour ne pas que des entités du tenant
                // courant fuient au tenant suivant.
                $this->entityManager->clear();
            }
        }

        $io->table(['Slug', 'Nom', 'Statut'], $rows);
        $io->success(sprintf(
            '%d tenant(s) OK · %d tenant(s) %s · %d tenant(s) skippé(s)',
            $countOk,
            $countCreated,
            $dryRun ? 'à créer (dry-run)' : 'créé(s)',
            $countSkipped,
        ));

        return Command::SUCCESS;
    }
}
