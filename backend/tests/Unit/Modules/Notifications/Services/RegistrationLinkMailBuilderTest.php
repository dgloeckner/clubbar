<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Services\RegistrationLinkMailBuilder;
use App\Modules\Registrations\DTOs\SelfRegistrationSettingsDto;
use App\Modules\Registrations\Services\SelfRegistrationAdminService;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What the drain hands the Anmeldelink renderer (#821, ADR-0053).
 *
 * Two claims carry this file, and they are the two the design turns on:
 *
 * 1. **The link that ships is the poster's**, built from the secret the club
 *    holds at send time — not a copy frozen into the queue row. That is what
 *    makes ADR-0053's "no credential" claim true rather than merely stated: if
 *    a per-send copy existed anywhere, `self_registration_config` would be
 *    holding a *set* of live secrets and rotation would stop meaning anything.
 * 2. **A club that cannot answer the link does not send one.** The send
 *    endpoint gates first, so a failure here means the club changed its mind
 *    between enqueue and drain — and the answer is a thrown builder the drain
 *    records against the message, never a link to a refusal page in the inbox
 *    of somebody the club is courting.
 */
class RegistrationLinkMailBuilderTest extends TestCase
{
    private const APP_URL = 'https://club.example';
    private const SECRET = 'p0sterSecret-abc123';

    private function mailConfig(): MailConfigDto
    {
        return MailConfigDto::fromRow([
            'sender_name' => 'FRGS Ruderbar',
            'sender_address' => 'bar@example.org',
            'footer_org_name' => 'FRGS Ruderbar',
        ]);
    }

    private function builder(
        bool $enabled = true,
        ?string $secret = self::SECRET,
        ?BusinessRuleReason $secretFailure = null,
    ): RegistrationLinkMailBuilder {
        $registrations = $this->createMock(SelfRegistrationAdminService::class);
        $registrations->method('settings')->willReturn(new SelfRegistrationSettingsDto(
            enabled: $enabled,
            disabledReason: $enabled ? null : 'Beta-Phase schon voll',
            hasSecret: $secret !== null,
            secretRotatedAt: '2026-03-01 09:00:00',
            documentUrl: 'https://club.example/Anmeldung.pdf',
            retentionDays: 30,
        ));

        if ($secretFailure !== null) {
            $registrations->method('currentSecret')->willThrowException(
                new BusinessRuleException($secretFailure, 'no secret')
            );
        } else {
            $registrations->method('currentSecret')->willReturn((string) $secret);
        }

        return new RegistrationLinkMailBuilder($registrations, self::APP_URL);
    }

    /** @return array<string,mixed> */
    private function row(string $recipient = 'interessent@example.org'): array
    {
        return [
            'kind' => MailKind::REGISTRATION_LINK->value,
            'subject_id' => 'self_registration_config',
            'recipient' => $recipient,
            'language' => 'de',
            'dedup_key' => '9f1c8a2e-0000-4000-8000-000000000001',
            'queued_at' => '2026-09-04 14:05:00',
        ];
    }

    /**
     * Claimed by kind, not by subject. `MailSubject::SELF_REGISTRATION` holds
     * one kind today, and a subject-wide claim would be a standing offer to
     * render the next one — which the registry accepts silently, resolving to
     * whichever builder claims a kind first.
     */
    public function test_it_claims_its_own_kind_and_no_other(): void
    {
        $builder = $this->builder();

        $this->assertTrue($builder->supports(MailKind::REGISTRATION_LINK));

        foreach (MailKind::cases() as $kind) {
            if ($kind === MailKind::REGISTRATION_LINK) {
                continue;
            }
            $this->assertFalse($builder->supports($kind), $kind->value . ' belongs to another builder');
        }
    }

    /**
     * The whole point of the feature: what lands in the inbox is the URL the
     * poster on the wall encodes, secret and all, in the fragment.
     */
    public function test_the_delivered_link_is_the_posters_own_url(): void
    {
        $message = $this->builder()->build($this->row(), $this->mailConfig());

        $expected = self::APP_URL . '/register#' . self::SECRET;

        $this->assertStringContainsString($expected, $message->html);
        // Both parts, and that is not redundancy: a reader whose client strips
        // the button, or who reads plain text, has no other way to the form.
        $this->assertStringContainsString($expected, $message->text);
    }

    /**
     * The address is the row's, because there is nowhere else it could come
     * from — this person has no record anywhere in the database.
     */
    public function test_the_recipient_is_the_rows_snapshot_and_carries_no_name(): void
    {
        $message = $this->builder()->build($this->row('neu@example.org'), $this->mailConfig());

        $this->assertSame('neu@example.org', $message->to);
        // Nothing is known about them, and guessing a display name out of the
        // local part is how a mail opens by greeting somebody as "j.schmidt".
        $this->assertNull($message->toName);
    }

    /**
     * The surprise this message exists to remove: filling the form is not
     * joining. Somebody standing at the poster learns that in the clubhouse;
     * somebody opening a link at home learns it only if the body says so.
     */
    public function test_the_body_says_a_signed_paper_form_is_part_of_joining(): void
    {
        $message = $this->builder()->build($this->row(), $this->mailConfig());

        foreach ([$message->html, $message->text] as $part) {
            $this->assertMatchesRegularExpression('/unterschreib/iu', $part);
            $this->assertMatchesRegularExpression('/ausdrucken/iu', $part);
        }
    }

    /**
     * No expiry is named, because there is none. Naming a lifetime the system
     * does not enforce is a promise nobody keeps — the invitation link names
     * one because it *has* one.
     */
    public function test_it_promises_no_expiry(): void
    {
        $message = $this->builder()->build($this->row(), $this->mailConfig());

        $this->assertDoesNotMatchRegularExpression('/gültig bis|läuft ab|Ablauf/iu', $message->text);
    }

    public function test_a_club_that_switched_registration_off_sends_nothing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/switched off/');

        $this->builder(enabled: false)->build($this->row(), $this->mailConfig());
    }

    /**
     * The secret is gone, or the key that reads it back is. Either way the URL
     * would open nothing, and a message that reached the reader would be worse
     * than one the Kassenwart can see failed and re-send.
     */
    public function test_an_unreadable_secret_fails_the_message_rather_than_shipping_a_dead_link(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no readable poster secret/');

        $this->builder(secretFailure: BusinessRuleReason::REGISTRATION_SECRET_UNREADABLE)
            ->build($this->row(), $this->mailConfig());
    }
}
