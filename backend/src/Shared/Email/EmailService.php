<?php

declare(strict_types=1);

namespace App\Shared\Email;

use App\Hotel\Billing\Domain\Entity\Invoice;
use App\Hotel\Property\Domain\Entity\HotelProfile;
use App\Platform\Auth\Domain\Entity\StaffInvitation;
use App\Shared\Url\TenantUrlBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
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
        #[Autowire('%default_frontend_url%')] private readonly string $frontendUrl,
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
                'class'          => $e::class,
            ]);

            return false;
        }
    }

    /**
     * Email d'invitation envoyé à un futur employé lorsqu'un manager
     * émet une invitation. Contient un lien pointant sur le frontend
     * du tenant (sous-domaine).
     *
     * Le `$plainToken` est le token EN CLAIR — il n'est jamais persisté
     * en BDD, ce paramètre est l'unique transport de ce secret.
     */
    public function sendStaffInvitation(
        StaffInvitation $invitation,
        string          $plainToken,
        string          $tenantSlug,
        string          $hotelName,
    ): bool {
        $url = TenantUrlBuilder::build(
            $this->frontendUrl,
            $tenantSlug,
            '/invitation/' . $plainToken,
        );

        try {
            $email = (new TemplatedEmail())
                ->from(new Address('noreply@stayos.sn', $hotelName))
                ->to(new Address(
                    $invitation->getEmail(),
                    $invitation->getFirstName() . ' ' . $invitation->getLastName(),
                ))
                ->subject(sprintf('Invitation à rejoindre %s sur StayOS', $hotelName))
                ->htmlTemplate('email/staff-invitation.html.twig')
                ->context([
                    'invitation' => $invitation,
                    'url'        => $url,
                    'hotelName'  => $hotelName,
                ]);

            $this->mailer->send($email);

            $this->logger->info('staff_invitation.email_sent', [
                'to'    => $invitation->getEmail(),
                'role'  => $invitation->getRole(),
                'hotel' => $tenantSlug,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('staff_invitation.email_failed', [
                'to'    => $invitation->getEmail(),
                'hotel' => $tenantSlug,
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);

            return false;
        }
    }
}
