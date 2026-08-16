<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

/**
 * What one credential-expiry scan did (#438, ADR-0036).
 *
 * Shaped like {@see PeriodicEnqueueResultDto} for the same reason: an
 * unattended job that says nothing is a job nobody notices has stopped. The
 * counts are about *rows*, not about credentials — one credential entering a
 * tier queues one message per admin, and the interesting number is how many
 * messages now exist.
 *
 * | Field | Meaning |
 * |---|---|
 * | `credentialsExamined` | Keys and terminal tokens the scan looked at |
 * | `inWarningWindow` | Of those, how many are inside a 90/30/7 tier right now |
 * | `queued` | Messages this pass actually inserted |
 * | `alreadyQueued` | In a tier, and this tier's message already existed. The ordinary case on every tick after the first |
 * | `adminsWithoutEmail` | Active admin accounts with no address, so nothing could be queued for them |
 * | `reason` | Why nothing was attempted, when nothing was |
 *
 * A pass that queued nothing is overwhelmingly the normal outcome. A key lives
 * for 365 days and crosses three tiers in that time; every other tick of every
 * other day has nothing to do.
 */
final readonly class CredentialExpiryScanResultDto
{
    public function __construct(
        public int $credentialsExamined = 0,
        public int $inWarningWindow = 0,
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
            'credentials_examined' => $this->credentialsExamined,
            'in_warning_window' => $this->inWarningWindow,
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
            'examined=%d warning_window=%d queued=%d already_queued=%d admins_without_email=%d',
            $this->credentialsExamined,
            $this->inWarningWindow,
            $this->queued,
            $this->alreadyQueued,
            $this->adminsWithoutEmail,
        );
    }
}
