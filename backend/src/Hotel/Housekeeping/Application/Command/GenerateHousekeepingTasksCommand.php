<?php

declare(strict_types=1);

namespace App\Hotel\Housekeeping\Application\Command;

use App\Hotel\Housekeeping\Domain\Service\HousekeepingService;
use App\Platform\Tenant\Infrastructure\Doctrine\TenantRepository;
use App\Shared\TenantContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'stayos:housekeeping:generate',
    description: 'Génère les tâches de ménage quotidiennes (STAY_OVER) pour tous les tenants actifs',
)]
class GenerateHousekeepingTasksCommand extends Command
{
    public function __construct(
        private readonly TenantRepository    $tenantRepository,
        private readonly HousekeepingService $housekeepingService,
        private readonly Connection          $connection,
        private readonly TenantContext       $tenantContext,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'slug',
            null,
            InputOption::VALUE_REQUIRED,
            'Générer pour un seul tenant (par slug)',
        );
        $this->addOption(
            'date',
            null,
            InputOption::VALUE_REQUIRED,
            'Date cible (YYYY-MM-DD, défaut : aujourd\'hui)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $slug = $input->getOption('slug');

        $io->title('Génération des tâches de ménage');

        // Parse date
        $tz   = new \DateTimeZone('Africa/Dakar');
        $date = null;

        $dateParam = $input->getOption('date');
        if ($dateParam !== null) {
            try {
                $date = new \DateTimeImmutable($dateParam, $tz);
            } catch (\Exception) {
                $io->error(\sprintf("Format de date invalide : '%s'. Utiliser YYYY-MM-DD.", $dateParam));
                return Command::FAILURE;
            }
        }

        // Resolve tenants
        if ($slug !== null) {
            $tenant = $this->tenantRepository->findActiveBySlug($slug);
            if ($tenant === null) {
                $io->error(\sprintf("Tenant '%s' introuvable ou non actif.", $slug));
                return Command::FAILURE;
            }
            $tenants = [$tenant];
        } else {
            $tenants = $this->tenantRepository->findAllActive();
            if (count($tenants) === 0) {
                $io->warning('Aucun tenant actif trouvé.');
                return Command::SUCCESS;
            }
        }

        $totalCreated  = 0;
        $tenantsCount  = 0;
        $errors        = 0;

        foreach ($tenants as $tenant) {
            $schemaName = $tenant->getSchemaName();

            // Validate schema name (anti SQL injection)
            if (!\preg_match('/^hotel_[0-9a-f_]+$/i', $schemaName)) {
                $io->error(\sprintf("Schema invalide pour '%s' : %s", $tenant->getSlug(), $schemaName));
                $errors++;
                continue;
            }

            try {
                // Set tenant context (for MercurePublisher)
                $this->tenantContext->set($tenant);

                // Set search_path
                $this->connection->executeStatement(
                    \sprintf('SET search_path TO %s, public', $schemaName)
                );

                $created = $this->housekeepingService->generateDailyTasks($date);
                $tenantsCount++;
                $totalCreated += $created;

                if ($created > 0) {
                    $io->text(\sprintf(
                        '  <info>%s</info> — %d tâche(s) STAY_OVER créée(s)',
                        $tenant->getSlug(),
                        $created,
                    ));
                } else {
                    $io->text(\sprintf('  %s — aucune tâche à créer', $tenant->getSlug()));
                }
            } catch (\Throwable $e) {
                $io->error(\sprintf(
                    "Erreur sur '%s' : %s",
                    $tenant->getSlug(),
                    $e->getMessage(),
                ));
                $errors++;
            } finally {
                // Always restore search_path
                $this->connection->executeStatement('SET search_path TO public');
            }
        }

        if ($errors > 0) {
            $io->warning(\sprintf(
                '%d tâche(s) créée(s) sur %d tenant(s), %d erreur(s).',
                $totalCreated,
                $tenantsCount,
                $errors,
            ));
            return Command::FAILURE;
        }

        $io->success(\sprintf(
            '%d tâche(s) créée(s) sur %d tenant(s).',
            $totalCreated,
            $tenantsCount,
        ));

        return Command::SUCCESS;
    }
}
