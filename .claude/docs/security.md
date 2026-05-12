# Sécurité — Référence complète

---

## Rate Limiting

Configuré via `symfony/rate-limiter` + Redis.
Voir `config/packages/rate_limiter.yaml`.

| Endpoint | Limite | Fenêtre | Stratégie |
|---|---|---|---|
| `POST /api/auth/login` | 5 tentatives | 1 minute | fixed_window |
| `POST /api/auth/refresh` | 10 requêtes | 1 minute | fixed_window |
| `POST /api/onboarding/register` | 3 tentatives | 1 heure | fixed_window |
| `POST /api/auth/resend-otp` | 3 tentatives | 10 minutes | fixed_window |
| `POST /api/webhooks/*` | 100 requêtes | 1 minute | sliding_window |
| `GET /api/*` (global) | 300 requêtes | 1 minute | sliding_window |
| `POST /api/*` (global) | 60 requêtes | 1 minute | sliding_window |

```php
// Appliquer dans un controller ou via un EventListener
// src/Shared/Security/RateLimiterListener.php

use Symfony\Component\RateLimiter\RateLimiterFactory;

class RateLimiterListener
{
    public function __construct(
        private RateLimiterFactory $loginLimiter,
        private RateLimiterFactory $apiGlobalLimiter,
    ) {}

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $ip = $request->getClientIp();

        // Login
        if ($request->getPathInfo() === '/api/auth/login') {
            $limiter = $this->loginLimiter->create($ip);
            if (!$limiter->consume()->isAccepted()) {
                throw new TooManyRequestsHttpException(60, 'Trop de tentatives. Réessayez dans 1 minute.');
            }
        }
    }
}
```

---

## OTP — Vérification email

Utilisé lors de l'onboarding (vérification de l'email) et pour les actions sensibles (changement de mot de passe, modification du plan).

### Entité OtpToken (schema public)
```php
// src/Platform/Auth/Domain/Entity/OtpToken.php
id, email, code (6 chiffres), type (OtpType enum),
expiresAt (DateTimeImmutable, +10 minutes),
usedAt (nullable), attempts (int default 0),
createdAt

// OtpType : EMAIL_VERIFICATION | PASSWORD_RESET | SENSITIVE_ACTION
```

### Service OtpService
```php
class OtpService
{
    // Génère un OTP 6 chiffres, le persiste, envoie par email via Mailjet
    public function generate(string $email, OtpType $type): OtpToken

    // Vérifie le code (max 3 tentatives, expiration 10 min)
    // Lève OtpInvalidException ou OtpExpiredException
    public function verify(string $email, string $code, OtpType $type): void

    // Invalide tous les OTP actifs pour cet email+type
    public function invalidateAll(string $email, OtpType $type): void
}
```

### Endpoints
```
POST /api/auth/send-otp
Body : { "email": "...", "type": "email_verification" }

POST /api/auth/verify-otp
Body : { "email": "...", "code": "123456", "type": "email_verification" }

POST /api/auth/resend-otp
Body : { "email": "...", "type": "email_verification" }
→ Rate limité à 3 fois / 10 minutes
```

---

## Audit Log

Traçabilité de toutes les actions importantes — exigence légale et bonne pratique pour un logiciel professionnel.

### Entité AuditLog (schema hotel_{uuid})
```php
// src/Hotel/Shared/Domain/Entity/AuditLog.php
id (uuid),
staffUser (ManyToOne StaffUser, nullable — null si action système),
action (string, ex: 'reservation.created', 'payment.recorded', 'room.status.changed'),
entityType (string, ex: 'Reservation', 'Invoice', 'Room'),
entityId (string),
before (JSON nullable — état avant),
after (JSON nullable — état après),
ipAddress (string),
userAgent (string),
createdAt (DateTimeImmutable)
```

### Actions à auditer OBLIGATOIREMENT
```
reservation.created         reservation.cancelled
reservation.checkin         reservation.checkout
payment.recorded            invoice.generated
room.status.changed         room.updated
rate.changed                promotion.created
staff.login                 staff.login_failed
staff.created               staff.role_changed
staff.password_changed      tenant.settings_changed
subscription.upgraded       subscription.cancelled
```

### Service AuditService
```php
class AuditService
{
    public function log(
        string      $action,
        string      $entityType,
        string      $entityId,
        ?array      $before = null,
        ?array      $after  = null,
        ?StaffUser  $user   = null,
    ): void

    // Récupère l'historique d'une entité
    public function getHistory(string $entityType, string $entityId): array

    // Récupère les actions d'un staff
    public function getStaffActivity(StaffUser $staff, int $days = 30): array
}
```

### Usage dans les services
```php
// Dans ReservationEngine::checkIn()
$this->auditService->log(
    action:     'reservation.checkin',
    entityType: 'Reservation',
    entityId:   $reservation->getId(),
    before:     ['status' => 'confirmed'],
    after:      ['status' => 'checked_in', 'checkedInAt' => now()],
    user:       $this->currentStaffUser,
);
```

---

## RBAC — Matrice des permissions

### Rôles
```
MANAGER       → Accès total à l'hôtel (sauf super admin)
RECEPTIONIST  → Réservations, check-in/out, clients, facturation
HOUSEKEEPER   → Tâches ménage uniquement (vue mobile simplifiée)
ACCOUNTANT    → Facturation, paiements, rapports financiers (lecture seule réservations)
```

### Matrice complète

| Action | MANAGER | RECEPTIONIST | HOUSEKEEPER | ACCOUNTANT |
|---|---|---|---|---|
| Voir dashboard | ✅ | ✅ | ❌ | ✅ |
| Créer réservation | ✅ | ✅ | ❌ | ❌ |
| Modifier réservation | ✅ | ✅ | ❌ | ❌ |
| Annuler réservation | ✅ | ✅ (même jour) | ❌ | ❌ |
| Check-in / Check-out | ✅ | ✅ | ❌ | ❌ |
| Voir clients | ✅ | ✅ | ❌ | ✅ |
| Modifier clients | ✅ | ✅ | ❌ | ❌ |
| Émettre facture | ✅ | ✅ | ❌ | ✅ |
| Enregistrer paiement | ✅ | ✅ | ❌ | ✅ |
| Voir rapports | ✅ | ❌ | ❌ | ✅ |
| Modifier tarifs | ✅ | ❌ | ❌ | ❌ |
| Gérer promotions | ✅ | ❌ | ❌ | ❌ |
| Voir tâches ménage | ✅ | ✅ | ✅ | ❌ |
| Mettre à jour tâche | ✅ | ✅ | ✅ (les siennes) | ❌ |
| Gérer le staff | ✅ | ❌ | ❌ | ❌ |
| Paramètres hôtel | ✅ | ❌ | ❌ | ❌ |
| Channel Manager | ✅ | ❌ | ❌ | ❌ |
| Audit logs | ✅ | ❌ | ❌ | ❌ |

### Voters Symfony
```php
// src/Hotel/Auth/Infrastructure/Security/ReservationVoter.php
class ReservationVoter extends Voter
{
    const CREATE = 'reservation.create';
    const CANCEL = 'reservation.cancel';
    const CHECKIN = 'reservation.checkin';

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        return match($attribute) {
            self::CREATE  => in_array($user->getRole(), ['MANAGER', 'RECEPTIONIST']),
            self::CHECKIN => in_array($user->getRole(), ['MANAGER', 'RECEPTIONIST']),
            self::CANCEL  => $user->getRole() === 'MANAGER'
                || ($user->getRole() === 'RECEPTIONIST' && $subject->getCheckIn() >= today()),
            default => false,
        };
    }
}

// Usage dans le controller :
$this->denyAccessUnlessGranted('reservation.cancel', $reservation);
```

---

## Politique de mots de passe

```php
// Contrainte de validation sur StaffUser
#[Assert\Length(min: 10)]
#[Assert\Regex(
    pattern: '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])/',
    message: 'Le mot de passe doit contenir majuscule, minuscule, chiffre et caractère spécial.'
)]
private string $password;
```

Règles :
- Minimum **10 caractères**
- Au moins 1 majuscule, 1 minuscule, 1 chiffre, 1 caractère spécial
- Pas d'expiration automatique (trop intrusif en hôtellerie) mais **historique des 5 derniers** (pas de réutilisation)
- Verrouillage après **10 tentatives échouées** → déverrouillage par le Manager uniquement

---

## Sécurité des webhooks

### Paydunya
```php
// Vérification HMAC-SHA256
public function verifyWebhookSignature(Request $request): bool
{
    $signature = $request->headers->get('X-Paydunya-Signature');
    $payload   = $request->getContent();
    $expected  = hash_hmac('sha256', $payload, $this->masterKey);
    return hash_equals($expected, $signature);
}
```

### Règles générales webhooks
- Toujours vérifier la signature **avant** tout traitement
- Répondre `200 OK` rapidement, traiter en async via Messenger
- Logger tous les webhooks reçus (même les invalides)
- Idempotence : vérifier si le paiement a déjà été enregistré avant de le traiter

---

## Headers de sécurité HTTP

```nginx
# À ajouter dans docker/nginx/default.conf et heroku.conf
add_header X-Frame-Options "SAMEORIGIN" always;
add_header X-Content-Type-Options "nosniff" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Referrer-Policy "strict-origin-when-cross-origin" always;
add_header Permissions-Policy "geolocation=(), camera=(), microphone=()" always;
# En prod avec HTTPS :
# add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
```

---

## Données sensibles — Règles

- **Numéros de documents** (CNI, passeport) : ne jamais logger, masquer dans les réponses API en liste (`****1234`)
- **Tokens JWT** : durée de vie 1h, ne jamais logger
- **Clés API** : uniquement dans les variables d'env, jamais dans le code ou les logs
- **Montants** : toujours vérifier côté serveur — ne jamais faire confiance au montant envoyé par le frontend
- **Photos de documents** (Uploadcare) : URLs signées avec expiration, pas d'URL publique permanente
