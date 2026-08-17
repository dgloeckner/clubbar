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
 * or an existing one re-credentialled, the actor says who at the club did it,
 * and the moment says when — to the minute, because that is what a person
 * matches against their own memory of the afternoon.
 *
 * **The actor can legitimately be blank.** Migration 043 records it durably at
 * enqueue time, alongside the recipient, so it survives to send time without
 * the guessing ADR-0038 rule 5 would otherwise require — but a row queued
 * before that migration, or one whose actor's admin account has since been
 * deleted (`ON DELETE SET NULL`), carries none. The template drops the line
 * rather than printing an empty one, and still points at the audit log, where
 * the answer is exact.
 */
final readonly class TerminalTokenIssuedDataDto
{
    /**
     * @param string  $event      `enrolled` (a new device) or `rotated` (a replacement
     *                            staged for a terminal that already had one). Carried as
     *                            a string because it is a label for a translation key
     *                            here, exactly as the anomaly kind is.
     * @param string  $deviceId   The identifier an admin typed when enrolling the device.
     * @param string  $actorLabel Who acted, formatted as `display name (login)` — or just
     *                            the login when no display name is set. Empty when the
     *                            actor is unknown; the template drops the line.
     * @param string  $issuedOn   Formatted issuance moment, to the minute.
     * @param string  $expiresOn  Formatted expiry of the credential that was just minted —
     *                            empty when the row no longer holds the generation this
     *                            message is about (revoked, or rotated again since).
     */
    public function __construct(
        public MailLanguage $language,
        public string $recipientAddress,
        public ?string $recipientName,
        public MailBranding $branding,
        public string $terminalName,
        public string $deviceId,
        public string $event,
        public string $actorLabel,
        public string $issuedOn,
        public string $expiresOn,
    ) {}
}
