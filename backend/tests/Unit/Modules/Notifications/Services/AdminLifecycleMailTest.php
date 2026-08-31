<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\AdminUsers\Repositories\AdminInvitationsRepository;
use App\Modules\AdminUsers\Repositories\AdminUserRolesRepository;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\AdminUsers\Services\InvitationTokenCipher;
use App\Modules\Notifications\DTOs\MailConfigDto;
use App\Modules\Notifications\Enums\MailKind;
use App\Modules\Notifications\Services\AdminSecurityMailBuilder;
use App\Modules\Notifications\Services\MailConfigService;
use App\Shared\Mail\MailLayout;
use PHPUnit\Framework\TestCase;

/**
 * The admin lifecycle notices, rendered at send time (ADR-0044 rule 3, #521).
 *
 * These are the messages that make account creation and role changes **loud**.
 * ADR-0044 calls creation the loud path against which promoting an existing
 * lesser role was the quiet one — it was loud only in intention until these
 * existed.
 *
 * Two properties matter more than the wording and are asserted hardest: the
 * message carries no credential of any kind, and it names the role set the
 * account holds *now* rather than a delta that may already be stale by the time
 * the drain runs (ADR-0038 rule 5).
 */
class AdminLifecycleMailTest extends TestCase
{
    private AdminUsersRepository $admins;
    private AdminUserRolesRepository $roles;
    private AdminSecurityMailBuilder $builder;
    private MailConfigDto $mailConfig;

    protected function setUp(): void
    {
        $this->admins = $this->createMock(AdminUsersRepository::class);
        $this->roles = $this->createMock(AdminUserRolesRepository::class);

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

        $mailConfigService = $this->createMock(MailConfigService::class);
        $mailConfigService->method('getConfig')->willReturn($this->mailConfig);

        $this->builder = new AdminSecurityMailBuilder(
            $this->admins,
            $mailConfigService,
            $this->roles,
            $this->createMock(AdminInvitationsRepository::class),
            $this->createMock(InvitationTokenCipher::class),
            'https://club.example.org',
        );
    }

    public function test_the_builder_claims_both_lifecycle_kinds(): void
    {
        $this->assertTrue($this->builder->supports(MailKind::ADMIN_ACCOUNT_CREATED));
        $this->assertTrue($this->builder->supports(MailKind::ADMIN_ROLE_CHANGED));
    }

    public function test_a_creation_notice_names_the_account_its_roles_and_the_actor(): void
    {
        $this->givenAccounts([
            'admin-new' => ['display_name' => 'Neue Kassenwartin', 'email' => 'neu@example.org'],
            'admin-actor' => ['display_name' => 'Vorsitzender', 'email' => 'chef@example.org'],
            'admin-reader' => ['display_name' => 'Getränkewart', 'email' => 'bar@example.org'],
        ]);
        $this->roles->method('rolesFor')->willReturn([AdminRole::KASSENWART]);

        $message = $this->builder->build($this->row(MailKind::ADMIN_ACCOUNT_CREATED), $this->mailConfig);

        $this->assertStringContainsString('Neue Kassenwartin (neu@example.org)', $message->text);
        $this->assertStringContainsString('Kassenwart', $message->text);
        $this->assertStringContainsString('Vorsitzender (chef@example.org)', $message->text);
        $this->assertStringContainsString('neues Admin-Konto', $message->subject);
    }

    public function test_a_role_change_notice_names_the_roles_the_account_holds_now(): void
    {
        $this->givenAccounts([
            'admin-new' => ['display_name' => 'Doppelrolle', 'email' => 'beide@example.org'],
            'admin-reader' => ['display_name' => 'Leser', 'email' => 'leser@example.org'],
        ]);
        $this->roles->method('rolesFor')->willReturn([AdminRole::KASSENWART, AdminRole::GETRAENKEWART]);

        $message = $this->builder->build($this->row(MailKind::ADMIN_ROLE_CHANGED), $this->mailConfig);

        $this->assertStringContainsString('Kassenwart, Getränkewart', $message->text);
        $this->assertStringContainsString('Rollen', $message->subject);
    }

    /**
     * The whole reason the club-level copy exists is that it goes somewhere no
     * account owns — so it has no admin to greet, and must not invent one.
     */
    public function test_the_club_copy_has_no_recipient_account_and_still_renders(): void
    {
        $this->givenAccounts(['admin-new' => ['display_name' => 'Neu', 'email' => 'neu@example.org']]);
        $this->roles->method('rolesFor')->willReturn([AdminRole::ADMIN]);

        $message = $this->builder->build($this->row(MailKind::ADMIN_ACCOUNT_CREATED, [
            'admin_user_id' => null,
            'recipient' => 'vorstand@example.org',
        ]), $this->mailConfig);

        $this->assertSame('vorstand@example.org', $message->to);
        $this->assertNull($message->toName);
        $this->assertStringContainsString('Neu (neu@example.org)', $message->text);
    }

    /**
     * The acceptance criterion stated as a test: no password, no token, no key
     * material. The generated password is shown once in the panel, and this
     * message reaches a wider audience than that screen ever does.
     */
    public function test_no_lifecycle_notice_carries_a_credential(): void
    {
        $this->givenAccounts([
            'admin-new' => ['display_name' => 'Neu', 'email' => 'neu@example.org'],
            'admin-reader' => ['display_name' => 'Leser', 'email' => 'leser@example.org'],
        ]);
        $this->roles->method('rolesFor')->willReturn([AdminRole::ADMIN]);

        foreach ([MailKind::ADMIN_ACCOUNT_CREATED, MailKind::ADMIN_ROLE_CHANGED] as $kind) {
            $message = $this->builder->build($this->row($kind), $this->mailConfig);
            $body = $message->text . $message->html;

            foreach (['password', 'Passwort', 'token', 'Token', 'secret', 'Schlüssel'] as $forbidden) {
                // The German copy says a password is *not* included, so the
                // word may appear — but only in that sentence.
                $occurrences = substr_count($body, $forbidden);
                $disclaimed = substr_count($body, 'bewusst keine Zugangsdaten')
                    + substr_count($body, 'carries no credentials');

                $this->assertLessThanOrEqual(
                    $disclaimed * 2,
                    $occurrences,
                    "{$kind->value} mentions '{$forbidden}' outside the no-credentials disclaimer",
                );
            }
        }
    }

    /** An actor the row does not name drops the row rather than printing a blank one. */
    public function test_an_absent_actor_leaves_no_empty_row(): void
    {
        $this->givenAccounts([
            'admin-new' => ['display_name' => 'Neu', 'email' => 'neu@example.org'],
            'admin-reader' => ['display_name' => 'Leser', 'email' => 'leser@example.org'],
        ]);
        $this->roles->method('rolesFor')->willReturn([AdminRole::ADMIN]);

        $message = $this->builder->build($this->row(MailKind::ADMIN_ACCOUNT_CREATED, [
            'actor_admin_user_id' => null,
        ]), $this->mailConfig);

        $this->assertStringNotContainsString('Ausgeführt von', $message->text);
    }

    public function test_the_english_variant_is_rendered_for_an_english_recipient(): void
    {
        $this->givenAccounts([
            'admin-new' => ['display_name' => 'New One', 'email' => 'new@example.org'],
            'admin-reader' => ['display_name' => 'Reader', 'email' => 'reader@example.org'],
        ]);
        $this->roles->method('rolesFor')->willReturn([AdminRole::ADMIN]);

        $message = $this->builder->build($this->row(MailKind::ADMIN_ACCOUNT_CREATED, ['language' => 'en']), $this->mailConfig);

        $this->assertStringContainsString('new admin account', $message->subject);
        $this->assertStringContainsString('Roles now', $message->text);
    }

    /** @param array<string, array<string,mixed>> $accounts */
    private function givenAccounts(array $accounts): void
    {
        $this->admins->method('findById')->willReturnCallback(
            static fn (string $id): ?array => isset($accounts[$id]) ? $accounts[$id] + ['id' => $id] : null
        );
    }

    /** @param array<string,mixed> $overrides @return array<string,mixed> */
    private function row(MailKind $kind, array $overrides = []): array
    {
        return $overrides + [
            'kind' => $kind->value,
            'subject_id' => 'admin-new',
            'recipient' => 'leser@example.org',
            'admin_user_id' => 'admin-reader',
            'actor_admin_user_id' => 'admin-actor',
            'language' => 'de',
            'queued_at' => '2026-08-21 09:30:00',
        ];
    }
}
