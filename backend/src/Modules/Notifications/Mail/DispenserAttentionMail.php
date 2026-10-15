<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Modules\Notifications\DTOs\DispenserAttentionDataDto;
use App\Modules\Notifications\Enums\DispenserAttentionOccasion;
use App\Modules\Terminals\Enums\DispenserUnavailableReason;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailMessage;

/**
 * "A dispenser needs a human" (#956, ADR-0057 / ADR-0058).
 *
 * The push half of the Terminals page: the same four conditions it shows —
 * jammed, unreachable, protocol mismatch, running out — reaching the one office
 * that can act on them, without waiting for somebody to open the panel.
 *
 * ## The words are not this template's to choose
 *
 * The condition names and the remedies are **verbatim** the panel's and the
 * kiosk's (`settings.terminalDispenserUnavailable*`,
 * `settings.terminalDispenserRemedy*`, `app_de.arb`). Three surfaces describe
 * one machine, and a fourth translation of "Stau oder leer" would be how a club
 * starts deciding which screen to believe.
 *
 * ## Four conditions, four errands, and one that is not a fault
 *
 * A protocol mismatch gets its own lede saying nothing is broken at the machine
 * (ADR-0057 context 4). Folding it into "offline" is the defect this epic
 * already found once, and it sent somebody looking for a power cable.
 *
 * ## It offers nothing to press
 *
 * No acknowledgement, no "mark as seen", no link that writes anything. The
 * device has no reset route and a jam is cleared by a power cycle (owner
 * decision 3 of #944), so a button here would change a screen and not a hopper
 * — and the next member would meet the same jam with the mail reporting it
 * handled. The message says so in a sentence, because the absence is the design.
 */
final class DispenserAttentionMail
{
    public static function render(DispenserAttentionDataDto $data): MailMessage
    {
        $t = new MailStrings($data->language);
        $condition = self::condition($t, $data);

        $subject = $data->cleared
            ? $t->t('dispenser.subject_cleared', ['terminal' => $data->terminalName])
            : $t->t('dispenser.subject', ['terminal' => $data->terminalName, 'condition' => $condition]);

        $html = MailLayout::document($data->branding, [
            'title' => $subject,
            'preview' => $data->cleared
                ? $t->t('dispenser.preheader_cleared')
                : $t->t('dispenser.preheader', [
                    'condition' => $condition,
                    'since' => $data->since ?? '—',
                ]),
            'lang' => $data->language->value,
            'content' => self::html($t, $data, $condition),
            'trailer' => $t->t('automated_note'),
        ]);

        return new MailMessage(
            to: $data->recipientAddress,
            subject: $subject,
            html: $html,
            text: self::text($t, $data, $condition),
            toName: $data->recipientName,
        );
    }

    private static function html(MailStrings $t, DispenserAttentionDataDto $data, string $condition): string
    {
        $html = MailLayout::contentStart()
            . MailLayout::eyebrow($t->t('dispenser.eyebrow'))
            . MailLayout::title($t->t($data->cleared ? 'dispenser.title_cleared' : 'dispenser.title'))
            . MailLayout::paragraph(MailLayout::esc(MailTextBody::greeting($t, $data->recipientName)));

        if ($data->cleared) {
            return $html
                . MailLayout::lede($t->t('dispenser.cleared_lede', [
                    'terminal' => MailLayout::esc($data->terminalName),
                ]))
                . MailLayout::signOff($t->t('signoff'), $data->branding->orgName)
                . MailLayout::contentEnd();
        }

        $html .= MailLayout::lede($t->t(self::ledeKey($data->occasion), [
            'terminal' => MailLayout::esc($data->terminalName),
            'condition' => MailLayout::esc($condition),
        ]));

        if ($data->occasion === DispenserAttentionOccasion::LOW) {
            $html .= MailLayout::paragraph(MailLayout::esc(self::fillLine($t, $data)))
                . MailLayout::paragraph(MailLayout::esc($t->t('dispenser.fill_note')));
        } elseif ($data->since !== null) {
            $html .= MailLayout::paragraph(MailLayout::esc(
                $t->t('dispenser.since', ['since' => $data->since])
            ));
        }

        if ($data->probablyEmpty) {
            $html .= MailLayout::paragraph(MailLayout::esc($t->t('dispenser.probably_empty')));
        }

        $html .= MailLayout::noteBox(
            $t->t('dispenser.remedy_heading'),
            MailLayout::esc($t->t(self::remedyKey($data->occasion)))
        );

        $where = $t->t('dispenser.where');
        $html .= $data->panelUrl === null
            ? MailLayout::paragraph(MailLayout::esc($where))
            : MailLayout::paragraph(
                MailLayout::esc($where) . ' ' . MailLayout::link($data->panelUrl, $data->panelUrl)
            );

        return $html
            . MailLayout::paragraph(MailLayout::esc($t->t('dispenser.no_remote')))
            . MailLayout::signOff($t->t('signoff'), $data->branding->orgName)
            . MailLayout::contentEnd();
    }

    private static function text(MailStrings $t, DispenserAttentionDataDto $data, string $condition): string
    {
        $lines = [MailTextBody::greeting($t, $data->recipientName), ''];

        if ($data->cleared) {
            $lines[] = $t->t('dispenser.cleared_lede', ['terminal' => $data->terminalName]);

            return MailTextBody::finish($lines, $data->branding, $t);
        }

        $lines[] = $t->t(self::ledeKey($data->occasion, text: true), [
            'terminal' => $data->terminalName,
            'condition' => $condition,
        ]);
        $lines[] = '';

        if ($data->occasion === DispenserAttentionOccasion::LOW) {
            $lines[] = self::fillLine($t, $data);
            $lines[] = $t->t('dispenser.fill_note');
        } elseif ($data->since !== null) {
            $lines[] = $t->t('dispenser.since', ['since' => $data->since]);
        }

        if ($data->probablyEmpty) {
            $lines[] = $t->t('dispenser.probably_empty');
        }

        $lines[] = '';
        $lines[] = $t->t('dispenser.remedy_heading') . ': ' . $t->t(self::remedyKey($data->occasion));
        $lines[] = '';
        $lines[] = $data->panelUrl === null
            ? $t->t('dispenser.where')
            : $t->t('dispenser.where') . ' ' . $data->panelUrl;
        $lines[] = '';
        $lines[] = $t->t('dispenser.no_remote');

        return MailTextBody::finish($lines, $data->branding, $t);
    }

    /**
     * The condition in the words the panel and the kiosk use.
     *
     * A hopper error carries its code, because Azkoyen's codes 1–7 name
     * different things to whoever opens the machine; every other condition is
     * one fixed phrase.
     */
    private static function condition(MailStrings $t, DispenserAttentionDataDto $data): string
    {
        if ($data->occasion === DispenserAttentionOccasion::LOW) {
            return $t->t('dispenser.condition.low');
        }

        // A fault occasion with no reason cannot arise from the scan, which
        // derives the occasion *from* the reason — but the builder reads a row
        // that may have been written by hand, and a subject line reading
        // "Ausgabegerät „Theke“: " would be worse than a generic word.
        $reason = $data->reason ?? DispenserUnavailableReason::UNSPECIFIED_FAULT;

        return $t->t('dispenser.condition.' . $reason->value, ['code' => (string) $data->faultCode]);
    }

    private static function fillLine(MailStrings $t, DispenserAttentionDataDto $data): string
    {
        // Zero is *exhausted*, never "0 tokens left" — the panel words it the
        // same way, because a number is a claim about precision the subtraction
        // does not have once it has run out.
        if ($data->estimatedLeft === null || $data->estimatedLeft <= 0) {
            return $t->t('dispenser.fill_exhausted');
        }

        return $t->t('dispenser.fill', [
            'value' => (string) $data->estimatedLeft,
            'threshold' => (string) ($data->lowThreshold ?? 0),
        ]);
    }

    private static function ledeKey(DispenserAttentionOccasion $occasion, bool $text = false): string
    {
        $prefix = $text ? 'dispenser.lede_text.' : 'dispenser.lede.';

        return $prefix . match ($occasion) {
            DispenserAttentionOccasion::FAULT => 'fault',
            DispenserAttentionOccasion::OFFLINE => 'offline',
            DispenserAttentionOccasion::MISMATCH => 'mismatch',
            DispenserAttentionOccasion::LOW => 'low',
        };
    }

    private static function remedyKey(DispenserAttentionOccasion $occasion): string
    {
        return 'dispenser.remedy.' . match ($occasion) {
            DispenserAttentionOccasion::FAULT => 'fault',
            DispenserAttentionOccasion::OFFLINE => 'offline',
            DispenserAttentionOccasion::MISMATCH => 'protocol',
            DispenserAttentionOccasion::LOW => 'low',
        };
    }
}
