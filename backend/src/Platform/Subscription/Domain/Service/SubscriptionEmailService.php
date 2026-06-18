<?php

declare(strict_types=1);

namespace App\Platform\Subscription\Domain\Service;

use App\Platform\Auth\Domain\Entity\StaffUser;
use App\Platform\Subscription\Domain\Entity\SaasInvoice;
use App\Platform\Subscription\Domain\Entity\Subscription;
use App\Platform\Tenant\Domain\Entity\Tenant;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Envoi des emails liés au cycle de vie d'un abonnement SaaS.
 *
 * Séparé de App\Shared\Email\EmailService — qui est couplé à
 * la facturation hôtel (Invoice + Guest dans le schema tenant).
 * Ici on adresse le MANAGER du tenant, qui vit dans le schema
 * hotel_{uuid} : on lit ses coordonnées en SET search_path + finally.
 */
class SubscriptionEmailService
{
    public function __construct(
        private readonly MailerInterface  $mailer,
        private readonly Connection       $connection,
        #[Target('external')] private readonly LoggerInterface $logger,
        #[Autowire('%default_frontend_url%')] private readonly string $frontendUrl,
    ) {}

    public function sendTrialExpiring7d(Subscription $subscription): bool
    {
        return $this->sendTrialEmail(
            $subscription,
            'email/subscription/trial-expiring-7d.html.twig',
            'Votre essai StayOS expire dans 7 jours',
        );
    }

    public function sendTrialExpiring1d(Subscription $subscription): bool
    {
        return $this->sendTrialEmail(
            $subscription,
            'email/subscription/trial-expiring-1d.html.twig',
            'Votre essai StayOS expire demain',
        );
    }

    public function sendTrialExpired(Subscription $subscription): bool
    {
        return $this->sendTrialEmail(
            $subscription,
            'email/subscription/trial-expired.html.twig',
            'Votre essai StayOS a expiré — accès suspendu',
        );
    }

    public function sendPaymentLink(SaasInvoice $invoice): bool
    {
        return $this->sendInvoiceEmail(
            $invoice,
            'email/subscription/payment-link.html.twig',
            sprintf('Votre facture StayOS %s', $invoice->getNumber()),
        );
    }

    public function sendPaymentFailed(SaasInvoice $invoice): bool
    {
        return $this->sendInvoiceEmail(
            $invoice,
            'email/subscription/payment-failed.html.twig',
            sprintf('Échec de paiement — facture %s', $invoice->getNumber()),
        );
    }

    public function sendPaymentSuccess(
        SaasInvoice         $invoice,
        \DateTimeImmutable  $nextPeriodStart,
        \DateTimeImmutable  $nextPeriodEnd,
    ): bool {
        return $this->sendInvoiceEmail(
            $invoice,
            'email/subscription/payment-success.html.twig',
            sprintf('Paiement confirmé — facture %s', $invoice->getNumber()),
            [
                'nextPeriodStart' => $nextPeriodStart,
                'nextPeriodEnd'   => $nextPeriodEnd,
            ],
        );
    }

    private function sendTrialEmail(
        Subscription $subscription,
        string       $template,
        string       $subject,
    ): bool {
        $tenant  = $subscription->getTenant();
        $manager = $this->findManager($tenant);

        if ($manager === null) {
            $this->logger->warning('Subscription email skipped: no manager', [
                'tenant' => $tenant->getSlug(),
                'kind'   => $template,
            ]);
            return false;
        }

        return $this->send(
            $manager['email'],
            $this->fullName($manager),
            $subject,
            $template,
            [
                'recipientName' => $this->fullName($manager),
                'hotelName'     => $tenant->getName(),
                'trialEndsAt'   => $subscription->getTrialEndsAt(),
                'pricingUrl'    => $this->buildPricingUrl($tenant),
            ],
        );
    }

    /**
     * @param array<string, mixed> $extraContext
     */
    private function sendInvoiceEmail(
        SaasInvoice $invoice,
        string      $template,
        string      $subject,
        array       $extraContext = [],
    ): bool {
        $tenant  = $invoice->getTenant();
        $manager = $this->findManager($tenant);

        if ($manager === null) {
            $this->logger->warning('Subscription email skipped: no manager', [
                'tenant'  => $tenant->getSlug(),
                'invoice' => $invoice->getNumber(),
                'kind'    => $template,
            ]);
            return false;
        }

        return $this->send(
            $manager['email'],
            $this->fullName($manager),
            $subject,
            $template,
            array_merge([
                'recipientName' => $this->fullName($manager),
                'hotelName'     => $tenant->getName(),
                'invoice'       => $invoice,
            ], $extraContext),
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function send(
        string $to,
        string $toName,
        string $subject,
        string $template,
        array  $context,
    ): bool {
        try {
            $email = (new TemplatedEmail())
                ->from(new Address('noreply@stayos.sn', 'StayOS'))
                ->to(new Address($to, $toName))
                ->subject($subject)
                ->htmlTemplate($template)
                ->context($context);

            $this->mailer->send($email);

            $this->logger->info('Subscription email sent', [
                'to'       => $to,
                'template' => $template,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Subscription email failed', [
                'to'       => $to,
                'template' => $template,
                'error'    => $e->getMessage(),
                'class'    => $e::class,
            ]);

            return false;
        }
    }

    /**
     * Cherche le 1er MANAGER actif du tenant — requête SQL brute pour
     * éviter de polluer le metadata Doctrine et garantir la restauration
     * du search_path en finally.
     *
     * @return array{email: string, first_name: string, last_name: string}|null
     */
    private function findManager(Tenant $tenant): ?array
    {
        $schemaName = $tenant->getSchemaName();

        if (!\preg_match('/^hotel_[0-9a-f_]+$/i', $schemaName)) {
            return null;
        }

        try {
            $this->connection->executeStatement(
                sprintf('SET search_path TO %s, public', $schemaName)
            );

            $row = $this->connection->fetchAssociative(
                "SELECT email, first_name, last_name
                 FROM staff_users
                 WHERE role = 'MANAGER' AND active = TRUE
                 ORDER BY created_at ASC
                 LIMIT 1"
            );

            if ($row === false || $row === null) {
                return null;
            }

            return [
                'email'      => (string) $row['email'],
                'first_name' => (string) $row['first_name'],
                'last_name'  => (string) $row['last_name'],
            ];
        } catch (\Throwable $e) {
            $this->logger->error('findManager failed', [
                'tenant' => $tenant->getSlug(),
                'error'  => $e->getMessage(),
                'class'  => $e::class,
            ]);
            return null;
        } finally {
            $this->connection->executeStatement('SET search_path TO public');
        }
    }

    /**
     * @param array{email: string, first_name: string, last_name: string} $manager
     */
    private function fullName(array $manager): string
    {
        return trim($manager['first_name'] . ' ' . $manager['last_name']);
    }

    private function buildPricingUrl(Tenant $tenant): string
    {
        return rtrim($this->frontendUrl, '/') . '/subscription/pricing?tenant=' . $tenant->getSlug();
    }
}
