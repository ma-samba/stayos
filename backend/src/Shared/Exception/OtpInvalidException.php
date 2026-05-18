<?php

namespace App\Shared\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

class OtpInvalidException extends HttpException
{
    public function __construct(string $message = 'OTP invalide ou expiré')
    {
        parent::__construct(statusCode: 422, message: $message);
    }
}
