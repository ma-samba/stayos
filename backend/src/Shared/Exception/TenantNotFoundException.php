<?php

namespace App\Shared\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantNotFoundException extends HttpException
{
    public function __construct(string $slug)
    {
        parent::__construct(
            statusCode: 404,
            message: sprintf("Hôtel introuvable : '%s'", $slug),
        );
    }
}
