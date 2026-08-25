<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Services\CredentialExpiryMailBuilder;
use App\Modules\Security\Repositories\EncryptionKeysRepository;
use App\Modules\Terminals\Repositories\TerminalsRepository;
use PHPUnit\Framework\TestCase;

/**
 * Rendering a credential expiry warning at send time (#438).
 *
 * The interesting part is what the builder has to reconstruct from a row that
 * stores none of it: the **tier** lives only in the `dedup_key`, and the days
 * remaining are recomputed rather than carried, so the number in a message
 * delivered after three retries is true when it is delivered.
 */
class CredentialExpiryMailBuilderTest extends TestCase
{
    private EncryptionKeysRepository $keys;
    private TerminalsRepository $terminals;
    private AdminUsersRepository $adminUsers;
    private CredentialExpiryMailBuilder $builder;
    private MailConfigDto $mailConfig;

    protected function setUp(): void
    {
        $this->keys = $this->createMock(EncryptionKeysRepository::class);
        $this->terminals = $this->createMock(TerminalsRepository::class);
        $this->adminUsers = $this->createMock(AdminUsersRepository::class);
        $this->adminUsers->method('findById')->willReturn(['display_name' => 'Kassenwart']);

        $this->mailConfig = MailConfigDto::fromRow([
            'sender_name' => 'FRGS Ruderbar',
            'sender_address' => 'bar@example.org',
            'footer_org_name' => 'FRGS Ruderbar',
        ]);

        $this->builder = new CredentialExpiryMailBuilder(
            $this->keys,
            $this->terminals,
            $this->adminUsers,
            'msgraph://tenant/client@drive/b!driveid/clubbar',
            '2027-03-01',
        );
    }

    /**
     * Twelve hours of slack on purpose. `daysUntilExpiry()` floors whole days,
     * so an expiry stamped exactly N days out reads as N-1 the moment the
     * second ticks over between building the fixture and rendering it — a test
     * that fails once a minute at the wrong instant.
     */
    private static function inDays(int $days): string
    {
        return (new \DateTimeImmutable())
            ->modify(sprintf('%+d days', $days))
            ->modify('+12 hours')
            ->format('Y-m-d H:i:s');
    }

    /** @param array<string,mixed> $overrides */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'kind' => MailKind::KEY_EXPIRY_WARNING->value,
            'subject_id' => 'key-1',
            'dedup_key' => '30d:admin-1',
            'recipient' => 'kassenwart@example.org',
            'language' => 'de',
            'admin_user_id' => 'admin-1',
        ];
    }

    public function test_it_claims_every_expiry_kind_and_nothing_else(): void
    {
        $claimed = array_values(array_filter(
            MailKind::cases(),
            fn (MailKind $kind): bool => $this->builder->supports($kind),
        ));

        // Stated as the whole set rather than as two assertions: a kind added to
        // the enum and quietly picked up here would pass those and fail this.
        $this->assertSame(
            [
                MailKind::KEY_EXPIRY_WARNING,
                MailKind::TERMINAL_TOKEN_EXPIRY_WARNING,
                // The third credential to ride the 90/30/7 tiers (#691), and
                // the one whose expiry is invisible: an encryption key that
                // lapses blocks the SEPA export the same week, a terminal
                // token locks a till out of the bar — an expired backup secret
                // looks exactly like a working one, because the nightly job
                // still writes and seals its archive and only the half that
                // takes it off the host stops.
                MailKind::BACKUP_SECRET_EXPIRY_WARNING,
            ],
            $claimed,
        );
    }

    public function test_it_renders_a_key_warning_from_the_tier_in_the_dedup_key(): void
    {
        $this->keys->method('findById')->willReturn([
            'id' => 'key-1',
            'key_identifier' => 'ruderbar-2026',
            'expires_at' => self::inDays(25),
        ]);

        $message = $this->builder->build(self::row(), $this->mailConfig);

        $this->assertSame('kassenwart@example.org', $message->to);
        $this->assertSame('Kassenwart', $message->toName);
        $this->assertStringContainsString('25 Tagen', $message->subject);
        $this->assertStringContainsString('ruderbar-2026', $message->text);
        // The tier decides the tone; 30 is the middle one, so no urgency prefix.
        $this->assertStringNotContainsString('Dringend', $message->subject);
        $this->assertStringContainsString('Einstellungen → Schlüssel', $message->text);
    }

    public function test_the_seven_day_tier_says_so_in_the_subject_line(): void
    {
        $this->keys->method('findById')->willReturn([
            'id' => 'key-1',
            'key_identifier' => 'ruderbar-2026',
            'expires_at' => self::inDays(3),
        ]);

        $message = $this->builder->build(self::row(['dedup_key' => '7d:admin-1']), $this->mailConfig);

        // At seven days nobody opens a mail to find out whether it matters.
        $this->assertStringStartsWith('Dringend:', $message->subject);
    }

    public function test_it_renders_a_terminal_warning(): void
    {
        $this->terminals->method('findById')->willReturn([
            'id' => 'terminal-1',
            'name' => 'Theke',
            'token_expires_at' => self::inDays(6),
        ]);

        $message = $this->builder->build(self::row([
            'kind' => MailKind::TERMINAL_TOKEN_EXPIRY_WARNING->value,
            'subject_id' => 'terminal-1',
            'dedup_key' => '7d:admin-1',
        ]), $this->mailConfig);

        $this->assertStringContainsString('Theke', $message->subject);
        $this->assertStringContainsString('Token rotieren', $message->text);
    }

    /**
     * The days are recomputed at send time, so a row that outlived its own
     * deadline in the retry ladder reads as "0 Tage" rather than as a negative
     * number rendered into a sentence that says "in -2 days".
     */
    public function test_a_row_delivered_after_the_deadline_floors_at_zero(): void
    {
        $this->keys->method('findById')->willReturn([
            'id' => 'key-1',
            'key_identifier' => 'ruderbar-2026',
            'expires_at' => self::inDays(-2),
        ]);

        $message = $this->builder->build(self::row(['dedup_key' => '7d:admin-1']), $this->mailConfig);

        $this->assertStringContainsString('0 Tage', $message->text);
        $this->assertStringNotContainsString('in -', $message->text);
    }

    public function test_a_missing_credential_refuses_to_invent_a_message(): void
    {
        $this->keys->method('findById')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no longer exists');

        $this->builder->build(self::row(), $this->mailConfig);
    }

    /**
     * A terminal row's key carries a generation segment between the tier and
     * the admin id, so the tier must be read as the *first* segment rather than
     * as "everything before the last colon".
     */
    public function test_the_tier_is_read_past_a_generation_segment(): void
    {
        $this->terminals->method('findById')->willReturn([
            'id' => 'terminal-1',
            'name' => 'Theke',
            'token_expires_at' => self::inDays(4),
        ])
        ;

        $message = $this->builder->build(self::row([
            'kind' => MailKind::TERMINAL_TOKEN_EXPIRY_WARNING->value,
            'subject_id' => 'terminal-1',
            'dedup_key' => '7d:20260102030405:admin-1',
        ]), $this->mailConfig);

        $this->assertStringStartsWith('Dringend:', $message->subject);
        $this->assertStringContainsString('Theke', $message->subject);
    }

    public function test_a_row_naming_no_known_tier_is_refused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no known warning tier');

        $this->builder->build(self::row(['dedup_key' => '45d:admin-1']), $this->mailConfig);
    }

    /**
     * The backup secret renders from configuration, because there is no table
     * to render it from: ADR-0049 decision 8 keeps the backup out of the
     * database it dumps entirely.
     */
    public function test_it_renders_a_backup_secret_warning_naming_the_remote_rather_than_the_secret(): void
    {
        $mail = $this->builder->build(
            [
                'kind' => 'backup_secret_expiry_warning',
                'subject_id' => '1',
                'dedup_key' => '30d:20270301:admin-1',
                'recipient' => 'admin@example.org',
                'language' => 'en',
                'admin_user_id' => null,
            ],
            $this->mailConfig,
        );

        $body = $mail->text . ' ' . $mail->subject;

        // What an operator needs is which store has stopped accepting uploads.
        $this->assertStringContainsString('clubbar', $body);
        // Never the credential, and never the ids in the DSN: this mail is
        // exactly the shape a phishing attempt imitates, so it carries the
        // fact and the date and nothing that could be used.
        $this->assertStringNotContainsString('b!driveid', $body);
        $this->assertStringNotContainsString('tenant/client', $body);
        // The sentence that matters: the backup keeps looking healthy.
        $this->assertStringContainsString('still sealed', $body);
    }

    /**
     * A broken DSN is a second problem, not a reason to stay silent about the
     * first: the expiry is real either way.
     */
    public function test_a_dsn_that_will_not_parse_still_produces_the_warning(): void
    {
        $builder = new CredentialExpiryMailBuilder(
            $this->keys,
            $this->terminals,
            $this->adminUsers,
            'not-a-dsn',
            '2027-03-01',
        );

        $mail = $builder->build(
            [
                'kind' => 'backup_secret_expiry_warning',
                'subject_id' => '1',
                'dedup_key' => '7d:20270301:admin-1',
                'recipient' => 'admin@example.org',
                'language' => 'de',
                'admin_user_id' => null,
            ],
            $this->mailConfig,
        );

        $this->assertNotSame('', $mail->subject);
    }
}