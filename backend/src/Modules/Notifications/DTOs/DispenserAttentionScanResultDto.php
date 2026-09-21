<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

/**
 * What one dispenser scan did (#956).
 *
 * Shaped like {@see BackupHealthScanResultDto} and
 * {@see CredentialExpiryScanResultDto} for the reason all three exist: an
 * unattended job that says nothing is a job nobody notices has stopped.
 *
 * | Field | Meaning |
 * |---|---|
 * | `terminalsExamined` | Active terminals looked at this pass |
 * | `needingAttention` | Terminals with a condition worth mailing about right now |
 * | `waiting` | Conditions younger than the persistence window — a WLAN blip during a dispense, not yet an incident |
 * | `stale` | Terminals whose last report is too old to be a claim about now. Not mailed here; that is the sync status's business |
 * | `queued` | Messages this pass actually inserted |
 * | `alreadyQueued` | The episode had already been mailed to that admin. The ordinary case on every tick after the first |
 * | `adminsWithoutEmail` | Active admin accounts with no address |
 * | `reason` | Why nothing was attempted, when nothing was |
 *
 * **A pass that queues nothing is the normal outcome, and the desirable one.**
 * A club whose dispensers work receives nothing at all, for ever — there is no
 * digest and no "all dispensers OK" (ADR-0044 rule 6).
 */
final readonly class DispenserAttentionScanResultDto
{
    public function __construct(
        public int $terminalsExamined = 0,
        public int $needingAttention = 0,
        public int $waiting = 0,
        public int $stale = 0,
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
            'terminals_examined' => $this->terminalsExamined,
            'needing_attention' => $this->needingAttention,
            'waiting' => $this->waiting,
            'stale' => $this->stale,
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
            'terminals=%d needing_attention=%d waiting=%d stale=%d queued=%d already_queued=%d '
            . 'admins_without_email=%d',
            $this->terminalsExamined,
            $this->needingAttention,
            $this->waiting,
            $this->stale,
            $this->queued,
            $this->alreadyQueued,
            $this->adminsWithoutEmail,
        );
    }
}
