<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Modules\Notifications\Enums\MailLanguage;
use App\Shared\Mail\MailBranding;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailMessage;

/**
 * "Your password was changed", "two-factor authentication was set up", and
 * "two-factor authentication was reset" (#892) — three notices about the
 * account's own login credentials, all addressed to the account itself.
 *
 * One class for the three, on the precedent {@see AdminLifecycleMail} set for
 * its own two variants: the difference between them is which words appear
 * where, not the shape of the message.
 *
 * The argument {@see AdminEmailChangedMail} makes about a stolen session
 * moving the login address applies here at least as strongly — a password or
 * a second factor *is* the login, not an attribute of it. Mail is the one
 * channel somebody holding a hijacked session cannot also redirect.
 *
 * `$actorLabel` is empty, and the row for it dropped, on
 * {@see renderTotpEnrolled()}: enrollment always names the enrolling
 * session's own account, so there is no other admin to report. The other two
 * carry it, because "you did this yourself" and "another admin did this to
 * your account" must not read as the same mail.
 */
final class AdminCredentialEventMail
{
    public static function renderPasswordChanged(
        string $recipientAddress,
        ?string $recipientName,
        string $occurredAt,
        string $actorLabel,
        MailLanguage $language,
        MailBranding $branding,
    ): MailMessage {
        return self::render(
            variant: 'password_changed',
            recipientAddress: $recipientAddress,
            recipientName: $recipientName,
            occurredAt: $occurredAt,
            actorLabel: $actorLabel,
            language: $language,
            branding: $branding,
        );
    }

    public static function renderTotpEnrolled(
        string $recipientAddress,
        ?string $recipientName,
        string $occurredAt,
        MailLanguage $language,
        MailBranding $branding,
    ): MailMessage {
        return self::render(
            variant: 'totp_enrolled',
            recipientAddress: $recipientAddress,
            recipientName: $recipientName,
            occurredAt: $occurredAt,
            actorLabel: '',
            language: $language,
            branding: $branding,
        );
    }

    public static function renderTotpReset(
        string $recipientAddress,
        ?string $recipientName,
        string $occurredAt,
        string $actorLabel,
        MailLanguage $language,
        MailBranding $branding,
    ): MailMessage {
        return self::render(
            variant: 'totp_reset',
            recipientAddress: $recipientAddress,
            recipientName: $recipientName,
            occurredAt: $occurredAt,
            actorLabel: $actorLabel,
            language: $language,
            branding: $branding,
        );
    }

    private static function render(
        string $variant,
        string $recipientAddress,
        ?string $recipientName,
        string $occurredAt,
        string $actorLabel,
        MailLanguage $language,
        MailBranding $branding,
    ): MailMessage {
        $t = new MailStrings($language);
        $subject = $t->t("{$variant}.subject");

        $rows = [
            $t->t("{$variant}.label_when") => MailFormat::date($occurredAt, $language),
        ];

        if ($actorLabel !== '') {
            $rows[$t->t("{$variant}.label_actor")] = $actorLabel;
        }

        $html = MailLayout::document($branding, [
            'title' => $subject,
            'preview' => $t->t("{$variant}.preheader"),
            'lang' => $language->value,
            'content' => self::html($t, $variant, $recipientName, $rows, $branding),
            'trailer' => $t->t('automated_note'),
        ]);

        return new MailMessage(
            to: $recipientAddress,
            subject: $subject,
            html: $html,
            text: self::text($t, $variant, $recipientName, $rows, $branding),
            toName: $recipientName,
        );
    }

    /** @param array<string,string> $rows */
    private static function html(
        MailStrings $t,
        string $variant,
        ?string $recipientName,
        array $rows,
        MailBranding $branding,
    ): string {
        return MailLayout::contentStart()
            . MailLayout::eyebrow($t->t("{$variant}.eyebrow"))
            . MailLayout::title($t->t("{$variant}.title"))
            . MailLayout::paragraph(MailLayout::esc(MailTextBody::greeting($t, $recipientName)))
            . MailLayout::lede($t->t("{$variant}.lede"))
            . MailLayout::dataTable($rows)
            . MailLayout::paragraph(MailLayout::esc($t->t("{$variant}.expected")))
            . MailLayout::paragraph(MailLayout::esc($t->t("{$variant}.unexpected")))
            . MailLayout::signOff($t->t('signoff'), $branding->orgName)
            . MailLayout::contentEnd();
    }

    /** @param array<string,string> $rows */
    private static function text(
        MailStrings $t,
        string $variant,
        ?string $recipientName,
        array $rows,
        MailBranding $branding,
    ): string {
        $out = [
            $t->t("{$variant}.title"),
            $t->t('text_separator'),
            '',
            MailTextBody::greeting($t, $recipientName),
            '',
            $t->t("{$variant}.lede_text"),
            '',
        ];

        foreach ($rows as $label => $value) {
            $out[] = $label . ': ' . $value;
        }

        $out[] = '';
        $out[] = $t->t("{$variant}.expected");
        $out[] = '';
        $out[] = $t->t("{$variant}.unexpected");
        $out[] = '';
        $out[] = $t->t('signoff');
        $out[] = $branding->orgName;

        return MailTextBody::finish($out, $branding, $t);
    }
}
