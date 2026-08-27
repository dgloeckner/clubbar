<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

use App\Modules\Notifications\Enums\MailLanguage;
use App\Shared\Mail\MailBranding;

/**
 * What a backup health warning says (#693, ADR-0049).
 *
 * **Row ids, not sentences.** `$failing` carries the self-check row ids —
 * `backup_ever_ran`, `backup_last_run`, `backup_last_upload`,
 * `backup_local_size` — and the template turns each into a localised line.
 *
 * That indirection is the point rather than an accident of layering. A
 * {@see \App\Shared\Security\SecurityFinding} carries English `observed` and
 * `remedy` strings, because the security self-check is an operator surface
 * rendered in one language. This mail is not: it goes to each admin in their own
 * language, like every other message this system sends. Pasting the finding's
 * text into it would put an English paragraph in a German club's inbox.
 *
 * ## What is deliberately absent
 *
 * No paths, no sizes, no hostnames, no provider error text — and no numbers.
 * The measured detail ("the newest archive is 60 hours old", "3.4 GB over a cap
 * of 1.0 GB") lives on the security self-check page, and the mail's job is to
 * get somebody to go and look at it. Restating it here would mean either
 * duplicating {@see \App\Modules\Backups\Services\BackupStatusCheck}'s
 * measurements or parsing its English prose, and the first is how a mail and a
 * page come to disagree.
 *
 * The same rule {@see \App\Shared\Security\HeartbeatPinger} follows for the push
 * monitor: a closed vocabulary leaves the host, never free text.
 */
final readonly class BackupHealthDataDto
{
    /**
     * @param list<string> $failing Self-check row ids reading `fail` at send
     *                              time, in report order. Empty is legal and
     *                              means the problem cleared between the queue
     *                              and the drain — the template says so in a
     *                              sentence rather than inventing a fault.
     */
    public function __construct(
        public MailLanguage $language,
        public string $recipientAddress,
        public ?string $recipientName,
        public MailBranding $branding,
        public array $failing,
    ) {}

    public function isEmpty(): bool
    {
        return $this->failing === [];
    }
}
