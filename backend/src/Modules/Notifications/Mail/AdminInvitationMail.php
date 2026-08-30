<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Modules\AdminUsers\Enums\AdminRole;
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
 * **It names the recipient's own role, and only that.** The first draft named
 * none, on the reasoning that a list of offices in an unauthenticated mailbox
 * tells an unintended reader how the club is organised. That reasoning holds
 * for *other people's* offices and not for the reader's own — and the cost of
 * omitting it was worse than the disclosure: every invitation called itself an
 * "Admin-Zugang" regardless of the account behind it, so a Getränkewart being
 * onboarded was told they had been given administrator access to the club's
 * installation. Alarming, and false.
 *
 * So the account's own role travels, the subject line says only that an
 * account exists, and no other account is mentioned. A reader who is not the
 * intended one learns that this address was given one named job — which the
 * previous version disclosed too, less accurately.
 */
final class AdminInvitationMail
{
    /** @param list<AdminRole> $roles The account's own roles — never anybody else's. */
    public static function render(
        string $recipientAddress,
        ?string $recipientName,
        string $signInEmail,
        array $roles,
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
            // Above the expiry: what the account *is* matters more to the
            // reader than when the link stops working.
            $t->t('admin_invitation.label_role') => self::roleList($t, $roles),
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

    /**
     * The account's roles, as the club names them.
     *
     * `Kassenwart` and `Getränkewart` are Vereinsämter and stay untranslated in
     * both languages, the same precedent CONTEXT.md sets for `Storno` and
     * `Deckel` — and the same table {@see AdminLifecycleMail} reads from, so
     * one account is described identically wherever it is described.
     *
     * An empty set cannot reach here: the domain refuses an account with no
     * role. It degrades rather than throwing anyway, because a mail builder is
     * the wrong place to discover it.
     *
     * @param list<AdminRole> $roles
     */
    private static function roleList(MailStrings $t, array $roles): string
    {
        if ($roles === []) {
            return $t->t('admin_lifecycle.roles_none');
        }

        return implode(', ', array_map(
            static fn (AdminRole $role): string => $t->t('admin_lifecycle.role.' . $role->value),
            $roles,
        ));
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
