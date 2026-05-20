<?php

namespace App\Shared\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

class ConflictException extends HttpException
{
    public function __construct(string $message = 'Conflit de données')
    {
        parent::__construct(statusCode: 409, message: $message);
    }
}
