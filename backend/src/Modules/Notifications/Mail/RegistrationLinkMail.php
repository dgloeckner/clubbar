<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Mail;

use App\Modules\Notifications\Enums\MailLanguage;
use App\Shared\Mail\MailBranding;
use App\Shared\Mail\MailLayout;
use App\Shared\Mail\MailMessage;

/**
 * "Here is how you join the club" — the Anmeldelink (#821, ADR-0053).
 *
 * The one message in this system addressed to somebody the club knows nothing
 * about. There is no name to greet them by, no record to refer to and no
 * account to sign in to; what travels is the same URL the QR poster on the
 * clubhouse wall encodes, opening the same page (UC-P01).
 *
 * Three things follow from that, and each is a deliberate difference from
 * {@see AdminInvitationMail}, the message this one otherwise resembles:
 *
 * 1. **No expiry is named, because there is none.** The link is the poster's
 *    and lives as long as the poster's secret does. Naming a lifetime the
 *    system does not enforce would be a promise nobody keeps; naming none is
 *    accurate. The one way it *can* die — a rotation — is the club's own act
 *    and is announced on the screen that performs it (UC-A69), not here.
 * 2. **The link is printed as text as well as linked**, exactly as the
 *    invitation's is and for the same reason: a button is not a link everybody
 *    can use, and the fallback for a broken one is asking the club to send
 *    another.
 * 3. **It says that paper is part of joining.** This is the biggest surprise in
 *    the flow and the one the poster does not have to explain: somebody
 *    standing in the clubhouse learns in a minute that filling the form is not
 *    joining — they print, sign by hand and hand it in. Somebody opening a link
 *    at home learns it only if the message says so, and a message that omits it
 *    converts worse than one that is honest about the step.
 *
 * **German only, for now.** There is no club-level default language to render
 * an unknown reader's message in (#820), and inventing one as a side effect of
 * this feature was rejected in design. The language is frozen into the outbox
 * row at enqueue like every other kind, so the day a club default exists this
 * needs no change here.
 */
final class RegistrationLinkMail
{
    public static function render(
        string $recipientAddress,
        string $url,
        MailLanguage $language,
        MailBranding $branding,
    ): MailMessage {
        $t = new MailStrings($language);

        $subject = $t->t('registration_link.subject', ['org' => $branding->orgName]);

        $html = MailLayout::document($branding, [
            'title' => $subject,
            'preview' => $t->t('registration_link.preheader'),
            'lang' => $language->value,
            'content' => self::html($t, $url, $branding),
            'trailer' => $t->t('automated_note'),
        ]);

        return new MailMessage(
            to: $recipientAddress,
            subject: $subject,
            html: $html,
            text: self::text($t, $url, $branding),
            // No display name: the club typed an address and knows nothing else
            // about the person behind it, and guessing one from the local part
            // is how a mail opens by greeting somebody as "j.schmidt".
            toName: null,
        );
    }

    private static function html(MailStrings $t, string $url, MailBranding $branding): string
    {
        return MailLayout::contentStart()
            . MailLayout::eyebrow($t->t('registration_link.eyebrow'))
            . MailLayout::title($t->t('registration_link.title'))
            // The impersonal greeting, always: there is no name on file, and
            // `greeting_generic` is what the table already says for that case.
            . MailLayout::paragraph(MailLayout::esc(MailTextBody::greeting($t, null)))
            . MailLayout::lede($t->t('registration_link.lede', ['org' => MailLayout::esc($branding->orgName)]))
            . MailLayout::button($t->t('registration_link.cta'), $url)
            . MailLayout::paragraph(MailLayout::esc($t->t('registration_link.fallback')))
            . MailLayout::paragraph(MailLayout::link($url, $url))
            // The paper step, in a box of its own rather than in the run of
            // prose: it is the thing a reader must not skim past, and it is the
            // reason somebody who filled the form still is not a member.
            . MailLayout::noteBox(
                $t->t('registration_link.paper_heading'),
                MailLayout::esc($t->t('registration_link.paper_body'))
            )
            . MailLayout::paragraph(MailLayout::esc($t->t('registration_link.unexpected')))
            . MailLayout::signOff($t->t('signoff'), $branding->orgName)
            . MailLayout::contentEnd();
    }

    private static function text(MailStrings $t, string $url, MailBranding $branding): string
    {
        $out = [
            $t->t('registration_link.title'),
            $t->t('text_separator'),
            '',
            MailTextBody::greeting($t, null),
            '',
            $t->t('registration_link.lede_text', ['org' => $branding->orgName]),
            '',
            $t->t('registration_link.cta') . ':',
            $url,
            '',
            $t->t('registration_link.paper_heading'),
            $t->t('registration_link.paper_body'),
            '',
            $t->t('registration_link.unexpected'),
            '',
            $t->t('signoff'),
            $branding->orgName,
        ];

        return MailTextBody::finish($out, $branding, $t);
    }
}
