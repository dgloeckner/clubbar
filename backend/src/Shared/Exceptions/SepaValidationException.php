<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * Thrown when a member lacks valid SEPA mandate data required for transactions
 */
class SepaValidationException extends AppException
{
    public function getHttpStatusCode(): int
    {
        return 422;
    }

    public function getErrorCode(): string
    {
        return 'sepa_invalid';
    }
}
