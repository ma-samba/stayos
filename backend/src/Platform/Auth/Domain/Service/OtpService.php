<?php

namespace App\Platform\Auth\Domain\Service;

use App\Platform\Auth\Domain\Entity\OtpToken;
use App\Platform\Auth\Infrastructure\Doctrine\OtpTokenRepository;
use App\Shared\Exception\OtpInvalidException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

class OtpService
{
    public function __construct(
        private readonly OtpTokenRepository     $otpTokenRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface        $mailer,
        private readonly LoggerInterface        $logger,
    ) {}

    /**
     * Génère un OTP 6 chiffres, invalide les précédents, envoie l'email.
     */
    public function generate(string $email, string $type): OtpToken
    {
        $this->otpTokenRepository->invalidateAll($email, $type);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $tz        = new \DateTimeZone('Africa/Dakar');
        $expiresAt = new \DateTimeImmutable('+10 minutes', $tz);

        $token = new OtpToken();
        $token->setEmail($email);
        $token->setCode($code);
        $token->setType($type);
        $token->setExpiresAt($expiresAt);

        $this->entityManager->persist($token);
        $this->entityManager->flush();

        $this->sendOtpEmail($email, $code);

        return $token;
    }

    /**
     * Vérifie un OTP. Lève OtpInvalidException si invalide ou incorrect.
     */
    public function verify(string $email, string $code, string $type): void
    {
        $token = $this->otpTokenRepository->findValidToken($email, $type);

        if (null === $token) {
            throw new OtpInvalidException('OTP invalide ou expiré');
        }

        $token->incrementAttempts();

        if ($token->getCode() !== $code) {
            $this->entityManager->flush();
            throw new OtpInvalidException('Code incorrect');
        }

        $token->markAsUsed();
        $this->entityManager->flush();
    }

    private function sendOtpEmail(string $to, string $code): void
    {
        $email = (new Email())
            ->from('noreply@stayos.sn')
            ->to($to)
            ->subject('Votre code de vérification StayOS')
            ->text("Votre code : {$code}\n\nValable 10 minutes.");

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            // L'OTP est persisté — l'utilisateur peut redemander via /api/auth/resend-otp
            // On ne bloque pas l'inscription pour un problème d'envoi email
            $this->logger->error('Échec envoi OTP email', [
                'to'    => $to,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
