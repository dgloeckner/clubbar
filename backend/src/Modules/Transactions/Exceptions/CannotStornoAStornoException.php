<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Exceptions;

use App\Shared\Exceptions\AppException;

/**
 * A storno cannot itself be stornoed.
 *
 * Booking a mistake back is a new purchase, not an un-reversal: chained
 * reversals would make the net effect of a booking depend on how many times it
 * had been flipped, which is exactly the traceability GoBD Rz. 64 asks for
 * (UC-A23, #169).
 */
class CannotStornoAStornoException extends AppException
{
    public function getHttpStatusCode(): int
    {
        return 422;
    }

    public function getErrorCode(): string
    {
        return 'cannot_storno_a_storno';
    }
}
