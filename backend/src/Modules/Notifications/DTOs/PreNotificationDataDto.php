<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

use App\Modules\Notifications\Enums\MailLanguage;
use App\Shared\Mail\MailBranding;

/**
 * Everything a pre-notification says, gathered but not yet worded.
 *
 * The renderer takes this and nothing else — no repository, no PDO, no
 * container — which is what makes the content assertions in #404's acceptance
 * criteria a unit test rather than an integration one, and what lets the
 * preview script render every variant without a database.
 */
final readonly class PreNotificationDataDto
{
    /**
     * @param string      $recipientAddress The snapshot from `mail_outbox.recipient`
     * @param string|null $salutationName   How the member is addressed; null greets generically
     * @param string|null $creditorAddress  One line, already assembled
     * @param string|null $treasurerEmail   Reply-to, for the six-week objection hint
     * @param list<StatementLineDto> $lines
     */
    public function __construct(
        public MailLanguage $language,
        public string $recipientAddress,
        public ?string $recipientName,
        public ?string $salutationName,
        public MailBranding $branding,
        public int $amountCents,
        public string $dueDate,
        public ?string $creditorName,
        public ?string $creditorAddress,
        public ?string $creditorId,
        public ?string $mandateReference,
        public ?string $accountLast4,
        public array $lines = [],
        public ?string $periodStart = null,
        public ?string $periodEnd = null,
        public ?string $treasurerEmail = null,
    ) {}
}
