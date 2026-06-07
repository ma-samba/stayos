<?php

declare(strict_types=1);

namespace App\Controller\SuperAdmin;

use App\Platform\Admin\Domain\Service\PlatformMetricsService;
use App\Platform\Subscription\Domain\Service\AbonnementService;
use App\Platform\Subscription\Infrastructure\Doctrine\SaasInvoiceRepository;
use App\Platform\Subscription\Infrastructure\Doctrine\SubscriptionRepository;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Platform\Tenant\Domain\Enum\TenantStatus;
use App\Platform\Tenant\Infrastructure\Doctrine\TenantRepository;
use App\Shared\Exception\BusinessRuleException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Back-office opérateur StayOS — gestion globale des tenants et
 * métriques plateforme. Hors du flux multi-tenant : le
 * TenantMiddleware exclut le préfixe /superadmin (cf. EXCLUDED_PREFIXES),
 * et les routes sont protégées par ROLE_SUPER_ADMIN via security.yaml.
 *
 * Choix d'architecture : on n'injecte PAS un tenant cible dans le
 * TenantContext pour les actions admin. À la place, on a ajouté des
 * variantes `suspendForTenant()` / `reactivateForTenant()` à
 * AbonnementService qui prennent un Tenant explicite et n'opèrent
 * que sur le schema public (tenants + subscriptions). Pas besoin de
 * SET search_path : le SuperAdmin ne touche jamais aux schemas
 * hotel_{uuid} depuis cette interface.
 */
#[Route('/superadmin', name: 'superadmin_')]
#[IsGranted('ROLE_SUPER_ADMIN')]
class SuperAdminController extends AbstractController
{
    public function __construct(
        private readonly TenantRepository        $tenantRepository,
        private readonly SubscriptionRepository  $subscriptionRepository,
        private readonly SaasInvoiceRepository   $invoiceRepository,
        private readonly AbonnementService       $abonnementService,
        private readonly PlatformMetricsService  $metricsService,
        private readonly EntityManagerInterface  $entityManager,
    ) {}

    #[Route('/tenants', name: 'tenants_list', methods: ['GET'])]
    public function listTenants(Request $request): JsonResponse
    {
        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = min(100, max(1, $request->query->getInt('perPage', 20)));
        $status  = $request->query->get('status');
        $plan    = $request->query->get('plan');
        $search  = trim((string) $request->query->get('search', ''));

        $qb = $this->entityManager->createQueryBuilder()
            ->select('t')
            ->from(Tenant::class, 't')
            ->orderBy('t.createdAt', 'DESC');

        if ($status !== null && $status !== '') {
            $qb->andWhere('t.status = :status')->setParameter('status', $status);
        }

        if ($plan !== null && $plan !== '') {
            // Filtre par plan : sous-requête pour ne garder que les tenants
            // dont la subscription la plus récente est sur ce plan.
            $qb->andWhere(
                't.id IN (
                    SELECT IDENTITY(s.tenant) FROM App\\Platform\\Subscription\\Domain\\Entity\\Subscription s
                    JOIN s.plan p
                    WHERE p.name = :plan
                )',
            )->setParameter('plan', $plan);
        }

        if ($search !== '') {
            $qb->andWhere('LOWER(t.slug) LIKE :q OR LOWER(t.name) LIKE :q')
                ->setParameter('q', '%' . strtolower($search) . '%');
        }

        $countQb = clone $qb;
        $countQb->resetDQLPart('orderBy');
        $total = (int) $countQb->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $qb->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        /** @var Tenant[] $tenants */
        $tenants = $qb->getQuery()->getResult();

        $data = array_map(fn (Tenant $t) => $this->serializeTenantSummary($t), $tenants);

        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'total'   => $total,
                'page'    => $page,
                'perPage' => $perPage,
                'pages'   => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
            ],
            'status'  => 200,
            'message' => 'OK',
        ]);
    }

    #[Route('/tenants/{slug}', name: 'tenants_detail', methods: ['GET'])]
    public function detailTenant(string $slug): JsonResponse
    {
        $tenant = $this->tenantRepository->findBySlug($slug);
        if ($tenant === null) {
            return $this->jsonError('Tenant introuvable.', 'NOT_FOUND', 404);
        }

        $subscription = $this->subscriptionRepository->findByTenant($tenant);
        $invoices     = array_slice($this->invoiceRepository->findByTenant($tenant), 0, 5);

        $summary = $this->serializeTenantSummary($tenant);

        $summary['subscription'] = $subscription === null ? null : [
            'id'                 => (string) $subscription->getId(),
            'status'             => $subscription->getStatus(),
            'billingCycle'       => $subscription->getBillingCycle(),
            'plan'               => $subscription->getPlan()->getName(),
            'trialEndsAt'        => $subscription->getTrialEndsAt()?->format(\DateTimeInterface::ATOM),
            'currentPeriodStart' => $subscription->getCurrentPeriodStart()?->format(\DateTimeInterface::ATOM),
            'currentPeriodEnd'   => $subscription->getCurrentPeriodEnd()?->format(\DateTimeInterface::ATOM),
            'cancelledAt'        => $subscription->getCancelledAt()?->format(\DateTimeInterface::ATOM),
        ];

        $summary['recentInvoices'] = array_map(fn ($i) => [
            'id'        => (string) $i->getId(),
            'number'    => $i->getNumber(),
            'planName'  => $i->getPlanName(),
            'amountXof' => $i->getAmountXof(),
            'status'    => $i->getStatus(),
            'dueAt'     => $i->getDueAt()?->format(\DateTimeInterface::ATOM),
            'paidAt'    => $i->getPaidAt()?->format(\DateTimeInterface::ATOM),
            'createdAt' => $i->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $invoices);

        return new JsonResponse([
            'data'    => $summary,
            'status'  => 200,
            'message' => 'OK',
        ]);
    }

    #[Route('/tenants/{slug}/suspend', name: 'tenants_suspend', methods: ['POST'])]
    public function suspendTenant(string $slug, Request $request): JsonResponse
    {
        $tenant = $this->tenantRepository->findBySlug($slug);
        if ($tenant === null) {
            return $this->jsonError('Tenant introuvable.', 'NOT_FOUND', 404);
        }

        $body   = json_decode($request->getContent() ?: '[]', true) ?? [];
        $reason = is_string($body['reason'] ?? null) ? trim((string) $body['reason']) : null;
        if ($reason === '') {
            $reason = null;
        }

        try {
            $this->abonnementService->suspendForTenant($tenant, $reason);
        } catch (BusinessRuleException $e) {
            return $this->jsonError($e->getMessage(), 'BUSINESS_RULE', 422);
        }

        return new JsonResponse([
            'data'    => $this->serializeTenantSummary($tenant),
            'status'  => 200,
            'message' => 'OK',
        ]);
    }

    #[Route('/tenants/{slug}/reactivate', name: 'tenants_reactivate', methods: ['POST'])]
    public function reactivateTenant(string $slug): JsonResponse
    {
        $tenant = $this->tenantRepository->findBySlug($slug);
        if ($tenant === null) {
            return $this->jsonError('Tenant introuvable.', 'NOT_FOUND', 404);
        }

        try {
            $this->abonnementService->reactivateForTenant($tenant);
        } catch (BusinessRuleException $e) {
            return $this->jsonError($e->getMessage(), 'BUSINESS_RULE', 422);
        }

        return new JsonResponse([
            'data'    => $this->serializeTenantSummary($tenant),
            'status'  => 200,
            'message' => 'OK',
        ]);
    }

    #[Route('/metrics', name: 'metrics', methods: ['GET'])]
    public function metrics(): JsonResponse
    {
        return new JsonResponse([
            'data'    => $this->metricsService->compute()->toArray(),
            'status'  => 200,
            'message' => 'OK',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTenantSummary(Tenant $tenant): array
    {
        $subscription = $this->subscriptionRepository->findByTenant($tenant);

        return [
            'id'        => (string) $tenant->getId(),
            'slug'      => $tenant->getSlug(),
            'name'      => $tenant->getName(),
            'status'    => $tenant->getStatus(),
            'subdomain' => $tenant->getSubdomain(),
            'country'   => $tenant->getCountry(),
            'currency'  => $tenant->getCurrency(),
            'createdAt' => $tenant->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'subscriptionStatus' => $subscription?->getStatus(),
            'planName'           => $subscription?->getPlan()->getName(),
        ];
    }

    private function jsonError(string $message, string $code, int $status): JsonResponse
    {
        return new JsonResponse([
            'error'  => $message,
            'code'   => $code,
            'status' => $status,
        ], $status);
    }
}
