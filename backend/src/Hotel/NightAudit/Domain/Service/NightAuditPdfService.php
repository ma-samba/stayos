<?php

declare(strict_types=1);

namespace App\Hotel\NightAudit\Domain\Service;

use App\Hotel\NightAudit\Domain\Entity\DailyClose;
use App\Hotel\Property\Domain\Entity\HotelProfile;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Dompdf\Options as DompdfOptions;
use Twig\Environment as Twig;

/**
 * Génère à la demande la liasse PDF d'une clôture journalière.
 *
 * Le PDF est régénéré à chaque appel à partir de `close.snapshot`
 * (immutable). Aucune donnée n'est recalculée depuis la BDD —
 * c'est l'invariant qui garantit la cohérence avec la clôture.
 */
class NightAuditPdfService
{
    public function __construct(
        private readonly Twig                   $twig,
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function generate(DailyClose $close): string
    {
        // Schema tenant courant (search_path déjà positionné par le middleware).
        $hotel = $this->entityManager->getRepository(HotelProfile::class)
            ->findOneBy([]);

        $html = $this->twig->render('night_audit/daily_close_pdf.html.twig', [
            'close' => $close,
            'hotel' => $hotel,
        ]);

        $options = new DompdfOptions();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'Helvetica');
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
