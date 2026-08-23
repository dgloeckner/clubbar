<?php

declare(strict_types=1);

namespace App\Modules\Transactions\Exceptions;

use App\Shared\Exceptions\AppException;

/**
 * The database refused the row outright — a dangling foreign key, a NULL in a
 * NOT NULL column, a value outside an ENUM, a date it cannot represent.
 *
 * This is the *structurally unstorable* case of ruling
 * [#143](https://github.com/dgloeckner/clubbar/issues/143): retrying it will
 * fail identically forever, so the batch must name it as rejected rather than
 * fail wholesale. It is deliberately narrower than "any database error" —
 * a lost connection or a deadlock is transient and still propagates as a
 * `PDOException`, because the terminal *should* retry those.
 *
 * Never report such a row as accepted. Issue
 * [#82](https://github.com/dgloeckner/clubbar/issues/82) did exactly that and
 * the terminal purged the sale from its offline queue.
 */
class TransactionNotStorableException extends AppException
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
