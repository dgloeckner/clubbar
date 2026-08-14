<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

use App\Modules\Notifications\Enums\MailLanguage;
use App\Shared\Mail\MailBranding;

/**
 * What a „Einzug entfällt" says.
 *
 * Shorter than the announcement it retracts, and short on purpose: it carries
 * **no mandate reference and no creditor identifier**. Those exist to authorise
 * a collection; repeating them alongside "this will not be collected" would
 * make a retraction look like a second announcement.
 *
 * The amount and the date are here only so the member can recognise *which*
 * announcement is being called off.
 */
final readonly class CancellationNoticeDataDto
{
    public function __construct(
        public MailLanguage $language,
        public string $recipientAddress,
        public ?string $recipientName,
        public ?string $salutationName,
        public MailBranding $branding,
        public int $amountCents,
        public string $dueDate,
        public ?string $treasurerEmail = null,
    ) {}
}
