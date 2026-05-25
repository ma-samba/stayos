<?php

declare(strict_types=1);

namespace App\Hotel\Analytics\Domain\Service;

use App\Hotel\Analytics\Domain\DTO\PeriodReport;

class ReportExporter
{
    /**
     * Indique si l'export XLSX est disponible (PhpSpreadsheet installé).
     */
    public function supportsXlsx(): bool
    {
        return class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class);
    }

    /**
     * Export CSV natif — toujours disponible, sans dépendance.
     * Séparateur ';' (compatibilité Excel FR), BOM UTF-8 pour les accents.
     */
    public function exportPeriodReportCsv(PeriodReport $report): string
    {
        $stream = fopen('php://memory', 'r+');

        // BOM UTF-8
        fwrite($stream, "\xEF\xBB\xBF");

        // ── Bloc synthèse ──
        fputcsv($stream, ['Rapport StayOS'], ';');
        fputcsv($stream, ['Période', $report->from, $report->to], ';');
        fputcsv($stream, [], ';');
        fputcsv($stream, ['Indicateur', 'Valeur'], ';');
        fputcsv($stream, ['Taux d\'occupation (%)', $report->occupancyRate], ';');
        fputcsv($stream, ['ADR HT (XOF)', $report->adrHt], ';');
        fputcsv($stream, ['RevPAR HT (XOF)', $report->revparHt], ';');
        fputcsv($stream, ['CA chambre HT (XOF)', $report->roomRevenueHt], ';');
        fputcsv($stream, ['CA chambre TTC (XOF)', $report->roomRevenueTtc], ';');
        fputcsv($stream, ['Nuits vendues', (string) $report->roomNightsSold], ';');
        fputcsv($stream, ['Nuits disponibles', (string) $report->roomNightsAvailable], ';');
        fputcsv($stream, [], ';');

        // ── Bloc détail journalier ──
        fputcsv($stream, ['Date', 'Occupation (%)', 'CA HT (XOF)', 'Nuits vendues'], ';');

        foreach ($report->dailySeries as $point) {
            $formattedDate = \DateTimeImmutable::createFromFormat('Y-m-d', $point->date)
                ?->format('d/m/Y') ?? $point->date;

            fputcsv($stream, [
                $formattedDate,
                $point->occupancyRate,
                $point->roomRevenueHt,
                (string) $point->soldNights,
            ], ';');
        }

        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content;
    }

    /**
     * Export XLSX — disponible uniquement si PhpSpreadsheet est installé.
     *
     * @throws \RuntimeException si PhpSpreadsheet n'est pas disponible
     */
    public function exportPeriodReportXlsx(PeriodReport $report): string
    {
        if (!$this->supportsXlsx()) {
            throw new \RuntimeException('PhpSpreadsheet n\'est pas installé. Utilisez le format CSV.');
        }

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

        // ── Feuille "Synthèse" ──
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Synthèse');

        $headers = ['Indicateur', 'Valeur'];
        $sheet->fromArray($headers, null, 'A1');
        $sheet->getStyle('A1:B1')->getFont()->setBold(true);

        $rows = [
            ['Période', $report->from . ' → ' . $report->to],
            ['Taux d\'occupation (%)', $report->occupancyRate],
            ['ADR HT (XOF)', $report->adrHt],
            ['RevPAR HT (XOF)', $report->revparHt],
            ['CA chambre HT (XOF)', $report->roomRevenueHt],
            ['CA chambre TTC (XOF)', $report->roomRevenueTtc],
            ['Nuits vendues', $report->roomNightsSold],
            ['Nuits disponibles', $report->roomNightsAvailable],
        ];
        $sheet->fromArray($rows, null, 'A2');

        // ── Feuille "Détail journalier" ──
        $detail = $spreadsheet->createSheet();
        $detail->setTitle('Détail journalier');

        $detailHeaders = ['Date', 'Occupation (%)', 'CA HT (XOF)', 'Nuits vendues'];
        $detail->fromArray($detailHeaders, null, 'A1');
        $detail->getStyle('A1:D1')->getFont()->setBold(true);

        $row = 2;
        foreach ($report->dailySeries as $point) {
            $formattedDate = \DateTimeImmutable::createFromFormat('Y-m-d', $point->date)
                ?->format('d/m/Y') ?? $point->date;

            $detail->fromArray([
                $formattedDate,
                $point->occupancyRate,
                $point->roomRevenueHt,
                $point->soldNights,
            ], null, "A{$row}");
            $row++;
        }

        $spreadsheet->setActiveSheetIndex(0);

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $stream = fopen('php://memory', 'r+');
        $writer->save($stream);
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content;
    }
}
