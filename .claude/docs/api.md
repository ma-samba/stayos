# Architecture API — Référence

## Routes et structure

Toutes les routes sont préfixées `/api`.

| Groupe | Préfixe | Auth |
|---|---|---|
| Auth hôtel | `/api/auth` | Public |
| Dashboard | `/api/dashboard` | JWT staff |
| Chambres | `/api/rooms` | JWT staff |
| Réservations | `/api/reservations` | JWT staff |
| Clients | `/api/guests` | JWT staff |
| Facturation | `/api/invoices` | JWT staff |
| Paiements | `/api/payments` | JWT staff |
| Housekeeping | `/api/housekeeping` | JWT staff |
| Tarifs | `/api/rates` | JWT manager+ |
| Rapports | `/api/reports` | JWT manager+ |
| Paramètres hôtel | `/api/settings` | JWT manager+ |
| Onboarding | `/api/onboarding` | Public |
| Abonnement | `/api/platform/subscription` | JWT admin |
| Webhooks | `/api/webhooks/{provider}` | Signature |
| Super Admin | `/superadmin/...` | JWT super_admin |
| Health | `/api/health` | Public |

## Endpoints principaux

```
# Auth
POST   /api/auth/login
POST   /api/auth/refresh
POST   /api/auth/logout

# Onboarding SaaS
POST   /api/onboarding/register          # Crée tenant + admin + schema
GET    /api/onboarding/steps             # État du tunnel
PATCH  /api/onboarding/steps/{step}      # Valide une étape

# Dashboard
GET    /api/dashboard/today              # KPIs du jour
GET    /api/dashboard/occupancy          # Taux d'occupation

# Chambres
GET    /api/rooms                        # Toutes les chambres
GET    /api/rooms/available?from=&to=&adults=
PATCH  /api/rooms/{id}/status

# Réservations
GET    /api/reservations                 # Liste + filtres
POST   /api/reservations                 # Créer
GET    /api/reservations/{id}
PUT    /api/reservations/{id}
POST   /api/reservations/{id}/checkin
POST   /api/reservations/{id}/checkout
POST   /api/reservations/{id}/cancel
DELETE /api/reservations/{id}            # Soft delete

# Clients
GET    /api/guests?q=                    # Recherche fulltext
POST   /api/guests
GET    /api/guests/{id}
PUT    /api/guests/{id}
GET    /api/guests/{id}/history          # Historique séjours

# Facturation
GET    /api/invoices
POST   /api/invoices/generate/{reservationId}
GET    /api/invoices/{id}
GET    /api/invoices/{id}/pdf
POST   /api/payments

# Housekeeping
GET    /api/housekeeping/tasks           # Tâches du jour
PATCH  /api/housekeeping/tasks/{id}/status
POST   /api/housekeeping/tasks/bulk-assign

# Rapports
GET    /api/reports/occupancy?from=&to=
GET    /api/reports/revenue?from=&to=
GET    /api/reports/revpar?from=&to=

# Abonnement SaaS
GET    /api/platform/subscription
POST   /api/platform/subscription/upgrade
POST   /api/platform/subscription/cancel
GET    /api/platform/subscription/invoices

# Webhooks
POST   /api/webhooks/wave
POST   /api/webhooks/orange-money
POST   /api/webhooks/stripe
POST   /api/webhooks/booking-com

# Health
GET    /api/health
```

## Structure d'un Controller

```php
#[Route('/api/reservations', name: 'api_reservations_')]
class ReservationController extends AbstractApiController
{
    public function __construct(
        private ReservationRepository  $reservationRepo,
        private ReservationEngine      $reservationEngine,
        private SerializerInterface    $serializer,
        private ValidatorInterface     $validator,
        private FeatureGuard           $featureGuard,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $reservations = $this->reservationRepo->findWithFilters(
            status: $request->query->get('status'),
            from:   $request->query->get('from'),
            to:     $request->query->get('to'),
        );
        return $this->jsonSuccess($reservations, ['reservation:read']);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(Reservation $reservation): JsonResponse
    {
        return $this->jsonSuccess($reservation, ['reservation:read', 'reservation:detail']);
    }

    #[Route('/{id}/checkin', name: 'checkin', methods: ['POST'])]
    public function checkIn(Reservation $reservation): JsonResponse
    {
        $this->reservationEngine->checkIn($reservation);
        return $this->jsonSuccess($reservation, ['reservation:read']);
    }
}
```

## AbstractApiController

```php
// src/Controller/Api/AbstractApiController.php
abstract class AbstractApiController extends AbstractController
{
    // Récupère le StaffUser depuis le JWT (schema hotel)
    protected function getStaffUser(): StaffUser
    {
        return $this->getUser();
    }

    // Réponse succès standard
    protected function jsonSuccess(mixed $data, array $groups = [], int $status = 200): JsonResponse
    {
        return $this->json([
            'data'    => $data,
            'status'  => $status,
            'message' => 'OK',
        ], $status, [], ['groups' => $groups]);
    }

    // Réponse erreur standard
    protected function jsonError(string $message, string $code = 'ERROR', int $status = 400): JsonResponse
    {
        return $this->json([
            'error'  => $message,
            'code'   => $code,
            'status' => $status,
        ], $status);
    }

    // Valide un DTO
    protected function validateDTO(object $dto): ?JsonResponse
    {
        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            $messages = [];
            foreach ($errors as $error) {
                $messages[$error->getPropertyPath()] = $error->getMessage();
            }
            return $this->jsonError('Données invalides', 'VALIDATION_ERROR', 422);
        }
        return null;
    }

    // Vérifie une feature du plan
    protected function requireFeature(string $feature): void
    {
        if (!$this->featureGuard->can($feature)) {
            throw new FeatureNotAvailableException($feature);
        }
    }
}
```

## DTOs

```php
// src/Hotel/Reservation/Application/DTO/CreateReservationDTO.php
class CreateReservationDTO
{
    #[Assert\NotNull]
    #[Assert\Positive]
    public int $roomId;

    #[Assert\NotNull]
    #[Assert\Positive]
    public int $guestId;

    #[Assert\NotNull]
    public \DateTimeImmutable $checkIn;

    #[Assert\NotNull]
    #[Assert\GreaterThan(propertyPath: 'checkIn')]
    public \DateTimeImmutable $checkOut;

    #[Assert\Positive]
    public int $adults = 1;

    public int $children = 0;

    public ?string $notes = null;
    public ?string $specialRequests = null;

    // Source de la réservation
    public string $source = 'direct';
}
```

## Serialization Groups

```php
// Groupes standards :
// {entity}:read       → liste (champs essentiels)
// {entity}:detail     → fiche complète avec relations
// {entity}:write      → désérialisation (entrée)

#[ORM\Column]
#[Groups(['reservation:read', 'reservation:detail'])]
private string $confirmationNumber;

#[ORM\ManyToOne]
#[Groups(['reservation:detail'])]   // seulement dans le détail
private ?Guest $guest = null;
```

## JWT Claims

```json
{
  "sub": "staff_user_uuid",
  "tenant": "hotel_uuid",
  "role": "RECEPTIONIST",
  "plan": "PRO",
  "features": ["channel_manager", "advanced_reports"],
  "hotel": "Hôtel Savana Dakar",
  "exp": 1234567890
}
```

## Firewalls Symfony (security.yaml)

```yaml
firewalls:
    superadmin:
        pattern: ^/superadmin
        stateless: true
        jwt: ~
        # + IP whitelist en production

    api:
        pattern: ^/api
        stateless: true
        jwt: ~

    onboarding:
        pattern: ^/api/onboarding/register
        security: false

    health:
        pattern: ^/api/health
        security: false
```

## Pagination

```php
// Réponse paginée standard
$pagination = $this->paginator->paginate(
    $query,
    $request->query->getInt('page', 1),
    $request->query->getInt('limit', 20)
);
```

```json
{
  "data": [...],
  "meta": {
    "total": 150,
    "page": 1,
    "limit": 20,
    "pages": 8
  }
}
```

## Mercure — Topics SSE

```
/hotel/{tenantId}/room.status.changed
/hotel/{tenantId}/reservation.created
/hotel/{tenantId}/reservation.checkin
/hotel/{tenantId}/task.assigned
/hotel/{tenantId}/payment.received
```

## Health endpoint

```json
GET /api/health
{
  "status": "ok",
  "db": "ok",
  "redis": "ok",
  "mercure": "ok",
  "version": "1.0.0"
}
```
