<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Modules\Notifications\DTOs\JugendschutzViolationDataDto;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailMessage;

/**
 * "A drink went out below its minimum age" (#622, ADR-0045 §3).
 *
 * Addressed to whoever runs the club, never to the member — the member here is
 * the person the message is *about*, and telling them the club has filed an
 * incident against them is neither the point nor kind.
 *
 * **It names no member, and that is not an oversight.** `AdminNotifier` writes
 * to every active admin account, which includes the Getränkewart, and ADR-0045
 * invariant 5 says they gain no member data. Rule 6 pushes the same way: name
 * what the *drink* required, never what the member is. The transaction number
 * is the handle — whoever is entitled to resolve it can, in a surface their own
 * role permits, and whoever is not simply cannot.
 *
 * It also does not tell anyone to reverse the booking. The sale happened, the
 * row is the § 146 AO record of it, and an email is the wrong place to suggest
 * otherwise.
 */
final class JugendschutzViolationMail
{
    public static function render(JugendschutzViolationDataDto $data): MailMessage
    {
        $t = new MailStrings($data->language);

        $subject = $t->t('jugendschutz.subject');
        $age = $t->t('jugendschutz.years', ['age' => (string) $data->requiredAge]);

        $rows = [
            $t->t('jugendschutz.row.product') => $data->productName
                ?? $t->t('jugendschutz.product_deleted'),
            $t->t('jugendschutz.row.required') => $age,
            $t->t('jugendschutz.row.occurred') => $data->occurredAt,
            $t->t('jugendschutz.row.transaction') => $data->transactionId,
        ];
        if ($data->terminalName !== null) {
            $rows[$t->t('jugendschutz.row.terminal')] = $data->terminalName;
        }

        $html = MailLayout::document($data->branding, [
            'title' => $subject,
            'preview' => $t->t('jugendschutz.preheader'),
            'lang' => $data->language->value,
            'content' => self::html($t, $data, $rows, $age),
            'trailer' => $t->t('automated_note'),
        ]);

        return new MailMessage(
            to: $data->recipientAddress,
            subject: $subject,
            html: $html,
            text: self::text($t, $data, $rows, $age),
            toName: $data->recipientName,
        );
    }

    /** @param array<string,string> $rows */
    private static function html(MailStrings $t, JugendschutzViolationDataDto $data, array $rows, string $age): string
    {
        return MailLayout::contentStart()
            . MailLayout::eyebrow($t->t('jugendschutz.eyebrow'))
            . MailLayout::title($t->t('jugendschutz.title'))
            . MailLayout::paragraph(MailLayout::esc(MailTextBody::greeting($t, $data->recipientName)))
            . MailLayout::lede($t->t('jugendschutz.lede', ['age' => (string) $data->requiredAge]))
            . MailLayout::dataTable($rows)
            . MailLayout::paragraph(MailLayout::esc($t->t('jugendschutz.why')))
            . MailLayout::paragraph(MailLayout::esc($t->t('jugendschutz.next')))
            . MailLayout::paragraph(MailLayout::esc($t->t('jugendschutz.no_member')))
            . MailLayout::signOff($t->t('signoff'), $data->branding->orgName)
            . MailLayout::contentEnd();
    }

    /** @param array<string,string> $rows */
    private static function text(MailStrings $t, JugendschutzViolationDataDto $data, array $rows, string $age): string
    {
        $lines = [
            MailTextBody::greeting($t, $data->recipientName),
            '',
            $t->t('jugendschutz.lede_text', ['age' => (string) $data->requiredAge]),
            '',
        ];

        foreach ($rows as $label => $value) {
            $lines[] = $label . ': ' . $value;
        }

        $lines[] = '';
        $lines[] = $t->t('jugendschutz.why');
        $lines[] = '';
        $lines[] = $t->t('jugendschutz.next');
        $lines[] = '';
        $lines[] = $t->t('jugendschutz.no_member');

        return MailTextBody::finish($lines, $data->branding, $t);
    }
}
