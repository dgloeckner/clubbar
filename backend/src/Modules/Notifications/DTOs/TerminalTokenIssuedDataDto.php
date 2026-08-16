<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

use App\Modules\Notifications\Enums\MailLanguage;
use App\Shared\Mail\MailBranding;

/**
 * What an issuance notice says (ADR-0043).
 *
 * Narrow for the same reason {@see TerminalAnomalyDataDto} is, and one step
 * further: this message exists *because* a credential was created, so it must
 * carry nothing that would help whoever the message is warning about. No token,
 * no hash, no prefix of either — the terminal is named by the name an admin
 * gave it and by the device id they typed, both of which the panel already
 * shows to anyone who can log in.
 *
 * What a reader needs is enough to answer one question: *was this us?* The name
 * and the device id say which till, the event says whether a device was enrolled
 * or an existing one re-credentialled, and the moment says when — to the minute,
 * because that is what a person matches against their own memory of the
 * afternoon.
 *
 * **The actor is deliberately absent.** The outbox row records who was *told*,
 * not who acted, and ADR-0038 rule 5 has builders re-derive their content from
 * current state at send time — from which the actor cannot be recovered without
 * guessing at the audit log's most recent matching entry. Naming the wrong
 * colleague in a security notice is worse than naming none, so the message
 * points at the audit log instead, where the answer is exact.
 */
final readonly class TerminalTokenIssuedDataDto
{
    /**
     * @param string $event     `enrolled` (a new device) or `rotated` (a replacement
     *                          staged for a terminal that already had one). Carried as
     *                          a string because it is a label for a translation key
     *                          here, exactly as the anomaly kind is.
     * @param string $deviceId  The identifier an admin typed when enrolling the device.
     * @param string $issuedOn  Formatted issuance moment, to the minute.
     * @param string $expiresOn Formatted expiry of the credential that was just minted —
     *                          empty when the row no longer holds the generation this
     *                          message is about (revoked, or rotated again since).
     */
    public function __construct(
        public MailLanguage $language,
        public string $recipientAddress,
        public ?string $recipientName,
        public MailBranding $branding,
        public string $terminalName,
        public string $deviceId,
        public string $event,
        public string $issuedOn,
        public string $expiresOn,
    ) {}
}
