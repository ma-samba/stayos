# Fixtures & Tests — Référence

## DataFixtures (backend/src/DataFixtures/)

L'ordre de chargement est géré par `getDependencies()`.

```
AppFixtures (orchestre)
├── PlanFixtures              → Plans SaaS (Starter, Pro, Enterprise)
├── SuperAdminFixtures        → Compte super admin plateforme
├── TenantFixtures            → 2 hôtels de démo (provision schema PG)
├── SubscriptionFixtures      → Abonnements (essai + actif)
├── StaffUserFixtures         → Staff avec rôles variés
├── HotelProfileFixtures      → Profils hôtels
├── FloorFixtures             → Étages
├── RoomTypeFixtures          → Types de chambres
├── RoomFixtures              → Chambres avec statuts variés
├── GuestFixtures             → Clients variés (sénégalais, français, américains)
├── ReservationFixtures       → Réservations (en cours, passées, à venir)
├── InvoiceFixtures           → Factures (payées, partielles, en attente)
├── PaymentFixtures           → Paiements (Wave, Orange Money, espèces)
└── CleaningTaskFixtures      → Tâches ménage du jour
```

## Comptes de démonstration

```
── SUPER ADMIN PLATEFORME ──
Email    : superadmin@stayos.sn
Password : superadmin123
Rôle     : ROLE_SUPER_ADMIN
URL      : http://superadmin.localhost:8080

── HÔTEL 1 : Hôtel Savana Dakar ──
Subdomain : savana.localhost
Email admin : admin@savana-hotel.sn
Password    : admin123
Rôle        : MANAGER
Plan        : Pro (toutes features)

Email réception : reception@savana-hotel.sn
Password        : recep123
Rôle            : RECEPTIONIST

── HÔTEL 2 : Villa Collines Saly ──
Subdomain : villa-collines.localhost
Email admin : admin@villa-collines.sn
Password    : admin123
Rôle        : MANAGER
Plan        : Starter (features limitées)
```

## Données de démo (Hôtel Savana)

```
Chambres   : 20 (Standard x8, Deluxe x8, Suite x4)
Statuts    : 12 occupées, 4 disponibles, 3 ménage, 1 maintenance
Clients    : 30 (dont 5 VIP avec historique)
Réservations en cours : 12
Arrivées aujourd'hui  : 4
Départs aujourd'hui   : 3
Taux occupation       : 80%
```

## Exemple de Fixture

```php
// src/DataFixtures/ReservationFixtures.php
class ReservationFixtures extends Fixture implements DependentFixtureInterface
{
    public function getDependencies(): array
    {
        return [RoomFixtures::class, GuestFixtures::class];
    }

    public function load(ObjectManager $manager): void
    {
        // Réservation en cours (check-in aujourd'hui)
        $reservation = new Reservation();
        $reservation
            ->setConfirmationNumber('RES-2026-04821')
            ->setRoom($this->getReference('room-312', Room::class))
            ->setGuest($this->getReference('guest-amadou-diallo', Guest::class))
            ->setStatus(ReservationStatus::CHECKED_IN)
            ->setCheckIn(new \DateTimeImmutable('today'))
            ->setCheckOut(new \DateTimeImmutable('+3 days'))
            ->setAdults(2)
            ->setChildren(0)
            ->setRateXof('45000.00')
            ->setTotalXof('135000.00')
            ->setSource('direct')
            ->setCheckedInAt(new \DateTimeImmutable('today 14:00'));

        $manager->persist($reservation);
        $this->addReference('reservation-active-1', $reservation);
        $manager->flush();
    }
}
```

## Clients de démo

```php
// Clients locaux (sénégalais)
Amadou Diallo       | +221 77 123 45 67 | CNI | 4 séjours
Fatou Ndiaye        | +221 78 987 65 43 | CNI | 2 séjours
Mamadou Sow         | +221 76 555 11 22 | PASSEPORT | 1 séjour

// Diaspora / étrangers
Pierre Martin       | +33 6 12 34 56 78 | PASSEPORT | 2 séjours (Paris)
Aissatou Ba         | +1 347 555 0199   | PASSEPORT | 1 séjour (NYC)
```

## Tests PHPUnit

```
backend/tests/
├── Unit/
│   ├── Entity/
│   │   ├── ReservationTest.php       # getNights(), getBalanceXof()
│   │   └── InvoiceTest.php
│   └── Service/
│       ├── ReservationEngineTest.php # isAvailable(), create()
│       ├── PriceCalculatorTest.php
│       └── InvoiceServiceTest.php
└── Functional/
    └── Api/
        ├── AuthControllerTest.php
        ├── ReservationControllerTest.php
        ├── RoomControllerTest.php
        └── HousekeepingControllerTest.php
```

## Exemple test unitaire

```php
// tests/Unit/Entity/ReservationTest.php
class ReservationTest extends TestCase
{
    public function testGetNights(): void
    {
        $reservation = new Reservation();
        $reservation->setCheckIn(new \DateTimeImmutable('2026-05-12'));
        $reservation->setCheckOut(new \DateTimeImmutable('2026-05-15'));

        $this->assertEquals(3, $reservation->getNights());
    }

    public function testGetTotalXof(): void
    {
        $reservation = new Reservation();
        $reservation->setCheckIn(new \DateTimeImmutable('2026-05-12'));
        $reservation->setCheckOut(new \DateTimeImmutable('2026-05-15'));
        $reservation->setRateXof('45000.00');

        $this->assertEquals('135000.00', $reservation->getTotalXof());
    }
}
```

## Exemple test fonctionnel

```php
// tests/Functional/Api/ReservationControllerTest.php
class ReservationControllerTest extends WebTestCase
{
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->token = $this->login('reception@savana-hotel.sn', 'recep123');
    }

    public function testGetReservations(): void
    {
        $this->client->request('GET', '/api/reservations', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            'HTTP_HOST' => 'savana.localhost',
        ]);

        $this->assertResponseIsSuccessful();
        $data = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('data', $data);
    }

    public function testReservationAutreHotelRetourne403(): void
    {
        // Tester l'isolation multi-tenant
        // Récupérer une réservation de l'hôtel 2 avec le token de l'hôtel 1
        // → doit retourner 403
    }
}
```

## Commandes utiles

```bash
make fixtures            # Recharger toutes les fixtures
make test                # Lancer PHPUnit
make test-coverage       # Avec couverture HTML (var/coverage/)

# Dans le conteneur PHP :
php bin/phpunit tests/Unit/
php bin/phpunit tests/Functional/Api/ReservationControllerTest.php
php bin/phpunit --filter testGetNights
```
