<?php

declare(strict_types=1);

namespace App\Platform\Subscription\Application\MessageHandler;

use App\Message\CheckSubscriptionsMessage;
use App\Platform\Subscription\Domain\Service\AbonnementService;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler du scheduler quotidien (cron externe ou Symfony Scheduler
 * quand il sera installé — voir backlog).
 *
 * AbonnementService itère sur toutes les subscriptions et fait du
 * try/catch par tenant en interne : une erreur sur un tenant
 * n'interrompt pas le batch. On garantit en plus que le search_path
 * reste sur `public` pendant tout le scan.
 */
#[AsMessageHandler]
class CheckSubscriptionsHandler
{
    public function __construct(
        private readonly AbonnementService $abonnementService,
        private readonly Connection        $connection,
        #[Target('business')] private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(CheckSubscriptionsMessage $message): void
    {
        try {
            // Le scheduler tourne hors HTTP : pas de tenant courant et
            // pas de search_path défini par le middleware. On force
            // public pour ne pas hériter d'un état précédent.
            $this->connection->executeStatement('SET search_path TO public');

            $stats = $this->abonnementService->checkExpirations();

            $this->logger->info('CheckSubscriptionsHandler: completed', $stats);
        } catch (\Throwable $e) {
            // L'AbonnementService isole déjà chaque tenant — une
            // exception ici est forcément une erreur globale (DB down,
            // mailer down). On la relogue mais ne la propage pas
            // pour ne pas déclencher le retry Messenger sur un batch
            // partiellement exécuté.
            $this->logger->error('CheckSubscriptionsHandler: fatal error', [
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
        }
    }
}
