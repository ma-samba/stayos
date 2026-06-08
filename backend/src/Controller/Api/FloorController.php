<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Hotel\Property\Application\DTO\CreateFloorDTO;
use App\Hotel\Property\Application\DTO\UpdateFloorDTO;
use App\Hotel\Property\Domain\Service\FloorService;
use App\Hotel\Property\Infrastructure\Repository\FloorRepository;
use App\Shared\Exception\AlreadyExistsException;
use App\Shared\Exception\BusinessRuleException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Sprint 13ter — CRUD des étages depuis le module Configuration.
 *
 * RBAC : ROLE_ACCESS_ROOMS suffit pour lire (tous les rôles staff
 * voient le plan), seul ROLE_MANAGER peut écrire.
 */
#[Route('/api/floors', name: 'api_floors_')]
#[IsGranted('ROLE_ACCESS_ROOMS')]
class FloorController extends AbstractApiController
{
    public function __construct(
        private readonly FloorRepository    $floorRepository,
        private readonly FloorService       $floorService,
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return $this->jsonSuccess(
            $this->floorRepository->findAllOrdered(),
            ['floor:read'],
        );
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $data = json_decode($request->getContent() ?: '[]', true) ?? [];
        $dto = new CreateFloorDTO();
        $dto->number = isset($data['number']) ? (int) $data['number'] : null;
        $dto->name   = isset($data['name']) ? trim((string) $data['name']) : null;
        if ($dto->name === '') {
            $dto->name = null;
        }

        if (null !== $error = $this->validateDto($dto)) {
            return $error;
        }

        try {
            $floor = $this->floorService->create($dto, $this->getStaffUser());
        } catch (AlreadyExistsException $e) {
            return $this->jsonError($e->getMessage(), 'ALREADY_EXISTS', 409);
        }

        return $this->jsonSuccess($floor, ['floor:read'], 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $floor = $this->floorRepository->find($id);
        if ($floor === null) {
            return $this->jsonError('Étage introuvable.', 'NOT_FOUND', 404);
        }

        $data = json_decode($request->getContent() ?: '[]', true) ?? [];
        $dto = new UpdateFloorDTO();
        $dto->number = isset($data['number']) ? (int) $data['number'] : null;
        $dto->name   = array_key_exists('name', $data) ? (is_string($data['name']) ? trim($data['name']) : null) : null;

        if (null !== $error = $this->validateDto($dto)) {
            return $error;
        }

        try {
            $floor = $this->floorService->update($floor, $dto, $this->getStaffUser());
        } catch (AlreadyExistsException $e) {
            return $this->jsonError($e->getMessage(), 'ALREADY_EXISTS', 409);
        }

        return $this->jsonSuccess($floor, ['floor:read']);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(string $id): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $floor = $this->floorRepository->find($id);
        if ($floor === null) {
            return $this->jsonError('Étage introuvable.', 'NOT_FOUND', 404);
        }

        try {
            $this->floorService->delete($floor, $this->getStaffUser());
        } catch (BusinessRuleException $e) {
            return $this->jsonError($e->getMessage(), 'BUSINESS_RULE', 422);
        }

        return $this->jsonSuccess(null, [], 204);
    }

    #[Route('/{id}/deactivate', name: 'deactivate', methods: ['POST'])]
    public function deactivate(string $id): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $floor = $this->floorRepository->find($id);
        if ($floor === null) {
            return $this->jsonError('Étage introuvable.', 'NOT_FOUND', 404);
        }

        $floor = $this->floorService->deactivate($floor, $this->getStaffUser());

        return $this->jsonSuccess($floor, ['floor:read']);
    }

    #[Route('/{id}/reactivate', name: 'reactivate', methods: ['POST'])]
    public function reactivate(string $id): JsonResponse
    {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError('Réservé au manager.', 'ACCESS_DENIED', 403);
        }

        $floor = $this->floorRepository->find($id);
        if ($floor === null) {
            return $this->jsonError('Étage introuvable.', 'NOT_FOUND', 404);
        }

        $floor = $this->floorService->reactivate($floor, $this->getStaffUser());

        return $this->jsonSuccess($floor, ['floor:read']);
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
