<?php

namespace App\Controller\Api;

use App\Platform\Auth\Domain\Service\OnboardingService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/onboarding', name: 'api_onboarding_')]
class OnboardingController extends AbstractApiController
{
    public function __construct(
        private readonly OnboardingService  $onboardingService,
        private readonly ValidatorInterface $validator,
    ) {}

    /**
     * POST /api/onboarding/register — Inscription d'un nouvel hôtel.
     * Route publique (pas de JWT requis).
     */
    #[Route('/register', name: 'register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $constraints = new Assert\Collection([
            'hotel_name' => [
                new Assert\NotBlank(message: "Le nom de l'hôtel est obligatoire."),
                new Assert\Length(min: 2, max: 100),
            ],
            'slug' => [
                new Assert\NotBlank(message: 'Le slug est obligatoire.'),
                new Assert\Regex(
                    pattern: '/^[a-z0-9-]+$/',
                    message: 'Le slug ne peut contenir que des lettres minuscules, chiffres et tirets.'
                ),
                new Assert\Length(min: 2, max: 50),
            ],
            'email' => [
                new Assert\NotBlank(message: "L'email est obligatoire."),
                new Assert\Email(message: "Format d'email invalide."),
            ],
            'password' => [
                new Assert\NotBlank(message: 'Le mot de passe est obligatoire.'),
                new Assert\Length(
                    min: 10,
                    minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.'
                ),
            ],
            'first_name' => [
                new Assert\NotBlank(message: 'Le prénom est obligatoire.'),
                new Assert\Length(min: 2, max: 100),
            ],
            'last_name' => [
                new Assert\NotBlank(message: 'Le nom est obligatoire.'),
                new Assert\Length(min: 2, max: 100),
            ],
        ]);

        $violations = $this->validator->validate($data, $constraints);

        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $violation) {
                $field          = trim($violation->getPropertyPath(), '[]');
                $errors[$field] = $violation->getMessage();
            }

            return $this->json([
                'error'  => 'Données invalides',
                'code'   => 'VALIDATION_ERROR',
                'status' => 422,
                'errors' => $errors,
            ], 422);
        }

        $result = $this->onboardingService->register($data);

        return $this->jsonSuccess([
            'tenant_slug' => $result['tenant']->getSlug(),
            'message'     => $result['message'],
        ], status: 201);
    }
}
