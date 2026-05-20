<?php

namespace App\Controller\Api;

use App\Hotel\Guest\Application\DTO\CreateGuestDTO;
use App\Hotel\Guest\Application\DTO\UpdateGuestDTO;
use App\Hotel\Guest\Domain\Service\GuestService;
use App\Hotel\Guest\Infrastructure\Repository\GuestRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/guests', name: 'api_guests_')]
class GuestController extends AbstractApiController
{
    public function __construct(
        private readonly GuestService       $guestService,
        private readonly GuestRepository    $guestRepository,
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');

        if ($query !== '') {
            $guests = $this->guestService->search($query);
            return $this->jsonSuccess($guests, ['guest:read']);
        }

        $page  = $request->query->getInt('page', 1);
        $limit = $request->query->getInt('limit', 20);

        $guests = $this->guestRepository->findAllPaginated($page, $limit);
        $total  = $this->guestRepository->countAll();

        return $this->json([
            'data'   => $guests,
            'meta'   => [
                'total' => $total,
                'page'  => $page,
                'limit' => $limit,
                'pages' => (int) ceil($total / $limit),
            ],
            'status' => 200,
        ], 200, [], ['groups' => ['guest:read']]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $dto = new CreateGuestDTO();
        $dto->firstName      = $data['firstName']      ?? '';
        $dto->lastName       = $data['lastName']       ?? '';
        $dto->email          = $data['email']          ?? null;
        $dto->phone          = $data['phone']          ?? null;
        $dto->nationality    = $data['nationality']    ?? null;
        $dto->documentNumber = $data['documentNumber'] ?? null;

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

        $guest = $this->guestService->create($dto, $this->getStaffUser());

        return $this->jsonSuccess($guest, ['guest:read'], 201);
    }

    #[Route('/{id}/stays', name: 'stays', methods: ['GET'])]
    public function stays(string $id): JsonResponse
    {
        $guest = $this->guestRepository->find($id);
        if ($guest === null) {
            return $this->jsonError('Client introuvable', 'NOT_FOUND', 404);
        }

        $stays = $this->guestService->getStayHistory($guest);

        return $this->jsonSuccess($stays, ['reservation:read']);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $guest = $this->guestRepository->find($id);
        if ($guest === null) {
            return $this->jsonError('Client introuvable', 'NOT_FOUND', 404);
        }

        return $this->jsonSuccess($guest, ['guest:read', 'guest:detail']);
    }

    #[Route('/{id}', name: 'update', methods: ['PUT'])]
    public function update(string $id, Request $request): JsonResponse
    {
        $guest = $this->guestRepository->find($id);
        if ($guest === null) {
            return $this->jsonError('Client introuvable', 'NOT_FOUND', 404);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $dto = new UpdateGuestDTO();
        $fields = ['firstName', 'lastName', 'email', 'phone', 'nationality', 'documentType', 'documentNumber', 'documentUrl', 'address', 'city', 'country'];
        foreach ($fields as $f) {
            if (array_key_exists($f, $data)) {
                $dto->$f = $data[$f];
            }
        }

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

        $guest = $this->guestService->update($guest, $dto, $this->getStaffUser());

        return $this->jsonSuccess($guest, ['guest:read', 'guest:detail']);
    }
}
