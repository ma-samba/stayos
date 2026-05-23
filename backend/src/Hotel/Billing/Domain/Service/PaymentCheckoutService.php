<?php

declare(strict_types=1);

namespace App\Hotel\Billing\Domain\Service;

use App\Hotel\Billing\Domain\Entity\Invoice;
use App\Hotel\Billing\Domain\Entity\Payment;
use App\Hotel\Billing\Domain\Enum\InvoiceStatus;
use App\Hotel\Billing\Domain\Enum\PaymentMethod;
use App\Hotel\Billing\Domain\Enum\PaymentStatus;
use App\Hotel\Billing\Domain\Gateway\PaymentCheckoutRequest;
use App\Hotel\Billing\Domain\Gateway\PaymentGatewayRegistry;
use App\Shared\Exception\BusinessRuleException;
use App\Shared\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;

class PaymentCheckoutService
{
    public function __construct(
        private readonly PaymentGatewayRegistry $gatewayRegistry,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext          $tenantContext,
        #[Target('business')] private readonly LoggerInterface $logger,
        #[Autowire('%env(default:default_frontend_url:FRONTEND_URL)%')] private readonly string $frontendUrl,
        #[Autowire('%env(default:default_backend_url:APP_BACKEND_URL)%')] private readonly string $backendUrl,
    ) {}

    /**
     * Initie un checkout Paydunya pour le solde restant d'une facture.
     *
     * @param string[] $channels ex: ['wave','orange-money','card']
     * @return array{checkoutUrl: string, paymentId: string}
     */
    public function initiate(Invoice $invoice, string $gatewayName, array $channels = []): array
    {
        if ($invoice->getStatusEnum() === InvoiceStatus::CANCELLED) {
            throw new BusinessRuleException('Impossible de payer une facture annulée.');
        }

        if ($invoice->isFullyPaid()) {
            throw new BusinessRuleException('Cette facture est déjà entièrement payée.');
        }

        $gateway = $this->gatewayRegistry->get($gatewayName);
        $tenant  = $this->tenantContext->get();

        // Montant = solde restant
        $balanceXof = $invoice->getBalanceXof();
        $amountInt  = (int) round((float) $balanceXof);

        if ($amountInt <= 0) {
            throw new BusinessRuleException('Le solde restant est nul.');
        }

        // Générer un secret unique pour le callback
        $callbackSecret = bin2hex(random_bytes(16));

        // Créer le Payment en statut PENDING
        $payment = new Payment();
        $payment->setInvoice($invoice);
        $payment->setMethod(PaymentMethod::MOBILE_MONEY->value); // mis a jour par le webhook selon le canal reel
        $payment->setAmountXof($balanceXof);
        $payment->setStatusEnum(PaymentStatus::PENDING);
        $payment->setGatewayName($gatewayName);
        $payment->setCallbackSecret($callbackSecret);

        $invoice->addPayment($payment);
        $this->entityManager->persist($payment);
        $this->entityManager->flush(); // flush pour obtenir l'ID du payment

        $guest = $invoice->getReservation()->getGuest();

        $callbackUrl = sprintf(
            '%s/api/payments/paydunya/ipn?secret=%s&tenant=%s',
            rtrim($this->backendUrl, '/'),
            $callbackSecret,
            $tenant->getSlug(),
        );

        $request = new PaymentCheckoutRequest(
            invoiceId: (string) $invoice->getId(),
            tenantSlug: $tenant->getSlug(),
            amountXof: $amountInt,
            description: sprintf('Facture %s — %s', $invoice->getNumber(), $tenant->getName()),
            customerName: $guest->getFirstName() . ' ' . $guest->getLastName(),
            customerEmail: $guest->getEmail(),
            customerPhone: $guest->getPhone(),
            callbackUrl: $callbackUrl,
            returnUrl: sprintf('%s/invoices/%s?payment=success', rtrim($this->frontendUrl, '/'), $invoice->getId()),
            cancelUrl: sprintf('%s/invoices/%s?payment=cancelled', rtrim($this->frontendUrl, '/'), $invoice->getId()),
            channels: $channels,
        );

        $result = $gateway->createCheckout($request);

        if (!$result->ok || $result->gatewayToken === null) {
            $payment->setStatusEnum(PaymentStatus::FAILED);
            $this->entityManager->flush();

            throw new BusinessRuleException('Impossible de créer le checkout. Réessayez plus tard.');
        }

        // Stocker le token gateway sur le Payment
        $payment->setGatewayToken($result->gatewayToken);
        $this->entityManager->flush();

        $this->logger->info('Payment checkout initiated', [
            'invoice_id'    => (string) $invoice->getId(),
            'invoice_number'=> $invoice->getNumber(),
            'payment_id'    => (string) $payment->getId(),
            'gateway'       => $gatewayName,
            'amountXof'     => $amountInt,
        ]);

        return [
            'checkoutUrl' => $result->checkoutUrl,
            'paymentId'   => (string) $payment->getId(),
        ];
    }
}
