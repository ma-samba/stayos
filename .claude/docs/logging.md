# Logs & Supervision — Référence complète

---

## Architecture des logs

```
Symfony Monolog
├── channel: app          → Activité générale
├── channel: security     → Auth, tentatives échouées, anomalies
├── channel: business     → Événements métier (check-in, paiements...)
├── channel: external     → Appels services externes (Paydunya, Uploadcare, Mailjet)
└── channel: deprecation  → Symfony deprecations (dev uniquement)

En dev  → fichiers var/log/dev.log + var/log/security.log
En prod → Heroku log drain → Papertrail (agrégation + recherche + alertes)
Erreurs → Sentry (stack traces, contexte, alertes email/Slack)
Uptime  → UptimeRobot (ping /api/health toutes les 5 minutes)
```

---

## Configuration Monolog (config/packages/monolog.yaml)

Voir `backend/config/packages/monolog.yaml` — le fichier est généré avec la config complète.

### Channels personnalisés
```yaml
monolog:
    channels: [security, business, external]
```

### Usage dans les services
```php
use Psr\Log\LoggerInterface;

class ReservationEngine
{
    public function __construct(
        // Injecter le bon channel avec l'attribut #[Target]
        #[Target('business')] private LoggerInterface $businessLogger,
        #[Target('security')] private LoggerInterface $securityLogger,
    ) {}

    public function checkIn(Reservation $reservation): void
    {
        // Log métier structuré
        $this->businessLogger->info('Reservation checked in', [
            'reservation_id'     => $reservation->getId(),
            'confirmation_number'=> $reservation->getConfirmationNumber(),
            'room'               => $reservation->getRoom()->getNumber(),
            'guest'              => $reservation->getGuest()->getFullName(),
            'staff_id'           => $this->currentUser->getId(),
            'tenant'             => $this->tenantContext->getTenant()->getSlug(),
        ]);
    }
}

class AuthService
{
    public function recordFailedLogin(string $email, string $ip): void
    {
        $this->securityLogger->warning('Failed login attempt', [
            'email'      => $email,
            'ip'         => $ip,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'timestamp'  => now(),
        ]);
    }
}
```

---

## Événements à logger OBLIGATOIREMENT

### Channel `security`
```
WARNING  login_failed          email, ip, tentative N/N
WARNING  login_rate_limited    email, ip
INFO     login_success         user_id, tenant, ip
INFO     logout                user_id
WARNING  invalid_token         endpoint, ip
WARNING  tenant_not_found      slug, ip
ERROR    webhook_invalid_sig   provider, ip, payload_hash
WARNING  access_denied         user_id, resource, action
```

### Channel `business`
```
INFO     reservation.created   id, room, guest, nights, amount
INFO     reservation.checkin   id, room, staff
INFO     reservation.checkout  id, room, staff, balance
INFO     reservation.cancelled id, reason, staff
INFO     payment.recorded      id, method, amount, invoice
INFO     invoice.generated     id, reservation, total
INFO     room.status_changed   id, from, to, staff
INFO     task.completed        id, room, staff, duration_minutes
```

### Channel `external`
```
INFO     paydunya.checkout_created  invoice_ref, amount
INFO     paydunya.webhook_received  token, status
ERROR    paydunya.webhook_failed    token, error
INFO     uploadcare.upload_success  uuid, file_size
ERROR    uploadcare.upload_failed   error, file_name
INFO     mailjet.email_sent         to, template, message_id
ERROR    mailjet.email_failed       to, template, error
```

---

## Sentry — Gestion des erreurs

**Service** : https://sentry.io — plan gratuit suffisant pour démarrer.

### Installation
```bash
composer require sentry/sentry-symfony
```

### Configuration (config/packages/sentry.yaml)
```yaml
sentry:
    dsn: '%env(SENTRY_DSN)%'
    options:
        environment: '%env(APP_ENV)%'
        release: '%env(APP_VERSION)%'
        # Ajouter le contexte tenant à chaque erreur
        before_send: 'App\Shared\Sentry\SentryBeforeSend'
    register_error_listener: true
    register_error_handler: true
```

### Contexte tenant dans Sentry
```php
// src/Shared/Sentry/SentryBeforeSend.php
// Ajoute automatiquement le tenant courant à chaque rapport d'erreur Sentry
// → facilite le debug en identifiant quel hôtel est affecté

\Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($tenant): void {
    $scope->setTag('tenant.slug', $tenant->getSlug());
    $scope->setTag('tenant.plan', $tenant->getSubscription()->getPlan()->getName());
    $scope->setContext('tenant', [
        'id'   => $tenant->getId(),
        'name' => $tenant->getName(),
    ]);
});
```

### Variables d'env
```bash
SENTRY_DSN=https://xxx@xxx.ingest.sentry.io/xxx
APP_VERSION=1.0.0   # Incrémenter à chaque déploiement
```

---

## Health Check — Endpoint complet

```php
// GET /api/health
// Vérifié par UptimeRobot toutes les 5 minutes
// Retourne 200 si tout est OK, 503 si un service est défaillant

#[Route('/api/health', name: 'api_health', methods: ['GET'])]
class HealthController
{
    public function __invoke(): JsonResponse
    {
        $checks = [];
        $healthy = true;

        // PostgreSQL
        try {
            $this->connection->executeQuery('SELECT 1');
            $checks['database'] = 'ok';
        } catch (\Exception $e) {
            $checks['database'] = 'error';
            $healthy = false;
        }

        // Redis
        try {
            $this->redis->ping();
            $checks['redis'] = 'ok';
        } catch (\Exception $e) {
            $checks['redis'] = 'error';
            $healthy = false;
        }

        // Messenger queue (vérifier la taille de la queue)
        $queueSize = $this->getMessengerQueueSize();
        $checks['queue'] = $queueSize < 100 ? 'ok' : 'warning';
        $checks['queue_size'] = $queueSize;

        // Mercure
        $checks['mercure'] = $this->isMercureAvailable() ? 'ok' : 'warning';

        return new JsonResponse([
            'status'  => $healthy ? 'ok' : 'error',
            'checks'  => $checks,
            'version' => $_ENV['APP_VERSION'] ?? '1.0.0',
            'env'     => $_ENV['APP_ENV'],
        ], $healthy ? 200 : 503);
    }
}
```

**Format attendu par UptimeRobot** : HTTP 200 = up, tout autre code = down.
Configurer une alerte SMS + email si le statut passe en down.

---

## Papertrail — Agrégation des logs Heroku

**Service** : https://papertrailapp.com — plan Faint (gratuit, 50 MB/mois) ou Fixa (7$/mois).

### Setup
```bash
# Ajouter le drain Heroku
heroku drains:add syslog+tls://logs.papertrailapp.com:XXXXX

# Ou via l'addon Heroku
heroku addons:create papertrail:choklad
```

### Recherches utiles dans Papertrail
```
# Erreurs critiques
level:error OR level:critical

# Tentatives de login échouées
"login_failed"

# Problèmes paiement
"paydunya" AND "error"

# Activité d'un tenant spécifique
"savana"

# Lenteurs (réponses > 1s)
"request_time" AND ms > 1000
```

### Alertes Papertrail à configurer
```
Alerte 1 : "level:critical" → email immédiat
Alerte 2 : "login_rate_limited" > 10/heure → email
Alerte 3 : "paydunya" AND "error" → email
Alerte 4 : "webhook_invalid_sig" > 5/heure → email (tentative d'attaque)
```

---

## UptimeRobot — Monitoring uptime

**Service** : https://uptimerobot.com — plan gratuit (50 moniteurs, check toutes les 5 min).

### Moniteurs à créer

| Nom | URL | Type | Alerte |
|---|---|---|---|
| StayOS API | https://stayos-api.herokuapp.com/api/health | HTTP(S) | SMS + email |
| StayOS Frontend | https://stayos.vercel.app | HTTP(S) | Email |
| Mercure SSE | https://stayos-mercure.herokuapp.com | HTTP(S) | Email |

### Status page publique
UptimeRobot permet de créer une page de statut publique :
`https://status.getstayos.com` → rassure les clients hôteliers en cas d'incident.

---

## Dashboard SuperAdmin — Métriques plateforme

Accessible sur `superadmin.getstayos.com` — vue opérateur en temps réel.

```
Métriques à afficher :
├── MRR (Monthly Recurring Revenue) total
├── Nombre de tenants actifs / en essai / suspendus
├── Nouveaux tenants (7 derniers jours)
├── Taux de churn (30 derniers jours)
├── Erreurs Sentry (dernières 24h)
├── Taille de la queue Messenger
├── Temps de réponse moyen API (P50/P95)
└── Derniers paiements SaaS reçus
```

---

## Alertes métier automatiques (Messenger)

```php
// Messages envoyés automatiquement par les services métier

// Si un paiement Paydunya échoue 3 fois de suite
→ SendEmailMessage(to: admin@getstayos.com, subject: 'Paiement échoué multiple', ...)

// Si la queue Messenger dépasse 50 messages en attente
→ Log warning + alerte Sentry

// Si un tenant n'a pas eu d'activité depuis 7 jours (pendant l'essai)
→ SendEmailMessage(to: tenant_admin, template: 'onboarding_nudge')

// Si l'abonnement expire dans 7 jours
→ SendEmailMessage(to: tenant_admin, template: 'subscription_expiring')

// Si l'abonnement expire dans 1 jour
→ SendEmailMessage(to: tenant_admin, template: 'subscription_expiring_urgent')
```
