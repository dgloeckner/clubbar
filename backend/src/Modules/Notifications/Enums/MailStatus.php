<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

/**
 * Where a queued message stands (ADR-0038).
 *
 * Four states, and the fourth is the one worth explaining: `SUPERSEDED` is what
 * a cancellation does to an announcement that never left the host. Deleting the
 * row would erase the fact that the club intended to announce; marking it
 * `FAILED` would claim something went wrong. Nothing went wrong — the
 * collection was called off before anyone was told, so there is nothing to
 * retract and nothing to send.
 */
enum MailStatus: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case FAILED = 'failed';
    case SUPERSEDED = 'superseded';
}
