<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

/**
 * What one periodic-enqueue pass did (ADR-0039 decision 1, #462 step 11.7).
 *
 * Reported for the same reason {@see DrainResultDto} is: an unattended job that
 * says nothing is a job nobody notices has stopped. The counts are deliberately
 * about *rows*, not about members — the scan's own idea of who was in scope is
 * uninteresting next to what the database now holds.
 *
 * | Field | Meaning |
 * |---|---|
 * | `period` | The key that went into `dedup_key`, or `null` when nothing was due |
 * | `queued` | Statements this pass actually inserted |
 * | `alreadyQueued` | In scope, and a row for this period already existed. The ordinary case on every tick after the first |
 * | `skipped` | Rows the database refused for a reason that is not the dedup index — see {@see \App\Modules\Notifications\Services\PeriodicEnqueueService} |
 * | `reason` | Why nothing was attempted, when nothing was |
 *
 * A pass that queued nothing is overwhelmingly the normal outcome: hourly ticks
 * against a monthly cadence means roughly seven hundred and thirty of every
 * seven hundred and thirty-one passes have nothing to do.
 */
final readonly class PeriodicEnqueueResultDto
{
    public function __construct(
        public ?string $period = null,
        public int $queued = 0,
        public int $alreadyQueued = 0,
        public int $skipped = 0,
        /** Set only when the pass declined to scan at all. */
        public ?string $reason = null,
    ) {}

    /** Nothing was due, and here is the one-word reason. */
    public static function nothingDue(string $reason, ?string $period = null): self
    {
        return new self(period: $period, reason: $reason);
    }

    public function toArray(): array
    {
        return [
            'period' => $this->period,
            'queued' => $this->queued,
            'already_queued' => $this->alreadyQueued,
            'skipped' => $this->skipped,
            'reason' => $this->reason,
        ];
    }

    /** One line for the cron's stdout and the application log. */
    public function summary(): string
    {
        if ($this->reason !== null) {
            return 'nothing due (' . $this->reason . ')';
        }

        return sprintf(
            'period=%s queued=%d already_queued=%d skipped=%d',
            $this->period ?? '-',
            $this->queued,
            $this->alreadyQueued,
            $this->skipped,
        );
    }
}
