<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Shared\Mail\MailBranding;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailMessage;

/**
 * The two messages a card assignment can produce (ADR-0051): the welcome that
 * onboards a member, and the notice that a different card now identifies them.
 *
 * One class for two kinds, as {@see EncryptionKeyEventMail} is for three. They
 * share a shape — a greeting, what just became true, what to do about it — and
 * differ only in which of those it is, so a second class would be the same
 * scaffolding twice.
 *
 * **Neither message prints the card UID.** The member is holding the card, so
 * naming it adds nothing they cannot read off the plastic; what it would add is
 * a card identifier sitting in a mailbox. The welcome likewise carries no
 * Mandatsreferenz and no Gläubiger-ID: the registration form promises those
 * „mit der Vorabankündigung zum ersten Einzug", and at card time there is often
 * no mandate on file to name.
 */
final class MemberCardMail
{
    public static function render(
        MailKind $kind,
        string $recipientAddress,
        ?string $firstName,
        MailLanguage $language,
        MailBranding $branding,
    ): MailMessage {
        $t = new MailStrings($language);
        $prefix = self::prefix($kind);
        $subject = $t->t($prefix . '.subject', ['club' => $branding->orgName]);

        $html = MailLayout::document($branding, [
            'title'   => $subject,
            'preview' => $t->t($prefix . '.preheader'),
            'lang'    => $language->value,
            'content' => self::html($t, $prefix, $firstName, $branding),
            'trailer' => $t->t('automated_note'),
        ]);

        return new MailMessage(
            to: $recipientAddress,
            subject: $subject,
            html: $html,
            text: self::text($t, $prefix, $firstName, $branding),
            toName: $firstName,
        );
    }

    /**
     * The string-table prefix for this kind.
     *
     * An exhaustive `match` rather than a default arm, for the reason
     * {@see MailKind} gives about its own four: a kind this class is later
     * handed and has no words for must fail loudly here rather than render as
     * whichever message happens to be first.
     */
    private static function prefix(MailKind $kind): string
    {
        return match ($kind) {
            MailKind::MEMBER_WELCOME       => 'member_welcome',
            MailKind::MEMBER_CARD_REPLACED => 'member_card',
            default => throw new \InvalidArgumentException(
                'MemberCardMail cannot render ' . $kind->value . ': it renders card assignments only'
            ),
        };
    }

    private static function html(
        MailStrings $t,
        string $prefix,
        ?string $firstName,
        MailBranding $branding,
    ): string {
        return MailLayout::contentStart()
            . MailLayout::eyebrow($t->t($prefix . '.eyebrow'))
            . MailLayout::title($t->t($prefix . '.title', ['club' => MailLayout::esc($branding->orgName)]))
            . MailLayout::paragraph(MailLayout::esc(MailTextBody::greeting($t, $firstName)))
            . MailLayout::lede($t->t($prefix . '.lede'))
            . MailLayout::paragraph(MailLayout::esc($t->t($prefix . '.body')))
            . MailLayout::paragraph(MailLayout::esc($t->t($prefix . '.next')))
            . MailLayout::paragraph(MailLayout::esc($t->t($prefix . '.unexpected')))
            . MailLayout::signOff($t->t('signoff'), $branding->orgName)
            . MailLayout::contentEnd();
    }

    private static function text(
        MailStrings $t,
        string $prefix,
        ?string $firstName,
        MailBranding $branding,
    ): string {
        $out = [
            $t->t($prefix . '.title', ['club' => $branding->orgName]),
            $t->t('text_separator'),
            '',
            MailTextBody::greeting($t, $firstName),
            '',
            $t->t($prefix . '.lede_text'),
            '',
            $t->t($prefix . '.body'),
            '',
            $t->t($prefix . '.next'),
            '',
            $t->t($prefix . '.unexpected'),
            '',
            $t->t('signoff'),
            $branding->orgName,
        ];

        return MailTextBody::finish($out, $branding, $t);
    }
}
