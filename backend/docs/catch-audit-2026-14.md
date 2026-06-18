# Audit des catch (\Throwable) — Sprint 14-A.1

Date : 2026-06-11
Volume audité : 31 occurrences dans `backend/src/`

## Synthèse

- TYPE 1 (silencieux corrigés en TYPE 2) : **4 cas**
- TYPE 2 (loggé, intentionnellement non propagé) : **2 cas**
- TYPE 3 (rethrow, transformation en réponse HTTP ou retour neutre + log) : **25 cas**

Aucun catch silencieux ne subsiste après le chantier.

## Inventaire détaillé

| # | Fichier:ligne | Méthode | Type | Action |
|---|---|---|---|---|
| 1 | `Hotel/Housekeeping/Application/Command/GenerateHousekeepingTasksCommand.php:64` | `execute` | 3 | RAS — `$io->error()` + `return FAILURE` |
| 2 | `Hotel/Housekeeping/Application/Command/GenerateHousekeepingTasksCommand.php:122` | `execute` | 3 | RAS — `$io->error()` + finally restore search_path |
| 3 | `Hotel/Room/Domain/Service/RoomService.php:272` | `bulkCreate` | 3 | RAS — rollback + rethrow |
| 4 | `Hotel/Notification/Application/MessageHandler/PublishDailyAlertsHandler.php:69` | `__invoke` | 3 | RAS — `logger->error()` + finally restore search_path |
| 5 | `Hotel/Notification/Application/Command/PublishDailyAlertsCommand.php:131` | `execute` | 3 | RAS — `$io->error()` + finally restore search_path |
| 6 | `Hotel/Reservation/Domain/Service/ReservationEngine.php:571` | `checkOut` | 3 | RAS — `logger->error()` (génération facture draft non-bloquante) |
| 7 | `Hotel/Billing/Infrastructure/Gateway/PaydunyaGateway.php:99` | `createCheckout` | 3 | RAS — `logger->error()` + retour `PaymentCheckoutResult(ok: false)` |
| 8 | `Hotel/Billing/Infrastructure/Gateway/PaydunyaGateway.php:146` | `confirmPayment` | 3 | RAS — `logger->error()` + retour `PaymentConfirmation(ok: false)` |
| 9 | `Hotel/Billing/Domain/Service/PaydunyaWebhookHandler.php:111` | `handle` | 3 | RAS — `logger->error()` + finally restore search_path |
| 10 | `Hotel/Billing/Domain/Service/PaydunyaWebhookHandler.php:264` | `processPayment` | 3 | RAS — `logger->warning('IPN: invoice email failed (non-blocking)')` |
| 11 | `Platform/Tenant/Application/Command/MigrateTenantsCommand.php:101` | `execute` | 3 | RAS — `$io->error()` + `return FAILURE` |
| 12 | `Platform/Tenant/Application/Command/CleanupOrphanSchemasCommand.php:174` | `execute` | 3 | RAS — `$io->error()` (CLI visible) |
| 13 | `Platform/Tenant/Application/Command/ProvisionTenantCommand.php:78` | `execute` | 3 | RAS — `$io->error()` + `return FAILURE` |
| 14 | `Platform/Auth/Infrastructure/Security/JWTCreatedListener.php:48` | `onJWTCreated` | 3 | RAS — log error détaillé puis rethrow |
| 15 | `Platform/Subscription/Application/MessageHandler/CheckSubscriptionsHandler.php:43` | `__invoke` | 2 | RAS — log error, non-propagé volontairement (évite retry Messenger sur batch partiel) |
| 16 | `Platform/Subscription/Domain/Service/AbonnementService.php:386` | `checkExpirations` | 2 | RAS — log error par tenant, on continue avec les autres tenants |
| 17 | `Platform/Subscription/Domain/Service/SubscriptionEmailService.php:187` | `sendEmail` | 3 | RAS — `logger->error()` + retour `false` |
| 18 | `Platform/Subscription/Domain/Service/SubscriptionEmailService.php:235` | `findManager` | 3 | RAS — `logger->error()` + retour `null` + finally restore search_path |
| 19 | `Shared/Mercure/MercurePublisher.php:51` | `publish` | 3 | RAS — fix Sprint 11 : `logger->warning('Mercure publish failed', ['class' => ...])` |
| 20 | `Shared/Email/EmailService.php:69` | `sendInvoice` | 3 | RAS — `logger->error()` + retour `false` |
| 21 | `Shared/Email/EmailService.php:124` | `sendStaffInvitation` | 3 | RAS — `logger->error()` + retour `false` |
| 22 | `Controller/SuperAdmin/SuperAdminController.php:460` | `forcePlan` | 3 | RAS — `jsonError(VALIDATION_ERROR, 422)` |
| 23 | `Controller/Api/CleaningTaskController.php:53` | `board` | 3 | RAS — `jsonError(VALIDATION_ERROR, 422)` |
| 24 | `Controller/Api/PaydunyaWebhookController.php:71` | `__invoke` | 3 | RAS — `logger->error()` puis retour `200 OK` (volontaire, ne jamais retourner d'erreur à Paydunya) |
| 25 | `Controller/Api/ReservationController.php:64` | `gantt` | 3 | RAS — `jsonError(VALIDATION_ERROR, 422)` |
| 26 | `Controller/Api/SubscriptionController.php:194` | `computeUsage` (rooms) | **1 → 2** | **Ajout `logger->warning('Subscription usage: rooms count failed, defaulting to 0', [...])`** |
| 27 | `Controller/Api/SubscriptionController.php:203` | `computeUsage` (staff_users) | **1 → 2** | **Ajout `logger->warning('Subscription usage: staff_users count failed, defaulting to 0', [...])`** |
| 28 | `Controller/Api/InvoiceController.php:229` | `send` | 3 | RAS — `logger->error()` + `jsonError(EXTERNAL_SERVICE_ERROR, 503)` |
| 29 | `Controller/Api/RoomController.php:65` | `available` | 3 | RAS — `jsonError(VALIDATION_ERROR, 422)` |
| 30 | `Controller/Api/HealthController.php:31` | `__invoke` (DB probe) | **1 → 2** | **Ajout `logger->error('Health check: database probe failed', [...])`** |
| 31 | `Controller/Api/HealthController.php:39` | `__invoke` (Redis probe) | **1 → 2** | **Ajout `logger->error('Health check: redis probe failed', [...])`** |

## Cas notables

### MercurePublisher::publish (rappel Sprint 11)
Déjà corrigé. Le `catch (\Throwable $e)` log un WARNING avec `['topic', 'error', 'class']`. RAS.

### SubscriptionController::computeUsage (cas 26 + 27) — TYPE 1 → TYPE 2

**Avant** :
```php
try {
    $rooms = (int) $this->connection->fetchOne(
        'SELECT COUNT(*) FROM rooms WHERE is_active = TRUE'
    );
    $usage['rooms'] = $rooms;
} catch (\Throwable) {
    // Schema vide ou table absente → on laisse 0 silencieusement
}
```

**Après** :
```php
try {
    $rooms = (int) $this->connection->fetchOne(
        'SELECT COUNT(*) FROM rooms WHERE is_active = TRUE'
    );
    $usage['rooms'] = $rooms;
} catch (\Throwable $e) {
    $this->logger->warning('Subscription usage: rooms count failed, defaulting to 0', [
        'error' => $e->getMessage(),
        'class' => $e::class,
        'tenant' => $this->tenantContext->has() ? $this->tenantContext->get()->getSlug() : null,
    ]);
}
```

Idem pour le compteur `staff_users` (catch suivant).

**Raison** : le commentaire `// Schema vide ou table absente` suggère un cas connu (onboarding en cours), mais sans log, une vraie panne SQL (timeout, lock, perte de connexion) afficherait silencieusement 0 chambre/utilisateur dans l'UI d'abonnement — donnée critique pour la facturation SaaS. Un `LoggerInterface` est désormais injecté dans le contrôleur.

### HealthController::__invoke (cas 30 + 31) — TYPE 1 → TYPE 2

**Avant** :
```php
try {
    $this->connection->executeQuery('SELECT 1');
    $checks['database'] = 'ok';
} catch (\Throwable) {
    $checks['database'] = 'error';
    $healthy = false;
}
```

**Après** :
```php
try {
    $this->connection->executeQuery('SELECT 1');
    $checks['database'] = 'ok';
} catch (\Throwable $e) {
    $this->logger->error('Health check: database probe failed', [
        'error' => $e->getMessage(),
        'class' => $e::class,
    ]);
    $checks['database'] = 'error';
    $healthy = false;
}
```

Idem pour la sonde Redis.

**Raison** : UptimeRobot ping toutes les 5 minutes. En cas d'incident, la réponse `503` est visible côté monitoring externe, mais aucune trace serveur n'existait pour corréler avec les logs Doctrine/Redis. L'ajout d'un `logger->error` avec la classe d'exception permet désormais de discriminer dans Papertrail entre une `Doctrine\DBAL\Exception\ConnectionException` (DB injoignable), un `Doctrine\DBAL\Exception\DriverException` (creds), un `RedisException` (timeout), etc. — diagnostic accéléré pendant l'incident.

## Cas TYPE 2 acceptés (silence justifié, log présent)

| Cas | Justification |
|---|---|
| 15 — `CheckSubscriptionsHandler::__invoke` | Le scheduler tourne en batch nocturne. Un échec global (DB down, mailer down) est loggé puis avalé pour ne pas déclencher le retry Messenger sur un batch partiellement exécuté (risque de double envoi d'emails de relance). |
| 16 — `AbonnementService::checkExpirations` | Boucle sur tous les tenants. Une erreur sur un tenant ne doit pas arrêter le traitement des autres. Le compteur `$stats['errors']` remonte l'incident dans le log final agrégé. |

## Recommandations pour la suite

- **Ajouter une règle PHPStan custom** ou un test reflection-based qui scanne `backend/src/` à la recherche de `catch (...)` dont le corps ne contient ni `$logger->`, ni `throw`. À planifier au sprint 14-B ou polish. Cela bloquerait toute régression dans les futurs PRs.
- **Étendre l'audit au frontend** : les `try { ... } catch { /* noop */ }` (notamment dans `api.service.ts`) pourraient masquer des erreurs UX. Hors périmètre 14-A.1.
