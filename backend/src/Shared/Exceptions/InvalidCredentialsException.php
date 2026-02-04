<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * Thrown when authentication credentials are invalid
 */
class InvalidCredentialsException extends AppException
{
    public function getHttpStatusCode(): int
    {
        return 401;
    }

    public function getErrorCode(): string
    {
        return 'invalid_credentials';
    }
}
