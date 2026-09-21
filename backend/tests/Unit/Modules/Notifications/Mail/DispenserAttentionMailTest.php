<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Mail;

use App\Modules\Notifications\DTOs\DispenserAttentionDataDto;
use App\Modules\Notifications\Enums\DispenserAttentionOccasion;
use App\Modules\Notifications\Enums\MailLanguage;
use App\Modules\Notifications\Mail\DispenserAttentionMail;
use App\Modules\Terminals\Enums\DispenserUnavailableReason;
use App\Shared\Mail\MailBranding;
use PHPUnit\Framework\TestCase;

/**
 * What the dispenser notice says, and what it refuses to offer (#956).
 *
 * The copy rule is the substance here. The condition names and the remedies
 * already exist, identically, in the kiosk's `app_de.arb` and in the panel's
 * `de.json` — so this template **reuses** them rather than translating the same
 * machine a third time. A club that reads „Stau oder leer" at the kiosk, „Stau
 * oder leer" in the panel and something else in its inbox has to decide which
 * screen to believe.
 *
 * Part of #956, epic #944.
 */
class DispenserAttentionMailTest extends TestCase
{
    /**
     * The five conditions, word for word as the panel renders them
     * (`settings.terminalDispenserUnavailable*`).
     */
    public function test_a_fault_is_named_in_the_panel_s_own_words(): void
    {
        $cases = [
            [DispenserUnavailableReason::JAM, 0, 'Stau oder leer'],
            [DispenserUnavailableReason::HOPPER_ERROR, 3, 'Hopper-Fehler 3'],
            [DispenserUnavailableReason::UNSPECIFIED_FAULT, 0, 'Störung'],
        ];

        foreach ($cases as [$reason, $code, $expected]) {
            $mail = DispenserAttentionMail::render($this->fault($reason, $code));

            $this->assertSame('Ausgabegerät „Theke“: ' . $expected, $mail->subject);
            $this->assertStringContainsString($expected, $mail->text);
        }

        $offline = DispenserAttentionMail::render(
            $this->fault(DispenserUnavailableReason::OFFLINE, occasion: DispenserAttentionOccasion::OFFLINE)
        );
        $this->assertSame('Ausgabegerät „Theke“: Nicht erreichbar', $offline->subject);

        $mismatch = DispenserAttentionMail::render(
            $this->fault(
                DispenserUnavailableReason::PROTOCOL_MISMATCH,
                occasion: DispenserAttentionOccasion::MISMATCH,
            )
        );
        $this->assertSame('Ausgabegerät „Theke“: Protokoll passt nicht', $mismatch->subject);
    }

    /** The remedy for a fault is the power-cycle sentence, verbatim. */
    public function test_the_remedy_is_the_one_the_panel_gives(): void
    {
        $mail = DispenserAttentionMail::render($this->fault(DispenserUnavailableReason::JAM));

        $this->assertStringContainsString(
            'Stau beseitigen, bei Bedarf nachfüllen, dann das Gerät 5 Sekunden vom Strom trennen.',
            $mail->text,
        );
        $this->assertStringContainsString('In diesem Zustand seit', $mail->text);
    }

    /**
     * **A protocol mismatch is a deployment errand, not a broken machine.**
     * It says so in its own words and never sends anybody after a power cable
     * — the defect ADR-0057 names as finding 13.
     */
    public function test_a_protocol_mismatch_says_nothing_is_broken_at_the_machine(): void
    {
        $mail = DispenserAttentionMail::render($this->fault(
            DispenserUnavailableReason::PROTOCOL_MISMATCH,
            occasion: DispenserAttentionOccasion::MISMATCH,
        ));

        $this->assertStringContainsString('Am Gerät selbst ist nichts kaputt', $mail->text);
        $this->assertStringNotContainsString('Nicht erreichbar', $mail->text);
        $this->assertStringNotContainsString('Strom und WLAN', $mail->text);
    }

    /**
     * **Nothing to press.** The device has no reset route and a jam is cleared
     * by a power cycle (owner decision 3 of #944), so this message carries no
     * acknowledgement, no "mark as handled" and no link that writes — and says
     * as much, because the absence is the design rather than an omission.
     */
    public function test_the_mail_offers_no_way_to_acknowledge_or_clear_anything(): void
    {
        foreach ([$this->fault(DispenserUnavailableReason::JAM), $this->low(12)] as $data) {
            $mail = DispenserAttentionMail::render($data);
            $body = $mail->text . $mail->html;

            $this->assertStringContainsString('quittiert nichts und setzt nichts zurück', $mail->text);

            foreach (['quittieren', 'zurücksetzen', 'erledigt markieren', 'Störung aufheben'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $body, $forbidden . ' must not be offered');
            }

            // **Every** address in the message is the panel it points at, and
            // opening a page writes nothing. Asserted over the set of URLs
            // rather than over one string, because what must not exist is the
            // *other* link — the one that would act on the machine.
            preg_match_all('#https?://[^\s"\'<>]+#', $body, $urls);
            $this->assertSame(
                ['https://club.example/settings'],
                array_values(array_unique($urls[0])),
                'the only address a dispenser notice carries is the page to look at',
            );
        }
    }

    /** The shortage says how much is probably left, and that it is an estimate. */
    public function test_a_low_hopper_reports_an_estimate_and_calls_it_one(): void
    {
        $mail = DispenserAttentionMail::render($this->low(12));

        $this->assertSame('Ausgabegerät „Theke“: Token gehen zur Neige', $mail->subject);
        $this->assertStringContainsString('Noch etwa 12 Token', $mail->text);
        $this->assertStringContainsString('Warnung ab 20', $mail->text);
        $this->assertStringContainsString('keinen Leer-Sensor', $mail->text);
    }

    /**
     * Zero is *used up*, never "0 Token übrig": once the subtraction has run
     * out it has no precision left to report, which is how the panel words it
     * too.
     */
    public function test_an_exhausted_estimate_is_not_rendered_as_a_number(): void
    {
        $mail = DispenserAttentionMail::render($this->low(0));

        $this->assertStringContainsString('Schätzung aufgebraucht', $mail->text);
        $this->assertStringNotContainsString('Noch etwa 0 Token', $mail->text);
    }

    /**
     * ADR-0058's one meeting point: a jam whose estimate is used up. The badge
     * stays „Stau oder leer" — the machine has no empty sensor and genuinely
     * cannot tell — and the books add what they suspect beside it.
     */
    public function test_a_jam_with_an_exhausted_estimate_adds_a_sentence_not_a_verdict(): void
    {
        $mail = DispenserAttentionMail::render($this->fault(
            DispenserUnavailableReason::JAM,
            probablyEmpty: true,
        ));

        $this->assertSame('Ausgabegerät „Theke“: Stau oder leer', $mail->subject);
        $this->assertStringContainsString('wahrscheinlich ist der Hopper leer', $mail->text);
    }

    /**
     * A jam somebody cleared between the scan and the drain renders as good
     * news rather than as a claim that is no longer true — and never as a
     * failed build, which would put a red row in the Notifications page for a
     * machine that is working.
     */
    public function test_a_condition_that_cleared_says_so(): void
    {
        $mail = DispenserAttentionMail::render($this->fault(
            DispenserUnavailableReason::JAM,
            cleared: true,
        ));

        $this->assertSame('Ausgabegerät „Theke“: hat sich erledigt', $mail->subject);
        $this->assertStringContainsString('es ist nichts zu tun', $mail->text);
        $this->assertStringNotContainsString('Stau beseitigen', $mail->text);
    }

    /** Each admin is written to in their own language, like every other notice. */
    public function test_the_english_wording_exists_and_is_not_the_german_one(): void
    {
        $mail = DispenserAttentionMail::render(
            $this->fault(DispenserUnavailableReason::JAM, language: MailLanguage::English)
        );

        $this->assertSame('Dispenser "Theke": Jammed or empty', $mail->subject);
        $this->assertStringContainsString(
            'Clear the jam, refill if empty, then unplug the dispenser for 5 seconds.',
            $mail->text,
        );
    }

    // ---------------------------------------------------------------- helpers

    private function fault(
        DispenserUnavailableReason $reason,
        int $faultCode = 0,
        DispenserAttentionOccasion $occasion = DispenserAttentionOccasion::FAULT,
        bool $probablyEmpty = false,
        bool $cleared = false,
        MailLanguage $language = MailLanguage::German,
    ): DispenserAttentionDataDto {
        return new DispenserAttentionDataDto(
            language: $language,
            recipientAddress: 'admin@club.example',
            recipientName: 'Alex',
            branding: new MailBranding(orgName: 'Club Bar'),
            terminalName: 'Theke',
            occasion: $occasion,
            cleared: $cleared,
            reason: $reason,
            faultCode: $faultCode,
            since: '21.09.2026, 12:00',
            probablyEmpty: $probablyEmpty,
            panelUrl: 'https://club.example/settings',
        );
    }

    private function low(int $left): DispenserAttentionDataDto
    {
        return new DispenserAttentionDataDto(
            language: MailLanguage::German,
            recipientAddress: 'admin@club.example',
            recipientName: 'Alex',
            branding: new MailBranding(orgName: 'Club Bar'),
            terminalName: 'Theke',
            occasion: DispenserAttentionOccasion::LOW,
            estimatedLeft: $left,
            lowThreshold: 20,
            panelUrl: 'https://club.example/settings',
        );
    }
}
