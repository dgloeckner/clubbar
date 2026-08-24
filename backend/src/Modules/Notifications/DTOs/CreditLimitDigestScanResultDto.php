<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

/**
 * What one near-limit digest pass did (ADR-0047, migration 054).
 *
 * Shaped like {@see CredentialExpiryScanResultDto} and
 * {@see PeriodicEnqueueResultDto} for the same reason all three exist: an
 * unattended job that says nothing is a job nobody notices has stopped.
 *
 * | Field | Meaning |
 * |---|---|
 * | `window` | The window this pass was for — `2026-W35`, `2026-08-24` |
 * | `membersNearLimit` | How many members the digest would name |
 * | `queued` | Messages this pass actually inserted |
 * | `alreadyQueued` | This window's digest already existed for that recipient. The ordinary case on every tick after the first |
 * | `recipientsWithoutEmail` | Eligible accounts with no address on file |
 * | `reason` | Why nothing was queued, when nothing was |
 *
 * A pass that queued nothing is by far the normal outcome: a weekly cadence has
 * one tick out of several hundred that does anything, and a club whose members
 * settle up has none at all.
 */
final readonly class CreditLimitDigestScanResultDto
{
    public function __construct(
        public ?string $window = null,
        public int $membersNearLimit = 0,
        public int $queued = 0,
        public int $alreadyQueued = 0,
        public int $recipientsWithoutEmail = 0,
        /** Set only when the pass declined to queue anything. */
        public ?string $reason = null,
    ) {}

    /** Nothing was queued, and here is the one-phrase reason. */
    public static function nothingDue(string $reason, ?string $window = null): self
    {
        return new self(window: $window, reason: $reason);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'window' => $this->window,
            'members_near_limit' => $this->membersNearLimit,
            'queued' => $this->queued,
            'already_queued' => $this->alreadyQueued,
            'recipients_without_email' => $this->recipientsWithoutEmail,
            'reason' => $this->reason,
        ];
    }

    /** One line for the cron's stdout and the application log. */
    public function summary(): string
    {
        if ($this->reason !== null) {
            return 'nothing due (' . $this->reason . ')'
                . ($this->window === null ? '' : ' for ' . $this->window);
        }

        return sprintf(
            'window=%s near_limit=%d queued=%d already_queued=%d recipients_without_email=%d',
            $this->window ?? '-',
            $this->membersNearLimit,
            $this->queued,
            $this->alreadyQueued,
            $this->recipientsWithoutEmail,
        );
    }
}
