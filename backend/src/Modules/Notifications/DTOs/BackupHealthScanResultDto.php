<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

/**
 * What one backup-health scan did (#693, ADR-0049).
 *
 * Shaped like {@see CredentialExpiryScanResultDto} for the same reason: an
 * unattended job that says nothing is a job nobody notices has stopped. The
 * counts are about *rows* rather than about problems — one broken backup queues
 * one message per admin, and the interesting number is how many messages now
 * exist.
 *
 * | Field | Meaning |
 * |---|---|
 * | `failingRows` | Self-check rows in the `backup` category reading `fail` right now |
 * | `queued` | Messages this pass actually inserted |
 * | `alreadyQueued` | Something is wrong and today's message already existed. The ordinary case on every tick after the first |
 * | `adminsWithoutEmail` | Active admin accounts with no address, so nothing could be queued for them |
 * | `reason` | Why nothing was attempted, when nothing was |
 *
 * **A pass that queues nothing is the normal outcome, and the desirable one.**
 * On a healthy installation this runs every fifteen minutes forever and never
 * sends anything, which is the point: silence has to mean "the condition does
 * not hold", or the first real warning arrives in an inbox that has learned to
 * filter this sender.
 */
final readonly class BackupHealthScanResultDto
{
    public function __construct(
        public int $failingRows = 0,
        public int $queued = 0,
        public int $alreadyQueued = 0,
        public int $adminsWithoutEmail = 0,
        /** Set only when the pass declined to scan at all. */
        public ?string $reason = null,
    ) {}

    /** Nothing was attempted, and here is the one-phrase reason. */
    public static function nothingDue(string $reason): self
    {
        return new self(reason: $reason);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'failing_rows' => $this->failingRows,
            'queued' => $this->queued,
            'already_queued' => $this->alreadyQueued,
            'admins_without_email' => $this->adminsWithoutEmail,
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
            'failing_rows=%d queued=%d already_queued=%d admins_without_email=%d',
            $this->failingRows,
            $this->queued,
            $this->alreadyQueued,
            $this->adminsWithoutEmail,
        );
    }
}
