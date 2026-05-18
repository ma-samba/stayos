<?php

namespace App\Shared\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

class AlreadyExistsException extends HttpException
{
    public function __construct(string $message = 'Cette ressource existe déjà')
    {
        parent::__construct(statusCode: 409, message: $message);
    }
}
