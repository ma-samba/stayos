# Gestion des erreurs — Référence complète

---

## Principe général

**Règle** : une erreur dans un service externe ne doit jamais faire planter l'expérience utilisateur principal.
Les fonctions critiques (réservation, check-in, facturation) doivent fonctionner même si Mailjet ou Uploadcare sont indisponibles.

```
Service externe indisponible → Logger l'erreur → Fallback gracieux → Retry async via Messenger
Erreur métier              → JsonResponse avec code d'erreur explicite
Erreur inattendue          → Sentry → JsonResponse générique 500
```

---

## ExceptionListener global

```php
// src/Shared/EventListener/ApiExceptionListener.php
// Transforme toutes les exceptions en JsonResponse standardisée
// Intercepte les routes /api et /superadmin

#[AsEventListener(event: KernelEvents::EXCEPTION)]
class ApiExceptionListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        [$status, $code, $message] = match (true) {
            $exception instanceof TenantNotFoundException      => [404, 'NOT_FOUND',        $exception->getMessage()],
            $exception instanceof TenantSuspendedException     => [402, 'TENANT_SUSPENDED', $exception->getMessage()],
            $exception instanceof OtpInvalidException          => [422, 'VALIDATION_ERROR', $exception->getMessage()],
            $exception instanceof AlreadyExistsException       => [409, 'ALREADY_EXISTS',   $exception->getMessage()],
            $exception instanceof ConflictException            => [409, 'CONFLICT',         $exception->getMessage()],
            $exception instanceof BusinessRuleException        => [422, 'BUSINESS_RULE',    $exception->getMessage()],
            $exception instanceof FeatureNotAvailableException => [403, 'PLAN_LIMIT',       $exception->getMessage()],
            $exception instanceof AccessDeniedHttpException    => [403, 'ACCESS_DENIED',    'Accès refusé.'],
            $exception instanceof NotFoundHttpException        => [404, 'NOT_FOUND',        'Ressource introuvable.'],
            $exception instanceof TooManyRequestsHttpException => [429, 'RATE_LIMITED',     'Trop de requêtes. Réessayez dans un moment.'],
            $exception instanceof HttpExceptionInterface       => [$exception->getStatusCode(), 'HTTP_ERROR', $exception->getMessage()],
            default                                            => [500, 'INTERNAL_ERROR',   'Une erreur interne est survenue.'],
        };

        // Logger les erreurs 500 inattendues
        if ($status === 500) {
            $this->logger->error('Unexpected API error', [...]);
        }

        $event->setResponse(new JsonResponse([
            'error' => $message, 'code' => $code, 'status' => $status,
        ], $status));
    }
}
```

---

## Circuit Breaker — Services externes

Quand un service externe est en panne, éviter de le bombarder de requêtes.

### Paydunya
```php
class PaydunyaService
{
    public function createCheckout(...): array
    {
        try {
            $response = $this->httpClient->request('POST', $this->apiUrl . '/checkout', [
                'timeout' => 10,  // Timeout 10 secondes
                'json'    => [...],
            ]);
            return $response->toArray();

        } catch (TransportException $e) {
            // Logger + alerter
            $this->logger->error('Paydunya unreachable', ['error' => $e->getMessage()]);

            // Lancer une exception métier (pas laisser une 500 générique)
            throw new PaydunyaException('La passerelle de paiement est temporairement indisponible. Veuillez réessayer.');
        } catch (ClientException $e) {
            $this->logger->error('Paydunya API error', [
                'status'   => $e->getResponse()->getStatusCode(),
                'response' => $e->getResponse()->getContent(false),
            ]);
            throw new PaydunyaException('Erreur lors de la création du paiement.');
        }
    }
}
```

### Mailjet — Fallback sur queue
```php
class EmailService
{
    public function send(Email $email): void
    {
        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            // Mailjet indisponible → mettre en queue pour retry
            $this->logger->error('Mailjet send failed, queuing for retry', [
                'to'    => $email->getTo(),
                'error' => $e->getMessage(),
            ]);

            // Dispatcher le message pour retry dans 5 minutes
            $this->messageBus->dispatch(
                new SendEmailMessage($email),
                [new DelayStamp(300000)] // 5 minutes
            );
        }
    }
}
```

### Uploadcare — Fallback sur placeholder
```php
class UploadcareService
{
    public function getImageUrl(string $uuid, string $transform = ''): string
    {
        if (!$uuid) {
            // Image placeholder si pas d'upload
            return '/images/placeholder-room.jpg';
        }
        return "https://ucarecdn.com/{$uuid}/{$transform}";
    }
}
// Ne jamais lancer d'exception si une image est manquante — afficher un placeholder
```

---

## Hiérarchie des exceptions applicatives

Le mapping ci-dessous correspond **exactement** au `match()` de
`ApiExceptionListener` (`src/Shared/EventListener/ApiExceptionListener.php`).

| Exception | Code API | Status | Quand l'utiliser |
|---|---|---|---|
| `TenantNotFoundException` | `NOT_FOUND` | 404 | Subdomain inconnu ou tenant introuvable |
| `TenantSuspendedException` | `TENANT_SUSPENDED` | 402 | Tenant suspendu (abonnement impayé) |
| `OtpInvalidException` | `VALIDATION_ERROR` | 422 | Code OTP invalide ou expiré |
| `AlreadyExistsException` | `ALREADY_EXISTS` | 409 | Doublon (email client déjà utilisé, etc.) |
| `ConflictException` | `CONFLICT` | 409 | Conflit de ressource (chevauchement de réservation, etc.) |
| `BusinessRuleException` | `BUSINESS_RULE` | 422 | Règle métier violée (transition d'état interdite, action impossible dans l'état courant) |
| `FeatureNotAvailableException` | `PLAN_LIMIT` | 403 | Fonctionnalité hors plan d'abonnement |
| `AccessDeniedHttpException` (Symfony) | `ACCESS_DENIED` | 403 | Droits insuffisants (RBAC) |
| `NotFoundHttpException` (Symfony) | `NOT_FOUND` | 404 | Ressource introuvable (ParamConverter, etc.) |
| `TooManyRequestsHttpException` (Symfony) | `RATE_LIMITED` | 429 | Limite de requêtes atteinte |
| Autre `HttpExceptionInterface` | `HTTP_ERROR` | variable | Erreur HTTP Symfony non spécifique |
| (toute autre exception) | `INTERNAL_ERROR` | 500 | Erreur non gérée — **Sentry alerté** |

**Ne jamais lever `\LogicException`, `\RuntimeException` ou `\Exception` brute
pour une erreur métier prévisible : elle tombera en 500 `INTERNAL_ERROR`.
Utiliser `BusinessRuleException` (422) ou `ConflictException` (409) selon le cas.**

### Fichiers réels (src/Shared/Exception/)

```
TenantNotFoundException.php
TenantSuspendedException.php
OtpInvalidException.php
AlreadyExistsException.php
ConflictException.php
BusinessRuleException.php
FeatureNotAvailableException.php
```

---

## Retry strategy — Messenger

```yaml
# config/packages/messenger.yaml
transports:
    async:
        dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
        retry_strategy:
            max_retries: 3
            delay: 1000        # 1 seconde
            multiplier: 3      # 1s, 3s, 9s
            max_delay: 30000   # max 30 secondes
```

```php
// Messages avec retry automatique :
// SendEmailMessage      → retry 3x si Mailjet indisponible
// SyncChannelMessage    → retry 3x si OTA indisponible
// GenerateInvoicePdfMessage → retry 2x

// Après 3 échecs → transport 'failed' (doctrine queue)
// Inspecter avec : make worker-failed
// Rejouer avec   : php bin/console messenger:failed:retry
```

---

## Réponses d'erreur frontend

```typescript
// src/shared/composables/useApi.ts
// Interceptor Axios qui transforme les erreurs API en messages utilisateur

const ERROR_MESSAGES: Record<string, string> = {
    'VALIDATION_ERROR':         'Les données saisies sont invalides.',
    'BUSINESS_RULE':            'Action impossible dans l\'état actuel.',
    'NOT_FOUND':                'Cette ressource n\'existe pas ou a été supprimée.',
    'ACCESS_DENIED':            'Vous n\'avez pas les droits pour cette action.',
    'PLAN_LIMIT':               'Cette fonctionnalité nécessite un plan supérieur.',
    'ALREADY_EXISTS':           'Cette ressource existe déjà.',
    'CONFLICT':                 'Cette chambre est déjà réservée pour ces dates.',
    'RATE_LIMITED':             'Trop de tentatives. Veuillez patienter.',
    'TENANT_SUSPENDED':         'Votre abonnement est suspendu. Contactez le support.',
    'INTERNAL_ERROR':           'Une erreur est survenue. Notre équipe a été notifiée.',
}

api.interceptors.response.use(
    res => res,
    err => {
        const code = err.response?.data?.code ?? 'INTERNAL_ERROR'
        const message = ERROR_MESSAGES[code] ?? ERROR_MESSAGES['INTERNAL_ERROR']

        // Afficher un toast d'erreur
        useNotificationStore().error(message)

        // Logger dans Sentry si erreur inattendue
        if (err.response?.status === 500) {
            Sentry.captureException(err)
        }

        return Promise.reject(err)
    }
)
```

---

## Refresh token silencieux (JWT)

```typescript
// src/services/api.service.ts
// Renouveler le JWT automatiquement avant qu'il expire
// → évite que l'hôtelier soit déconnecté en plein check-in

let isRefreshing = false
let failedQueue: any[] = []

api.interceptors.response.use(
    res => res,
    async err => {
        const originalRequest = err.config

        if (err.response?.status === 401 && !originalRequest._retry) {
            if (isRefreshing) {
                // Mettre en file d'attente et rejouer après le refresh
                return new Promise((resolve, reject) => {
                    failedQueue.push({ resolve, reject })
                })
            }

            originalRequest._retry = true
            isRefreshing = true

            try {
                const refreshToken = localStorage.getItem('refresh_token')
                const { data } = await axios.post('/api/auth/refresh', { refresh_token: refreshToken })

                localStorage.setItem('token', data.token)
                api.defaults.headers.common['Authorization'] = `Bearer ${data.token}`

                // Rejouer les requêtes en attente
                failedQueue.forEach(p => p.resolve(api(p.config)))
                return api(originalRequest)
            } catch {
                // Refresh échoué → logout
                useAuthStore().logout()
                return Promise.reject(err)
            } finally {
                isRefreshing = false
                failedQueue = []
            }
        }

        return Promise.reject(err)
    }
)
```
