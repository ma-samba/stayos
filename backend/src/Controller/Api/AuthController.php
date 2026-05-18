<?php

namespace App\Controller\Api;

use App\Platform\Auth\Domain\Service\OtpService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/auth', name: 'api_auth_')]
class AuthController extends AbstractApiController
{
    public function __construct(
        private readonly OtpService $otpService,
    ) {}

    /**
     * POST /api/auth/send-otp — Envoie un OTP par email.
     * Route publique.
     */
    #[Route('/send-otp', name: 'send_otp', methods: ['POST'])]
    public function sendOtp(Request $request): JsonResponse
    {
        $data  = json_decode($request->getContent(), true) ?? [];
        $email = $data['email'] ?? '';
        $type  = $data['type'] ?? 'email_verification';

        if ('' === $email) {
            return $this->jsonError('Email obligatoire', 'VALIDATION_ERROR', 422);
        }

        $this->otpService->generate($email, $type);

        return $this->jsonSuccess(['message' => 'Code envoyé.']);
    }

    /**
     * POST /api/auth/verify-otp — Vérifie un OTP.
     * Route publique.
     */
    #[Route('/verify-otp', name: 'verify_otp', methods: ['POST'])]
    public function verifyOtp(Request $request): JsonResponse
    {
        $data  = json_decode($request->getContent(), true) ?? [];
        $email = $data['email'] ?? '';
        $code  = $data['code'] ?? '';
        $type  = $data['type'] ?? 'email_verification';

        if ('' === $email || '' === $code) {
            return $this->jsonError('Email et code obligatoires', 'VALIDATION_ERROR', 422);
        }

        $this->otpService->verify($email, $code, $type);

        return $this->jsonSuccess(['verified' => true]);
    }

    /**
     * POST /api/auth/logout — Déconnexion stateless (le client supprime le token).
     * JWT requis.
     */
    #[Route('/logout', name: 'logout', methods: ['POST'])]
    public function logout(): JsonResponse
    {
        return $this->jsonSuccess(['message' => 'Déconnecté.']);
    }
}
