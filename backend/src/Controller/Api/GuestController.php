<?php

namespace App\Controller\Api;

use App\Hotel\Guest\Application\DTO\CreateGuestDTO;
use App\Hotel\Guest\Domain\Service\GuestService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/guests', name: 'api_guests_')]
class GuestController extends AbstractApiController
{
    public function __construct(
        private readonly GuestService       $guestService,
        private readonly ValidatorInterface $validator,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');
        $guests = $this->guestService->search($query);

        return $this->jsonSuccess($guests, ['guest:read']);
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
}
