<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Modules\CreditLimits\Domain\CreditLimitStatus;
use App\Modules\Notifications\DTOs\CreditLimitDigestDataDto;
use App\Modules\Notifications\DTOs\CreditLimitDigestLineDto;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailMessage;

/**
 * "These members are close to their Deckel limit" — one mail, every name.
 *
 * Addressed to whoever runs the club and never to a member. A member who is
 * near their ceiling is told by the terminal, at the bar, at the moment it
 * matters (UC-T12); this is the treasurer's copy of the same fact, early enough
 * to do something about it.
 *
 * **This one names members, where {@see JugendschutzViolationMail} deliberately
 * does not.** The difference is who receives it: that notice fans out to every
 * active admin account and had to survive a Getränkewart reading it, while this
 * kind's `recipientRoles()` is the treasury pair — the same set that can already
 * open the dashboard panel this digest mirrors and click through to any member
 * on it. Withholding the names here would not protect anything; it would just
 * make the mail useless, because "somebody is near their limit" is not
 * actionable and "Anna Schmidt is €4 short of hers" is.
 *
 * It asks for nothing and collects nothing. Whether a full Deckel means a quiet
 * word, a settlement run or a raised ceiling is a judgement the mail does not
 * make on the reader's behalf.
 */
final class CreditLimitDigestMail
{
    public static function render(CreditLimitDigestDataDto $data): MailMessage
    {
        $t = new MailStrings($data->language);

        // The count is in the subject line, because a subject that reads the
        // same every week is one that stops being read. "3 Mitglieder" against
        // "11 Mitglieder" is the difference between filing it and opening it.
        $subject = $data->report->isEmpty()
            ? $t->t('credit_digest.subject_empty')
            : $t->t('credit_digest.subject', ['count' => (string) $data->report->count()]);

        $html = MailLayout::document($data->branding, [
            'title' => $subject,
            'preview' => self::preheader($t, $data),
            'lang' => $data->language->value,
            'content' => self::html($t, $data),
            'trailer' => $t->t('automated_note'),
        ]);

        return new MailMessage(
            to: $data->recipientAddress,
            subject: $subject,
            html: $html,
            text: self::text($t, $data),
            toName: $data->recipientName,
        );
    }

    private static function preheader(MailStrings $t, CreditLimitDigestDataDto $data): string
    {
        return $data->report->isEmpty()
            ? $t->t('credit_digest.preheader_empty')
            : $t->t('credit_digest.preheader', [
                'count' => (string) $data->report->count(),
                'total' => MailFormat::money($data->report->totalOwedCents, $data->language),
            ]);
    }

    private static function html(MailStrings $t, CreditLimitDigestDataDto $data): string
    {
        $html = MailLayout::contentStart()
            . MailLayout::eyebrow($t->t('credit_digest.eyebrow'))
            . MailLayout::title($t->t('credit_digest.title'))
            . MailLayout::paragraph(MailLayout::esc(MailTextBody::greeting($t, $data->recipientName)));

        if ($data->report->isEmpty()) {
            return $html
                . MailLayout::lede($t->t('credit_digest.empty_lede'))
                . MailLayout::signOff($t->t('signoff'), $data->branding->orgName)
                . MailLayout::contentEnd();
        }

        $html .= MailLayout::lede($t->t('credit_digest.lede', [
            'count' => (string) $data->report->count(),
        ]));

        // The itemised table: name, share of the ceiling, and the tab against
        // the ceiling. `itemTable` gives three columns with the money right
        // aligned, which is what makes a column of amounts scannable — the
        // same shape the Deckelauszug uses for its bookings.
        $html .= MailLayout::itemTable(
            array_map(
                static fn(CreditLimitDigestLineDto $line): array => self::row($t, $line, $data),
                $data->report->lines,
            ),
            $t->t('credit_digest.total_label'),
            MailFormat::money($data->report->totalOwedCents, $data->language),
        );

        if ($data->report->omitted > 0) {
            // Never a silent truncation. A hundred names is already an
            // unusual evening; a list that simply stopped there would read as
            // "that is everybody", and the club where it is not is the one
            // that most needs to know.
            $html .= MailLayout::paragraph(MailLayout::esc($t->t('credit_digest.omitted', [
                'count' => (string) $data->report->omitted,
            ])));
        }

        if ($data->report->exceededCount > 0) {
            // Called out separately rather than left to be spotted in the
            // table: a member past their ceiling is not "approaching" anything,
            // they are being refused at the till right now, and that is the one
            // line in this mail with a deadline attached to it.
            $html .= MailLayout::noteBox(
                $t->t('credit_digest.exceeded_heading'),
                MailLayout::esc($t->t('credit_digest.exceeded_body', [
                    'count' => (string) $data->report->exceededCount,
                ])),
            );
        }

        return $html
            . MailLayout::paragraph(MailLayout::esc(self::policyLine($t, $data)))
            . MailLayout::paragraph(MailLayout::esc($t->t('credit_digest.next')))
            . MailLayout::signOff($t->t('signoff'), $data->branding->orgName)
            . MailLayout::contentEnd();
    }

    /**
     * The line that makes the numbers above interpretable: the club's own
     * ceiling and the share at which the terminal starts warning.
     *
     * Without it a reader cannot tell an override from a mistake — a row
     * showing €480 of €500 next to a club default of €100 looks like a bug
     * until the club default is stated.
     */
    private static function policyLine(MailStrings $t, CreditLimitDigestDataDto $data): string
    {
        return $t->t('credit_digest.policy', [
            'limit' => MailFormat::money($data->report->clubDefaultLimitCents, $data->language),
            'percent' => (string) $data->report->warnThresholdPercent,
        ]);
    }

    /**
     * @return array{label: string, quantity: string, amount: string}
     */
    private static function row(
        MailStrings $t,
        CreditLimitDigestLineDto $line,
        CreditLimitDigestDataDto $data,
    ): array {
        $name = $line->name !== '' ? $line->name : $t->t('credit_digest.unnamed_member');

        return [
            // The status marks the row that has already crossed the line. A
            // word rather than a colour: a table cell tinted red is invisible
            // in a plain-text client and in half the HTML ones.
            'label' => $line->status === CreditLimitStatus::EXCEEDED
                ? $name . ' — ' . $t->t('credit_digest.status.exceeded')
                : $name,
            'quantity' => $t->t('credit_digest.percent', ['percent' => (string) $line->percentOfLimit]),
            'amount' => $t->t('credit_digest.of_limit', [
                'balance' => MailFormat::money($line->balanceCents, $data->language),
                'limit' => MailFormat::money($line->limitCents, $data->language),
            ]),
        ];
    }

    private static function text(MailStrings $t, CreditLimitDigestDataDto $data): string
    {
        $lines = [MailTextBody::greeting($t, $data->recipientName), ''];

        if ($data->report->isEmpty()) {
            $lines[] = $t->t('credit_digest.empty_lede_text');

            return MailTextBody::finish($lines, $data->branding, $t);
        }

        $lines[] = $t->t('credit_digest.lede_text', ['count' => (string) $data->report->count()]);
        $lines[] = '';

        foreach ($data->report->lines as $line) {
            $row = self::row($t, $line, $data);
            $lines[] = '- ' . $row['label'] . ': ' . $row['amount'] . ' (' . $row['quantity'] . ')';
        }

        $lines[] = '';
        $lines[] = $t->t('credit_digest.total_label') . ': '
            . MailFormat::money($data->report->totalOwedCents, $data->language);

        if ($data->report->omitted > 0) {
            $lines[] = '';
            $lines[] = $t->t('credit_digest.omitted', ['count' => (string) $data->report->omitted]);
        }

        if ($data->report->exceededCount > 0) {
            $lines[] = '';
            $lines[] = $t->t('credit_digest.exceeded_heading') . ': '
                . $t->t('credit_digest.exceeded_body', ['count' => (string) $data->report->exceededCount]);
        }

        $lines[] = '';
        $lines[] = self::policyLine($t, $data);
        $lines[] = '';
        $lines[] = $t->t('credit_digest.next');

        return MailTextBody::finish($lines, $data->branding, $t);
    }
}
