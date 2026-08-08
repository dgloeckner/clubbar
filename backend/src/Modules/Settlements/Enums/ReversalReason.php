<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Enums;

/**
 * Why a collection is being undone after the money moved (ruling #148).
 *
 * The two reasons differ in exactly one consequence, and it is the one that
 * matters: whether the member goes on collection hold. A settlement run sweeps
 * a member's *whole* unsettled position, so a bank return that freed those
 * items would be re-collected by the very next run — bouncing again, earning a
 * second return fee, forever. A club error is the opposite case: the club got
 * something wrong, fixed it, and re-collection is the entire point.
 *
 * Deliberately absent is any finer classification of *why* the bank returned
 * it. In Germany AM04 (insufficient funds), AC04 (account closed), MD07
 * (debtor deceased) and RR01-04 are suppressed for data-protection reasons and
 * collapse into "sonstige Gründe" on the return booking, so no logic may
 * assume the two are distinguishable.
 */
enum ReversalReason: string
{
    case BANK_RETURN = 'bank_return';
    case CLUB_ERROR = 'club_error';

    public function label(): string
    {
        return match ($this) {
            self::BANK_RETURN => 'Returned by the bank',
            self::CLUB_ERROR => 'Club error',
        };
    }

    /**
     * Whether this reason stops the next run collecting the member again.
     *
     * A returned direct debit must not be swept up by the next run without a
     * human looking at it first; a club error must be.
     */
    public function placesCollectionHold(): bool
    {
        return $this === self::BANK_RETURN;
    }
}
