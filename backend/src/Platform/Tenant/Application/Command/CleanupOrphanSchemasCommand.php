<?php

declare(strict_types=1);

namespace App\Platform\Tenant\Application\Command;

use App\Platform\Tenant\Domain\Entity\Tenant;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Sprint 13ter — Nettoyage des schemas `hotel_*` orphelins (= sans
 * tenant correspondant dans `public.tenants`).
 *
 * Origine de la pollution :
 *  - tests fonctionnels `@group integration` : un test crée un
 *    tenant via OnboardingService::provision(), qui CREATE SCHEMA.
 *    Le test rollback la transaction, mais `CREATE SCHEMA` est un
 *    DDL non-transactionnel en PostgreSQL → le schema persiste.
 *  - `make fixtures` avec purger qui DELETE/TRUNCATE `public.tenants`
 *    sans DROP SCHEMA explicite.
 *
 * Sécurités :
 *  - liste les tenants actifs depuis `public.tenants`
 *  - calcule leurs `schema_name` (Tenant::getSchemaName())
 *  - ne droppe JAMAIS un schema qui correspond à un tenant actif
 *  - `--dry-run` : affiche seulement, ne touche pas
 *  - confirmation interactive obligatoire en mode effectif (sauf
 *    `--no-interaction` côté CI)
 *  - `--dump-to` : pg_dump optionnel de chaque orphelin avant DROP
 */
#[AsCommand(
    name: 'stayos:tenant:cleanup-orphans',
    description: 'Drop les schemas hotel_* sans tenant correspondant dans public.tenants',
)]
class CleanupOrphanSchemasCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Connection             $connection,
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
                'Affiche la liste des orphelins sans rien dropper',
            )
            ->addOption(
                'dump-to',
                null,
                InputOption::VALUE_REQUIRED,
                'Répertoire où dump chaque schema orphelin avant DROP (ex: /tmp)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $dumpTo = $input->getOption('dump-to');

        $io->title('Nettoyage schemas hotel_* orphelins' . ($dryRun ? ' (dry-run)' : ''));

        // 1. Schemas actifs (calculés depuis public.tenants)
        /** @var Tenant[] $tenants */
        $tenants = $this->entityManager->getRepository(Tenant::class)->findAll();
        $activeSchemas = [];
        foreach ($tenants as $t) {
            $activeSchemas[$t->getSchemaName()] = $t->getSlug();
        }

        // 2. Tous les schemas hotel_* en BDD
        $allHotelSchemas = $this->connection->fetchFirstColumn(
            "SELECT schema_name
             FROM information_schema.schemata
             WHERE schema_name LIKE 'hotel\\_%' ESCAPE '\\'
             ORDER BY schema_name"
        );

        $orphans = array_values(array_filter(
            $allHotelSchemas,
            fn (string $sch) => !isset($activeSchemas[$sch]),
        ));

        $io->section('Inventaire');
        $io->writeln(sprintf('  Tenants actifs : <info>%d</info>', count($activeSchemas)));
        $io->writeln(sprintf('  Schemas hotel_* en BDD : <info>%d</info>', count($allHotelSchemas)));
        $io->writeln(sprintf('  Orphelins détectés : <comment>%d</comment>', count($orphans)));

        if (count($orphans) === 0) {
            $io->success('Aucun schema orphelin. Rien à faire.');
            return Command::SUCCESS;
        }

        $io->section('Schemas orphelins');
        foreach ($orphans as $sch) {
            $io->writeln('  • ' . $sch);
        }

        if ($dryRun) {
            $io->info('Dry-run terminé — aucun schema droppé.');
            return Command::SUCCESS;
        }

        // 3. Confirmation interactive
        if ($input->isInteractive()) {
            $question = new ConfirmationQuestion(
                sprintf(
                    "<question>DROP SCHEMA CASCADE pour %d schema(s) orphelin(s) ? (yes/no)</question> ",
                    count($orphans),
                ),
                false,
            );
            $helper = $this->getHelper('question');
            if (!$helper->ask($input, $output, $question)) {
                $io->warning('Opération annulée par l\'utilisateur.');
                return Command::SUCCESS;
            }
        }

        // 4. Dump optionnel + DROP
        $dumped = 0;
        $dropped = 0;

        foreach ($orphans as $sch) {
            // Garde-fou strict : on refuse même un cas qui ne devrait JAMAIS arriver
            if (isset($activeSchemas[$sch])) {
                $io->error(sprintf("Refus de dropper '%s' : appartient au tenant actif '%s'.", $sch, $activeSchemas[$sch]));
                continue;
            }

            if ($dumpTo !== null && $dumpTo !== '') {
                $dumpPath = rtrim((string) $dumpTo, '/') . '/orphan_' . $sch . '.sql';
                $cmd = sprintf(
                    'PGPASSWORD=%s pg_dump -h %s -U %s -d %s -n %s --no-owner --no-privileges -f %s 2>&1',
                    escapeshellarg((string) ($_ENV['POSTGRES_PASSWORD'] ?? 'stayos_password')),
                    escapeshellarg((string) ($_ENV['DATABASE_HOST'] ?? 'db')),
                    escapeshellarg((string) ($_ENV['POSTGRES_USER'] ?? 'stayos_user')),
                    escapeshellarg((string) ($_ENV['POSTGRES_DB'] ?? 'stayos_db')),
                    escapeshellarg($sch),
                    escapeshellarg($dumpPath),
                );
                exec($cmd, $cmdOutput, $cmdReturn);
                if ($cmdReturn !== 0) {
                    $io->warning(sprintf(
                        "pg_dump a échoué pour '%s' (code %d). DROP annulé pour ce schema.\n%s",
                        $sch,
                        $cmdReturn,
                        implode("\n", $cmdOutput),
                    ));
                    continue;
                }
                $dumped++;
                $io->writeln(sprintf("  📦 dumped → %s", $dumpPath));
            }

            try {
                $this->connection->executeStatement(sprintf('DROP SCHEMA %s CASCADE', $sch));
                $io->writeln(sprintf('  <info>✓</info> dropped : %s', $sch));
                $dropped++;
            } catch (\Throwable $e) {
                $io->error(sprintf("Erreur DROP SCHEMA %s : %s", $sch, $e->getMessage()));
            }
        }

        $io->success(sprintf(
            '%d schema(s) droppé(s)%s sur %d orphelin(s).',
            $dropped,
            $dumpTo !== null ? sprintf(' (%d dumpé(s))', $dumped) : '',
            count($orphans),
        ));

        return Command::SUCCESS;
    }
}
