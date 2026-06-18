<?php

declare(strict_types=1);

namespace App\Hotel\Notification\Application\MessageHandler;

use App\Hotel\Notification\Domain\Service\OperationalAlertService;
use App\Message\PublishDailyAlertsMessage;
use App\Platform\Tenant\Infrastructure\Doctrine\TenantRepository;
use App\Shared\TenantContext;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class PublishDailyAlertsHandler
{
    public function __construct(
        private readonly TenantRepository         $tenantRepository,
        private readonly OperationalAlertService  $alertService,
        private readonly Connection               $connection,
        private readonly TenantContext            $tenantContext,
        #[Target('business')] private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(PublishDailyAlertsMessage $message): void
    {
        if ($message->tenantSlug !== null) {
            $tenant = $this->tenantRepository->findActiveBySlug($message->tenantSlug);
            if ($tenant === null) {
                $this->logger->warning('PublishDailyAlerts: tenant not found or inactive', [
                    'slug' => $message->tenantSlug,
                ]);
                return;
            }
            $tenants = [$tenant];
        } else {
            $tenants = $this->tenantRepository->findAllActive();
        }

        foreach ($tenants as $tenant) {
            $schemaName = $tenant->getSchemaName();

            if (!\preg_match('/^hotel_[0-9a-f_]+$/i', $schemaName)) {
                $this->logger->error('PublishDailyAlerts: invalid schema name', [
                    'schema' => $schemaName,
                    'slug'   => $tenant->getSlug(),
                ]);
                continue;
            }

            try {
                $this->connection->executeStatement(
                    \sprintf('SET search_path TO %s, public', $schemaName)
                );
                $this->tenantContext->set($tenant);

                $stats = $this->alertService->publishDailyAlerts();

                if ($message->includeLateCheckouts) {
                    $stats['lateCheckouts'] = $this->alertService->checkLateCheckouts();
                }

                $this->logger->info('Daily alerts published for tenant', [
                    'slug'  => $tenant->getSlug(),
                    'stats' => $stats,
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('PublishDailyAlerts: error on tenant', [
                    'slug'  => $tenant->getSlug(),
                    'error' => $e->getMessage(),
                    'class' => $e::class,
                ]);
            } finally {
                $this->connection->executeStatement('SET search_path TO public');
            }
        }
    }
}
