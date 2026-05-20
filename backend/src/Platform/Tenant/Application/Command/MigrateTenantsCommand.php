<?php

namespace App\Platform\Tenant\Application\Command;

use App\Platform\Tenant\Domain\Service\TenantMigrator;
use App\Platform\Tenant\Infrastructure\Doctrine\TenantRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'stayos:tenant:migrate',
    description: 'Applique les migrations tenant manquantes sur tous les schemas hotel_{uuid}',
)]
class MigrateTenantsCommand extends Command
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly TenantMigrator   $tenantMigrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'slug',
            null,
            InputOption::VALUE_REQUIRED,
            'Migrer un seul tenant (par slug)',
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Afficher les migrations en attente sans les appliquer',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $slug   = $input->getOption('slug');
        $dryRun = $input->getOption('dry-run');

        $io->title('Migrations tenant' . ($dryRun ? ' (dry-run)' : ''));

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

        $totalApplied = 0;

        foreach ($tenants as $tenant) {
            $schemaName = $tenant->getSchemaName();

            if ($dryRun) {
                $pending = $this->tenantMigrator->getPending($schemaName);
                if (count($pending) === 0) {
                    $io->text(\sprintf('  %s — à jour', $tenant->getSlug()));
                } else {
                    $versions = array_map(fn ($m) => $m->getVersion(), $pending);
                    $io->text(\sprintf(
                        '  %s — %d en attente : %s',
                        $tenant->getSlug(),
                        count($pending),
                        implode(', ', $versions),
                    ));
                }
                continue;
            }

            try {
                $applied = $this->tenantMigrator->migrate($schemaName);

                if (count($applied) === 0) {
                    $io->text(\sprintf('  %s — à jour', $tenant->getSlug()));
                } else {
                    $io->text(\sprintf(
                        '  <info>%s</info> — %d migration(s) appliquée(s) : %s',
                        $tenant->getSlug(),
                        count($applied),
                        implode(', ', $applied),
                    ));
                    $totalApplied += count($applied);
                }
            } catch (\Throwable $e) {
                $io->error(\sprintf(
                    "Erreur sur '%s' (%s) : %s",
                    $tenant->getSlug(),
                    $schemaName,
                    $e->getMessage(),
                ));
                return Command::FAILURE;
            }
        }

        if ($dryRun) {
            $io->info('Dry-run terminé — aucune migration appliquée.');
        } else {
            $io->success(\sprintf(
                '%d migration(s) appliquée(s) sur %d tenant(s).',
                $totalApplied,
                count($tenants),
            ));
        }

        return Command::SUCCESS;
    }
}
