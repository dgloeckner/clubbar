<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Shared\Mail\MailBranding;

/**
 * The parts of the plain-text alternative that both messages share.
 *
 * The text part is not a courtesy — `MailMessage` refuses to be constructed
 * without one — and it has to stand on its own: a reader who never sees the
 * HTML still needs to know who is writing to them about their bank account.
 * The HTML part gets its footer from `MailLayout`; this is that footer, in the
 * only form a text part can carry it.
 */
final class MailTextBody
{
    public static function greeting(MailStrings $t, ?string $name): string
    {
        $name = trim((string) $name);

        return $name === '' ? $t->t('greeting_generic') : $t->t('greeting', ['name' => $name]);
    }

    /** @param list<string> $lines */
    public static function finish(array $lines, MailBranding $branding, MailStrings $t): string
    {
        $lines[] = '';
        $lines[] = $t->t('text_separator');
        $lines[] = $branding->orgName;

        if (trim((string) $branding->addressLine) !== '') {
            $lines[] = (string) $branding->addressLine;
        }
        foreach ($branding->footerLinks as $url) {
            $lines[] = (string) $url;
        }

        $lines[] = $t->t('automated_note');

        return implode("\n", $lines) . "\n";
    }
}
