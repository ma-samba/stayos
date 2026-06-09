<?php

declare(strict_types=1);

namespace App\Hotel\NightAudit\Domain\Service;

use App\Hotel\Billing\Infrastructure\Repository\InvoiceRepository;
use App\Hotel\Reservation\Infrastructure\Repository\ReservationRepository;
use App\Hotel\Room\Infrastructure\Repository\RoomRepository;

/**
 * Construit la liste d'avertissements à présenter avant une clôture.
 *
 * Non bloquante par défaut : le contrôleur acceptera le `force=true`
 * pour passer outre. Les warnings sont aussi stockés dans le snapshot
 * pour traçabilité.
 *
 * V1 : uniquement severity = 'warning'. Réserver 'critical' à un cas
 * vraiment grave (non utilisé pour l'instant).
 */
class NightAuditChecklistService
{
    private const DETAILS_MAX = 10;

    public function __construct(
        private readonly ReservationRepository $reservationRepository,
        private readonly InvoiceRepository     $invoiceRepository,
        private readonly RoomRepository        $roomRepository,
        private readonly BusinessDateService   $businessDateService,
    ) {}

    /**
     * @return array<int, array{
     *   code: string, severity: string, label: string,
     *   message: string, count: int, details?: array
     * }>
     */
    public function buildWarnings(): array
    {
        $bd       = $this->businessDateService->getCurrentBusinessDate();
        $warnings = [];

        // 1. Arrivées prévues non checked-in
        $pendingArrivals = $this->reservationRepository->findConfirmedArrivingOn($bd);
        if ($pendingArrivals !== []) {
            $warnings[] = [
                'code'     => 'arrivals.pending',
                'severity' => 'warning',
                'label'    => 'Arrivées non enregistrées',
                'message'  => sprintf(
                    "%d arrivée(s) prévue(s) aujourd'hui n'ont pas été enregistrée(s). " .
                    "Marquez no-show si le client n'est pas venu.",
                    count($pendingArrivals)
                ),
                'count'   => count($pendingArrivals),
                'details' => $this->truncate(array_map(static fn ($r) => [
                    'id'                 => (string) $r->getId(),
                    'confirmationNumber' => $r->getConfirmationNumber(),
                    'guest'              => $r->getGuest()->getFullName(),
                    'room'               => $r->getRoom()->getNumber(),
                ], $pendingArrivals)),
            ];
        }

        // 2. Départs prévus non checked-out
        $pendingDepartures = $this->reservationRepository->findCheckedInDepartingOn($bd);
        if ($pendingDepartures !== []) {
            $warnings[] = [
                'code'     => 'departures.pending',
                'severity' => 'warning',
                'label'    => 'Départs non enregistrés',
                'message'  => sprintf(
                    "%d départ(s) prévu(s) aujourd'hui n'ont pas été enregistré(s). " .
                    "Faites le check-out ou prolongez le séjour.",
                    count($pendingDepartures)
                ),
                'count'   => count($pendingDepartures),
                'details' => $this->truncate(array_map(static fn ($r) => [
                    'id'                 => (string) $r->getId(),
                    'confirmationNumber' => $r->getConfirmationNumber(),
                    'guest'              => $r->getGuest()->getFullName(),
                    'room'               => $r->getRoom()->getNumber(),
                ], $pendingDepartures)),
            ];
        }

        // 3. Factures DRAFT pour résas checked-out aujourd'hui
        $draftInvoices = $this->invoiceRepository->findDraftForReservationsCheckedOutOn($bd);
        if ($draftInvoices !== []) {
            $warnings[] = [
                'code'     => 'invoices.draft',
                'severity' => 'warning',
                'label'    => 'Factures en brouillon',
                'message'  => sprintf(
                    "%d facture(s) en brouillon attendent d'être émises pour des départs d'aujourd'hui.",
                    count($draftInvoices)
                ),
                'count'   => count($draftInvoices),
                'details' => $this->truncate(array_map(static fn ($inv) => [
                    'id'          => (string) $inv->getId(),
                    'number'      => $inv->getNumber(),
                    'totalXof'    => $inv->getTotalXof(),
                    'reservation' => $inv->getReservation()?->getConfirmationNumber(),
                ], $draftInvoices)),
            ];
        }

        // 4. Chambres OCCUPIED sans résa active
        $orphanRooms = $this->roomRepository->findOccupiedWithoutActiveReservation();
        if ($orphanRooms !== []) {
            $warnings[] = [
                'code'     => 'rooms.orphan_occupied',
                'severity' => 'warning',
                'label'    => 'Chambres occupées sans résa',
                'message'  => sprintf(
                    "%d chambre(s) marquée(s) occupée(s) mais sans réservation active. " .
                    "Incohérence à corriger (libérer ou créer la résa).",
                    count($orphanRooms)
                ),
                'count'   => count($orphanRooms),
                'details' => $this->truncate(array_map(static fn ($r) => [
                    'id'     => (string) $r->getId(),
                    'number' => $r->getNumber(),
                ], $orphanRooms)),
            ];
        }

        return $warnings;
    }

    /**
     * @param array $items
     * @return array
     */
    private function truncate(array $items): array
    {
        return array_values(array_slice($items, 0, self::DETAILS_MAX));
    }
}
