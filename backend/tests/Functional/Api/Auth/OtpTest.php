<?php

namespace App\Tests\Functional\Api\Auth;

use App\Platform\Auth\Domain\Entity\OtpToken;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Tests OTP — POST /api/auth/send-otp et /api/auth/verify-otp
 *
 * Ces routes sont publiques (pas de tenant ni JWT requis).
 */
class OtpTest extends ApiTestCase
{
    private EntityManagerInterface $em;

    private const EMAIL = 'otp-test@stayos-test.sn';

    protected function setUp(): void
    {
        parent::setUp();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testSendOtpReturns200(): void
    {
        $response = $this->apiRequest(
            'POST',
            '/api/auth/send-otp',
            'localhost',
            ['email' => self::EMAIL, 'type' => 'email_verification'],
        );

        $this->assertApiSuccess($response);
        self::assertEquals('Code envoyé.', $response['data']['message']);
    }

    public function testVerifyValidOtp(): void
    {
        // Générer un OTP directement en BDD pour contrôler le code
        $tz = new \DateTimeZone('Africa/Dakar');

        $token = new OtpToken();
        $token->setEmail(self::EMAIL);
        $token->setCode('123456');
        $token->setType('email_verification');
        $token->setExpiresAt(new \DateTimeImmutable('+10 minutes', $tz));

        $this->em->persist($token);
        $this->em->flush();

        $response = $this->apiRequest(
            'POST',
            '/api/auth/verify-otp',
            'localhost',
            ['email' => self::EMAIL, 'code' => '123456', 'type' => 'email_verification'],
        );

        $this->assertApiSuccess($response);
        self::assertTrue($response['data']['verified']);
    }

    public function testVerifyExpiredOtp(): void
    {
        $tz = new \DateTimeZone('Africa/Dakar');

        $token = new OtpToken();
        $token->setEmail(self::EMAIL);
        $token->setCode('654321');
        $token->setType('email_verification');
        $token->setExpiresAt(new \DateTimeImmutable('-1 minute', $tz));

        $this->em->persist($token);
        $this->em->flush();

        $this->apiRequest(
            'POST',
            '/api/auth/verify-otp',
            'localhost',
            ['email' => self::EMAIL, 'code' => '654321', 'type' => 'email_verification'],
        );

        $this->assertApiError('VALIDATION_ERROR', 422);
    }

    public function testVerifyWrongCodeThreeTimes(): void
    {
        $tz = new \DateTimeZone('Africa/Dakar');

        $token = new OtpToken();
        $token->setEmail(self::EMAIL);
        $token->setCode('999999');
        $token->setType('email_verification');
        $token->setExpiresAt(new \DateTimeImmutable('+10 minutes', $tz));

        $this->em->persist($token);
        $this->em->flush();

        // 3 mauvais codes
        for ($i = 0; $i < 3; $i++) {
            $this->apiRequest(
                'POST',
                '/api/auth/verify-otp',
                'localhost',
                ['email' => self::EMAIL, 'code' => '000000', 'type' => 'email_verification'],
            );
        }

        // Le token est maintenant invalide (attempts >= 3)
        $this->apiRequest(
            'POST',
            '/api/auth/verify-otp',
            'localhost',
            ['email' => self::EMAIL, 'code' => '999999', 'type' => 'email_verification'],
        );

        $this->assertApiError('VALIDATION_ERROR', 422);
    }
}
