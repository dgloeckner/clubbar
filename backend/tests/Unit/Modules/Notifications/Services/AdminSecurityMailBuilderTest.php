<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Services\AdminSecurityMailBuilder;
use App\Shared\Mail\MailLayout;
use PHPUnit\Framework\TestCase;

/**
 * Security notices about an admin's own account, rendered at send time
 * (ADR-0038 rule 5).
 *
 * One behaviour here is unlike every other builder and is the reason this class
 * exists: the recipient comes from the outbox row's `recipient` snapshot, never
 * from `admin_users.email`. By the time the drain runs, that column already
 * holds the *new* address — which is precisely the address that does not need
 * telling.
 */
class AdminSecurityMailBuilderTest extends TestCase
{
    private AdminUsersRepository $adminUsersRepository;
    private AdminSecurityMailBuilder $builder;
    private MailConfigDto $mailConfig;

    protected function setUp(): void
    {
        $this->adminUsersRepository = $this->createMock(AdminUsersRepository::class);

        $this->mailConfig = new MailConfigDto(
            senderName: 'Beispiel-Ruderverein e.V.',
            senderAddress: 'kasse@example.org',
            replyToAddress: null,
            headerStyle: MailLayout::DEFAULT_HEADER_STYLE,
            footerOrgName: 'Beispiel-Ruderverein e.V.',
            footerAddressLine: 'Musterweg 35, 60599 Frankfurt am Main',
            websiteUrl: null,
            logoUrl: null,
        );

        $this->builder = new AdminSecurityMailBuilder($this->adminUsersRepository);
    }

    /** @param array<string,mixed> $overrides */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'kind' => MailKind::ADMIN_EMAIL_CHANGED->value,
            'subject_id' => 'admin-1',
            'recipient' => 'former@example.org',
            'language' => 'de',
            'queued_at' => '2026-08-21 09:30:00',
        ];
    }

    public function test_it_claims_the_admin_user_subject_and_nothing_else(): void
    {
        $this->assertTrue($this->builder->supports(MailKind::ADMIN_EMAIL_CHANGED));

        $this->assertFalse($this->builder->supports(MailKind::SEPA_PRENOTIFICATION));
        $this->assertFalse($this->builder->supports(MailKind::CANCELLATION_NOTICE));
        // #438's warnings are admin-*addressed* but are about a key or a
        // terminal, so they are not this builder's to render.
        $this->assertFalse($this->builder->supports(MailKind::KEY_EXPIRY_WARNING));
        $this->assertFalse($this->builder->supports(MailKind::TERMINAL_TOKEN_EXPIRY_WARNING));
    }

    /**
     * The whole point. The account has already moved to `now@example.org`; the
     * notice must still reach the address it moved away from.
     */
    public function test_it_addresses_the_snapshot_not_the_accounts_current_address(): void
    {
        $this->adminUsersRepository->method('findById')->willReturn([
            'id' => 'admin-1',
            'email' => 'now@example.org',
            'display_name' => 'Erika Mustermann',
        ]);

        $message = $this->builder->build(self::row(), $this->mailConfig);

        $this->assertSame('former@example.org', $message->to);
        $this->assertStringNotContainsString('now@example.org', $message->html);
        $this->assertStringNotContainsString('now@example.org', $message->text);
    }

    public function test_it_renders_in_the_language_on_the_row(): void
    {
        $this->adminUsersRepository->method('findById')->willReturn([
            'id' => 'admin-1',
            'email' => 'now@example.org',
            'display_name' => 'Erika',
        ]);

        $german = $this->builder->build(self::row(['language' => 'de']), $this->mailConfig);
        $english = $this->builder->build(self::row(['language' => 'en']), $this->mailConfig);

        $this->assertNotSame($german->subject, $english->subject);
        $this->assertStringContainsString('21.08.2026', $german->text);
        $this->assertStringContainsString('21 August 2026', $english->text);
    }

    /**
     * The FK cascades, so a missing account means somebody deleted rows by hand.
     * A message must not be invented around the gap — a wrong notice is worse
     * than a failed one.
     */
    public function test_a_vanished_account_throws_rather_than_inventing_a_message(): void
    {
        $this->adminUsersRepository->method('findById')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/admin-1/');

        $this->builder->build(self::row(), $this->mailConfig);
    }
}
