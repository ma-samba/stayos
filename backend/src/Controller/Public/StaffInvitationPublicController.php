<?php

declare(strict_types=1);

namespace App\Controller\Public;

use App\Platform\Auth\Domain\Service\StaffInvitationService;
use App\Shared\Exception\AlreadyExistsException;
use App\Shared\Exception\BusinessRuleException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints publics d'acceptation d'invitation (Sprint 13bis).
 *
 * Routes hors firewall API (PAS de JWT) : c'est l'invité qui appelle
 * AVANT d'avoir un compte. Le tokenHash fait office d'authentification.
 *
 * ⚠️ Résolution du tenant : ces endpoints sont en dehors de `/api`
 * mais doivent passer par `TenantMiddleware` pour poser le search_path
 * sur le schema tenant (sinon les tables `staff_invitations` et
 * `staff_users` sont introuvables). Donc :
 *  - PAS d'ajout à `TenantMiddleware::EXCLUDED_PREFIXES`.
 *  - Firewall `public_invitations` configuré dans `security.yaml`
 *    avant le firewall `api`, `security: false`.
 *
 * Le slug tenant arrive via le sous-domaine
 * (`{slug}.getstayos.com`) ou le header `X-Tenant-Slug` en dev local.
 *
 * Un tenant suspendu ne peut PAS valider d'invitation (V1) :
 * `TenantMiddleware` lèvera 402 avant ce controller, ce qui est
 * acceptable (un opérateur StayOS doit réactiver le tenant d'abord).
 */
#[Route('/public/invitations', name: 'public_invitations_')]
class StaffInvitationPublicController extends AbstractController
{
    public function __construct(
        private readonly StaffInvitationService $service,
    ) {}

    /**
     * GET /public/invitations/{token}
     * Retourne les infos publiques de l'invitation, sans le tokenHash
     * ni l'id (pas besoin côté front pour la page d'acceptation).
     */
    #[Route('/{token}', name: 'show', methods: ['GET'])]
    public function show(string $token): JsonResponse
    {
        try {
            $invitation = $this->service->getByToken($token);
        } catch (BusinessRuleException $e) {
            return $this->jsonError($e->getMessage(), 'BUSINESS_RULE', 422);
        }

        return new JsonResponse([
            'data' => [
                'email'     => $invitation->getEmail(),
                'firstName' => $invitation->getFirstName(),
                'lastName'  => $invitation->getLastName(),
                'role'      => $invitation->getRole(),
                'expiresAt' => $invitation->getExpiresAt()->format(\DateTimeInterface::ATOM),
            ],
            'status'  => 200,
            'message' => 'OK',
        ]);
    }

    /**
     * POST /public/invitations/{token}/accept
     * Body : { password } (min 8 caractères).
     */
    #[Route('/{token}/accept', name: 'accept', methods: ['POST'])]
    public function accept(string $token, Request $request): JsonResponse
    {
        $body     = json_decode($request->getContent() ?: '[]', true) ?? [];
        $password = (string) ($body['password'] ?? '');

        if (strlen($password) < 8) {
            return $this->jsonError(
                'Le mot de passe doit contenir au moins 8 caractères.',
                'VALIDATION_ERROR',
                422,
            );
        }

        try {
            $staffUser = $this->service->accept($token, $password);
        } catch (AlreadyExistsException $e) {
            return $this->jsonError($e->getMessage(), 'ALREADY_EXISTS', 409);
        } catch (BusinessRuleException $e) {
            return $this->jsonError($e->getMessage(), 'BUSINESS_RULE', 422);
        }

        return new JsonResponse([
            'data' => [
                'email' => $staffUser->getEmail(),
                'role'  => $staffUser->getRole(),
            ],
            'message' => 'Compte créé. Connectez-vous avec votre mot de passe.',
            'status'  => 201,
        ], 201);
    }

    private function jsonError(string $message, string $code, int $status): JsonResponse
    {
        return new JsonResponse([
            'error'  => $message,
            'code'   => $code,
            'status' => $status,
        ], $status);
    }
}
