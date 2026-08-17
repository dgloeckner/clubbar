<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Shared\Mail\MailBranding;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailMessage;

/**
 * "A key was registered / put in force / withdrawn" (ADR-0036).
 *
 * The message exists to reach an admin who did **not** perform the action.
 * Everything else about a key swap stays inside the panel: the audit log
 * records it and nothing reads the audit log, and the dashboard banner only
 * speaks for a key that is missing or expiring, so one activated today with
 * 365 days on it says nothing at all.
 *
 * **The fingerprint travels in full, and that is the point.** It is the public
 * SHA-256 of a public key — not key material, and safe to put in a mailbox —
 * and comparing it against what the offline generator printed is the only
 * check that separates the club's own key from somebody else's. The dashboard
 * names a key by its `key_identifier`, which whoever registered it typed in
 * and can therefore make say anything; the fingerprint is the half that cannot
 * be chosen.
 *
 * What it does not carry is any private key, any ciphertext, and any IBAN.
 *
 * **It names who acted, when that is known.** The outbox row's `admin_user_id`
 * is the admin being *written to* — `warnAdmins()` fans one message out per
 * admin — but migration 043 added a separate `actor_admin_user_id`, recorded
 * durably at enqueue time, so the row also carries who performed the swap. It
 * can legitimately be blank — a row queued before that migration, or one whose
 * actor's admin account was later deleted — and the line drops rather than
 * printing empty. The full attribution, with the fingerprint, still lives in
 * the `key_registered` / `key_activated` / `key_revoked` audit entry.
 *
 * There is likewise no action link: the remedy is to open Settings → Security &
 * Credentials, and a one-click control reachable from an email would be a way
 * around the step-up that guards key management in the first place — the same
 * reasoning {@see AdminEmailChangedMail} gives for having no undo link.
 */
final class EncryptionKeyEventMail
{
    public static function render(
        MailKind $kind,
        string $recipientAddress,
        ?string $recipientName,
        string $keyIdentifier,
        string $fingerprintSha256,
        string $actorLabel,
        string $occurredAt,
        MailLanguage $language,
        MailBranding $branding,
    ): MailMessage {
        $t = new MailStrings($language);
        $event = self::event($kind);

        $subject = $t->t('key_event.subject.' . $event);

        $rows = array_filter([
            $t->t('key_event.label_name') => $keyIdentifier,
            // Unbroken and unshortened: an admin comparing it against a sheet
            // of paper needs the whole string, and a truncated one would let
            // two keys that differ late in the digest look identical.
            $t->t('key_event.label_fingerprint') => $fingerprintSha256,
            $t->t('key_event.label_actor') => $actorLabel,
            $t->t('key_event.label_when') => MailFormat::date($occurredAt, $language),
        ], static fn(string $value): bool => trim($value) !== '');

        $preheader = $t->t('key_event.preheader', [
            'name' => $keyIdentifier,
            'short' => substr($fingerprintSha256, 0, 16),
        ]);

        $html = MailLayout::document($branding, [
            'title' => $subject,
            'preview' => $preheader,
            'lang' => $language->value,
            'content' => self::html($t, $event, $recipientName, $rows, $branding),
            'trailer' => $t->t('automated_note'),
        ]);

        return new MailMessage(
            to: $recipientAddress,
            subject: $subject,
            html: $html,
            text: self::text($t, $event, $recipientName, $rows, $branding),
            toName: $recipientName,
        );
    }

    /** The lifecycle step this kind announces, as it appears in string keys. */
    private static function event(MailKind $kind): string
    {
        return match ($kind) {
            MailKind::ENCRYPTION_KEY_REGISTERED => 'registered',
            MailKind::ENCRYPTION_KEY_ACTIVATED => 'activated',
            MailKind::ENCRYPTION_KEY_REVOKED => 'revoked',
            default => throw new \InvalidArgumentException(
                sprintf('%s is not an encryption key lifecycle event', $kind->value)
            ),
        };
    }

    /** @param array<string,string> $rows */
    private static function html(
        MailStrings $t,
        string $event,
        ?string $recipientName,
        array $rows,
        MailBranding $branding,
    ): string {
        return MailLayout::contentStart()
            . MailLayout::eyebrow($t->t('key_event.eyebrow'))
            . MailLayout::title($t->t('key_event.title.' . $event))
            . MailLayout::paragraph(MailLayout::esc(MailTextBody::greeting($t, $recipientName)))
            . MailLayout::lede($t->t('key_event.lede.' . $event))
            . MailLayout::dataTable($rows)
            . MailLayout::paragraph(MailLayout::esc($t->t('key_event.verify')))
            . MailLayout::paragraph(MailLayout::esc($t->t('key_event.unexpected')))
            . MailLayout::signOff($t->t('signoff'), $branding->orgName)
            . MailLayout::contentEnd();
    }

    /** @param array<string,string> $rows */
    private static function text(
        MailStrings $t,
        string $event,
        ?string $recipientName,
        array $rows,
        MailBranding $branding,
    ): string {
        $out = [
            $t->t('key_event.title.' . $event),
            $t->t('text_separator'),
            '',
            MailTextBody::greeting($t, $recipientName),
            '',
            $t->t('key_event.lede.' . $event),
            '',
        ];

        foreach ($rows as $label => $value) {
            $out[] = $label . ': ' . $value;
        }

        $out[] = '';
        $out[] = $t->t('key_event.verify');
        $out[] = '';
        $out[] = $t->t('key_event.unexpected');
        $out[] = '';
        $out[] = $t->t('signoff');
        $out[] = $branding->orgName;

        return MailTextBody::finish($out, $branding, $t);
    }
}
