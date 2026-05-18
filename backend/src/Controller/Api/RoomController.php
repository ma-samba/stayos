<?php

namespace App\Controller\Api;

use App\Hotel\Room\Application\DTO\UpdateRoomStatusDTO;
use App\Hotel\Room\Domain\Entity\Room;
use App\Hotel\Room\Domain\Enum\RoomStatus;
use App\Hotel\Room\Domain\Service\RoomService;
use App\Hotel\Room\Infrastructure\Repository\RoomRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/rooms', name: 'api_rooms_')]
class RoomController extends AbstractApiController
{
    public function __construct(
        private readonly RoomRepository    $roomRepository,
        private readonly RoomService       $roomService,
        private readonly ValidatorInterface $validator,
    ) {}

    /**
     * GET /api/rooms — Liste toutes les chambres actives.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $rooms = $this->roomService->findAll();

        return $this->jsonSuccess($rooms, ['room:read']);
    }

    /**
     * GET /api/rooms/available?from=YYYY-MM-DD&to=YYYY-MM-DD&adults=N
     */
    #[Route('/available', name: 'available', methods: ['GET'])]
    public function available(Request $request): JsonResponse
    {
        $from   = $request->query->get('from');
        $to     = $request->query->get('to');
        $adults = $request->query->getInt('adults', 1);

        if (!$from || !$to) {
            return $this->jsonError('Les paramètres from et to sont obligatoires', 'VALIDATION_ERROR', 422);
        }

        try {
            $checkIn  = new \DateTimeImmutable($from, new \DateTimeZone('Africa/Dakar'));
            $checkOut = new \DateTimeImmutable($to, new \DateTimeZone('Africa/Dakar'));
        } catch (\Exception) {
            return $this->jsonError('Format de date invalide (YYYY-MM-DD attendu)', 'VALIDATION_ERROR', 422);
        }

        if ($checkOut <= $checkIn) {
            return $this->jsonError('La date de départ doit être postérieure à la date d\'arrivée', 'VALIDATION_ERROR', 422);
        }

        if ($adults < 1) {
            return $this->jsonError('Le nombre d\'adultes doit être au moins 1', 'VALIDATION_ERROR', 422);
        }

        $rooms = $this->roomService->findAvailable($checkIn, $checkOut, $adults);

        return $this->jsonSuccess($rooms, ['room:read']);
    }

    /**
     * GET /api/rooms/{id} — Détail d'une chambre.
     */
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $room = $this->roomRepository->findByIdWithRelations($id);

        if (null === $room) {
            return $this->jsonError('Chambre introuvable', 'NOT_FOUND', 404);
        }

        return $this->jsonSuccess($room, ['room:read', 'room:detail']);
    }

    /**
     * PATCH /api/rooms/{id}/status — Change le statut d'une chambre.
     */
    #[Route('/{id}/status', name: 'update_status', methods: ['PATCH'])]
    public function updateStatus(string $id, Request $request): JsonResponse
    {
        $room = $this->roomRepository->findByIdWithRelations($id);

        if (null === $room) {
            return $this->jsonError('Chambre introuvable', 'NOT_FOUND', 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $dto = new UpdateRoomStatusDTO();
        $dto->status = $data['status'] ?? '';
        $dto->notes  = $data['notes'] ?? null;

        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            $messages = [];
            foreach ($errors as $error) {
                $messages[$error->getPropertyPath()] = $error->getMessage();
            }
            return $this->jsonError('Données invalides', 'VALIDATION_ERROR', 422);
        }

        $newStatus = RoomStatus::from($dto->status);
        $staffUser = $this->getStaffUser();

        $this->roomService->updateStatus($room, $newStatus, $dto->notes, $staffUser);

        return $this->jsonSuccess($room, ['room:read']);
    }
}
