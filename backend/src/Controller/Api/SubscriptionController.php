<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Platform\Subscription\Domain\Entity\Plan;
use App\Platform\Subscription\Domain\Service\AbonnementService;
use App\Platform\Subscription\Infrastructure\Doctrine\SaasInvoiceRepository;
use App\Platform\Subscription\Infrastructure\Doctrine\SubscriptionRepository;
use App\Shared\Exception\BusinessRuleException;
use App\Shared\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints d'abonnement à destination du MANAGER de chaque hôtel.
 *
 * Toutes les routes ciblent des tables du schema public (subscriptions,
 * plans, saas_invoices). Le TenantMiddleware positionne le search_path
 * sur `hotel_{uuid}, public` — les requêtes sur `public.*` restent
 * donc accessibles.
 *
 * Tous les endpoints sont restreints à ROLE_MANAGER : la facturation
 * SaaS et les changements de plan ne concernent ni la réception ni
 * le ménage.
 */
#[Route('/api/subscription', name: 'api_subscription_')]
class SubscriptionController extends AbstractApiController
{
    public function __construct(
        private readonly SubscriptionRepository  $subscriptionRepository,
        private readonly SaasInvoiceRepository   $invoiceRepository,
        private readonly AbonnementService       $abonnementService,
        private readonly EntityManagerInterface  $entityManager,
        private readonly TenantContext           $tenantContext,
        private readonly Connection              $connection,
        private readonly LoggerInterface         $logger,
    ) {}

    #[Route('', name: 'current', methods: ['GET'])]
    public function current(): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $tenant       = $this->tenantContext->get();
        $subscription = $this->subscriptionRepository->findByTenant($tenant);

        if ($subscription === null) {
            return $this->jsonError("Aucun abonnement trouvé.", 'NOT_FOUND', 404);
        }

        $plan  = $subscription->getPlan();
        $usage = $this->computeUsage();

        return $this->json([
            'data' => [
                'id'                 => (string) $subscription->getId(),
                'status'             => $subscription->getStatus(),
                'billingCycle'       => $subscription->getBillingCycle(),
                'trialEndsAt'        => $subscription->getTrialEndsAt()?->format(\DateTimeInterface::ATOM),
                'currentPeriodStart' => $subscription->getCurrentPeriodStart()?->format(\DateTimeInterface::ATOM),
                'currentPeriodEnd'   => $subscription->getCurrentPeriodEnd()?->format(\DateTimeInterface::ATOM),
                'cancelledAt'        => $subscription->getCancelledAt()?->format(\DateTimeInterface::ATOM),
                'plan'               => [
                    'id'        => $plan->getId(),
                    'name'      => $plan->getName(),
                    'priceXof'  => $plan->getPriceXof(),
                    'priceEur'  => $plan->getPriceEur(),
                    'maxRooms'  => $plan->getMaxRooms(),
                    'maxUsers'  => $plan->getMaxUsers(),
                    'features'  => $plan->getFeatures(),
                ],
                'usage' => $usage,
            ],
            'status'  => 200,
            'message' => 'OK',
        ]);
    }

    #[Route('/plans', name: 'plans', methods: ['GET'])]
    public function plans(): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $plans = $this->entityManager->getRepository(Plan::class)
            ->findBy(['isActive' => true], ['priceXof' => 'ASC']);

        return $this->jsonSuccess($plans, ['plan:read']);
    }

    #[Route('/upgrade', name: 'upgrade', methods: ['POST'])]
    public function upgrade(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $body   = json_decode($request->getContent() ?: '[]', true) ?? [];
        $planId = $body['planId'] ?? null;

        if (!is_int($planId) && !ctype_digit((string) $planId)) {
            return $this->jsonError('planId requis (entier).', 'VALIDATION_ERROR', 422);
        }

        $plan = $this->entityManager->getRepository(Plan::class)->find((int) $planId);

        if ($plan === null || !$plan->isActive()) {
            return $this->jsonError("Plan introuvable ou inactif.", 'NOT_FOUND', 404);
        }

        $tenant = $this->tenantContext->get();

        try {
            $subscription = $this->abonnementService->upgrade($tenant, $plan);
        } catch (BusinessRuleException $e) {
            return $this->jsonError($e->getMessage(), 'BUSINESS_RULE', 422);
        }

        return $this->json([
            'data' => [
                'status'             => $subscription->getStatus(),
                'plan'               => $subscription->getPlan()->getName(),
                'currentPeriodStart' => $subscription->getCurrentPeriodStart()?->format(\DateTimeInterface::ATOM),
                'currentPeriodEnd'   => $subscription->getCurrentPeriodEnd()?->format(\DateTimeInterface::ATOM),
            ],
            'status'  => 200,
            'message' => 'OK',
        ]);
    }

    #[Route('/cancel', name: 'cancel', methods: ['POST'])]
    public function cancel(): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $tenant = $this->tenantContext->get();

        try {
            $subscription = $this->abonnementService->cancel($tenant);
        } catch (BusinessRuleException $e) {
            return $this->jsonError($e->getMessage(), 'BUSINESS_RULE', 422);
        }

        return $this->json([
            'data' => [
                'status'      => $subscription->getStatus(),
                'cancelledAt' => $subscription->getCancelledAt()?->format(\DateTimeInterface::ATOM),
                'accessUntil' => (
                    $subscription->getCurrentPeriodEnd() ?? $subscription->getTrialEndsAt()
                )?->format(\DateTimeInterface::ATOM),
            ],
            'status'  => 200,
            'message' => 'OK',
        ]);
    }

    #[Route('/invoices', name: 'invoices', methods: ['GET'])]
    public function invoices(): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $tenant   = $this->tenantContext->get();
        $invoices = $this->invoiceRepository->findByTenant($tenant);

        return $this->jsonSuccess($invoices, ['saas_invoice:read']);
    }

    /**
     * Compte les chambres et utilisateurs en SQL brut depuis le schema
     * tenant, sans hardcoder la dépendance aux entités Room/StaffUser.
     *
     * @return array{rooms:int, users:int}
     */
    private function computeUsage(): array
    {
        $usage = ['rooms' => 0, 'users' => 0];

        try {
            $rooms = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM rooms WHERE is_active = TRUE'
            );
            $usage['rooms'] = $rooms;
        } catch (\Throwable $e) {
            $this->logger->warning('Subscription usage: rooms count failed, defaulting to 0', [
                'error' => $e->getMessage(),
                'class' => $e::class,
                'tenant' => $this->tenantContext->has() ? $this->tenantContext->get()->getSlug() : null,
            ]);
        }

        try {
            $users = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM staff_users WHERE active = TRUE'
            );
            $usage['users'] = $users;
        } catch (\Throwable $e) {
            $this->logger->warning('Subscription usage: staff_users count failed, defaulting to 0', [
                'error' => $e->getMessage(),
                'class' => $e::class,
                'tenant' => $this->tenantContext->has() ? $this->tenantContext->get()->getSlug() : null,
            ]);
        }

        return $usage;
    }
}
