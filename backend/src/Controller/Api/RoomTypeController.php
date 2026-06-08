<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Hotel\Room\Application\DTO\CreateRoomTypeDTO;
use App\Hotel\Room\Application\DTO\UpdateRoomTypeDTO;
use App\Hotel\Room\Domain\Service\RoomService;
use App\Hotel\Room\Infrastructure\Repository\RoomTypeRepository;
use App\Shared\Exception\AlreadyExistsException;
use App\Shared\Exception\BusinessRuleException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Sprint 13ter — CRUD des types de chambre.
 *
 * NB : le legacy `PUT /api/rooms/types/{typeId}` est conservé pour
 * ne pas casser l'UI existante. Dette à supprimer au Sprint 14.
 */
#[Route('/api/room-types', name: 'api_room_types_')]
#[IsGranted('ROLE_ACCESS_ROOMS')]
class RoomTypeController extends AbstractApiController
{
    public function __construct(
        private readonly RoomTypeRepository $roomTypeRepository,
        private readonly RoomService        $roomService,
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->jsonSuccess(
            $this->roomTypeRepository->findBy([], ['sortOrder' => 'ASC']),
            ['room:read'],
        );
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $data = json_decode($request->getContent() ?: '[]', true) ?? [];

        $dto = new CreateRoomTypeDTO();
        $dto->name             = isset($data['name']) ? trim((string) $data['name']) : null;
        $dto->description      = isset($data['description']) ? (string) $data['description'] : null;
        $dto->baseRateXof      = isset($data['baseRateXof']) ? (string) $data['baseRateXof'] : null;
        $dto->maxOccupancy     = isset($data['maxOccupancy']) ? (int) $data['maxOccupancy'] : null;
        $dto->bedConfiguration = isset($data['bedConfiguration']) && is_array($data['bedConfiguration'])
            ? $data['bedConfiguration'] : null;
        $dto->amenities        = isset($data['amenities']) && is_array($data['amenities'])
            ? $data['amenities'] : null;
        $dto->sortOrder        = isset($data['sortOrder']) ? (int) $data['sortOrder'] : null;

        if (null !== $error = $this->validateDto($dto)) {
            return $error;
        }

        try {
            $type = $this->roomService->createType($dto, $this->getStaffUser());
        } catch (AlreadyExistsException $e) {
            return $this->jsonError($e->getMessage(), 'ALREADY_EXISTS', 409);
        }

        return $this->jsonSuccess($type, ['room:read'], 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $type = $this->roomTypeRepository->find($id);
        if ($type === null) {
            return $this->jsonError('Type introuvable.', 'NOT_FOUND', 404);
        }

        $data = json_decode($request->getContent() ?: '[]', true) ?? [];
        $dto = new UpdateRoomTypeDTO();
        $dto->name         = isset($data['name']) ? trim((string) $data['name']) : null;
        $dto->baseRateXof  = isset($data['baseRateXof']) ? (string) $data['baseRateXof'] : null;
        $dto->maxOccupancy = isset($data['maxOccupancy']) ? (int) $data['maxOccupancy'] : null;
        $dto->description  = array_key_exists('description', $data) ? (string) $data['description'] : null;

        if (null !== $error = $this->validateDto($dto)) {
            return $error;
        }

        try {
            $type = $this->roomService->updateType($type, $dto, $this->getStaffUser());
        } catch (AlreadyExistsException $e) {
            return $this->jsonError($e->getMessage(), 'ALREADY_EXISTS', 409);
        }

        return $this->jsonSuccess($type, ['room:read']);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $type = $this->roomTypeRepository->find($id);
        if ($type === null) {
            return $this->jsonError('Type introuvable.', 'NOT_FOUND', 404);
        }

        try {
            $this->roomService->deleteType($type, $this->getStaffUser());
        } catch (BusinessRuleException $e) {
            return $this->jsonError($e->getMessage(), 'BUSINESS_RULE', 422);
        }

        return $this->jsonSuccess(null, [], 204);
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
