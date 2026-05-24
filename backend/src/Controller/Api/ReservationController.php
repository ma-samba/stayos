<?php

namespace App\Controller\Api;

use App\Hotel\Reservation\Application\DTO\CheckInDTO;
use App\Hotel\Reservation\Application\DTO\CreateReservationDTO;
use App\Hotel\Reservation\Application\DTO\UpdateReservationDTO;
use App\Hotel\Reservation\Domain\Service\ReservationEngine;
use App\Hotel\Reservation\Infrastructure\Repository\ReservationRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/reservations', name: 'api_reservations_')]
#[IsGranted('ROLE_ACCESS_RESERVATIONS')]
class ReservationController extends AbstractApiController
{
    public function __construct(
        private readonly ReservationRepository $reservationRepository,
        private readonly ReservationEngine     $reservationEngine,
        private readonly ValidatorInterface    $validator,
    ) {}

    /**
     * GET /api/reservations — Liste avec filtres optionnels.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $reservations = $this->reservationRepository->findWithFilters(
            status: $request->query->get('status'),
            from:   $request->query->get('from'),
            to:     $request->query->get('to'),
        );

        return $this->jsonSuccess($reservations, ['reservation:read']);
    }

    /**
     * GET /api/reservations/gantt?from=YYYY-MM-DD&to=YYYY-MM-DD — Planning Gantt.
     */
    #[Route('/gantt', name: 'gantt', methods: ['GET'])]
    public function gantt(Request $request): JsonResponse
    {
        $from = $request->query->get('from');
        $to   = $request->query->get('to');

        if (!$from || !$to) {
            return $this->jsonError('Les paramètres from et to sont obligatoires', 'VALIDATION_ERROR', 422);
        }

        try {
            $tz = new \DateTimeZone('Africa/Dakar');
            $fromDate = new \DateTimeImmutable($from, $tz);
            $toDate   = new \DateTimeImmutable($to, $tz);
        } catch (\Exception) {
            return $this->jsonError('Format de date invalide (YYYY-MM-DD attendu)', 'VALIDATION_ERROR', 422);
        }

        $reservations = $this->reservationRepository->findForGantt($fromDate, $toDate);

        return $this->jsonSuccess($reservations, ['reservation:gantt']);
    }

    /**
     * GET /api/reservations/{id} — Détail d'une réservation.
     */
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $reservation = $this->reservationRepository->findByIdWithRelations($id);

        if ($reservation === null) {
            return $this->jsonError('Réservation introuvable', 'NOT_FOUND', 404);
        }

        return $this->jsonSuccess($reservation, ['reservation:read', 'reservation:detail']);
    }

    /**
     * POST /api/reservations — Créer une réservation.
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $dto = new CreateReservationDTO();
        $dto->roomId          = $data['roomId'] ?? '';
        $dto->guestId         = $data['guestId'] ?? '';
        $dto->checkIn         = $data['checkIn'] ?? '';
        $dto->checkOut        = $data['checkOut'] ?? '';
        $dto->adults          = $data['adults'] ?? 1;
        $dto->children        = $data['children'] ?? 0;
        $dto->notes           = $data['notes'] ?? null;
        $dto->specialRequests = $data['specialRequests'] ?? null;
        $dto->source          = $data['source'] ?? 'direct';
        $dto->depositXof      = $data['depositXof'] ?? null;

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            $messages = [];
            foreach ($errors as $error) {
                $messages[$error->getPropertyPath()] = $error->getMessage();
            }
            return $this->json([
                'error'  => 'Données invalides',
                'code'   => 'VALIDATION_ERROR',
                'status' => 422,
                'errors' => $messages,
            ], 422);
        }

        $reservation = $this->reservationEngine->create($dto, $this->getStaffUser());

        return $this->jsonSuccess($reservation, ['reservation:read'], 201);
    }

    /**
     * PUT /api/reservations/{id} — Modifier une réservation.
     */
    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $reservation = $this->reservationRepository->findByIdWithRelations($id);

        if ($reservation === null) {
            return $this->jsonError('Réservation introuvable', 'NOT_FOUND', 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $dto = new UpdateReservationDTO();
        $dto->roomId          = $data['roomId'] ?? null;
        $dto->guestId         = $data['guestId'] ?? null;
        $dto->checkIn         = $data['checkIn'] ?? null;
        $dto->checkOut        = $data['checkOut'] ?? null;
        $dto->adults          = $data['adults'] ?? null;
        $dto->children        = $data['children'] ?? null;
        $dto->notes           = $data['notes'] ?? null;
        $dto->specialRequests = $data['specialRequests'] ?? null;
        $dto->source          = $data['source'] ?? null;
        $dto->depositXof      = $data['depositXof'] ?? null;

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            $messages = [];
            foreach ($errors as $error) {
                $messages[$error->getPropertyPath()] = $error->getMessage();
            }
            return $this->json([
                'error'  => 'Données invalides',
                'code'   => 'VALIDATION_ERROR',
                'status' => 422,
                'errors' => $messages,
            ], 422);
        }

        $reservation = $this->reservationEngine->update($reservation, $dto, $this->getStaffUser());

        return $this->jsonSuccess($reservation, ['reservation:read']);
    }

    /**
     * POST /api/reservations/{id}/checkin — Enregistrer l'arrivée.
     */
    #[Route('/{id}/checkin', name: 'checkin', methods: ['POST'])]
    public function checkIn(string $id, Request $request): JsonResponse
    {
        $reservation = $this->reservationRepository->findByIdWithRelations($id);

        if ($reservation === null) {
            return $this->jsonError('Réservation introuvable', 'NOT_FOUND', 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $dto = new CheckInDTO();
        $dto->notes = $data['notes'] ?? null;

        $reservation = $this->reservationEngine->checkIn($reservation, $dto->notes, $this->getStaffUser());

        return $this->jsonSuccess($reservation, ['reservation:read']);
    }

    /**
     * POST /api/reservations/{id}/checkout — Enregistrer le départ.
     */
    #[Route('/{id}/checkout', name: 'checkout', methods: ['POST'])]
    public function checkOut(string $id): JsonResponse
    {
        $reservation = $this->reservationRepository->findByIdWithRelations($id);

        if ($reservation === null) {
            return $this->jsonError('Réservation introuvable', 'NOT_FOUND', 404);
        }

        $reservation = $this->reservationEngine->checkOut($reservation, $this->getStaffUser());

        return $this->jsonSuccess($reservation, ['reservation:read']);
    }

    /**
     * POST /api/reservations/{id}/cancel — Annuler une réservation.
     */
    #[Route('/{id}/cancel', name: 'cancel', methods: ['POST'])]
    public function cancel(string $id, Request $request): JsonResponse
    {
        $reservation = $this->reservationRepository->findByIdWithRelations($id);

        if ($reservation === null) {
            return $this->jsonError('Réservation introuvable', 'NOT_FOUND', 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $reason = $data['reason'] ?? 'Annulation sans motif';

        $reservation = $this->reservationEngine->cancel($reservation, $reason, $this->getStaffUser());

        return $this->jsonSuccess($reservation, ['reservation:read']);
    }
}
