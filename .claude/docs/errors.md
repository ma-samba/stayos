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

class ApiExceptionListener
{
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        $request   = $event->getRequest();

        // Ne traiter que les routes /api
        if (!str_starts_with($request->getPathInfo(), '/api')) return;

        [$status, $code, $message] = match(true) {
            $exception instanceof ValidationException      => [422, 'VALIDATION_ERROR', $exception->getMessage()],
            $exception instanceof NotFoundHttpException    => [404, 'NOT_FOUND', 'Ressource introuvable'],
            $exception instanceof AccessDeniedException    => [403, 'ACCESS_DENIED', 'Accès refusé'],
            $exception instanceof TenantNotFoundException  => [404, 'TENANT_NOT_FOUND', 'Hôtel introuvable'],
            $exception instanceof TenantSuspendedException => [402, 'TENANT_SUSPENDED', 'Abonnement suspendu'],
            $exception instanceof FeatureNotAvailableException => [403, 'PLAN_LIMIT', 'Fonctionnalité non disponible dans votre plan'],
            $exception instanceof ConflictException        => [409, 'CONFLICT', $exception->getMessage()],
            $exception instanceof TooManyRequestsHttpException => [429, 'RATE_LIMITED', 'Trop de requêtes. Réessayez dans un moment.'],
            $exception instanceof PaydunyaException        => [502, 'PAYMENT_GATEWAY_ERROR', 'Erreur de la passerelle de paiement'],
            $exception instanceof ExternalServiceException => [503, 'EXTERNAL_SERVICE_ERROR', 'Service externe temporairement indisponible'],
            default => [500, 'INTERNAL_ERROR', 'Une erreur interne est survenue'],
        };

        // Logger les 500 dans Sentry
        if ($status === 500) {
            $this->logger->error('Unexpected error', [
                'exception' => $exception->getMessage(),
                'trace'     => $exception->getTraceAsString(),
            ]);
        }

        $event->setResponse(new JsonResponse([
            'error'  => $message,
            'code'   => $code,
            'status' => $status,
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

## Exceptions personnalisées

```php
// src/Shared/Exception/
├── TenantNotFoundException.php
├── TenantSuspendedException.php
├── FeatureNotAvailableException.php
├── ConflictException.php           // Double réservation
├── PaydunyaException.php           // Erreur passerelle paiement
├── ExternalServiceException.php    // Erreur service externe générique
├── OtpInvalidException.php
├── OtpExpiredException.php
└── PlanLimitException.php          // Limite du plan atteinte
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
    'NOT_FOUND':                'Cette ressource n\'existe pas ou a été supprimée.',
    'ACCESS_DENIED':            'Vous n\'avez pas les droits pour cette action.',
    'PLAN_LIMIT':               'Cette fonctionnalité nécessite un plan supérieur.',
    'CONFLICT':                 'Cette chambre est déjà réservée pour ces dates.',
    'RATE_LIMITED':             'Trop de tentatives. Veuillez patienter.',
    'PAYMENT_GATEWAY_ERROR':    'La passerelle de paiement est indisponible. Réessayez.',
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
