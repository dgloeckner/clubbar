<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

use App\Modules\Notifications\Enums\MailLanguage;
use App\Shared\Mail\MailBranding;

/**
 * What the Jugendschutz notice says (#622, ADR-0045 §3).
 *
 * Every field here is a deliberate inclusion, and the absences are the design.
 * There is no member id, no name, no birth date and no age-at-sale — this
 * message reaches every active admin account, which includes the Getränkewart,
 * and invariant 5 says they gain no member data.
 *
 * What is left is enough to act on: which drink, what age it required, when,
 * which till, and the transaction id to look the incident up by in a surface
 * the reader's own role permits.
 */
final readonly class JugendschutzViolationDataDto
{
    /**
     * @param int         $requiredAge  As it stood **at the sale**, carried in the
     *                                  outbox row's occasion rather than read from
     *                                  the product — un-restricting the drink later
     *                                  must not rewrite what the notice says was
     *                                  breached (invariant 4).
     * @param string|null $productName  Null when the product has been deleted since.
     */
    public function __construct(
        public MailLanguage $language,
        public string $recipientAddress,
        public ?string $recipientName,
        public MailBranding $branding,
        public string $transactionId,
        public int $requiredAge,
        public ?string $productName,
        public string $occurredAt,
        public ?string $terminalName,
    ) {}
}
