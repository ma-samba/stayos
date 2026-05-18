<?php

namespace App\Controller\Api;

use App\Platform\Auth\Domain\Entity\StaffUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Classe de base pour tous les controllers API.
 */
abstract class AbstractApiController extends AbstractController
{
    /**
     * Réponse succès standard : { "data": ..., "status": 200, "message": "OK" }
     */
    protected function jsonSuccess(mixed $data, array $groups = [], int $status = 200): JsonResponse
    {
        if ($groups !== []) {
            return $this->json([
                'data'    => $data,
                'status'  => $status,
                'message' => 'OK',
            ], $status, [], ['groups' => $groups]);
        }

        return $this->json([
            'data'    => $data,
            'status'  => $status,
            'message' => 'OK',
        ], $status);
    }

    /**
     * Réponse erreur standard : { "error": "...", "code": "...", "status": 400 }
     */
    protected function jsonError(string $message, string $code = 'ERROR', int $status = 400): JsonResponse
    {
        return $this->json([
            'error'  => $message,
            'code'   => $code,
            'status' => $status,
        ], $status);
    }

    /**
     * Retourne l'utilisateur courant casté en StaffUser.
     *
     * @throws \LogicException si l'utilisateur n'est pas un StaffUser
     */
    protected function getStaffUser(): StaffUser
    {
        $user = $this->getUser();

        if (!$user instanceof StaffUser) {
            throw new \LogicException('Cet endpoint requiert un StaffUser authentifié.');
        }

        return $user;
    }
}
