<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Services\TerminalTokenIssuedMailBuilder;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use PHPUnit\Framework\TestCase;

/**
 * Rendering the issuance notice at send time (ADR-0043).
 *
 * The interesting part is what has to be reconstructed from a row that stores
 * none of it. Two facts live only in the `dedup_key` — which event happened, and
 * which generation of the token it was — and the expiry is looked up *by* that
 * generation rather than taken from whichever column happens to be populated.
 * A notice about Friday's credential must not quote Monday's date.
 */
class TerminalTokenIssuedMailBuilderTest extends TestCase
{
    private TerminalsRepository $terminals;
    private AdminUsersRepository $adminUsers;
    private TerminalTokenIssuedMailBuilder $builder;
    private MailConfigDto $mailConfig;

    protected function setUp(): void
    {
        $this->terminals = $this->createMock(TerminalsRepository::class);

        $adminUsers = $this->adminUsers = $this->createMock(AdminUsersRepository::class);
        $adminUsers->method('findById')->willReturnMap([
            ['admin-1', ['display_name' => 'Kassenwart', 'email' => 'kassenwart@example.org']],
            ['admin-2', ['display_name' => 'Vorstand', 'email' => 'vorstand@example.org']],
            ['admin-3', ['display_name' => '', 'email' => 'noname@example.org']],
        ]);

        $this->mailConfig = MailConfigDto::fromRow([
            'sender_name' => 'FRGS Ruderbar',
            'sender_address' => 'bar@example.org',
            'footer_org_name' => 'FRGS Ruderbar',
        ]);

        $this->builder = new TerminalTokenIssuedMailBuilder(
            $this->terminals,
            $adminUsers,
        );
    }

    /** @param array<string,mixed> $overrides */
    private static function terminal(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'terminal-1',
            'name' => 'Theke',
            'device_id' => 'BAR-MAIN-001',
            'token_issued_at' => '2026-08-16 14:23:17',
            'token_expires_at' => '2027-08-16 14:23:17',
            'pending_token_issued_at' => null,
            'pending_token_expires_at' => null,
        ];
    }

    /** @param array<string,mixed> $overrides */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'kind' => MailKind::TERMINAL_TOKEN_ISSUED->value,
            'subject_id' => 'terminal-1',
            'dedup_key' => 'enrolled:20260816142317:admin-1',
            'recipient' => 'kassenwart@example.org',
            'language' => 'de',
            'admin_user_id' => 'admin-1',
        ];
    }

    public function test_it_claims_its_own_kind_and_nothing_else(): void
    {
        $claimed = array_filter(
            MailKind::cases(),
            fn (MailKind $kind): bool => $this->builder->supports($kind),
        );

        // Stated as the whole set rather than as one assertion per kind: a kind
        // added to the enum and accidentally claimed here would pass those and
        // fail this.
        $this->assertSame([MailKind::TERMINAL_TOKEN_ISSUED], array_values($claimed));
    }

    /**
     * The occasion is what the builder reads back, so the two halves are pinned
     * against each other rather than against a literal written twice.
     */
    public function test_the_occasion_it_builds_is_the_occasion_it_parses(): void
    {
        $occasion = TerminalTokenIssuedMailBuilder::occasion(
            TerminalTokenIssuedMailBuilder::EVENT_ROTATED,
            '2026-08-16 14:23:17',
        );

        $this->assertSame('rotated:20260816142317', $occasion);
        $this->assertSame(
            [TerminalTokenIssuedMailBuilder::EVENT_ROTATED, '20260816142317'],
            TerminalTokenIssuedMailBuilder::parseOccasion($occasion . ':admin-1'),
        );
    }

    /**
     * The whole key has to fit `mail_outbox.dedup_key`, which is VARCHAR(64),
     * and an admin id spends 36 of those. An occasion that overflowed would be
     * truncated by the database and collapse two issuances into one message —
     * silently, and in the direction that loses an alert.
     */
    public function test_the_dedup_key_fits_the_column(): void
    {
        $occasion = TerminalTokenIssuedMailBuilder::occasion(
            TerminalTokenIssuedMailBuilder::EVENT_ENROLLED,
            '2026-08-16 14:23:17',
        );

        $this->assertLessThanOrEqual(64, strlen($occasion . ':' . str_repeat('a', 36)));
    }

    public function test_an_enrolment_names_the_terminal_the_device_and_the_moment(): void
    {
        $this->terminals->method('findById')->willReturn(self::terminal());

        $message = $this->builder->build(self::row(), $this->mailConfig);

        $this->assertSame('kassenwart@example.org', $message->to);
        $this->assertStringContainsString('Theke', $message->subject);

        foreach ([$message->html, $message->text] as $part) {
            $this->assertStringContainsString('BAR-MAIN-001', $part);
            // The issuance moment to the minute — read back out of the stamp,
            // which is the only place it is recorded.
            $this->assertStringContainsString('16.08.2026, 14:23', $part);
            // …and the expiry of the credential that was just minted.
            $this->assertStringContainsString('16.08.2027', $part);
        }
    }

    /**
     * A rotation stages its token in the pending columns, and that is the
     * generation the message is about — not the one still in the field.
     */
    public function test_a_rotation_quotes_the_pending_generation(): void
    {
        $this->terminals->method('findById')->willReturn(self::terminal([
            'token_issued_at' => '2025-08-16 09:00:00',
            'token_expires_at' => '2026-08-16 09:00:00',
            'pending_token_issued_at' => '2026-08-16 14:23:17',
            'pending_token_expires_at' => '2027-08-16 14:23:17',
        ]));

        $message = $this->builder->build(self::row([
            'dedup_key' => 'rotated:20260816142317:admin-1',
        ]), $this->mailConfig);

        foreach ([$message->html, $message->text] as $part) {
            $this->assertStringContainsString('16.08.2027', $part);
            // The outgoing token's dates belong to a credential this message is
            // not about.
            $this->assertStringNotContainsString('16.08.2026, 09:00', $part);
        }
    }

    /**
     * The same rotation once the terminal has presented its replacement: the
     * pending columns are empty and the generation now lives in the active ones.
     * The message must still find its own expiry.
     */
    public function test_it_follows_a_generation_that_was_promoted_between_enqueue_and_drain(): void
    {
        $this->terminals->method('findById')->willReturn(self::terminal([
            'token_issued_at' => '2026-08-16 14:23:17',
            'token_expires_at' => '2027-08-16 14:23:17',
            'pending_token_issued_at' => null,
            'pending_token_expires_at' => null,
        ]));

        $message = $this->builder->build(self::row([
            'dedup_key' => 'rotated:20260816142317:admin-1',
        ]), $this->mailConfig);

        $this->assertStringContainsString('16.08.2027', $message->text);
    }

    /**
     * A credential revoked between enqueue and drain has no expiry left to
     * quote. The line is dropped rather than filled from whichever token is on
     * the row now — and the message still goes, because "a credential was
     * minted and then revoked" is exactly what an admin wants to hear about.
     */
    public function test_a_generation_that_is_gone_leaves_the_expiry_out_and_still_sends(): void
    {
        $this->terminals->method('findById')->willReturn(self::terminal([
            'token_issued_at' => null,
            'token_expires_at' => null,
        ]));

        $message = $this->builder->build(self::row(), $this->mailConfig);

        $this->assertStringContainsString('Theke', $message->subject);
        $this->assertStringNotContainsString('Gültig bis', $message->text);
        // What happened, and when, survive — they come from the key.
        $this->assertStringContainsString('16.08.2026, 14:23', $message->text);
    }

    /**
     * `subject_id` is polymorphic and carries no foreign key, so this is
     * reachable. A security notice must not be invented around the gap; the
     * drain records the failure against the message.
     */
    public function test_a_missing_terminal_is_refused_rather_than_guessed_at(): void
    {
        $this->terminals->method('findById')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/terminal-1 no longer exists/');

        $this->builder->build(self::row(), $this->mailConfig);
    }

    public function test_a_key_naming_no_known_event_is_refused(): void
    {
        $this->terminals->method('findById')->willReturn(self::terminal());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/names no known issuance event/');

        $this->builder->build(self::row(['dedup_key' => 'reissued:20260816142317:admin-1']), $this->mailConfig);
    }

    /**
     * The actor is a separate identity from the recipient: `admin_user_id` is
     * who this copy is addressed to, `actor_admin_user_id` (migration 043) is
     * who performed the mint. A row where they differ — the ordinary case, since
     * every active admin gets a copy — must still name the actor, not the
     * reader.
     */
    public function test_it_names_the_actor_by_display_name_and_login(): void
    {
        $this->terminals->method('findById')->willReturn(self::terminal());

        $message = $this->builder->build(self::row([
            'admin_user_id' => 'admin-1',
            'actor_admin_user_id' => 'admin-2',
        ]), $this->mailConfig);

        foreach ([$message->html, $message->text] as $part) {
            $this->assertStringContainsString('Vorstand (vorstand@example.org)', $part);
            // The reader's own admin record must not be substituted for the actor's.
            $this->assertStringNotContainsString('Kassenwart (kassenwart@example.org)', $part);
        }
    }

    /** No display name set: the login alone is still an unambiguous identifier. */
    public function test_it_falls_back_to_the_login_when_the_actor_has_no_display_name(): void
    {
        $this->terminals->method('findById')->willReturn(self::terminal());

        $message = $this->builder->build(self::row(['actor_admin_user_id' => 'admin-3']), $this->mailConfig);

        $this->assertStringContainsString('noname@example.org', $message->text);
    }

    /**
     * A row queued before migration 043 carries no actor column at all. The
     * builder must not invent one — the template drops the line, as it does for
     * a missing expiry.
     */
    public function test_a_row_with_no_actor_omits_the_line(): void
    {
        $this->terminals->method('findById')->willReturn(self::terminal());

        $message = $this->builder->build(self::row(), $this->mailConfig);

        $this->assertStringNotContainsString('Ausgeführt von', $message->text);
    }

    /**
     * The message exists *because* a secret was created, so the one thing it
     * must never do is carry it. Asserted against the rendered parts rather
     * than against the template's intent.
     */
    public function test_it_carries_no_token_material(): void
    {
        $this->terminals->method('findById')->willReturn(self::terminal([
            // Nothing here is the token — the plaintext is never stored — but a
            // hash on the row is the closest thing to one, and a builder that
            // rendered `$terminal` wholesale would leak it.
            'api_token_hash' => str_repeat('a', 64),
            'pending_token_hash' => str_repeat('b', 64),
        ]));

        $message = $this->builder->build(self::row(), $this->mailConfig);

        foreach ([$message->html, $message->text] as $part) {
            $this->assertDoesNotMatchRegularExpression('/\b[0-9a-f]{64}\b/', $part);
        }
    }
}
