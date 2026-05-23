# Entités Doctrine — Référence complète

## Vue d'ensemble

| Schema | Domaine | Entités |
|---|---|---|
| `public` | Platform SaaS | `Tenant`, `Plan`, `Subscription`, `SaasInvoice`, `SaasPayment` |
| `public` | Auth | `User` (admin plateforme uniquement) |
| `hotel_{uuid}` | Hôtel | `HotelProfile`, `Floor`, `RoomType`, `Room` |
| `hotel_{uuid}` | Auth locale | `StaffUser` |
| `hotel_{uuid}` | Réservations | `Reservation`, `ReservationRoom` |
| `hotel_{uuid}` | Clients | `Guest`, `GuestDocument` |
| `hotel_{uuid}` | Facturation | `Invoice`, `InvoiceLine`, `Payment` |
| `hotel_{uuid}` | Housekeeping | `CleaningTask` |
| `hotel_{uuid}` | Tarifs | `RatePlan`, `SeasonalRate`, `Promotion` |
| `hotel_{uuid}` | Channel | `ChannelMapping` |

## Règles Doctrine

- Entités Platform → annotations `#[ORM\Table(schema: 'public')]`
- Entités Hotel → pas d'annotation schema (le search_path le gère)
- `createdAt` initialisé dans `__construct()`
- `updatedAt` via `#[ORM\PreUpdate]` + `#[ORM\HasLifecycleCallbacks]`
- Montants financiers en `DECIMAL(10,2)` → type PHP `string` (jamais float)
- Dates en `\DateTimeImmutable`
- Statuts via **enums PHP 8.1** (backed by string)

## Contraintes CHECK SQL sur les colonnes-enum

Ces colonnes ont une contrainte CHECK PostgreSQL (définie dans
`CreateHotelTables` + migrations tenant ultérieures). Toute nouvelle
valeur d'enum PHP nécessite une migration tenant qui recrée la
contrainte, sinon l'INSERT est rejeté en SQLSTATE 23514.

| Table.colonne | Valeurs autorisées (CHECK) |
|---|---|
| `rooms.status` | `available`, `occupied`, `cleaning`, `maintenance`, `out_of_order` |
| `reservations.status` | `confirmed`, `pending`, `checked_in`, `checked_out`, `cancelled`, `no_show` |
| `reservations.source` | `direct`, `booking_com`, `airbnb`, `expedia`, `walk_in` |
| `invoices.status` | `draft`, `issued`, `paid`, `partial`, `cancelled` |
| `payments.method` | `cash`, `wave`, `orange_money`, `card`, `bank_transfer`, `mobile_money`, `ota` |
| `cleaning_tasks.status` | `pending`, `in_progress`, `done`, `inspected`, `skipped` |
| `cleaning_tasks.type` | `departure`, `stay_over`, `inspection`, `maintenance` |

---

## SCHEMA PUBLIC — Entités Platform

### Tenant
```php
id (uuid), slug (unique), name, status (TenantStatus enum),
subdomain (unique), timezone (default:'Africa/Dakar'),
country (default:'SN'), currency (default:'XOF'),
settings (JSON), createdAt, updatedAt

// TenantStatus : TRIAL | ACTIVE | SUSPENDED | CHURNED
// slug → identifie le tenant via le subdomain : {slug}.stayos.sn
```

### Plan
```php
id, name (STARTER|PRO|ENTERPRISE), priceXof (DECIMAL), priceEur (DECIMAL),
maxRooms (int, null = illimité), maxUsers (int, null = illimité),
features (JSON array), isActive (bool)

// features : ['channel_manager', 'advanced_reports', 'api_access',
//             'multi_property', 'revenue_management']
```

### Subscription
```php
id, tenant (ManyToOne), plan (ManyToOne),
status (SubscriptionStatus enum), billingCycle (MONTHLY|ANNUAL),
trialEndsAt, currentPeriodStart, currentPeriodEnd,
cancelledAt, createdAt

// SubscriptionStatus : TRIAL | ACTIVE | EXPIRED | SUSPENDED | CANCELLED
```

### SaasInvoice
```php
id, tenant, subscription, amount (DECIMAL XOF), status (PENDING|PAID|FAILED),
dueAt, paidAt, reference (unique), createdAt
```

### User (super admin plateforme)
```php
id, email (unique), password (hashé), roles (JSON),
name, active (bool), lastLoginAt, createdAt

// Roles : ROLE_SUPER_ADMIN uniquement pour ce User
// Le staff de l'hôtel est dans StaffUser (schema hotel)
```

---

## SCHEMA hotel_{uuid} — Entités métier

### HotelProfile
```php
id, name, address, city, country, phone, email,
starRating (int 1-5), totalRooms (int), checkInTime, checkOutTime,
logoPath, settings (JSON), createdAt, updatedAt
```

### Floor
```php
id, hotel (ManyToOne HotelProfile), number (int), name, active (bool)
```

### RoomType
```php
id, hotel, name, description, baseRateXof (DECIMAL),
maxOccupancy (int), bedConfiguration (JSON), amenities (JSON),
sortOrder (int)

// bedConfiguration : {"beds": [{"type": "king", "count": 1}]}
// amenities : ["wifi", "ac", "minibar", "safe", "sea_view"]
```

### Room
```php
id, hotel, floor (ManyToOne), type (ManyToOne RoomType),
number (string unique), status (RoomStatus enum),
notes, isActive (bool), createdAt

// RoomStatus : AVAILABLE | OCCUPIED | CLEANING | MAINTENANCE | OUT_OF_ORDER
```

### StaffUser
```php
id, email (unique dans le schema), password (hashé),
firstName, lastName, role (StaffRole enum), phone,
active (bool), lastLoginAt, createdAt

// StaffRole : MANAGER | RECEPTIONIST | HOUSEKEEPER | ACCOUNTANT
```

### Guest
```php
id, firstName, lastName, email, phone, nationality (ISO-2),
documentType (PASSPORT|ID_CARD|RESIDENCE_PERMIT),
documentNumber, dateOfBirth,
address, city, country,
preferences (JSON), totalStays (int), createdAt, updatedAt

// preferences : {"floor": "high", "pillow": "firm", "smoking": false}
```

### Reservation
```php
id, confirmationNumber (unique, ex: RES-2026-04821),
guest (ManyToOne), room (ManyToOne),
status (ReservationStatus enum),
checkIn (DateTimeImmutable, date uniquement),
checkOut (DateTimeImmutable, date uniquement),
adults (int), children (int),
rateXof (DECIMAL, tarif par nuit),
totalXof (DECIMAL, total séjour),
source (DIRECT|BOOKING_COM|AIRBNB|EXPEDIA|WALK_IN),
depositXof (DECIMAL, nullable),
notes, specialRequests,
checkedInAt, checkedOutAt, createdAt, updatedAt

// ReservationStatus : CONFIRMED | PENDING | CHECKED_IN | CHECKED_OUT
//                     CANCELLED | NO_SHOW

// Méthodes calculées (PAS en BDD) :
getNights()         // (checkOut - checkIn)->days
getTotalXof()       // nights * rateXof
getBalanceXof()     // totalXof - montants payés
```

### Invoice
```php
id, reservation (ManyToOne), number (unique, ex: FAC-2026-00142),
status (DRAFT|ISSUED|PAID|PARTIAL|CANCELLED),
subtotalXof (DECIMAL), taxRate (DECIMAL, default:18.00),
taxXof (DECIMAL), totalXof (DECIMAL),
notes, issuedAt, dueAt, createdAt
```

### InvoiceLine
```php
id, invoice (ManyToOne), label, quantity (int),
unitPriceXof (DECIMAL), totalXof (DECIMAL), sortOrder (int)
```

### Payment
```php
id, invoice (ManyToOne), method (PaymentMethod enum),
amountXof (DECIMAL), reference (nullable),
processedAt, notes

// PaymentMethod : CASH | WAVE | ORANGE_MONEY | CARD | BANK_TRANSFER | OTA
```

### CleaningTask
```php
id, room (ManyToOne), assignedTo (ManyToOne StaffUser, nullable),
status (CleaningStatus enum), type (DEPARTURE|STAY_OVER|INSPECTION|MAINTENANCE),
scheduledAt (DateTimeImmutable), startedAt, completedAt, notes

// CleaningStatus : PENDING | IN_PROGRESS | DONE | INSPECTED | SKIPPED
```

### RatePlan
```php
id, hotel (ManyToOne HotelProfile), roomType (ManyToOne),
name, baseRateXof (DECIMAL), minNights (int, default:1),
conditions (JSON), isActive (bool), validFrom, validTo
```

### ChannelMapping
```php
id, room (ManyToOne), channel (BOOKING_COM|AIRBNB|EXPEDIA),
externalRoomId, externalRoomTypeId, isActive (bool),
lastSyncAt, syncSettings (JSON)
```

---

## Relations importantes

```
Tenant (public) ──< Subscription >── Plan
Tenant (public) ──< SaasInvoice

// Dans le schema hotel_{uuid} :
HotelProfile ──< Floor ──< Room >── RoomType
Room ──< Reservation >── Guest
Reservation ──< Invoice ──< InvoiceLine
Invoice ──< Payment
Room ──< CleaningTask >── StaffUser
RoomType ──< RatePlan
Room ──< ChannelMapping
```

## Namespaces
```php
// Platform
use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Platform\Subscription\Domain\Entity\Plan;
use App\Platform\Subscription\Domain\Entity\Subscription;

// Hotel
use App\Hotel\Room\Domain\Entity\Room;
use App\Hotel\Reservation\Domain\Entity\Reservation;
use App\Hotel\Guest\Domain\Entity\Guest;
use App\Hotel\Billing\Domain\Entity\Invoice;
use App\Hotel\Housekeeping\Domain\Entity\CleaningTask;
```

## Enums PHP 8.1

```php
// src/Hotel/Room/Domain/Enum/RoomStatus.php
enum RoomStatus: string {
    case AVAILABLE   = 'available';
    case OCCUPIED    = 'occupied';
    case CLEANING    = 'cleaning';
    case MAINTENANCE = 'maintenance';
    case OUT_OF_ORDER = 'out_of_order';
}

// src/Hotel/Reservation/Domain/Enum/ReservationStatus.php
enum ReservationStatus: string {
    case CONFIRMED   = 'confirmed';
    case PENDING     = 'pending';
    case CHECKED_IN  = 'checked_in';
    case CHECKED_OUT = 'checked_out';
    case CANCELLED   = 'cancelled';
    case NO_SHOW     = 'no_show';
}

// src/Platform/Tenant/Domain/Enum/TenantStatus.php
enum TenantStatus: string {
    case TRIAL     = 'trial';
    case ACTIVE    = 'active';
    case SUSPENDED = 'suspended';
    case CHURNED   = 'churned';
}
```
