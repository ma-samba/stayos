<?php

declare(strict_types=1);

namespace App\Controller\SuperAdmin;

use App\Platform\Admin\Domain\Service\PlatformMetricsService;
use App\Platform\Admin\Domain\Service\SuperAdminAuditService;
use App\Platform\Admin\Domain\Service\TenantSeedService;
use App\Platform\Admin\Infrastructure\Doctrine\SuperAdminAuditLogRepository;
use App\Platform\Auth\Domain\Service\OnboardingService;
use App\Platform\Subscription\Domain\Entity\Plan;
use App\Platform\Subscription\Domain\Service\AbonnementService;
use App\Platform\Subscription\Infrastructure\Doctrine\SaasInvoiceRepository;
use App\Platform\Subscription\Infrastructure\Doctrine\SubscriptionRepository;
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Platform\Tenant\Domain\Enum\TenantStatus;
use App\Platform\Tenant\Infrastructure\Doctrine\TenantRepository;
use App\Platform\User\Domain\Entity\User;
use App\Shared\Exception\AlreadyExistsException;
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
        private readonly TenantRepository              $tenantRepository,
        private readonly SubscriptionRepository        $subscriptionRepository,
        private readonly SaasInvoiceRepository         $invoiceRepository,
        private readonly AbonnementService             $abonnementService,
        private readonly PlatformMetricsService        $metricsService,
        private readonly OnboardingService             $onboardingService,
        private readonly SuperAdminAuditService        $auditService,
        private readonly SuperAdminAuditLogRepository  $auditLogRepository,
        private readonly EntityManagerInterface        $entityManager,
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

        $this->auditService->log(
            actor:   $this->getActor(),
            tenant:  $tenant,
            action:  'tenant.suspended',
            payload: ['reason' => $reason],
            request: $request,
        );

        return new JsonResponse([
            'data'    => $this->serializeTenantSummary($tenant),
            'status'  => 200,
            'message' => 'OK',
        ]);
    }

    #[Route('/tenants/{slug}/reactivate', name: 'tenants_reactivate', methods: ['POST'])]
    public function reactivateTenant(string $slug, Request $request): JsonResponse
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

        $this->auditService->log(
            actor:   $this->getActor(),
            tenant:  $tenant,
            action:  'tenant.reactivated',
            payload: null,
            request: $request,
        );

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
     * POST /superadmin/tenants — Création manuelle d'un tenant par
     * l'opérateur (Sprint 13bis-B). Pas d'OTP : l'identité a été
     * vérifiée hors plateforme.
     */
    #[Route('/tenants', name: 'tenants_create', methods: ['POST'])]
    public function createTenant(Request $request): JsonResponse
    {
        $body = json_decode($request->getContent() ?: '[]', true) ?? [];

        $hotelName = trim((string) ($body['hotel_name'] ?? ''));
        $slug      = strtolower(trim((string) ($body['slug'] ?? '')));
        $email     = strtolower(trim((string) ($body['manager_email'] ?? '')));
        $first     = trim((string) ($body['manager_first_name'] ?? ''));
        $last      = trim((string) ($body['manager_last_name']  ?? ''));
        $plan      = strtoupper(trim((string) ($body['plan'] ?? 'STARTER')));
        $initial   = (string) ($body['initial_status'] ?? 'trial');
        $template  = (string) ($body['seed_template'] ?? TenantSeedService::TEMPLATE_EMPTY);

        if ($hotelName === '') {
            return $this->jsonError('hotel_name requis.', 'VALIDATION_ERROR', 422);
        }
        if (!preg_match('/^[a-z0-9-]{2,40}$/', $slug)) {
            return $this->jsonError(
                "Slug invalide (lettres minuscules, chiffres et tirets uniquement, 2-40 caractères).",
                'VALIDATION_ERROR',
                422,
            );
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->jsonError('manager_email invalide.', 'VALIDATION_ERROR', 422);
        }
        if ($first === '' || $last === '') {
            return $this->jsonError('Prénom et nom du manager requis.', 'VALIDATION_ERROR', 422);
        }
        if (!in_array($plan, ['STARTER', 'PRO', 'ENTERPRISE'], true)) {
            return $this->jsonError('Plan invalide.', 'VALIDATION_ERROR', 422);
        }
        if (!in_array($initial, ['trial', 'active'], true)) {
            return $this->jsonError(
                "initial_status doit valoir 'trial' ou 'active'.",
                'VALIDATION_ERROR',
                422,
            );
        }
        if (!in_array($template, TenantSeedService::ALLOWED_TEMPLATES, true)) {
            return $this->jsonError(
                'seed_template invalide.',
                'VALIDATION_ERROR',
                422,
            );
        }

        try {
            $result = $this->onboardingService->provision([
                'hotel_name' => $hotelName,
                'slug'       => $slug,
                'email'      => $email,
                'first_name' => $first,
                'last_name'  => $last,
                'plan'       => $plan,
            ], $initial, $template);
        } catch (AlreadyExistsException $e) {
            return $this->jsonError($e->getMessage(), 'ALREADY_EXISTS', 409);
        } catch (BusinessRuleException $e) {
            return $this->jsonError($e->getMessage(), 'BUSINESS_RULE', 422);
        }

        $this->auditService->log(
            actor:   $this->getActor(),
            tenant:  $result['tenant'],
            action:  'tenant.created',
            payload: [
                'slug'           => $slug,
                'plan'           => $plan,
                'initial_status' => $initial,
                'seed_template'  => $template,
                'manager_email'  => $email,
            ],
            request: $request,
        );

        return new JsonResponse([
            'data' => [
                'tenant'           => $this->serializeTenantSummary($result['tenant']),
                'manager_password' => $result['password'],
                'seed_template'    => $template,
            ],
            'message' => 'Tenant créé. Communiquez le mot de passe au manager — il ne sera plus jamais affiché.',
            'status'  => 201,
        ], 201);
    }

    /**
     * PATCH /superadmin/tenants/{slug} — Édition des paramètres
     * mutables d'un tenant. Slug/subdomain/id NON modifiables.
     */
    #[Route('/tenants/{slug}', name: 'tenants_update', methods: ['PATCH'])]
    public function updateTenant(string $slug, Request $request): JsonResponse
    {
        $tenant = $this->tenantRepository->findBySlug($slug);
        if ($tenant === null) {
            return $this->jsonError('Tenant introuvable.', 'NOT_FOUND', 404);
        }

        $body = json_decode($request->getContent() ?: '[]', true) ?? [];

        $before = [
            'name'     => $tenant->getName(),
            'timezone' => $tenant->getTimezone(),
            'country'  => $tenant->getCountry(),
            'currency' => $tenant->getCurrency(),
        ];

        $touched = false;

        if (array_key_exists('name', $body)) {
            $value = trim((string) $body['name']);
            if ($value === '') {
                return $this->jsonError('Nom invalide.', 'VALIDATION_ERROR', 422);
            }
            $tenant->setName($value);
            $touched = true;
        }
        if (array_key_exists('timezone', $body)) {
            $value = trim((string) $body['timezone']);
            if ($value === '') {
                return $this->jsonError('Timezone invalide.', 'VALIDATION_ERROR', 422);
            }
            $tenant->setTimezone($value);
            $touched = true;
        }
        if (array_key_exists('country', $body)) {
            $value = strtoupper(trim((string) $body['country']));
            if (!preg_match('/^[A-Z]{2}$/', $value)) {
                return $this->jsonError('Code pays ISO-2 attendu.', 'VALIDATION_ERROR', 422);
            }
            $tenant->setCountry($value);
            $touched = true;
        }
        if (array_key_exists('currency', $body)) {
            $value = strtoupper(trim((string) $body['currency']));
            if (!preg_match('/^[A-Z]{3}$/', $value)) {
                return $this->jsonError('Code devise ISO-3 attendu.', 'VALIDATION_ERROR', 422);
            }
            $tenant->setCurrency($value);
            $touched = true;
        }

        if (!$touched) {
            return $this->jsonError(
                'Aucun champ à mettre à jour.',
                'VALIDATION_ERROR',
                422,
            );
        }

        $this->entityManager->flush();

        $after = [
            'name'     => $tenant->getName(),
            'timezone' => $tenant->getTimezone(),
            'country'  => $tenant->getCountry(),
            'currency' => $tenant->getCurrency(),
        ];

        $this->auditService->log(
            actor:   $this->getActor(),
            tenant:  $tenant,
            action:  'tenant.updated',
            payload: ['before' => $before, 'after' => $after],
            request: $request,
        );

        return new JsonResponse([
            'data'    => $this->serializeTenantSummary($tenant),
            'status'  => 200,
            'message' => 'OK',
        ]);
    }

    /**
     * POST /superadmin/tenants/{slug}/force-plan — Force un plan
     * et optionnellement une nouvelle date de fin de période (geste
     * commercial). Reason obligatoire (audit trail).
     */
    #[Route('/tenants/{slug}/force-plan', name: 'tenants_force_plan', methods: ['POST'])]
    public function forcePlan(string $slug, Request $request): JsonResponse
    {
        $tenant = $this->tenantRepository->findBySlug($slug);
        if ($tenant === null) {
            return $this->jsonError('Tenant introuvable.', 'NOT_FOUND', 404);
        }

        $body         = json_decode($request->getContent() ?: '[]', true) ?? [];
        $planName     = strtoupper(trim((string) ($body['plan'] ?? '')));
        $reason       = trim((string) ($body['reason'] ?? ''));
        $newEndRaw    = $body['new_period_end'] ?? null;

        if (!in_array($planName, ['STARTER', 'PRO', 'ENTERPRISE'], true)) {
            return $this->jsonError('Plan invalide.', 'VALIDATION_ERROR', 422);
        }
        if (strlen($reason) < 5) {
            return $this->jsonError(
                "La raison du changement est obligatoire (5 caractères minimum).",
                'VALIDATION_ERROR',
                422,
            );
        }

        $plan = $this->entityManager->getRepository(Plan::class)
            ->findOneBy(['name' => $planName, 'isActive' => true]);
        if ($plan === null) {
            return $this->jsonError("Plan '$planName' introuvable.", 'NOT_FOUND', 404);
        }

        $newPeriodEnd = null;
        if (is_string($newEndRaw) && $newEndRaw !== '') {
            try {
                $newPeriodEnd = new \DateTimeImmutable(
                    $newEndRaw,
                    new \DateTimeZone('Africa/Dakar'),
                );
            } catch (\Exception $e) {
                return $this->jsonError(
                    'new_period_end invalide (format ISO-8601 attendu).',
                    'VALIDATION_ERROR',
                    422,
                );
            }
        }

        $previousSub = $this->subscriptionRepository->findByTenant($tenant);
        $previousPlan = $previousSub?->getPlan()->getName();

        try {
            $sub = $this->abonnementService->forcePlan($tenant, $plan, $newPeriodEnd);
        } catch (BusinessRuleException $e) {
            return $this->jsonError($e->getMessage(), 'BUSINESS_RULE', 422);
        }

        $this->auditService->log(
            actor:   $this->getActor(),
            tenant:  $tenant,
            action:  'subscription.force_plan',
            payload: [
                'planFrom'      => $previousPlan,
                'planTo'        => $planName,
                'newPeriodEnd'  => $sub->getCurrentPeriodEnd()?->format(\DateTimeInterface::ATOM),
                'reason'        => $reason,
            ],
            request: $request,
        );

        return new JsonResponse([
            'data'    => $this->serializeTenantSummary($tenant),
            'status'  => 200,
            'message' => 'OK',
        ]);
    }

    /**
     * GET /superadmin/audit — Liste paginée des actions SuperAdmin
     * loggées. Lecture seule, pas d'audit récursif.
     */
    #[Route('/audit', name: 'audit', methods: ['GET'])]
    public function auditList(Request $request): JsonResponse
    {
        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = min(100, max(1, $request->query->getInt('per_page', 20)));

        $result = $this->auditLogRepository->paginate(
            actor:      $request->query->get('actor'),
            tenantSlug: $request->query->get('tenant_slug'),
            action:     $request->query->get('action'),
            page:       $page,
            perPage:    $perPage,
        );

        $data = array_map(fn ($log) => [
            'id'          => (string) $log->getId(),
            'actorEmail'  => $log->getActorEmail(),
            'tenantSlug'  => $log->getTenantSlug(),
            'action'      => $log->getAction(),
            'payload'     => $log->getPayload(),
            'ipAddress'   => $log->getIpAddress(),
            'userAgent'   => $log->getUserAgent(),
            'createdAt'   => $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $result['data']);

        return new JsonResponse([
            'data' => $data,
            'meta' => [
                'total'   => $result['total'],
                'page'    => $page,
                'perPage' => $perPage,
                'pages'   => $perPage > 0 ? (int) ceil($result['total'] / $perPage) : 0,
            ],
            'status'  => 200,
            'message' => 'OK',
        ]);
    }

    private function getActor(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException(
                'SuperAdminController exige un User platform authentifié.',
            );
        }
        return $user;
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
