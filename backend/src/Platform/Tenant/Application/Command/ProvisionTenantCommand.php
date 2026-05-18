<?php

namespace App\Platform\Tenant\Application\Command;

use App\Platform\Tenant\Infrastructure\Doctrine\TenantRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'stayos:tenant:provision',
    description: 'Crée le schema PostgreSQL hotel_{uuid} pour un tenant',
)]
class ProvisionTenantCommand extends Command
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly Connection       $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'slug',
            InputArgument::REQUIRED,
            'Le slug du tenant (ex: savana)',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $slug = $input->getArgument('slug');

        $io->title(\sprintf("Provisionnement du tenant '%s'", $slug));

        $tenant = $this->tenantRepository->findActiveBySlug($slug);

        if (null === $tenant) {
            $io->error(\sprintf(
                "Tenant '%s' introuvable ou non actif (statuts acceptés : trial, active).",
                $slug,
            ));

            return Command::FAILURE;
        }

        $schemaName = $tenant->getSchemaName();

        $io->text(\sprintf('UUID du tenant  : %s', $tenant->getId()));
        $io->text(\sprintf('Nom du schema   : %s', $schemaName));

        // Validation préventive — même règle que TenantMiddleware
        if (!\preg_match('/^hotel_[0-9a-f_]+$/i', $schemaName)) {
            $io->error(\sprintf('Nom de schema invalide : %s', $schemaName));
            return Command::FAILURE;
        }

        try {
            $this->connection->executeStatement(
                \sprintf('CREATE SCHEMA IF NOT EXISTS %s', $schemaName)
            );

            $io->success(\sprintf(
                "Schema '%s' créé avec succès pour '%s' (%s).",
                $schemaName,
                $tenant->getName(),
                $slug,
            ));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error(\sprintf(
                'Erreur lors de la création du schema : %s',
                $e->getMessage(),
            ));

            return Command::FAILURE;
        }
    }
}
