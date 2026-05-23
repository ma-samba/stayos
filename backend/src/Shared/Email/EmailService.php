<?php

declare(strict_types=1);

namespace App\Shared\Email;

use App\Hotel\Billing\Domain\Entity\Invoice;
use App\Hotel\Property\Domain\Entity\HotelProfile;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;

class EmailService
{
    public function __construct(
        private readonly MailerInterface        $mailer,
        private readonly EntityManagerInterface $entityManager,
        #[Target('external')] private readonly LoggerInterface $logger,
    ) {}

    /**
     * Envoie la facture PDF par email au client.
     * Retourne true si l'envoi a reussi, false sinon (erreur loggee).
     */
    public function sendInvoice(Invoice $invoice, string $pdfContent): bool
    {
        $guest = $invoice->getReservation()->getGuest();
        $recipientEmail = $guest->getEmail();

        if ($recipientEmail === null || $recipientEmail === '') {
            $this->logger->warning('Cannot send invoice email: guest has no email', [
                'invoice_number' => $invoice->getNumber(),
                'guest_id'       => (string) $guest->getId(),
            ]);
            return false;
        }

        try {
            $hotel = $this->entityManager->getRepository(HotelProfile::class)->findOneBy([]);
            $hotelName = $hotel?->getName() ?? 'StayOS';

            $email = (new TemplatedEmail())
                ->from(new Address('noreply@stayos.sn', $hotelName))
                ->to($recipientEmail)
                ->subject('Votre facture ' . $invoice->getNumber())
                ->htmlTemplate('email/invoice.html.twig')
                ->context([
                    'invoice'   => $invoice,
                    'guestName' => $guest->getFirstName() . ' ' . $guest->getLastName(),
                    'hotel'     => $hotel,
                ])
                ->attach($pdfContent, $invoice->getNumber() . '.pdf', 'application/pdf');

            $this->mailer->send($email);

            $this->logger->info('Invoice email sent', [
                'to'             => $recipientEmail,
                'invoice_number' => $invoice->getNumber(),
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send invoice email', [
                'to'             => $recipientEmail,
                'invoice_number' => $invoice->getNumber(),
                'error'          => $e->getMessage(),
            ]);

            return false;
        }
    }
}
