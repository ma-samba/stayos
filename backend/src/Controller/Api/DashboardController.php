<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Hotel\Analytics\Domain\Service\KpiService;
use App\Hotel\Analytics\Domain\Service\ReportExporter;
use App\Hotel\Shared\Domain\Service\FeatureChecker;
use App\Shared\Exception\BusinessRuleException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/dashboard', name: 'api_dashboard_')]
class DashboardController extends AbstractApiController
{
    public function __construct(
        private readonly KpiService       $kpiService,
        private readonly FeatureChecker   $featureChecker,
        private readonly ReportExporter   $reportExporter,
    ) {}

    /**
     * KPIs du jour — réception, manager, comptable (pas housekeeper).
     */
    #[Route('/today', name: 'today', methods: ['GET'])]
    #[IsGranted('ROLE_ACCESS_BILLING')]
    public function today(): JsonResponse
    {
        $kpis = $this->kpiService->dashboardToday();

        return $this->jsonSuccess($kpis->toArray());
    }

    /**
     * Rapport sur une période — manager + comptable, feature 'advanced_reports'.
     */
    #[Route('/report', name: 'report', methods: ['GET'])]
    #[IsGranted('ROLE_ACCESS_REPORTS')]
    public function report(Request $request): JsonResponse
    {
        $this->featureChecker->assertEnabled('advanced_reports');

        [$from, $to] = $this->parseDateRange($request);

        $report = $this->kpiService->periodReport($from, $to);

        return $this->jsonSuccess($report->toArray());
    }

    /**
     * Export du rapport — manager + comptable, feature 'advanced_reports'.
     */
    #[Route('/report/export', name: 'export', methods: ['GET'])]
    #[IsGranted('ROLE_ACCESS_REPORTS')]
    public function export(Request $request): Response
    {
        $this->featureChecker->assertEnabled('advanced_reports');

        [$from, $to] = $this->parseDateRange($request);

        $report = $this->kpiService->periodReport($from, $to);

        $format   = $request->query->get('format', 'csv');
        $fromSlug = $from->format('Y-m-d');
        $toSlug   = $to->format('Y-m-d');

        if ($format === 'xlsx' && $this->reportExporter->supportsXlsx()) {
            $content  = $this->reportExporter->exportPeriodReportXlsx($report);
            $filename = "rapport-{$fromSlug}_{$toSlug}.xlsx";
            $mime     = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        } else {
            $content  = $this->reportExporter->exportPeriodReportCsv($report);
            $filename = "rapport-{$fromSlug}_{$toSlug}.csv";
            $mime     = 'text/csv; charset=UTF-8';
        }

        return new Response($content, 200, [
            'Content-Type'        => $mime,
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control'       => 'no-store',
        ]);
    }

    /**
     * Parse et valide les paramètres from/to communs à /report et /export.
     *
     * @return array{\DateTimeImmutable, \DateTimeImmutable}
     */
    private function parseDateRange(Request $request): array
    {
        $fromStr = $request->query->get('from');
        $toStr   = $request->query->get('to');

        if (!$fromStr || !$toStr) {
            throw new BusinessRuleException('Les paramètres "from" et "to" sont requis (format YYYY-MM-DD).');
        }

        $from = \DateTimeImmutable::createFromFormat('Y-m-d', $fromStr, new \DateTimeZone('Africa/Dakar'));
        $to   = \DateTimeImmutable::createFromFormat('Y-m-d', $toStr, new \DateTimeZone('Africa/Dakar'));

        if ($from === false || $to === false) {
            throw new BusinessRuleException('Format de date invalide. Utilisez YYYY-MM-DD.');
        }

        $from = $from->setTime(0, 0);
        $to   = $to->setTime(0, 0);

        if ($from > $to) {
            throw new BusinessRuleException('La date de début doit être antérieure ou égale à la date de fin.');
        }

        $days = (int) $from->diff($to)->days + 1;
        if ($days > 366) {
            throw new BusinessRuleException('La période ne peut pas dépasser 366 jours.');
        }

        return [$from, $to];
    }
}
