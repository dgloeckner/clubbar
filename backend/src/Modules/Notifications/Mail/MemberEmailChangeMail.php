<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Shared\Mail\MailBranding;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailMessage;

/**
 * The two ends of a member's address change (ADR-0051): the notice to the
 * address being left, and the notice to the address being taken up.
 *
 * **Neither message names the other address**, and that is load-bearing rather
 * than cautious. {@see AdminEmailChangedMail} withholds it because telling a
 * possibly-hijacked mailbox where the account went helps nobody; here the
 * argument is ADR-0038 rule 5's. Bodies are rendered at send time from live
 * state, so an address printed in one could have moved again between the
 * attempt that was greylisted and the attempt that succeeded — and a message
 * that names the wrong address is worse than one that names none. Each copy
 * proves the address it is about by arriving there.
 *
 * The time comes from the row's own `queued_at`, which is the moment of the
 * change: the enqueue happens in the same call that writes it.
 */
final class MemberEmailChangeMail
{
    public static function render(
        MailKind $kind,
        string $recipientAddress,
        ?string $firstName,
        string $changedAt,
        MailLanguage $language,
        MailBranding $branding,
    ): MailMessage {
        $t = new MailStrings($language);
        $prefix = self::prefix($kind);
        $subject = $t->t($prefix . '.subject');

        $rows = [
            $t->t($prefix . '.label_address') => $recipientAddress,
            $t->t($prefix . '.label_when')    => MailFormat::dateTime($changedAt, $language),
        ];

        $html = MailLayout::document($branding, [
            'title'   => $subject,
            'preview' => $t->t($prefix . '.preheader'),
            'lang'    => $language->value,
            'content' => self::html($t, $prefix, $firstName, $rows, $branding),
            'trailer' => $t->t('automated_note'),
        ]);

        return new MailMessage(
            to: $recipientAddress,
            subject: $subject,
            html: $html,
            text: self::text($t, $prefix, $firstName, $rows, $branding),
            toName: $firstName,
        );
    }

    /** @see MemberCardMail::prefix() for why this refuses rather than defaults. */
    private static function prefix(MailKind $kind): string
    {
        return match ($kind) {
            MailKind::MEMBER_EMAIL_CHANGED   => 'member_mail_former',
            MailKind::MEMBER_EMAIL_ACTIVATED => 'member_mail_current',
            default => throw new \InvalidArgumentException(
                'MemberEmailChangeMail cannot render ' . $kind->value . ': it renders address changes only'
            ),
        };
    }

    /** @param array<string,string> $rows */
    private static function html(
        MailStrings $t,
        string $prefix,
        ?string $firstName,
        array $rows,
        MailBranding $branding,
    ): string {
        return MailLayout::contentStart()
            . MailLayout::eyebrow($t->t($prefix . '.eyebrow'))
            . MailLayout::title($t->t($prefix . '.title'))
            . MailLayout::paragraph(MailLayout::esc(MailTextBody::greeting($t, $firstName)))
            . MailLayout::lede($t->t($prefix . '.lede'))
            . MailLayout::dataTable($rows)
            . MailLayout::paragraph(MailLayout::esc($t->t($prefix . '.expected')))
            . MailLayout::paragraph(MailLayout::esc($t->t($prefix . '.unexpected')))
            . MailLayout::signOff($t->t('signoff'), $branding->orgName)
            . MailLayout::contentEnd();
    }

    /** @param array<string,string> $rows */
    private static function text(
        MailStrings $t,
        string $prefix,
        ?string $firstName,
        array $rows,
        MailBranding $branding,
    ): string {
        $out = [
            $t->t($prefix . '.title'),
            $t->t('text_separator'),
            '',
            MailTextBody::greeting($t, $firstName),
            '',
            $t->t($prefix . '.lede_text'),
            '',
        ];

        foreach ($rows as $label => $value) {
            $out[] = $label . ': ' . $value;
        }

        $out[] = '';
        $out[] = $t->t($prefix . '.expected');
        $out[] = '';
        $out[] = $t->t($prefix . '.unexpected');
        $out[] = '';
        $out[] = $t->t('signoff');
        $out[] = $branding->orgName;

        return MailTextBody::finish($out, $branding, $t);
    }
}
