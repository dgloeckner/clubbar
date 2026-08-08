<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Exceptions;

use App\Shared\Exceptions\AppException;

/**
 * A transaction may be stornoed at most once.
 *
 * The guarantee is the unique index on `related_transaction_id`, not the
 * service's own lookup — two concurrent requests both read "not yet stornoed"
 * before either writes, so only the database can arbitrate. This exception is
 * how that refusal reaches the caller (#169).
 */
class TransactionAlreadyStornoedException extends AppException
{
    public function getHttpStatusCode(): int
    {
        return 409;
    }

    public function getErrorCode(): string
    {
        return 'already_stornoed';
    }
}
