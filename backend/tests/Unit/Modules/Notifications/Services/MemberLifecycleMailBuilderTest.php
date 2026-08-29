<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\Members\Repositories\MembersRepository;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Services\MailConfigService;
use App\Modules\Notifications\Services\MemberLifecycleMailBuilder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What the drain hands the member lifecycle renderers (ADR-0051).
 *
 * One claim carries this file: **the address comes off the queued row and
 * never off the member.** By the time a drain runs, `members.email` holds the
 * address the change moved *to*, so a builder that re-derived its recipient
 * would send the "your address was changed" notice to the new address — the
 * one place it is useless — and the member would never learn that the old one
 * had been taken away from them.
 */
class MemberLifecycleMailBuilderTest extends TestCase
{
    private const MEMBER_ID = '11111111-1111-4111-8111-111111111111';

    private function mailConfig(): MailConfigDto
    {
        return MailConfigDto::fromRow([
            'sender_name' => 'FRGS Ruderbar',
            'sender_address' => 'bar@example.org',
            'footer_org_name' => 'FRGS Ruderbar',
        ]);
    }

    /** @param array<string,mixed> $member */
    private function builder(?array $member = ['first_name' => 'Anna']): MemberLifecycleMailBuilder
    {
        $members = $this->createMock(MembersRepository::class);
        $members->method('findMailRecipients')->willReturn(
            $member === null ? [] : [self::MEMBER_ID => ['id' => self::MEMBER_ID] + $member]
        );

        $config = $this->createMock(MailConfigService::class);
        $config->method('getConfig')->willReturn($this->mailConfig());

        return new MemberLifecycleMailBuilder($members, $config);
    }

    /** @return array<string,mixed> */
    private function row(MailKind $kind, string $recipient = 'anna.old@example.org'): array
    {
        return [
            'kind' => $kind->value,
            'subject_id' => self::MEMBER_ID,
            'recipient' => $recipient,
            'language' => 'de',
            'dedup_key' => 'welcome',
            'queued_at' => '2026-08-29 14:05:00',
        ];
    }

    /**
     * Claimed by kind, not by subject. `MailSubject::MEMBER` is shared with the
     * Deckelauszug, and {@see \App\Modules\Notifications\Services\MailContentRegistry}
     * resolves to whichever builder claims a kind *first* — so a subject-wide
     * claim here would shadow the statement or be shadowed by it depending on
     * the order in `ServiceFactory`, silently.
     */
    public function test_it_claims_its_four_kinds_and_nothing_else(): void
    {
        $builder = $this->builder();

        $owned = [
            MailKind::MEMBER_WELCOME,
            MailKind::MEMBER_CARD_REPLACED,
            MailKind::MEMBER_EMAIL_CHANGED,
            MailKind::MEMBER_EMAIL_ACTIVATED,
        ];

        foreach (MailKind::cases() as $kind) {
            $this->assertSame(
                in_array($kind, $owned, true),
                $builder->supports($kind),
                $kind->value . ' is claimed by the wrong builder'
            );
        }

        // Named explicitly, because "supports() is false" would also be
        // satisfied by a builder that claims nothing at all.
        $this->assertFalse($builder->supports(MailKind::DECKEL_STATEMENT));
    }

    public function test_the_recipient_is_the_snapshot_and_not_the_member_row(): void
    {
        // The member row deliberately carries a *different* address — the one
        // the change moved to. A builder reading it would send the notice about
        // losing an address to the address that gained it.
        $builder = $this->builder(['first_name' => 'Anna', 'email' => 'anna.new@example.org']);

        $message = $builder->build($this->row(MailKind::MEMBER_EMAIL_CHANGED), $this->mailConfig());

        $this->assertSame('anna.old@example.org', $message->to);
        $this->assertStringNotContainsString('anna.new@example.org', $message->text);
    }

    public function test_the_change_time_comes_off_the_row(): void
    {
        $builder = $this->builder();

        $message = $builder->build(
            $this->row(MailKind::MEMBER_EMAIL_ACTIVATED, 'anna.new@example.org'),
            $this->mailConfig(),
        );

        // 29.08.2026 in the German format MailFormat produces — the row's
        // queued_at, which is the moment of the change, not today.
        $this->assertStringContainsString('29.08.2026', $message->text);
    }

    public function test_it_renders_in_the_language_frozen_on_the_row(): void
    {
        $builder = $this->builder();

        $row = ['language' => 'en'] + $this->row(MailKind::MEMBER_WELCOME, 'anna@example.org');
        $message = $builder->build($row, $this->mailConfig());

        $this->assertStringContainsString('your membership card is now active', $message->text);
    }

    /**
     * A row whose member is gone throws rather than sending an anonymous
     * greeting. The FK cascades, so reaching this means the row was edited by
     * hand — and erasure supersedes these rows before a drain sees them
     * (ADR-0029, #408).
     */
    public function test_a_missing_member_is_an_error_rather_than_a_blank_greeting(): void
    {
        $builder = $this->builder(null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/is gone/');

        $builder->build($this->row(MailKind::MEMBER_WELCOME), $this->mailConfig());
    }
}
