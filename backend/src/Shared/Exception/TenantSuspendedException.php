<?php

namespace App\Shared\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

class TenantSuspendedException extends HttpException
{
    public function __construct(string $slug)
    {
        parent::__construct(
            statusCode: 402,
            message: sprintf("L'abonnement de '%s' est suspendu.", $slug),
        );
    }
}
