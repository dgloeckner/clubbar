<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Shared\Mail\MailBranding;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailMessage;
use App\Modules\Notifications\Enums\MailLanguage;

/**
 * "Your sign-in address was changed", sent to the address it was changed from.
 *
 * The message deliberately says almost nothing. It names the former address —
 * which the recipient can already see, since they are reading it there — the
 * time, and what to do if this was not them. It does **not** name the new
 * address: whoever receives this can no longer sign in, and if the change was
 * an account takeover, telling the victim's mailbox where the account went
 * hands the same information to anyone else reading it, while helping the
 * legitimate owner not at all. An admin who did change their own address
 * already knows what they changed it to.
 *
 * There is no undo link, no "this wasn't me" button, and no token. Recovery is
 * another admin resetting the account (ADR-0026); a self-service reversal
 * reachable from an email would be a second way in, defeating the step-up that
 * gates the change in the first place.
 */
final class AdminEmailChangedMail
{
    public static function render(
        string $recipientAddress,
        ?string $recipientName,
        string $changedAt,
        MailLanguage $language,
        MailBranding $branding,
    ): MailMessage {
        $t = new MailStrings($language);

        $when = MailFormat::date($changedAt, $language);
        $subject = $t->t('email_changed.subject');

        $rows = [
            $t->t('email_changed.label_former') => $recipientAddress,
            $t->t('email_changed.label_when') => $when,
        ];

        $html = MailLayout::document($branding, [
            'title' => $subject,
            'preview' => $t->t('email_changed.preheader'),
            'lang' => $language->value,
            'content' => self::html($t, $recipientAddress, $recipientName, $rows, $branding),
            'trailer' => $t->t('automated_note'),
        ]);

        return new MailMessage(
            to: $recipientAddress,
            subject: $subject,
            html: $html,
            text: self::text($t, $recipientAddress, $recipientName, $rows, $branding),
            toName: $recipientName,
        );
    }

    /** @param array<string,string> $rows */
    private static function html(
        MailStrings $t,
        string $formerAddress,
        ?string $recipientName,
        array $rows,
        MailBranding $branding,
    ): string {
        return MailLayout::contentStart()
            . MailLayout::eyebrow($t->t('email_changed.eyebrow'))
            . MailLayout::title($t->t('email_changed.title'))
            . MailLayout::paragraph(MailLayout::esc(MailTextBody::greeting($t, $recipientName)))
            . MailLayout::lede($t->t('email_changed.lede', [
                'former' => MailLayout::esc($formerAddress),
            ]))
            . MailLayout::dataTable($rows)
            . MailLayout::paragraph(MailLayout::esc($t->t('email_changed.expected')))
            . MailLayout::paragraph(MailLayout::esc($t->t('email_changed.unexpected')))
            . MailLayout::signOff($t->t('signoff'), $branding->orgName)
            . MailLayout::contentEnd();
    }

    /** @param array<string,string> $rows */
    private static function text(
        MailStrings $t,
        string $formerAddress,
        ?string $recipientName,
        array $rows,
        MailBranding $branding,
    ): string {
        $out = [
            $t->t('email_changed.title'),
            $t->t('text_separator'),
            '',
            MailTextBody::greeting($t, $recipientName),
            '',
            $t->t('email_changed.lede_text', ['former' => $formerAddress]),
            '',
        ];

        foreach ($rows as $label => $value) {
            $out[] = $label . ': ' . $value;
        }

        $out[] = '';
        $out[] = $t->t('email_changed.expected');
        $out[] = '';
        $out[] = $t->t('email_changed.unexpected');
        $out[] = '';
        $out[] = $t->t('signoff');
        $out[] = $branding->orgName;

        return MailTextBody::finish($out, $branding, $t);
    }
}
