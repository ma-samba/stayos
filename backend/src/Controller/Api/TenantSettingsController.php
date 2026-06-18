<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Hotel\Reservation\Domain\Enum\CancellationPolicy;
use App\Hotel\Reservation\Domain\Enum\NoShowPolicy;
use App\Hotel\Shared\Domain\Service\AuditService;
use App\Platform\Tenant\Application\DTO\UpdateTenantSettingsDTO;
use App\Shared\Exception\BusinessRuleException;
use App\Shared\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Endpoint léger pour exposer / éditer les paramètres tenant utiles côté UI
 * sans surcharger le JWT.
 *
 * V1 :
 *  - GET  : noShowPolicy, cancellationPolicy, businessDayCutoffHour,
 *           timezone, currency (timezone et currency restent lecture seule)
 *  - PATCH (manager) : les 3 politiques financières uniquement.
 *
 * Lu une fois au mount des modales / vues concernées, modifié depuis l'onglet
 * Finances de la configuration hôtel.
 */
#[Route('/api/tenant', name: 'api_tenant_')]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
class TenantSettingsController extends AbstractApiController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    #[Route('/settings', name: 'settings', methods: ['GET'])]
    public function settings(): JsonResponse
    {
        return $this->jsonSuccess($this->serializeSettings());
    }

    /**
     * PATCH /api/tenant/settings — Manager uniquement.
     *
     * Accepte un payload partiel (1, 2 ou 3 champs). Audit log skippé si
     * aucun changement effectif (pas d'entrée fantôme). Payload de retour
     * identique au GET pour permettre au front de remplacer son state local.
     */
    #[Route('/settings', name: 'settings_update', methods: ['PATCH'])]
    public function updateSettings(
        Request                $request,
        ValidatorInterface     $validator,
        EntityManagerInterface $em,
        AuditService           $audit,
    ): JsonResponse {
        if (!$this->isGranted('ROLE_MANAGER')) {
            return $this->jsonError(
                'Seul un manager peut modifier les paramètres financiers.',
                'ACCESS_DENIED',
                403,
            );
        }

        $data = json_decode($request->getContent() ?: '[]', true);
        if (!is_array($data) || $data === []) {
            throw new BusinessRuleException('Aucun champ à mettre à jour fourni.');
        }

        $dto = new UpdateTenantSettingsDTO();
        if (array_key_exists('noShowPolicy', $data)) {
            $dto->noShowPolicy = $data['noShowPolicy'] !== null ? (string) $data['noShowPolicy'] : null;
        }
        if (array_key_exists('cancellationPolicy', $data)) {
            $dto->cancellationPolicy = $data['cancellationPolicy'] !== null ? (string) $data['cancellationPolicy'] : null;
        }
        if (array_key_exists('businessDayCutoffHour', $data)) {
            // Pas de cast : Assert\Type('integer') rejette les chaînes / floats
            // ("5" → 422 VALIDATION_ERROR plutôt que cast silencieux à 5).
            $dto->businessDayCutoffHour = $data['businessDayCutoffHour'];
        }

        $errors = $validator->validate($dto);
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

        $tenant = $this->tenantContext->get();

        // Capture before pour audit log diff
        $before = [
            'noShowPolicy'          => $tenant->getNoShowPolicy()->value,
            'cancellationPolicy'    => $tenant->getCancellationPolicy()->value,
            'businessDayCutoffHour' => $tenant->getBusinessDayCutoffHour(),
        ];

        // Apply (uniquement les champs FOURNIS et non null)
        if ($dto->noShowPolicy !== null) {
            $tenant->setNoShowPolicy(NoShowPolicy::from($dto->noShowPolicy));
        }
        if ($dto->cancellationPolicy !== null) {
            $tenant->setCancellationPolicy(CancellationPolicy::from($dto->cancellationPolicy));
        }
        if ($dto->businessDayCutoffHour !== null) {
            // Validé int par Assert\Type ci-dessus — cast sûr ici.
            $tenant->setBusinessDayCutoffHour((int) $dto->businessDayCutoffHour);
        }

        // Diff before/after pour audit log (uniquement les champs CHANGÉS)
        $after = [
            'noShowPolicy'          => $tenant->getNoShowPolicy()->value,
            'cancellationPolicy'    => $tenant->getCancellationPolicy()->value,
            'businessDayCutoffHour' => $tenant->getBusinessDayCutoffHour(),
        ];

        $changedBefore = [];
        $changedAfter  = [];
        foreach ($before as $key => $oldValue) {
            if ($oldValue !== $after[$key]) {
                $changedBefore[$key] = $oldValue;
                $changedAfter[$key]  = $after[$key];
            }
        }

        if ($changedBefore !== []) {
            $audit->log(
                action:     'tenant.settings_updated',
                entityType: 'Tenant',
                entityId:   (string) $tenant->getId(),
                before:     $changedBefore,
                after:      $changedAfter,
                staffUser:  $this->getStaffUser(),
            );
        }

        $em->flush();

        return $this->jsonSuccess($this->serializeSettings());
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSettings(): array
    {
        $tenant = $this->tenantContext->get();

        return [
            'noShowPolicy'         => $tenant->getNoShowPolicy()->value,
            'cancellationPolicy'   => $tenant->getCancellationPolicy()->value,
            'businessDayCutoffHour'=> $tenant->getBusinessDayCutoffHour(),
            'timezone'             => $tenant->getTimezone(),
            'currency'             => $tenant->getCurrency(),
        ];
    }
}
