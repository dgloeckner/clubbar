<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Modules\Notifications\DTOs\CredentialExpiryDataDto;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailMessage;

/**
 * "A credential is running out" (#438, ADR-0036).
 *
 * One template for both credentials rather than two nearly identical ones. What
 * differs between an encryption key and a terminal token is four translation
 * keys — what it is, what happens when it lapses, what renewing involves, and
 * the word for the thing — and a second template would mean the next change to
 * the shape had to be made twice and would eventually be made once.
 *
 * Addressed to an admin, never to a member: a member can do nothing about
 * either credential, and telling them one is running out leaks the state of the
 * club's own security for no benefit.
 *
 * Two things it deliberately does.
 *
 * **It names the consequence, not just the date.** "Your key expires on 3
 * November" is a fact nobody schedules around; "after that no SEPA files can be
 * produced" is. The tier changes how loudly this is said — the 7-day subject
 * line carries the urgency, because at that point nobody is opening a mail to
 * find out whether it matters — but never *what* is said: all three tiers carry
 * the same four facts, so a warning skimmed at 90 days and read at 7 does not
 * appear to be about a different thing.
 *
 * **It carries no secret.** Not the public key, not a fingerprint, not any part
 * of a token — the key is named by the identifier the dashboard shows and the
 * terminal by the name an admin gave it. The message says so out loud, because
 * a mail about credentials is exactly the shape a phishing attempt imitates,
 * and a reader who knows this one never contains key material has a rule they
 * can apply to the one that does.
 */
final class CredentialExpiryMail
{
    public static function render(CredentialExpiryDataDto $data): MailMessage
    {
        $t = new MailStrings($data->language);

        $credentialLabel = $t->t('credential_expiry.credential.' . $data->credential);

        $subject = $t->t('credential_expiry.subject.' . $data->tierDays, [
            // How the credential is named in a subject line is a translation,
            // down to the quotation marks — German and English do not use the
            // same ones. The key is named by what it *is* rather than by its
            // identifier: "a3f9c1: expires in 7 days" is unreadable in an inbox
            // list, and the identifier is in the table two lines down.
            'name' => $t->t('credential_expiry.subject_name.' . $data->credential, [
                'name' => $data->name,
            ]),
            'days' => (string) $data->daysLeft,
        ]);

        $rows = [
            $t->t('credential_expiry.label_credential') => $credentialLabel,
            $t->t('credential_expiry.label_name') => $data->name,
            $t->t('credential_expiry.label_expires') => $data->expiresOn,
            $t->t('credential_expiry.label_days') => $t->t('credential_expiry.days', [
                'days' => (string) $data->daysLeft,
            ]),
        ];

        $html = MailLayout::document($data->branding, [
            'title' => $subject,
            'preview' => $t->t('credential_expiry.preheader', [
                'date' => $data->expiresOn,
                'consequence' => $t->t('credential_expiry.consequence_short.' . $data->credential),
            ]),
            'lang' => $data->language->value,
            'content' => self::html($t, $data, $rows),
            'trailer' => $t->t('automated_note'),
        ]);

        return new MailMessage(
            to: $data->recipientAddress,
            subject: $subject,
            html: $html,
            text: self::text($t, $data, $rows),
            toName: $data->recipientName,
        );
    }

    /** @param array<string,string> $rows */
    private static function html(MailStrings $t, CredentialExpiryDataDto $data, array $rows): string
    {
        return MailLayout::contentStart()
            . MailLayout::eyebrow($t->t('credential_expiry.eyebrow'))
            . MailLayout::title($t->t('credential_expiry.title'))
            . MailLayout::paragraph(MailLayout::esc(MailTextBody::greeting($t, $data->recipientName)))
            . MailLayout::lede($t->t('credential_expiry.lede.' . $data->credential, [
                'name' => MailLayout::esc($data->name),
                'date' => MailLayout::esc($data->expiresOn),
                'days' => (string) $data->daysLeft,
            ]))
            . MailLayout::dataTable($rows)
            . MailLayout::paragraph(MailLayout::esc($t->t('credential_expiry.consequence.' . $data->credential)))
            . MailLayout::paragraph(MailLayout::esc($t->t('credential_expiry.next.' . $data->credential)))
            . MailLayout::paragraph(MailLayout::esc($t->t('credential_expiry.no_secret')))
            . MailLayout::signOff($t->t('signoff'), $data->branding->orgName)
            . MailLayout::contentEnd();
    }

    /** @param array<string,string> $rows */
    private static function text(MailStrings $t, CredentialExpiryDataDto $data, array $rows): string
    {
        $lines = [
            MailTextBody::greeting($t, $data->recipientName),
            '',
            $t->t('credential_expiry.lede_text.' . $data->credential, [
                'name' => $data->name,
                'date' => $data->expiresOn,
                'days' => (string) $data->daysLeft,
            ]),
            '',
        ];

        foreach ($rows as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }

        $lines[] = '';
        $lines[] = $t->t('credential_expiry.consequence.' . $data->credential);
        $lines[] = '';
        $lines[] = $t->t('credential_expiry.next.' . $data->credential);
        $lines[] = '';
        $lines[] = $t->t('credential_expiry.no_secret');

        return MailTextBody::finish($lines, $data->branding, $t);
    }
}
