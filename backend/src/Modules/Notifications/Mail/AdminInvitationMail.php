<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Modules\Notifications\Enums\MailLanguage;
use App\Shared\Mail\MailBranding;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailMessage;

/**
 * "You have been given an admin account — set your password" (migration 058).
 *
 * The only message in this system whose body carries a working credential, and
 * the design follows from that in three places:
 *
 * 1. **The link is printed as text as well as linked.** A button alone is
 *    unusable in a client that strips them, in a plain-text reader, and on a
 *    phone where somebody wants to move the link to a laptop — and the fallback
 *    for a link that does not work is asking another admin for a whole new one.
 * 2. **The expiry is named in the message**, not left to be discovered. A link
 *    that has quietly stopped working reads as a broken system rather than as a
 *    deliberate lifetime, and the person who finds out is the one who does not
 *    yet have an account to ask about it from.
 * 3. **It says what happens next.** Setting a password is half of getting in;
 *    the second factor follows on first sign-in, and an admin who does not
 *    expect that reads the authenticator prompt as an obstacle rather than as
 *    the next step.
 *
 * It names no roles. What the account may do is the panel's answer to somebody
 * who has signed in, and a list of offices in an unauthenticated mailbox tells
 * a reader who is not the intended one how the club is organised.
 */
final class AdminInvitationMail
{
    public static function render(
        string $recipientAddress,
        ?string $recipientName,
        string $signInEmail,
        string $url,
        string $expiresAt,
        MailLanguage $language,
        MailBranding $branding,
    ): MailMessage {
        $t = new MailStrings($language);

        $subject = $t->t('admin_invitation.subject', ['org' => $branding->orgName]);
        $expires = MailFormat::dateTime($expiresAt, $language);

        $rows = [
            $t->t('admin_invitation.label_login') => $signInEmail,
            $t->t('admin_invitation.label_expires') => $expires,
        ];

        $html = MailLayout::document($branding, [
            'title' => $subject,
            'preview' => $t->t('admin_invitation.preheader'),
            'lang' => $language->value,
            'content' => self::html($t, $recipientName, $rows, $url, $branding),
            'trailer' => $t->t('automated_note'),
        ]);

        return new MailMessage(
            to: $recipientAddress,
            subject: $subject,
            html: $html,
            text: self::text($t, $recipientName, $rows, $url, $branding),
            toName: $recipientName,
        );
    }

    /** @param array<string,string> $rows */
    private static function html(
        MailStrings $t,
        ?string $recipientName,
        array $rows,
        string $url,
        MailBranding $branding,
    ): string {
        return MailLayout::contentStart()
            . MailLayout::eyebrow($t->t('admin_invitation.eyebrow'))
            . MailLayout::title($t->t('admin_invitation.title'))
            . MailLayout::paragraph(MailLayout::esc(MailTextBody::greeting($t, $recipientName)))
            . MailLayout::lede($t->t('admin_invitation.lede', ['org' => MailLayout::esc($branding->orgName)]))
            . MailLayout::dataTable($rows)
            . MailLayout::button($t->t('admin_invitation.cta'), $url)
            // The same URL again, as text. See the class comment: a button is
            // not a link everybody can use.
            . MailLayout::paragraph(MailLayout::esc($t->t('admin_invitation.fallback')))
            . MailLayout::paragraph(MailLayout::link($url, $url))
            . MailLayout::paragraph(MailLayout::esc($t->t('admin_invitation.next_step')))
            . MailLayout::paragraph(MailLayout::esc($t->t('admin_invitation.unexpected')))
            . MailLayout::signOff($t->t('signoff'), $branding->orgName)
            . MailLayout::contentEnd();
    }

    /** @param array<string,string> $rows */
    private static function text(
        MailStrings $t,
        ?string $recipientName,
        array $rows,
        string $url,
        MailBranding $branding,
    ): string {
        $out = [
            $t->t('admin_invitation.title'),
            $t->t('text_separator'),
            '',
            MailTextBody::greeting($t, $recipientName),
            '',
            $t->t('admin_invitation.lede_text', ['org' => $branding->orgName]),
            '',
        ];

        foreach ($rows as $label => $value) {
            $out[] = $label . ': ' . $value;
        }

        $out[] = '';
        $out[] = $t->t('admin_invitation.cta') . ':';
        $out[] = $url;
        $out[] = '';
        $out[] = $t->t('admin_invitation.next_step');
        $out[] = '';
        $out[] = $t->t('admin_invitation.unexpected');
        $out[] = '';
        $out[] = $t->t('signoff');
        $out[] = $branding->orgName;

        return MailTextBody::finish($out, $branding, $t);
    }
}
