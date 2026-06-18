<?php

namespace App\Controller\Api;

use App\Hotel\Room\Application\DTO\BulkCreateRoomsDTO;
use App\Hotel\Room\Application\DTO\CreateRoomDTO;
use App\Hotel\Room\Application\DTO\UpdateRoomDTO;
use App\Hotel\Room\Application\DTO\UpdateRoomStatusDTO;
use App\Hotel\Room\Domain\Entity\Room;
use App\Hotel\Room\Domain\Enum\RoomStatus;
use App\Hotel\Room\Domain\Service\RoomService;
use App\Hotel\Room\Infrastructure\Repository\RoomRepository;
use App\Hotel\Room\Infrastructure\Repository\RoomTypeRepository;
use App\Platform\Subscription\Domain\Service\SubscriptionLimitChecker;
use App\Shared\Exception\AlreadyExistsException;
use App\Shared\Exception\BusinessRuleException;
use App\Shared\Exception\ConflictException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/rooms', name: 'api_rooms_')]
#[IsGranted('ROLE_ACCESS_ROOMS')]
class RoomController extends AbstractApiController
{
    public function __construct(
        private readonly RoomRepository           $roomRepository,
        private readonly RoomTypeRepository       $roomTypeRepository,
        private readonly RoomService              $roomService,
        private readonly SubscriptionLimitChecker $limitChecker,
        private readonly ValidatorInterface       $validator,
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
     * GET /api/rooms/usage — Décompte X/Y chambres pour la jauge.
     */
    #[Route('/usage', name: 'usage', methods: ['GET'])]
    public function usage(): JsonResponse
    {
        return $this->jsonSuccess($this->limitChecker->getRoomUsage());
    }

    /**
     * GET /api/rooms/types — Liste des types de chambre.
     */
    #[Route('/types', name: 'types_index', methods: ['GET'])]
    public function types(): JsonResponse
    {
        return $this->jsonSuccess(
            $this->roomTypeRepository->findBy([], ['sortOrder' => 'ASC']),
            ['room:read']
        );
    }

    /**
     * POST /api/rooms — Création unitaire. Sprint 13ter.
     */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $data = json_decode($request->getContent() ?: '[]', true) ?? [];
        $dto = new CreateRoomDTO();
        $dto->number   = isset($data['number']) ? trim((string) $data['number']) : null;
        $dto->typeId   = isset($data['typeId']) ? (string) $data['typeId'] : null;
        $dto->floorId  = isset($data['floorId']) ? (string) $data['floorId'] : null;
        $dto->notes    = isset($data['notes']) ? (string) $data['notes'] : null;
        $dto->isActive = array_key_exists('isActive', $data) ? (bool) $data['isActive'] : true;

        if (null !== $error = $this->validateDto($dto)) {
            return $error;
        }

        try {
            $room = $this->roomService->createRoom($dto, $this->getStaffUser());
        } catch (AlreadyExistsException $e) {
            return $this->jsonError($e->getMessage(), 'ALREADY_EXISTS', 409);
        } catch (ConflictException $e) {
            return $this->jsonError($e->getMessage(), 'CONFLICT', 409);
        } catch (BusinessRuleException $e) {
            return $this->jsonError($e->getMessage(), 'BUSINESS_RULE', 422);
        }

        return $this->jsonSuccess($room, ['room:read', 'room:detail'], 201);
    }

    /**
     * POST /api/rooms/bulk — Création en lot. Sprint 13ter.
     */
    #[Route('/bulk', name: 'bulk_create', methods: ['POST'])]
    public function bulkCreate(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $data = json_decode($request->getContent() ?: '[]', true) ?? [];
        $dto = new BulkCreateRoomsDTO();
        $dto->floorId     = isset($data['floorId']) ? (string) $data['floorId'] : null;
        $dto->typeId      = isset($data['typeId']) ? (string) $data['typeId'] : null;
        $dto->startNumber = isset($data['startNumber']) ? (int) $data['startNumber'] : null;
        $dto->count       = isset($data['count']) ? (int) $data['count'] : null;
        $dto->prefix      = isset($data['prefix']) ? trim((string) $data['prefix']) : null;
        if ($dto->prefix === '') {
            $dto->prefix = null;
        }

        if (null !== $error = $this->validateDto($dto)) {
            return $error;
        }

        try {
            $rooms = $this->roomService->bulkCreateRooms($dto, $this->getStaffUser());
        } catch (AlreadyExistsException $e) {
            return $this->jsonError($e->getMessage(), 'ALREADY_EXISTS', 409);
        } catch (ConflictException $e) {
            return $this->jsonError($e->getMessage(), 'CONFLICT', 409);
        } catch (BusinessRuleException $e) {
            return $this->jsonError($e->getMessage(), 'BUSINESS_RULE', 422);
        }

        return $this->jsonSuccess($rooms, ['room:read'], 201);
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
     * PUT /api/rooms/{id} — Modifier une chambre.
     */
    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $room = $this->roomRepository->findByIdWithRelations($id);
        if (null === $room) {
            return $this->jsonError('Chambre introuvable', 'NOT_FOUND', 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $dto = new UpdateRoomDTO();
        $dto->number   = $data['number']   ?? null;
        $dto->typeId   = $data['typeId']   ?? null;
        $dto->floorId  = $data['floorId']  ?? null;
        $dto->notes    = $data['notes']    ?? null;
        $dto->isActive = $data['isActive'] ?? null;

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

        $room = $this->roomService->updateRoom($room, $dto, $this->getStaffUser());

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
            return $this->json([
                'error'  => 'Données invalides',
                'code'   => 'VALIDATION_ERROR',
                'status' => 422,
                'errors' => $messages,
            ], 422);
        }

        $newStatus = RoomStatus::from($dto->status);
        $staffUser = $this->getStaffUser();

        $this->roomService->updateStatus($room, $newStatus, $dto->notes, $staffUser);

        return $this->jsonSuccess($room, ['room:read']);
    }

    /**
     * DELETE /api/rooms/{id} — Soft delete (isActive=false). Sprint 13ter.
     * Bloqué si des réservations actives portent sur la chambre.
     */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $room = $this->roomRepository->findByIdWithRelations($id);
        if ($room === null) {
            return $this->jsonError('Chambre introuvable', 'NOT_FOUND', 404);
        }

        try {
            $this->roomService->softDelete($room, $this->getStaffUser());
        } catch (BusinessRuleException $e) {
            return $this->jsonError($e->getMessage(), 'BUSINESS_RULE', 422);
        }

        return $this->jsonSuccess(null, [], 204);
    }

    /**
     * POST /api/rooms/{id}/reactivate — Réactive une chambre soft-deleted.
     */
    #[Route('/{id}/reactivate', name: 'reactivate', methods: ['POST'])]
    public function reactivate(string $id): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $room = $this->roomRepository->findByIdWithRelations($id);
        if ($room === null) {
            return $this->jsonError('Chambre introuvable', 'NOT_FOUND', 404);
        }

        try {
            $room = $this->roomService->reactivate($room, $this->getStaffUser());
        } catch (BusinessRuleException $e) {
            return $this->jsonError($e->getMessage(), 'BUSINESS_RULE', 422);
        }

        return $this->jsonSuccess($room, ['room:read']);
    }

    private function validateDto(object $dto): ?JsonResponse
    {
        $errors = $this->validator->validate($dto);
        if (count($errors) === 0) {
            return null;
        }
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
}
