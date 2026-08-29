<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Mail;

use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\MemberCardMail;
use App\Modules\Notifications\Mail\MemberEmailChangeMail;
use App\Shared\Mail\MailBranding;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The four messages a member gets about their own record (ADR-0051).
 *
 * Most of what is asserted here is what these messages must **not** say. They
 * are the first mail this system sends a member for a reason other than money,
 * and each one is read in a mailbox the club does not control — so the
 * interesting claims are about what stays out of them.
 */
class MemberLifecycleMailTest extends TestCase
{
    private function branding(): MailBranding
    {
        return new MailBranding(orgName: 'FRGS Ruderbar');
    }

    /** Both parts of a message, tags stripped, for "does this text appear at all". */
    private function parts(\App\Shared\Mail\MailMessage $m): array
    {
        return [
            'html' => html_entity_decode(strip_tags($m->html), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            'text' => $m->text,
        ];
    }

    public function test_the_welcome_says_the_card_works_and_what_happens_next(): void
    {
        $mail = MemberCardMail::render(
            kind: MailKind::MEMBER_WELCOME,
            recipientAddress: 'anna@example.org',
            firstName: 'Anna',
            language: MailLanguage::German,
            branding: $this->branding(),
        );

        $this->assertSame('anna@example.org', $mail->to);
        $this->assertStringContainsString('FRGS Ruderbar', $mail->subject);

        foreach ($this->parts($mail) as $where => $body) {
            $this->assertStringContainsString('Anna', $body, "$where greets the member by first name");
            $this->assertStringContainsString('freigeschaltet', $body, "$where says the card works");
            // The point of the "what happens next" paragraph: a member who has
            // been told this cannot be surprised by the first Vorabankündigung.
            $this->assertStringContainsString('Vorabankündigung', $body, "$where sets up the announcement");
            $this->assertStringContainsString('Deckel', $body, "$where explains the tab");
        }
    }

    /**
     * Both card notices assume the plastic has not arrived yet.
     *
     * The Kassenwart types the UID in while *preparing* an onboarding, so this
     * mail routinely reaches a member holding nothing. Without the caveat the
     * welcome is an instruction that cannot be followed — and the replacement
     * is worse, because assigning a new UID stops the old card immediately and
     * the member would find that out at the bar.
     */
    public function test_both_card_notices_allow_for_the_card_not_having_arrived(): void
    {
        foreach ([MailKind::MEMBER_WELCOME, MailKind::MEMBER_CARD_REPLACED] as $kind) {
            $mail = MemberCardMail::render(
                kind: $kind,
                recipientAddress: 'anna@example.org',
                firstName: 'Anna',
                language: MailLanguage::German,
                branding: $this->branding(),
            );

            foreach ($this->parts($mail) as $where => $body) {
                $this->assertStringContainsString(
                    'noch gar nicht bekommen',
                    $body,
                    "$where of {$kind->value} must allow for the card not having arrived"
                );
                $this->assertStringContainsString(
                    'in der Hand',
                    $body,
                    "$where of {$kind->value} must say when the card starts working"
                );
            }
        }
    }

    /**
     * The replacement names the gap rather than leaving it to be discovered.
     *
     * `card_uid` is a single column, so a new UID means the old one matches
     * nobody from that moment. A member who has not yet been handed the
     * replacement genuinely cannot pay, and being told beforehand is the whole
     * difference between a warned-about gap and a card that mysteriously
     * stopped working at the bar.
     */
    public function test_the_replacement_warns_about_the_gap_before_the_new_card_arrives(): void
    {
        $mail = MemberCardMail::render(
            kind: MailKind::MEMBER_CARD_REPLACED,
            recipientAddress: 'anna@example.org',
            firstName: 'Anna',
            language: MailLanguage::German,
            branding: $this->branding(),
        );

        foreach ($this->parts($mail) as $where => $body) {
            $this->assertStringContainsString(
                'nicht bezahlen',
                $body,
                "$where must say the member cannot pay until the new card arrives"
            );
        }
    }

    /**
     * The registration form promises the mandate reference and creditor id
     * arrive „mit der Vorabankündigung zum ersten Einzug". A welcome carrying
     * them would move that to a channel the club did not commit to — and at
     * card time there is often no mandate on file to name.
     */
    public function test_the_welcome_carries_no_banking_details(): void
    {
        $mail = MemberCardMail::render(
            kind: MailKind::MEMBER_WELCOME,
            recipientAddress: 'anna@example.org',
            firstName: 'Anna',
            language: MailLanguage::German,
            branding: $this->branding(),
        );

        foreach ($this->parts($mail) as $where => $body) {
            foreach (['Mandatsreferenz', 'Gläubiger', 'IBAN', 'DE89'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $body, "$where must not carry $forbidden");
            }
        }
    }

    public function test_the_replacement_says_the_old_card_stopped_and_the_tab_did_not(): void
    {
        $mail = MemberCardMail::render(
            kind: MailKind::MEMBER_CARD_REPLACED,
            recipientAddress: 'anna@example.org',
            firstName: 'Anna',
            language: MailLanguage::German,
            branding: $this->branding(),
        );

        foreach ($this->parts($mail) as $where => $body) {
            $this->assertStringContainsString('funktioniert nicht mehr', $body, "$where retires the old card");
            $this->assertStringContainsString('unverändert', $body, "$where reassures about the tab");
            $this->assertStringContainsString('Kassenwart', $body, "$where says who to tell");
        }
    }

    /**
     * The member is holding the card. Printing its UID adds nothing they cannot
     * read off the plastic, and puts a card identifier in a mailbox.
     */
    public function test_no_card_notice_prints_a_card_uid(): void
    {
        foreach ([MailKind::MEMBER_WELCOME, MailKind::MEMBER_CARD_REPLACED] as $kind) {
            $mail = MemberCardMail::render(
                kind: $kind,
                recipientAddress: 'anna@example.org',
                firstName: 'Anna',
                language: MailLanguage::German,
                branding: $this->branding(),
            );

            foreach ($this->parts($mail) as $where => $body) {
                $this->assertDoesNotMatchRegularExpression(
                    '/\b[0-9A-F]{8,20}\b/',
                    $body,
                    "$where of {$kind->value} looks like it contains a card UID"
                );
            }
        }
    }

    /**
     * The load-bearing claim of the address pair, and the reason
     * {@see MemberEmailChangeMail} takes no second address at all.
     *
     * Bodies render at send time from live state (ADR-0038 rule 5). An address
     * printed in one of these could have moved again between a greylisted
     * attempt and the one that succeeds, and a message naming the wrong address
     * is worse than one naming none. Each copy proves its own address by
     * arriving there.
     */
    public function test_neither_address_notice_names_the_other_address(): void
    {
        $former = 'anna.old@example.org';
        $current = 'anna.new@example.org';

        $formerMail = MemberEmailChangeMail::render(
            kind: MailKind::MEMBER_EMAIL_CHANGED,
            recipientAddress: $former,
            firstName: 'Anna',
            changedAt: '2026-08-29 14:05:00',
            language: MailLanguage::German,
            branding: $this->branding(),
        );
        $currentMail = MemberEmailChangeMail::render(
            kind: MailKind::MEMBER_EMAIL_ACTIVATED,
            recipientAddress: $current,
            firstName: 'Anna',
            changedAt: '2026-08-29 14:05:00',
            language: MailLanguage::German,
            branding: $this->branding(),
        );

        foreach ($this->parts($formerMail) as $where => $body) {
            $this->assertStringContainsString($former, $body, "$where names the address it arrived at");
            $this->assertStringNotContainsString($current, $body, "$where must not name the new address");
        }

        foreach ($this->parts($currentMail) as $where => $body) {
            $this->assertStringContainsString($current, $body, "$where names the address it arrived at");
            $this->assertStringNotContainsString($former, $body, "$where must not name the old address");
        }
    }

    /**
     * The old-address copy is the one that has to say "this is the last thing
     * you get here, and tell somebody if it was not you" — it is the only
     * channel a change the member did not ask for can reach them through.
     */
    public function test_the_former_address_copy_says_it_is_the_last_one(): void
    {
        $mail = MemberEmailChangeMail::render(
            kind: MailKind::MEMBER_EMAIL_CHANGED,
            recipientAddress: 'anna.old@example.org',
            firstName: 'Anna',
            changedAt: '2026-08-29 14:05:00',
            language: MailLanguage::German,
            branding: $this->branding(),
        );

        foreach ($this->parts($mail) as $where => $body) {
            $this->assertStringContainsString('letzte Nachricht', $body, "$where says it is the last one");
            $this->assertStringContainsString('Kassenwart', $body, "$where says who to tell");
        }
    }

    /**
     * It is a reachability probe, not a verification. Nothing is gated on it
     * and there is no token to click, so it must not ask for one.
     */
    public function test_the_new_address_copy_asks_for_no_confirmation(): void
    {
        $mail = MemberEmailChangeMail::render(
            kind: MailKind::MEMBER_EMAIL_ACTIVATED,
            recipientAddress: 'anna.new@example.org',
            firstName: 'Anna',
            changedAt: '2026-08-29 14:05:00',
            language: MailLanguage::German,
            branding: $this->branding(),
        );

        foreach ($this->parts($mail) as $where => $body) {
            $this->assertStringContainsString('nichts bestätigen', $body, "$where says there is nothing to do");
            $this->assertStringNotContainsString('http', $body, "$where must offer no confirmation link");
        }
    }

    public function test_english_renders_in_english(): void
    {
        $mail = MemberCardMail::render(
            kind: MailKind::MEMBER_WELCOME,
            recipientAddress: 'anna@example.org',
            firstName: 'Anna',
            language: MailLanguage::English,
            branding: $this->branding(),
        );

        $this->assertStringContainsString('Welcome to FRGS Ruderbar', $mail->subject);
        $this->assertStringContainsString('your membership card is now active', $mail->text);
        $this->assertStringContainsString('has not reached you yet', $mail->text);
        $this->assertStringNotContainsString('freigeschaltet', $mail->text);
    }

    /** An anonymized member has no first name; the greeting falls back rather than breaking. */
    public function test_a_member_with_no_name_still_gets_a_greeting(): void
    {
        $mail = MemberCardMail::render(
            kind: MailKind::MEMBER_WELCOME,
            recipientAddress: 'anna@example.org',
            firstName: null,
            language: MailLanguage::German,
            branding: $this->branding(),
        );

        $this->assertStringContainsString('Hallo', $mail->text);
        $this->assertNotSame('', trim($mail->text));
    }

    /**
     * Each class refuses a kind it has no words for rather than rendering
     * whichever message its `match` happens to reach first. A silent wrong
     * message is the failure mode {@see MailKind} builds its whole exhaustive
     * -match discipline around.
     */
    public function test_each_renderer_refuses_a_kind_it_does_not_own(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MemberCardMail::render(
            kind: MailKind::MEMBER_EMAIL_CHANGED,
            recipientAddress: 'anna@example.org',
            firstName: 'Anna',
            language: MailLanguage::German,
            branding: $this->branding(),
        );
    }

    public function test_the_address_renderer_refuses_a_card_kind(): void
    {
        $this->expectException(InvalidArgumentException::class);

        MemberEmailChangeMail::render(
            kind: MailKind::MEMBER_WELCOME,
            recipientAddress: 'anna@example.org',
            firstName: 'Anna',
            changedAt: '2026-08-29 14:05:00',
            language: MailLanguage::German,
            branding: $this->branding(),
        );
    }
}
