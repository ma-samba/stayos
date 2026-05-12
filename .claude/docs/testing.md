# Tests — Stratégie complète

---

## Philosophie

Pour un SaaS hôtelier professionnel, les tests protègent trois choses critiques :
1. **L'isolation multi-tenant** — un hôtel ne doit JAMAIS voir les données d'un autre
2. **Le moteur de réservation** — pas de double réservation, pas de calcul de prix faux
3. **La facturation** — pas d'erreur sur les montants, TVA correcte

**Règle** : toute logique dans un Service doit avoir un test unitaire. Tout endpoint API doit avoir un test fonctionnel.

---

## Structure des tests

```
backend/tests/
├── Unit/                          # Tests sans BDD ni HTTP
│   ├── Entity/
│   │   ├── ReservationTest.php    # getNights(), getBalanceXof()
│   │   └── InvoiceTest.php        # getTaxXof(), getTotalXof()
│   └── Service/
│       ├── ReservationEngineTest.php
│       ├── PriceCalculatorTest.php
│       ├── InvoiceServiceTest.php
│       ├── HousekeepingServiceTest.php
│       └── OtpServiceTest.php
│
├── Functional/                    # Tests avec BDD de test + HTTP
│   ├── Api/
│   │   ├── Auth/
│   │   │   ├── LoginTest.php
│   │   │   └── OtpTest.php
│   │   ├── Reservation/
│   │   │   ├── ReservationCrudTest.php
│   │   │   ├── CheckInCheckOutTest.php
│   │   │   └── ReservationConflictTest.php  ← CRITIQUE
│   │   ├── Room/
│   │   │   └── RoomAvailabilityTest.php
│   │   ├── Billing/
│   │   │   ├── InvoiceTest.php
│   │   │   └── PaymentTest.php
│   │   ├── Housekeeping/
│   │   │   └── TaskTest.php
│   │   └── Webhook/
│   │       └── PaydunyaWebhookTest.php
│   └── Security/
│       ├── MultiTenantIsolationTest.php    ← CRITIQUE
│       ├── RateLimitingTest.php
│       └── RbacTest.php
```

---

## Configuration PHPUnit

```xml
<!-- backend/phpunit.xml.dist -->
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">

    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Functional">
            <directory>tests/Functional</directory>
        </testsuite>
    </testsuites>

    <php>
        <ini name="error_reporting" value="-1" />
        <server name="APP_ENV" value="test" force="true" />
        <server name="KERNEL_CLASS" value="App\Kernel" />
        <!-- BDD de test séparée -->
        <env name="DATABASE_URL" value="postgresql://stayos_user:stayos_password@db:5432/stayos_test?serverVersion=16" />
    </php>

    <coverage>
        <include>
            <directory suffix=".php">src</directory>
        </include>
        <report>
            <html outputDirectory="var/coverage" />
            <text outputFile="php://stdout" showOnlySummary="true" />
        </report>
    </coverage>
</phpunit>
```

---

## Helpers de test

### ApiTestCase — base pour tous les tests fonctionnels
```php
// tests/Functional/ApiTestCase.php
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    // Login et retourne le JWT
    protected function login(string $email, string $password, string $host = 'savana.localhost'): string
    {
        $this->client->request('POST', '/api/auth/login',
            server: ['HTTP_HOST' => $host],
            content: json_encode(['email' => $email, 'password' => $password])
        );
        $data = json_decode($this->client->getResponse()->getContent(), true);
        return $data['token'];
    }

    // Requête authentifiée avec JWT + host du tenant
    protected function apiRequest(
        string $method,
        string $url,
        string $token,
        string $host = 'savana.localhost',
        array  $body = []
    ): array {
        $this->client->request(
            $method, $url,
            server: [
                'HTTP_HOST'          => $host,
                'HTTP_AUTHORIZATION' => "Bearer $token",
                'CONTENT_TYPE'       => 'application/json',
            ],
            content: $body ? json_encode($body) : null
        );
        return json_decode($this->client->getResponse()->getContent(), true);
    }

    // Assertions communes
    protected function assertApiSuccess(array $response, int $status = 200): void
    {
        $this->assertResponseStatusCodeSame($status);
        $this->assertArrayHasKey('data', $response);
        $this->assertEquals($status, $response['status']);
    }

    protected function assertApiError(string $expectedCode, int $expectedStatus): void
    {
        $response = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertResponseStatusCodeSame($expectedStatus);
        $this->assertEquals($expectedCode, $response['code']);
    }
}
```

---

## Tests critiques — Exemples

### 1. Isolation multi-tenant (PRIORITÉ ABSOLUE)
```php
// tests/Functional/Security/MultiTenantIsolationTest.php
class MultiTenantIsolationTest extends ApiTestCase
{
    public function testReceptionistCannotAccessOtherHotelReservation(): void
    {
        // Login sur l'hôtel Savana
        $tokenSavana = $this->login('reception@savana-hotel.sn', 'recep123', 'savana.localhost');

        // Tenter d'accéder à une réservation de Villa Collines
        $villaReservationId = $this->getVillaReservationId();

        $this->client->request('GET', "/api/reservations/$villaReservationId",
            server: [
                'HTTP_HOST'          => 'savana.localhost',  // ← hôtel différent
                'HTTP_AUTHORIZATION' => "Bearer $tokenSavana",
            ]
        );

        // Doit retourner 404 (pas 403 — ne pas révéler l'existence)
        $this->assertResponseStatusCodeSame(404);
    }

    public function testGuestDataNotLeakedAcrossTenants(): void
    {
        // Un client homonyme dans les deux hôtels ne doit pas se mélanger
        $tokenSavana = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        $response = $this->apiRequest('GET', '/api/guests?q=Diallo', $tokenSavana, 'savana.localhost');

        // Vérifier que tous les clients retournés appartiennent à Savana
        foreach ($response['data'] as $guest) {
            $this->assertStringContainsString('savana', strtolower($guest['hotelSlug'] ?? 'savana'));
        }
    }

    public function testCannotUseSavanTokenOnVillaEndpoints(): void
    {
        $tokenSavana = $this->login('admin@savana-hotel.sn', 'admin123', 'savana.localhost');

        // Même token, hôte différent → doit échouer
        $this->client->request('GET', '/api/rooms',
            server: [
                'HTTP_HOST'          => 'villa-collines.localhost',
                'HTTP_AUTHORIZATION' => "Bearer $tokenSavana",
            ]
        );
        $this->assertResponseStatusCodeSame(403);
    }
}
```

### 2. Conflit de réservation (double booking)
```php
// tests/Functional/Api/Reservation/ReservationConflictTest.php
class ReservationConflictTest extends ApiTestCase
{
    public function testCannotDoubleBookRoom(): void
    {
        $token = $this->login('reception@savana-hotel.sn', 'recep123');

        $payload = [
            'roomId'   => 1,
            'guestId'  => 1,
            'checkIn'  => '2026-06-01',
            'checkOut' => '2026-06-05',
            'adults'   => 2,
        ];

        // Première réservation → succès
        $r1 = $this->apiRequest('POST', '/api/reservations', $token, body: $payload);
        $this->assertApiSuccess($r1, 201);

        // Deuxième réservation sur les mêmes dates → conflit
        $r2 = $this->apiRequest('POST', '/api/reservations', $token, body: $payload);
        $this->assertApiError('CONFLICT', 409);
    }

    public function testCanBookAdjacentDates(): void
    {
        $token = $this->login('reception@savana-hotel.sn', 'recep123');

        // Réservation 1 : 1→5 juin
        $this->apiRequest('POST', '/api/reservations', $token, body: [
            'roomId' => 2, 'guestId' => 1,
            'checkIn' => '2026-06-01', 'checkOut' => '2026-06-05', 'adults' => 1,
        ]);

        // Réservation 2 : 5→8 juin (check-out = check-in suivant → OK)
        $r2 = $this->apiRequest('POST', '/api/reservations', $token, body: [
            'roomId' => 2, 'guestId' => 2,
            'checkIn' => '2026-06-05', 'checkOut' => '2026-06-08', 'adults' => 1,
        ]);
        $this->assertApiSuccess($r2, 201);
    }
}
```

### 3. Calcul de prix
```php
// tests/Unit/Service/PriceCalculatorTest.php
class PriceCalculatorTest extends TestCase
{
    public function testBaseRateCalculation(): void
    {
        // 3 nuits à 45 000 XOF = 135 000 XOF
        $total = $this->calculator->getTotalRate($room, checkIn: '2026-06-01', checkOut: '2026-06-04');
        $this->assertEquals('135000.00', $total);
    }

    public function testWeekendRateApplied(): void
    {
        // Si tarif weekend configuré : vendredi + samedi à tarif majoré
        $total = $this->calculator->getTotalRate($room, checkIn: '2026-06-05', checkOut: '2026-06-08');
        // vendredi 45k, samedi 55k, dimanche 45k = 145k
        $this->assertEquals('145000.00', $total);
    }

    public function testTaxCalculation(): void
    {
        // TVA 18% sur 135 000 XOF = 24 300 XOF
        $invoice = $this->invoiceService->generateFromReservation($reservation);
        $this->assertEquals('24300.00', $invoice->getTaxXof());
        $this->assertEquals('159300.00', $invoice->getTotalXof());
    }
}
```

### 4. Webhook Paydunya
```php
// tests/Functional/Api/Webhook/PaydunyaWebhookTest.php
class PaydunyaWebhookTest extends ApiTestCase
{
    public function testValidWebhookRecordsPayment(): void
    {
        $payload = json_encode([
            'data' => [
                'invoice' => [
                    'token'  => 'test_token_123',
                    'status' => 'completed',
                    'total_amount' => 135000,
                ]
            ]
        ]);

        $signature = hash_hmac('sha256', $payload, $_ENV['PAYDUNYA_MASTER_KEY']);

        $this->client->request('POST', '/api/webhooks/paydunya',
            server: [
                'CONTENT_TYPE'          => 'application/json',
                'HTTP_X_PAYDUNYA_SIGNATURE' => $signature,
            ],
            content: $payload
        );

        $this->assertResponseStatusCodeSame(200);
        // Vérifier que le Payment a été créé en BDD
    }

    public function testInvalidSignatureReturns401(): void
    {
        $this->client->request('POST', '/api/webhooks/paydunya',
            server: ['HTTP_X_PAYDUNYA_SIGNATURE' => 'invalid'],
            content: '{}'
        );
        $this->assertResponseStatusCodeSame(401);
    }
}
```

### 5. Rate limiting
```php
// tests/Functional/Security/RateLimitingTest.php
class RateLimitingTest extends ApiTestCase
{
    public function testLoginRateLimited(): void
    {
        // 5 tentatives échouées
        for ($i = 0; $i < 5; $i++) {
            $this->client->request('POST', '/api/auth/login',
                content: json_encode(['email' => 'test@test.com', 'password' => 'wrong'])
            );
        }

        // 6ème tentative → 429
        $this->client->request('POST', '/api/auth/login',
            content: json_encode(['email' => 'test@test.com', 'password' => 'wrong'])
        );
        $this->assertResponseStatusCodeSame(429);
    }
}
```

---

## Commandes Make

```bash
make test                    # Tous les tests
make test-unit               # Tests unitaires uniquement
make test-functional         # Tests fonctionnels uniquement
make test-security           # Tests sécurité/isolation uniquement
make test-coverage           # Rapport de couverture HTML (var/coverage/)
make test-setup              # Créer et migrer la BDD de test

# Dans le conteneur PHP :
php bin/phpunit --filter MultiTenantIsolation   # Un test spécifique
php bin/phpunit tests/Functional/Api/           # Un dossier
php bin/phpunit --group security                # Par groupe
```

---

## Objectifs de couverture

| Module | Couverture cible |
|---|---|
| Services métier | ≥ 90% |
| Controllers API | ≥ 80% |
| Entités (méthodes calculées) | 100% |
| Sécurité / isolation tenant | 100% |
| Global | ≥ 75% |
