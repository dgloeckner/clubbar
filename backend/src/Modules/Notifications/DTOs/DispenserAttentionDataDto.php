<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

use App\Modules\Notifications\Enums\DispenserAttentionOccasion;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Terminals\Enums\DispenserUnavailableReason;
use App\Shared\Mail\MailBranding;

/**
 * What a dispenser notice says (#956, ADR-0057 / ADR-0058).
 *
 * Everything in here is read at **send time** from the terminal row, never
 * carried in the queue (ADR-0038 rule 5): a jam cleared between the scan and
 * the drain must not produce a mail claiming it is still jammed.
 *
 * Three fields deserve their reason in writing.
 *
 * - **`reason` is the backend's derived verdict**, not a second judgement made
 *   here. The kiosk badge, the panel cell and this mail therefore name one
 *   machine with one word — which is what stops a club deciding that one of
 *   the three screens is the one to believe.
 * - **`probablyEmpty` is a sentence, never a verdict.** A jam with an exhausted
 *   estimate is the case ADR-0058 words on the panel; the machine itself cannot
 *   tell a jam from an empty hopper, because it has no empty sensor (owner
 *   decision 7), so the badge stays „Stau oder leer" and this adds what the
 *   books suggest beside it.
 * - **`estimatedLeft` may be null and is never rendered as a zero.** No refill
 *   recorded is not an empty hopper — they are opposite errands — which is why
 *   the scan never queues a low warning without an estimate in the first place.
 *
 * There is **no acknowledgement**: no token, no link that writes, nothing to
 * dismiss. The device has no reset route and a jam is cleared by a power cycle.
 */
final readonly class DispenserAttentionDataDto
{
    public function __construct(
        public MailLanguage $language,
        public string $recipientAddress,
        public ?string $recipientName,
        public MailBranding $branding,
        /** The terminal the dispenser is bolted next to, by the name the panel shows. */
        public string $terminalName,
        public DispenserAttentionOccasion $occasion,
        /**
         * True when the condition no longer holds at send time. The message
         * then says so in a sentence rather than refusing to render — the call
         * {@see BackupHealthDataDto} makes for a cleared problem, and for the
         * same reason: a red row in the Notifications page for a jam somebody
         * fixed within the quarter-hour would train an admin to ignore both.
         */
        public bool $cleared = false,
        /** The derived reason, for a fault occasion that still holds. */
        public ?DispenserUnavailableReason $reason = null,
        /** The Azkoyen hopper's own code, meaningful only with `hopper_error`. */
        public int $faultCode = 0,
        /** When this episode began — `state_since`, already formatted. */
        public ?string $since = null,
        /** Tokens the books say are left, for a low occasion. Never invented. */
        public ?int $estimatedLeft = null,
        /** The terminal's own warning threshold, for a low occasion. */
        public ?int $lowThreshold = null,
        /** A jam whose estimate is used up: ADR-0058's "probably empty" sentence. */
        public bool $probablyEmpty = false,
        /** Where to look, when the installation knows its own address. */
        public ?string $panelUrl = null,
    ) {}
}
