<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Hotel\Billing\Application\DTO\RecordPaymentDTO;
use App\Hotel\Billing\Domain\Entity\Invoice;
use App\Hotel\Billing\Domain\Enum\PaymentMethod;
use App\Hotel\Billing\Domain\Service\InvoiceService;
use App\Hotel\Billing\Domain\Service\PaymentCheckoutService;
use App\Hotel\Billing\Infrastructure\Repository\InvoiceRepository;
use App\Shared\Email\EmailService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/invoices', name: 'api_invoices_')]
class InvoiceController extends AbstractApiController
{
    public function __construct(
        private readonly InvoiceRepository      $invoiceRepository,
        private readonly InvoiceService         $invoiceService,
        private readonly PaymentCheckoutService $checkoutService,
        private readonly EmailService           $emailService,
        private readonly ValidatorInterface     $validator,
        #[Target('business')] private readonly LoggerInterface $logger,
    ) {}

    /**
     * GET /api/invoices — Liste des factures.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $status = $request->query->get('status');

        $criteria = [];
        if ($status !== null) {
            $criteria['status'] = $status;
        }

        $invoices = $this->invoiceRepository->findBy($criteria, ['createdAt' => 'DESC']);

        return $this->jsonSuccess($invoices, ['invoice:read']);
    }

    /**
     * GET /api/invoices/by-reservation/{reservationId} — Facture liee a une reservation.
     */
    #[Route('/by-reservation/{reservationId}', name: 'by_reservation', methods: ['GET'])]
    public function byReservation(string $reservationId): JsonResponse
    {
        $invoice = $this->invoiceRepository->findOneBy(['reservation' => $reservationId]);

        if ($invoice === null) {
            return $this->jsonError('Aucune facture pour cette réservation', 'NOT_FOUND', 404);
        }

        return $this->jsonSuccess($invoice, ['invoice:read']);
    }

    /**
     * GET /api/invoices/{id} — Detail d'une facture.
     */
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(Invoice $invoice): JsonResponse
    {
        return $this->jsonSuccess($invoice, ['invoice:read', 'invoice:detail']);
    }

    /**
     * POST /api/invoices/{id}/issue — Emettre une facture (draft -> issued).
     */
    #[Route('/{id}/issue', name: 'issue', methods: ['POST'])]
    public function issue(Invoice $invoice): JsonResponse
    {
        $this->invoiceService->issue($invoice, $this->getStaffUser());

        return $this->jsonSuccess($invoice, ['invoice:read', 'invoice:detail']);
    }

    /**
     * POST /api/invoices/{id}/payments — Enregistrer un paiement.
     */
    #[Route('/{id}/payments', name: 'record_payment', methods: ['POST'])]
    public function recordPayment(Invoice $invoice, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $dto = new RecordPaymentDTO();
        $dto->method    = $data['method'] ?? '';
        $dto->amountXof = $data['amountXof'] ?? '';
        $dto->reference = $data['reference'] ?? null;
        $dto->notes     = $data['notes'] ?? null;

        // Valider le DTO
        $errors = $this->validator->validate($dto);
        if (count($errors) > 0) {
            $messages = [];
            foreach ($errors as $error) {
                $messages[$error->getPropertyPath()] = $error->getMessage();
            }
            return $this->jsonError(
                json_encode($messages, JSON_UNESCAPED_UNICODE),
                'VALIDATION_ERROR',
                422,
            );
        }

        // Valider que la methode de paiement est connue
        if (PaymentMethod::tryFrom($dto->method) === null) {
            return $this->jsonError(
                'Moyen de paiement invalide.',
                'VALIDATION_ERROR',
                422,
            );
        }

        $payment = $this->invoiceService->recordPayment($invoice, $dto, $this->getStaffUser());

        return $this->jsonSuccess($payment, ['payment:read'], 201);
    }

    /**
     * GET /api/invoices/{id}/pdf — Telecharger le PDF.
     */
    #[Route('/{id}/pdf', name: 'pdf', methods: ['GET'])]
    public function pdf(Invoice $invoice): Response
    {
        $pdfContent = $this->invoiceService->generatePdf($invoice);

        return new Response($pdfContent, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $invoice->getNumber() . '.pdf"',
        ]);
    }

    /**
     * POST /api/invoices/{id}/checkout — Initier un paiement en ligne (Paydunya).
     */
    #[Route('/{id}/checkout', name: 'checkout', methods: ['POST'])]
    public function checkout(Invoice $invoice, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];

        $gateway  = $data['gateway'] ?? 'paydunya';
        $channels = $data['channels'] ?? [];

        $result = $this->checkoutService->initiate($invoice, $gateway, $channels);

        return $this->jsonSuccess($result);
    }

    /**
     * POST /api/invoices/{id}/send — Envoyer la facture par email.
     */
    #[Route('/{id}/send', name: 'send', methods: ['POST'])]
    public function send(Invoice $invoice): JsonResponse
    {
        $guest = $invoice->getReservation()->getGuest();

        if ($guest->getEmail() === null || $guest->getEmail() === '') {
            return $this->jsonError(
                'Le client n\'a pas d\'adresse email.',
                'VALIDATION_ERROR',
                422,
            );
        }

        try {
            $pdfContent = $this->invoiceService->generatePdf($invoice);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to generate invoice PDF', [
                'invoice_id' => (string) $invoice->getId(),
                'error'      => $e->getMessage(),
            ]);
            return $this->jsonError(
                'Erreur lors de la génération du PDF.',
                'EXTERNAL_SERVICE_ERROR',
                503,
            );
        }

        $sent = $this->emailService->sendInvoice($invoice, $pdfContent);

        if (!$sent) {
            return $this->jsonError(
                'Erreur lors de l\'envoi de la facture. Réessayez plus tard.',
                'EXTERNAL_SERVICE_ERROR',
                503,
            );
        }

        return $this->jsonSuccess(['sent' => true, 'to' => $guest->getEmail()]);
    }
}
