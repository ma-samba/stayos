<?php

declare(strict_types=1);

namespace App\Platform\Subscription\Application\Command;

use App\Platform\Subscription\Domain\Service\AbonnementService;
use App\Platform\Subscription\Infrastructure\Doctrine\SubscriptionRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Scanner quotidien des abonnements — idempotent, sûr à relancer.
 *
 * Usage :
 *   php bin/console stayos:subscriptions:check
 *   php bin/console stayos:subscriptions:check --dry-run
 *
 * En attendant le Symfony Scheduler (pas installé dans le projet —
 * voir backlog), cette commande est appelée par un cron externe
 * (Heroku Scheduler par ex.) une fois par jour à 03h00 heure de Dakar.
 */
#[AsCommand(
    name: 'stayos:subscriptions:check',
    description: 'Scan quotidien des abonnements : relances, factures, suspensions',
)]
class CheckSubscriptionsCommand extends Command
{
    public function __construct(
        private readonly AbonnementService      $abonnementService,
        private readonly SubscriptionRepository $subscriptionRepository,
        private readonly Connection             $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'N\'exécute pas les effets — affiche seulement ce qui SERAIT fait.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('Vérification des abonnements');

        // Garantir un search_path public — la commande tourne hors HTTP.
        $this->connection->executeStatement('SET search_path TO public');

        if ($dryRun) {
            $subs = $this->subscriptionRepository->createQueryBuilder('s')
                ->andWhere('s.status IN (:statuses)')
                ->setParameter('statuses', ['trial', 'active'])
                ->getQuery()
                ->getResult();

            $io->section(sprintf('%d subscription(s) à scanner', \count($subs)));

            $tz  = new \DateTimeZone('Africa/Dakar');
            $now = new \DateTimeImmutable('now', $tz);

            foreach ($subs as $sub) {
                $tenant = $sub->getTenant();
                if ($sub->getStatus() === 'trial') {
                    $endsAt = $sub->getTrialEndsAt();
                    $action = match (true) {
                        $endsAt === null => 'rien (pas de date)',
                        $endsAt < $now   => 'SUSPENDRE + email trial-expired',
                        $endsAt->diff($now)->days <= 1 => 'email trial-expiring-1d',
                        $endsAt->diff($now)->days <= 7 => 'email trial-expiring-7d',
                        default => 'rien',
                    };
                    $io->text(sprintf('  - %s [trial] → %s', $tenant->getSlug(), $action));
                } else {
                    $periodEnd = $sub->getCurrentPeriodEnd();
                    $action = match (true) {
                        $periodEnd === null  => 'rien (pas de date)',
                        $periodEnd >= $now   => 'rien (période en cours)',
                        default              => 'facturation + email payment-link',
                    };
                    $io->text(sprintf('  - %s [active] → %s', $tenant->getSlug(), $action));
                }
            }

            $io->success('Dry-run terminé — aucun effet appliqué.');
            return Command::SUCCESS;
        }

        $stats = $this->abonnementService->checkExpirations();

        $io->success(sprintf(
            '%d subscription(s) traitée(s) — suspendus: %d, factures: %d, emails: %d, erreurs: %d',
            $stats['processed'],
            $stats['suspended'],
            $stats['invoiced'],
            $stats['emailed'],
            $stats['errors'],
        ));

        return $stats['errors'] > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
