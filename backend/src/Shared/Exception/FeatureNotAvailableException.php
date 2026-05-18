<?php

namespace App\Shared\Exception;

use Symfony\Component\HttpKernel\Exception\HttpException;

class FeatureNotAvailableException extends HttpException
{
    public function __construct(string $feature = '')
    {
        $message = $feature
            ? sprintf("La fonctionnalité '%s' n'est pas disponible dans votre plan.", $feature)
            : "Cette fonctionnalité n'est pas disponible dans votre plan.";

        parent::__construct(statusCode: 403, message: $message);
    }
}
