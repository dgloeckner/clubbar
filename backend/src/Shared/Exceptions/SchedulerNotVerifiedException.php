<?php

declare(strict_types=1);

namespace App\Shared\Exceptions;

/**
 * Thrown when a collection would be finalized on an installation whose
 * scheduler has never been observed to run (#405, ADR-0038).
 *
 * A type of its own rather than a `BusinessRuleException`, for two reasons.
 * The frontend has to be able to tell *this* refusal apart from "these members
 * cannot be settled" in order to point at the setup instructions instead of at
 * the member list — a distinct `error` code is what makes that possible without
 * matching on prose. And the cause is not a business rule at all: nothing about
 * this settlement is wrong. The installation cannot keep the announcement
 * promise of Nutzungsordnung § 7 Abs. 3, and refusing the collection is how
 * that stays a hard failure instead of a silent one.
 *
 * 409 rather than 422: no submitted field is invalid, and re-sending the same
 * request unchanged is exactly what should succeed once the cron is scheduled.
 */
class SchedulerNotVerifiedException extends AppException
{
    public function getHttpStatusCode(): int
    {
        return 409;
    }

    public function getErrorCode(): string
    {
        return 'scheduler_not_verified';
    }
}
