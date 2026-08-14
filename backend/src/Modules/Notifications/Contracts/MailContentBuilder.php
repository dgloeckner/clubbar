<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Contracts;

use App\Modules\Notifications\Enums\MailKind;
use App\Shared\Mail\MailMessage;

/**
 * Turns one queued row into one message, at send time (ADR-0038 rule 5).
 *
 * The seam exists because the outbox is not a settlement queue: the drain
 * (#403) claims rows of every kind in one batch and must not know what any of
 * them mean. It asks the registry for a builder and sends what comes back —
 * so #438's expiry warnings, and #410's payment requests, are new
 * implementations rather than new branches inside the drain.
 *
 * Implementations render; they never send, and they never write to the queue.
 */
interface MailContentBuilder
{
    public function supports(MailKind $kind): bool;

    /**
     * @param array<string,mixed> $outboxRow A row as `claimBatch()` returns it.
     *
     * @throws \RuntimeException When the subject the row points at is gone. A
     *         message must not be invented around the gap — a wrong
     *         announcement is worse than a failed one.
     */
    public function build(array $outboxRow): MailMessage;
}
