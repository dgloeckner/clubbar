<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

/**
 * The languages an outgoing message actually exists in.
 *
 * Deliberately **not** `SupportedLanguage`, which the member record uses and
 * which also carries French. A member may well prefer French; there is no
 * French announcement, and pretending otherwise would render a mail with empty
 * strings where the amount and the due date belong. A German mail is a poor
 * outcome for that member — a blank one is an unusable one, so the fallback is
 * `de` (ADR-0002's principle, applied where the translations end).
 *
 * The chosen value is frozen into `mail_outbox.language` at enqueue, so the
 * column always states the language the message will be in, not a preference
 * that may not be honourable.
 */
enum MailLanguage: string
{
    case German = 'de';
    case English = 'en';

    public static function fromPreferred(?string $preferred): self
    {
        return self::tryFrom(trim((string) $preferred)) ?? self::German;
    }
}
