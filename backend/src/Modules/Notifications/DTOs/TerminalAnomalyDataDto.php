<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

use App\Modules\Notifications\Enums\MailLanguage;
use App\Shared\Mail\MailBranding;

/**
 * What a terminal anomaly warning says (ADR-0041 §4).
 *
 * Deliberately narrow. The evidence is addresses, timestamps and cursor
 * positions — enough for an admin to recognise their own second device, and
 * nothing else. No token material, no member data, no sales figures: this
 * message goes out on the observation that a credential *might* be in the wrong
 * hands, so it must be worth nothing to whoever might have it.
 */
final readonly class TerminalAnomalyDataDto
{
    /**
     * @param string               $kind     A {@see \App\Modules\Terminals\Enums\TerminalAnomalyKind} value.
     *                                       Carried as a string because the mail module does not depend on
     *                                       the terminals module; it is a label for a translation key here.
     * @param array<string,string> $evidence Label => value, rendered as the message's data table. Built by
     *                                       the detector, because what is worth showing differs per kind.
     */
    public function __construct(
        public MailLanguage $language,
        public string $recipientAddress,
        public ?string $recipientName,
        public MailBranding $branding,
        public string $terminalName,
        public string $kind,
        public array $evidence,
    ) {}
}
