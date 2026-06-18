<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api\Billing;

use App\Platform\Tenant\Domain\Entity\Tenant;
use App\Tests\Functional\ApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tests fonctionnels — POST /api/invoices/{id}/refunds
 *
 * Stratégie : on insère directement en BDD une facture émise avec un
 * paiement existant pour avoir un état "paid > 0" prévisible. tearDown
 * nettoie ce qu'on a créé via le préfixe de numéro.
 *
 */
class RefundTest extends ApiTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    private string $schema;

    private const HOST          = 'savana.localhost';
    private const MANAGER       = 'admin@savana-hotel.sn';
    private const MANAGER_PWD   = 'admin123';
    private const RECEPTIONIST  = 'reception@savana-hotel.sn';
    private const RECEPT_PWD    = 'recep123';
    private const HOUSEKEEPER   = 'menage@savana-hotel.sn';
    private const HK_PWD        = 'menage123';

    private const INVOICE_PREFIX = 'FAC-REFTEST-';

    protected function setUp(): void
    {
        parent::setUp();
        $this->em   = static::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();

        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['slug' => 'savana']);
        self::assertNotNull($tenant);
        $this->schema = $tenant->getSchemaName();

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        try {
            $this->cleanup();
        } finally {
            parent::tearDown();
        }
    }

    private function cleanup(): void
    {
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $this->schema));
        try {
            $this->conn->executeStatement(
                "DELETE FROM payments WHERE invoice_id IN (
                    SELECT id FROM invoices WHERE number LIKE :p
                )",
                ['p' => self::INVOICE_PREFIX . '%']
            );
            $this->conn->executeStatement(
                'DELETE FROM invoices WHERE number LIKE :p',
                ['p' => self::INVOICE_PREFIX . '%']
            );
            $this->conn->executeStatement(
                "DELETE FROM audit_logs WHERE action = 'payment.refunded'"
            );
            $this->conn->executeStatement('DELETE FROM daily_closes');
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    /**
     * Insère une facture ISSUED avec un paiement initial PAID.
     * Le numéro suit le préfixe pour faciliter le tearDown.
     *
     * @return array{id: string, totalXof: string, paidXof: string}
     */
    private function seedInvoice(string $marker, string $totalXof, string $paid, string $status = 'issued'): array
    {
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $this->schema));
        try {
            // Une résa quelconque pour le FK NOT NULL
            $resId = $this->conn->executeQuery('SELECT id FROM reservations LIMIT 1')->fetchOne();

            $id  = $this->conn->executeQuery('SELECT gen_random_uuid()')->fetchOne();
            $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

            $this->conn->executeStatement(
                "INSERT INTO invoices
                 (id, reservation_id, number, status, subtotal_xof, tax_rate, tax_xof, total_xof,
                  issued_at, created_at, updated_at)
                 VALUES (:id, :res, :num, :status, :sub, '0.00', '0.00', :total, :now, :now, :now)",
                [
                    'id'     => $id,
                    'res'    => $resId,
                    'num'    => self::INVOICE_PREFIX . $marker,
                    'status' => $status,
                    'sub'    => $totalXof,
                    'total'  => $totalXof,
                    'now'    => $now,
                ]
            );

            if (bccomp($paid, '0', 2) > 0) {
                $payId = $this->conn->executeQuery('SELECT gen_random_uuid()')->fetchOne();
                $this->conn->executeStatement(
                    "INSERT INTO payments
                     (id, invoice_id, method, amount_xof, status, processed_at, paid_at)
                     VALUES (:id, :inv, 'wave', :amt, 'paid', :now, :now)",
                    [
                        'id'  => $payId,
                        'inv' => $id,
                        'amt' => $paid,
                        'now' => $now,
                    ]
                );
            }

            return [
                'id'       => (string) $id,
                'totalXof' => $totalXof,
                'paidXof'  => $paid,
            ];
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    private function refund(string $token, string $invoiceId, array $body): array
    {
        return $this->apiRequest(
            'POST', "/api/invoices/$invoiceId/refunds", self::HOST,
            body:    $body,
            headers: ['Authorization' => "Bearer $token"]
        );
    }

    public function testReceptionistCanRefund(): void
    {
        $inv = $this->seedInvoice('R1', '100000.00', '100000.00', 'paid');
        $token = $this->login(self::RECEPTIONIST, self::RECEPT_PWD, self::HOST);

        $resp = $this->refund($token, $inv['id'], [
            'amountXof' => '30000',
            'method'    => 'cash',
            'reason'    => 'Remboursement réceptionniste',
        ]);

        $this->assertApiSuccess($resp, 201);
        self::assertSame('-30000.00', $resp['data']['refund']['amountXof']);
    }

    public function testRefundCreatesNegativePayment(): void
    {
        $inv = $this->seedInvoice('R2', '100000.00', '100000.00', 'paid');
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $this->refund($token, $inv['id'], [
            'amountXof' => '20000',
            'method'    => 'wave',
            'reason'    => 'Test négativation',
        ]);
        self::assertResponseStatusCodeSame(201);

        // Vérif en BDD : 2 paiements (le positif initial + le négatif)
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $this->schema));
        try {
            $rows = $this->conn->executeQuery(
                'SELECT amount_xof FROM payments WHERE invoice_id = :id ORDER BY paid_at ASC',
                ['id' => $inv['id']]
            )->fetchFirstColumn();
            self::assertCount(2, $rows);
            self::assertSame('100000.00', $rows[0]);
            self::assertSame('-20000.00', $rows[1]);
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }
    }

    public function testRefundUpdatesInvoiceStatusToPartial(): void
    {
        $inv = $this->seedInvoice('R3', '100000.00', '100000.00', 'paid');
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $resp = $this->refund($token, $inv['id'], [
            'amountXof' => '25000',
            'method'    => 'cash',
            'reason'    => 'Geste commercial 25%',
        ]);

        $this->assertApiSuccess($resp, 201);
        self::assertSame('partial', $resp['data']['invoice']['status']);
    }

    public function testRefundFullPaymentReturnsStatusToIssued(): void
    {
        $inv = $this->seedInvoice('R4', '100000.00', '100000.00', 'paid');
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $resp = $this->refund($token, $inv['id'], [
            'amountXof' => '100000',
            'method'    => 'wave',
            'reason'    => 'Annulation totale du client',
        ]);

        $this->assertApiSuccess($resp, 201);
        self::assertSame('issued', $resp['data']['invoice']['status']);
    }

    public function testRefundExceedingPaidIsRefused(): void
    {
        $inv = $this->seedInvoice('R5', '100000.00', '40000.00', 'partial');
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $this->refund($token, $inv['id'], [
            'amountXof' => '60000',
            'method'    => 'cash',
            'reason'    => 'Tentative over-refund',
        ]);

        $this->assertApiError('BUSINESS_RULE', 422);
    }

    public function testRefundRequiresReasonMinLength(): void
    {
        $inv = $this->seedInvoice('R6', '100000.00', '100000.00', 'paid');
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $this->refund($token, $inv['id'], [
            'amountXof' => '10000',
            'method'    => 'cash',
            'reason'    => 'no',
        ]);

        $this->assertApiError('VALIDATION_ERROR', 422);
    }

    public function testRefundOnUnpaidInvoiceRefused(): void
    {
        $inv = $this->seedInvoice('R7', '100000.00', '0.00', 'issued');
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $this->refund($token, $inv['id'], [
            'amountXof' => '5000',
            'method'    => 'cash',
            'reason'    => 'Test sans paiement',
        ]);

        $this->assertApiError('BUSINESS_RULE', 422);
    }

    public function testRefundOnCancelledInvoiceStaysCancelled(): void
    {
        $inv = $this->seedInvoice('R8', '100000.00', '50000.00', 'cancelled');
        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);

        $resp = $this->refund($token, $inv['id'], [
            'amountXof' => '50000',
            'method'    => 'wave',
            'reason'    => 'Rembourser même si annulée',
        ]);

        $this->assertApiSuccess($resp, 201);
        self::assertSame('cancelled', $resp['data']['invoice']['status']);
    }

    public function testRefundIsBlockedByNightAuditLockOnToday(): void
    {
        $inv = $this->seedInvoice('R9', '100000.00', '100000.00', 'paid');

        // Seed une clôture qui couvre aujourd'hui ou plus tard pour
        // verrouiller la business date courante.
        $today = (new \DateTimeImmutable('today', new \DateTimeZone('Africa/Dakar')))->format('Y-m-d');
        $this->conn->executeStatement(sprintf('SET search_path TO %s, public', $this->schema));
        try {
            $this->conn->executeStatement(
                "INSERT INTO daily_closes
                 (id, business_date, closed_at, closed_by_id, closed_by_email,
                  cutoff_hour, snapshot)
                 VALUES (gen_random_uuid(), :bd, NOW(),
                         '00000000-0000-0000-0000-000000000001',
                         'seed@example.sn', 5, '{}'::jsonb)",
                ['bd' => $today]
            );
        } finally {
            $this->conn->executeStatement('SET search_path TO public');
            $this->em->clear();
        }

        $token = $this->login(self::MANAGER, self::MANAGER_PWD, self::HOST);
        $this->refund($token, $inv['id'], [
            'amountXof' => '10000',
            'method'    => 'cash',
            'reason'    => 'Test verrou night audit',
        ]);

        $this->assertApiError('BUSINESS_RULE', 422);
    }

    public function testHousekeeperCannotRefund(): void
    {
        $inv = $this->seedInvoice('R10', '100000.00', '100000.00', 'paid');
        $token = $this->login(self::HOUSEKEEPER, self::HK_PWD, self::HOST);

        $this->refund($token, $inv['id'], [
            'amountXof' => '10000',
            'method'    => 'cash',
            'reason'    => 'Tentative housekeeper',
        ]);

        self::assertResponseStatusCodeSame(403);
    }
}
