# Services métier — Référence

Tous les services sont dans `backend/src/Hotel/{Domain}/Domain/Service/`
ou `backend/src/Platform/{Domain}/Domain/Service/`.
La logique métier ne va **JAMAIS** dans les controllers.

---

## Services Platform (SaaS)

### TenantProvisioner
Crée le schema PostgreSQL d'un nouvel hôtel.

```php
class TenantProvisioner
{
    // Crée le schema hotel_{uuid} et applique les migrations tenant
    public function provision(Tenant $tenant): void

    // Supprime le schema (résiliation)
    public function deprovision(Tenant $tenant): void

    // Initialise les données par défaut (RoomTypes standard, RatePlan de base)
    public function initializeDefaults(Tenant $tenant): void
}
```

### TenantResolver
Identifie le tenant depuis la requête HTTP.

```php
class TenantResolver
{
    // Extrait le slug depuis le subdomain : acacia.stayos.sn → "acacia"
    public function resolveFromRequest(Request $request): Tenant

    // Lève TenantNotFoundException si slug inconnu ou tenant suspendu
}
```

### FeatureGuard
Vérifie les droits selon le plan.

```php
class FeatureGuard
{
    // Vérifie si le tenant courant a accès à une feature
    public function can(string $feature): bool

    // Lève FeatureNotAvailableException si non autorisé
    public function require(string $feature): void

    // Vérifie une limite (ex: nombre de chambres)
    public function checkLimit(string $limitKey, int $current): bool
}

// Features disponibles :
// 'channel_manager'    → synchronisation OTA
// 'advanced_reports'   → rapports RevPAR, BI
// 'api_access'         → accès API REST externe
// 'multi_property'     → plusieurs hôtels
// 'revenue_management' → tarification dynamique
```

### AbonnementService
Gère les abonnements et essais.

```php
class AbonnementService
{
    // Crée l'essai gratuit à l'inscription (14 jours)
    public function createTrial(Tenant $tenant, Plan $plan): Subscription

    // Active après paiement confirmé
    public function activate(Subscription $subscription): void

    // Suspend (paiement échoué)
    public function suspend(Subscription $subscription): void

    // Vérifie les abonnements expirés (via Messenger, lancé quotidiennement)
    public function checkExpirations(): void

    // Retourne l'abonnement actif
    public function getActive(Tenant $tenant): ?Subscription
}
```

---

## Services Hotel (Métier)

### ReservationEngine
Cœur du moteur de réservation.

```php
class ReservationEngine
{
    // Vérifie la disponibilité d'une chambre sur une période
    // Retourne false si conflit (reservation CONFIRMED ou CHECKED_IN)
    public function isAvailable(Room $room, \DateTimeImmutable $checkIn, \DateTimeImmutable $checkOut): bool

    // Retourne toutes les chambres disponibles
    public function getAvailableRooms(
        \DateTimeImmutable $checkIn,
        \DateTimeImmutable $checkOut,
        int $adults,
        ?RoomType $type = null
    ): array

    // Crée une réservation (vérifie disponibilité + calcule prix)
    public function create(CreateReservationDTO $dto): Reservation

    // Enregistre le check-in (met room en OCCUPIED, crée task housekeeping départ)
    public function checkIn(Reservation $reservation): void

    // Enregistre le check-out (met room en CLEANING, génère facture si inexistante)
    public function checkOut(Reservation $reservation): void

    // Annule une réservation
    public function cancel(Reservation $reservation, string $reason): void
}
```

### PriceCalculator
Calcule les tarifs selon les règles en vigueur.

```php
class PriceCalculator
{
    // Prix par nuit pour une chambre à une date donnée
    // Prend en compte : RatePlan actif, SeasonalRate, Promotion
    public function getNightlyRate(Room $room, \DateTimeImmutable $date): string

    // Prix total d'un séjour
    public function getTotalRate(Room $room, \DateTimeImmutable $checkIn, \DateTimeImmutable $checkOut): string

    // Détail nuit par nuit
    public function getBreakdown(Room $room, \DateTimeImmutable $checkIn, \DateTimeImmutable $checkOut): array
}
```

### InvoiceService
Génère et gère les factures.

```php
class InvoiceService
{
    // Génère la facture d'une réservation
    // Lignes : nuitées, petit-déjeuner, extras, TVA (18%)
    public function generateFromReservation(Reservation $reservation): Invoice

    // Enregistre un paiement (Wave, Orange Money, Espèces...)
    public function recordPayment(Invoice $invoice, PaymentMethod $method, string $amountXof, ?string $reference): Payment

    // Calcule le solde restant
    public function getBalance(Invoice $invoice): string

    // Génère le PDF (via KnpSnappy)
    public function generatePdf(Invoice $invoice): string  // chemin du fichier
}
```

### HousekeepingService
Gère les tâches de ménage.

```php
class HousekeepingService
{
    // Crée les tâches du matin (toutes chambres avec départ aujourd'hui)
    public function generateDailyTasks(): void

    // Assigne une tâche à un membre du staff
    public function assign(CleaningTask $task, StaffUser $staff): void

    // Change le statut d'une tâche
    // → Si DONE : met la chambre en AVAILABLE (si pas de prochain check-in)
    public function updateStatus(CleaningTask $task, CleaningStatus $status): void

    // Retourne les tâches du jour groupées par statut
    public function getTasksForToday(): array

    // Retourne les tâches assignées à un membre du staff
    public function getTasksForStaff(StaffUser $staff): array
}
```

### DashboardService
Agrège les KPIs.

```php
class DashboardService
{
    // KPIs du jour
    public function getToday(): array
    // Retourne :
    // - occupancyRate (%) : chambres occupées / chambres totales
    // - arrivals (int) : check-ins prévus aujourd'hui
    // - departures (int) : check-outs prévus aujourd'hui
    // - revenue (string XOF) : CA du jour
    // - pendingTasks (int) : tâches ménage en attente
    // - unassignedTasks (int) : tâches sans assignation

    // Taux d'occupation sur une période
    public function getOccupancyRate(\DateTimeImmutable $from, \DateTimeImmutable $to): float

    // RevPAR (Revenue Per Available Room) = CA / chambres disponibles
    public function getRevPAR(\DateTimeImmutable $from, \DateTimeImmutable $to): string

    // Évolution du CA (12 derniers mois)
    public function getRevenueEvolution(): array

    // Répartition des sources de réservation
    public function getSourceBreakdown(): array
}
```

### GuestService
Gestion des clients.

```php
class GuestService
{
    // Recherche fulltext (nom, prénom, email, numéro de document)
    public function search(string $query, int $limit = 10): array

    // Historique complet des séjours d'un client
    public function getHistory(Guest $guest): array

    // Crée ou retrouve un client (évite les doublons par email/document)
    public function findOrCreate(array $data): Guest
}
```

### ChannelSyncService
Synchronisation avec les OTA (Booking.com, Airbnb...).

```php
class ChannelSyncService
{
    // Pousse les disponibilités vers tous les channels actifs
    public function pushAvailability(\DateTimeImmutable $from, \DateTimeImmutable $to): void

    // Pousse les tarifs
    public function pushRates(\DateTimeImmutable $from, \DateTimeImmutable $to): void

    // Importe les nouvelles réservations depuis un channel
    public function pullReservations(string $channel): int  // nombre importées

    // Traite un webhook entrant (Booking.com, Airbnb...)
    public function handleWebhook(string $channel, array $payload): void
}
```

---

## Messages Messenger (src/Message/)

```php
// Traitement asynchrone
App\Message\SendEmailMessage            // Envoi email (confirmation, facture)
App\Message\SendSmsMessage              // SMS (confirmation, rappel)
App\Message\GenerateInvoicePdfMessage   // Génération PDF facture
App\Message\SyncChannelMessage          // Sync OTA
App\Message\CheckSubscriptionsMessage   // Vérification expirations (quotidien)
App\Message\GenerateDailyTasksMessage   // Tâches ménage (chaque matin à 7h)
App\Message\PublishMercureMessage       // Notification temps réel
```

## Injection des services

```php
// Autowiring automatique — injecter via le constructeur :
public function __construct(
    private ReservationEngine  $reservationEngine,
    private InvoiceService     $invoiceService,
    private HousekeepingService $housekeepingService,
) {}
```
