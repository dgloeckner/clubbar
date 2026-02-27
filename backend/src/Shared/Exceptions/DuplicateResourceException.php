<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * Thrown when attempting to create a resource that already exists (unique constraint violation)
 */
class DuplicateResourceException extends AppException
{
    public function getHttpStatusCode(): int
    {
        return 422;
    }

    public function getErrorCode(): string
    {
        return 'validation_failed';
    }
}
