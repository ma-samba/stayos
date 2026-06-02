<?php

declare(strict_types=1);

namespace App\Hotel\Notification\Application\Command;

use App\Hotel\Notification\Domain\Service\OperationalAlertService;
use App\Platform\Tenant\Infrastructure\Doctrine\TenantRepository;
use App\Shared\TenantContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Publie les alertes opérationnelles quotidiennes pour tous les tenants actifs.
 *
 * Usage :
 *   php bin/console stayos:alerts:daily                          # arrivées + tâches non assignées
 *   php bin/console stayos:alerts:daily --late-checkouts         # + départs en retard
 *   php bin/console stayos:alerts:daily --slug=savana            # un seul tenant
 *
 * Note : le déclenchement automatique (cron) sera câblé avec le Scheduler Messenger (Sprint 12).
 */
#[AsCommand(
    name: 'stayos:alerts:daily',
    description: 'Publie les alertes opérationnelles (arrivées, tâches non assignées, départs en retard) via Mercure',
)]
class PublishDailyAlertsCommand extends Command
{
    public function __construct(
        private readonly TenantRepository        $tenantRepository,
        private readonly OperationalAlertService $alertService,
        private readonly Connection              $connection,
        private readonly TenantContext           $tenantContext,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'slug',
            null,
            InputOption::VALUE_REQUIRED,
            'Publier pour un seul tenant (par slug)',
        );
        $this->addOption(
            'late-checkouts',
            null,
            InputOption::VALUE_NONE,
            'Inclure les alertes de départs en retard',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io                 = new SymfonyStyle($input, $output);
        $slug               = $input->getOption('slug');
        $includeLateCheckouts = $input->getOption('late-checkouts');

        $io->title('Alertes opérationnelles quotidiennes');

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
            if (\count($tenants) === 0) {
                $io->warning('Aucun tenant actif trouvé.');
                return Command::SUCCESS;
            }
        }

        $totalArrivals    = 0;
        $totalUnassigned  = 0;
        $totalLate        = 0;
        $tenantsProcessed = 0;
        $errors           = 0;

        foreach ($tenants as $tenant) {
            $schemaName = $tenant->getSchemaName();

            if (!\preg_match('/^hotel_[0-9a-f_]+$/i', $schemaName)) {
                $io->error(\sprintf("Schema invalide pour '%s' : %s", $tenant->getSlug(), $schemaName));
                $errors++;
                continue;
            }

            try {
                $this->tenantContext->set($tenant);
                $this->connection->executeStatement(
                    \sprintf('SET search_path TO %s, public', $schemaName)
                );

                $stats = $this->alertService->publishDailyAlerts();
                $totalArrivals   += $stats['arrivals'];
                $totalUnassigned += $stats['unassignedTasks'];

                $parts = [];
                if ($stats['arrivals'] > 0) {
                    $parts[] = \sprintf('%d arrivée(s)', $stats['arrivals']);
                }
                if ($stats['unassignedTasks'] > 0) {
                    $parts[] = \sprintf('%d tâche(s) non assignée(s)', $stats['unassignedTasks']);
                }

                if ($includeLateCheckouts) {
                    $late = $this->alertService->checkLateCheckouts();
                    $totalLate += $late;
                    if ($late > 0) {
                        $parts[] = \sprintf('%d départ(s) en retard', $late);
                    }
                }

                if (\count($parts) > 0) {
                    $io->text(\sprintf('  <info>%s</info> — %s', $tenant->getSlug(), implode(', ', $parts)));
                } else {
                    $io->text(\sprintf('  %s — aucune alerte', $tenant->getSlug()));
                }

                $tenantsProcessed++;
            } catch (\Throwable $e) {
                $io->error(\sprintf("Erreur sur '%s' : %s", $tenant->getSlug(), $e->getMessage()));
                $errors++;
            } finally {
                $this->connection->executeStatement('SET search_path TO public');
            }
        }

        if ($errors > 0) {
            $io->warning(\sprintf(
                '%d tenant(s) traité(s), %d erreur(s). Arrivées: %d, Tâches non assignées: %d, Départs en retard: %d.',
                $tenantsProcessed, $errors, $totalArrivals, $totalUnassigned, $totalLate,
            ));
            return Command::FAILURE;
        }

        $io->success(\sprintf(
            '%d tenant(s) traité(s). Arrivées: %d, Tâches non assignées: %d%s.',
            $tenantsProcessed,
            $totalArrivals,
            $totalUnassigned,
            $includeLateCheckouts ? \sprintf(', Départs en retard: %d', $totalLate) : '',
        ));

        return Command::SUCCESS;
    }
}
