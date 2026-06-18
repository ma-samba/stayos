<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Payments;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Sprint 14-B.1.2.2 — Vérification du hash SHA-512 MasterKey
 * Paydunya sur les IPN.
 *
 * Chaque test boot un kernel avec un override d'env adapté
 * (les env vars sont lues dynamiquement par '%env(...)%' à
 * l'instanciation du PaydunyaWebhookHandler — un kernel frais
 * par test suffit pour piloter le binding).
 *
 * Limite assumée : l'endpoint répond toujours 200 (jamais de
 * 4xx vers Paydunya pour éviter les retries) — les tests ne
 * peuvent que valider le bon plumbing du container + l'absence
 * de crash. La logique fine du gating est testée par les unit
 * tests dans PaydunyaWebhookHandlerTest.
 */
class PaydunyaIpnHashVerificationTest extends WebTestCase
{
    private const TEST_MASTER_KEY = 'test_master_key_for_sprint_14b';

    private KernelBrowser $client;

    /** @var array<string, string|false> sauvegarde des env pour restauration */
    private array $envBackup = [];

    protected function tearDown(): void
    {
        $this->restoreEnv();
        parent::tearDown();
    }

    private function bootWithEnv(bool $enabled, string $masterKey = ''): void
    {
        $this->setEnv('PAYDUNYA_HASH_VERIFICATION_ENABLED', $enabled ? 'true' : 'false');
        $this->setEnv('PAYDUNYA_MASTER_KEY', $masterKey);

        static::ensureKernelShutdown();
        $this->client = static::createClient();

        static::getContainer()->get('cache.rate_limiter')->clear();
    }

    private function setEnv(string $name, string $value): void
    {
        if (!array_key_exists($name, $this->envBackup)) {
            $this->envBackup[$name] = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);
        }
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
        putenv("$name=$value");
    }

    private function restoreEnv(): void
    {
        foreach ($this->envBackup as $name => $original) {
            if ($original === false) {
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($name);
            } else {
                $_ENV[$name] = $original;
                $_SERVER[$name] = $original;
                putenv("$name=$original");
            }
        }
        $this->envBackup = [];
    }

    private function validHash(): string
    {
        return hash('sha512', self::TEST_MASTER_KEY);
    }

    /**
     * Encode le payload comme Paydunya l'envoie : form-data avec
     * une clé "data" qui contient un sous-tableau.
     *
     * @param array<string, mixed> $data
     */
    private function postIpn(array $data, string $tenant = 'savana', string $secret = 'any'): void
    {
        $this->client->request(
            method: 'POST',
            uri: "/api/payments/paydunya/ipn?secret={$secret}&tenant={$tenant}",
            parameters: ['data' => $data],
        );
    }

    public function testIpnWithInvalidHashIsRejectedSilently(): void
    {
        $this->bootWithEnv(true, self::TEST_MASTER_KEY);

        $invalidHash = str_repeat('a', 128); // SHA-512 = 128 hex chars

        $this->postIpn([
            'response_code' => '00',
            'response_text' => 'Transaction Found',
            'hash'          => $invalidHash,
            'invoice'       => ['token' => 'test_token_xyz'],
        ]);

        // Toujours 200 vers Paydunya (jamais de 4xx) mais le
        // handler stoppe à l'étape 0 — aucun side-effect métier.
        self::assertResponseStatusCodeSame(200);
        self::assertSame(
            ['status' => 'ok'],
            json_decode((string) $this->client->getResponse()->getContent(), true),
        );
    }

    public function testIpnWithValidHashIsAccepted(): void
    {
        $this->bootWithEnv(true, self::TEST_MASTER_KEY);

        $this->postIpn([
            'response_code' => '00',
            'response_text' => 'Transaction Found',
            'hash'          => $this->validHash(),
            'invoice'       => ['token' => 'test_token_xyz'],
        ]);

        // Hash valide → handler poursuit l'exécution (s'arrête
        // plus loin car le payment n'existe pas en BDD pour ce
        // token, mais l'étape 0 est passée). 200 attendu.
        self::assertResponseStatusCodeSame(200);
    }

    public function testIpnSkipsHashCheckWhenDisabled(): void
    {
        // Mode dev/test par défaut : vérification désactivée
        $this->bootWithEnv(false);

        // Aucun hash dans le payload — accepté car vérif désactivée
        $this->postIpn([
            'response_code' => '00',
            'invoice'       => ['token' => 'test_token_xyz'],
        ]);

        self::assertResponseStatusCodeSame(200);
    }
}
