<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

use App\Modules\Notifications\Enums\DrainSource;

/**
 * What one drain run did (ADR-0038, #403).
 *
 * Deliberately counts four outcomes rather than two. "Sent 40 of 47" says
 * nothing about whether the other seven are coming back — and that is the only
 * question worth asking about a queue:
 *
 * | Field | Meaning | Is it a problem? |
 * |---|---|---|
 * | `sent` | Handed to the transport, which accepted it | No |
 * | `retrying` | Transient failure, backed off, will be tried again | Not yet — greylisting looks exactly like this |
 * | `failed` | Out of attempts, or a permanent refusal. `last_error` is visible to the Kassenwart | Yes |
 * | `skipped` | Claimed, but no message could be built from it | Yes, and it is a data problem rather than a mail one |
 *
 * `budgetExhausted` is not a failure either: the run stopped at its wall-clock
 * limit with work still queued, and the next tick continues. It matters only
 * because a run that reports it on every tick means the schedule is too slow
 * for the queue, which no single run can tell you.
 *
 * `pruned` counts rows the run *deleted* rather than sent (#408) — delivered
 * messages past their retention window. It is reported for the same reason the
 * rest is: an unattended deletion that nobody can see is how a retention policy
 * quietly stops running.
 */
final readonly class DrainResultDto
{
    public function __construct(
        public DrainSource $source,
        public int $claimed = 0,
        public int $sent = 0,
        public int $retrying = 0,
        public int $failed = 0,
        public int $skipped = 0,
        public int $pruned = 0,
        public bool $budgetExhausted = false,
        public float $durationSeconds = 0.0,
    ) {}

    /** A run that found nothing to do — the ordinary case, twelve times a year notwithstanding. */
    public static function idle(DrainSource $source, float $durationSeconds = 0.0): self
    {
        return new self($source, durationSeconds: $durationSeconds);
    }

    public function toArray(): array
    {
        return [
            'source' => $this->source->value,
            'claimed' => $this->claimed,
            'sent' => $this->sent,
            'retrying' => $this->retrying,
            'failed' => $this->failed,
            'skipped' => $this->skipped,
            'pruned' => $this->pruned,
            'budget_exhausted' => $this->budgetExhausted,
            'duration_seconds' => round($this->durationSeconds, 3),
        ];
    }

    /** One line for the cron's stdout and the application log. */
    public function summary(): string
    {
        return sprintf(
            'source=%s claimed=%d sent=%d retrying=%d failed=%d skipped=%d pruned=%d budget_exhausted=%s duration=%.2fs',
            $this->source->value,
            $this->claimed,
            $this->sent,
            $this->retrying,
            $this->failed,
            $this->skipped,
            $this->pruned,
            $this->budgetExhausted ? 'yes' : 'no',
            $this->durationSeconds,
        );
    }
}
