<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Hotel\NightAudit\Domain\Entity\DailyClose;
use App\Hotel\NightAudit\Domain\Service\BusinessDateService;
use App\Hotel\NightAudit\Domain\Service\DailyCloseService;
use App\Hotel\NightAudit\Domain\Service\NightAuditChecklistService;
use App\Hotel\NightAudit\Domain\Service\NightAuditPdfService;
use App\Hotel\NightAudit\Infrastructure\Repository\DailyCloseRepository;
use App\Shared\Exception\BusinessRuleException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Night audit (clôture comptable journalière).
 *
 * Le minimum commun est ROLE_ACCESS_BILLING (manager + receptionniste +
 * comptable peuvent consulter). La clôture exige RECEPTIONIST ou plus ;
 * la réouverture exige MANAGER strict.
 */
#[Route('/api/night-audit', name: 'api_night_audit_')]
#[IsGranted('ROLE_ACCESS_BILLING')]
class NightAuditController extends AbstractApiController
{
    public function __construct(
        private readonly DailyCloseService          $closeService,
        private readonly DailyCloseRepository       $repository,
        private readonly NightAuditChecklistService $checklistService,
        private readonly BusinessDateService        $businessDateService,
        private readonly NightAuditPdfService       $pdfService,
    ) {}

    /**
     * État courant : business date, dernière clôture, peut-on clôturer ?
     */
    #[Route('/current', name: 'current', methods: ['GET'])]
    public function current(): JsonResponse
    {
        return $this->jsonSuccess($this->closeService->getCurrent());
    }

    /**
     * Checklist pré-clôture : warnings non bloquants par défaut.
     * Le front les présente, l'utilisateur confirme avec force=true
     * via POST /close.
     */
    #[Route('/checklist', name: 'checklist', methods: ['GET'])]
    public function checklist(): JsonResponse
    {
        $warnings = $this->checklistService->buildWarnings();
        return $this->jsonSuccess([
            'businessDate'   => $this->businessDateService->getCurrentBusinessDate()->format('Y-m-d'),
            'warnings'       => $warnings,
            'canCloseClean'  => $warnings === [],
        ]);
    }

    /**
     * Liste paginée des clôtures (sans snapshot pour économie payload).
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $page    = max(1, $request->query->getInt('page', 1));
        $perPage = max(1, min(100, $request->query->getInt('perPage', 20)));

        $items = $this->repository->paginate($page, $perPage);
        $total = $this->repository->countAll();

        return $this->json([
            'data'   => $items,
            'meta'   => [
                'total'   => $total,
                'page'    => $page,
                'perPage' => $perPage,
                'pages'   => $perPage > 0 ? (int) ceil($total / $perPage) : 1,
            ],
            'status' => 200,
            'message'=> 'OK',
        ], 200, [], ['groups' => ['night_audit:read']]);
    }

    /**
     * Détail complet d'une clôture (avec snapshot JSON).
     */
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(DailyClose $close): JsonResponse
    {
        return $this->jsonSuccess($close, ['night_audit:detail']);
    }

    /**
     * Clôture la business date courante.
     * Body optionnel : { force: bool } — passe outre les warnings checklist.
     */
    #[Route('/close', name: 'close', methods: ['POST'])]
    public function close(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent() ?: '[]', true);
        if (!is_array($payload)) {
            throw new BusinessRuleException('Payload JSON invalide.');
        }
        $force = (bool) ($payload['force'] ?? false);

        $close = $this->closeService->close($this->getStaffUser(), $force);

        return $this->jsonSuccess($close, ['night_audit:detail'], 201);
    }

    /**
     * Liasse PDF d'une clôture, générée à partir du snapshot immuable.
     * Toujours disponible (même pour les clôtures rouvertes).
     */
    #[Route('/{id}/pdf', name: 'pdf', methods: ['GET'])]
    public function pdf(DailyClose $close): Response
    {
        $content  = $this->pdfService->generate($close);
        $filename = sprintf('cloture-%s.pdf', $close->getBusinessDate()->format('Y-m-d'));

        return new Response($content, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Cache-Control'       => 'no-store',
        ]);
    }

    /**
     * Réouverture — MANAGER strict (pas de propagation depuis RECEPTIONIST).
     */
    #[Route('/{id}/reopen', name: 'reopen', methods: ['POST'])]
    public function reopen(DailyClose $close, Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError(
                'Seul un manager peut rouvrir une clôture.',
                'ACCESS_DENIED',
                403,
            );
        }

        $payload = json_decode($request->getContent() ?: '[]', true);
        if (!is_array($payload)) {
            throw new BusinessRuleException('Payload JSON invalide.');
        }

        $reason = (string) ($payload['reason'] ?? '');

        $close = $this->closeService->reopen($close, $this->getStaffUser(), $reason);

        return $this->jsonSuccess($close, ['night_audit:detail']);
    }
}
