<?php

declare(strict_types=1);

namespace App\Shared\Mail;

/**
 * Who the mail comes from, and where a reply goes.
 *
 * These are **club data, not constants.** The FRGS template this layout is
 * ported from hardcodes `Mailvorlage::ABSENDERNAME`; the 2026-08-11 decision on
 * #361 explicitly rejects that for Club Bar, which is deployed by more than one
 * club. They live in `mail_config` and are editable in the admin panel — unlike
 * the DSN, which is a secret and stays in `config.php`.
 *
 * The display name is not decoration: without it an inbox shows the bare
 * address, and that is the one part of a message visible before it is opened.
 *
 * Reply-to is the Kassenwart, because a pre-notification invites a reply
 * (Beanstandungen within six weeks, Nutzungsordnung § 4 Abs. 6) and the sending
 * address is usually a mailbox nobody reads.
 */
final readonly class MailSender
{
    public function __construct(
        public string $address,
        public string $name,
        public ?string $replyTo = null,
    ) {}
}
