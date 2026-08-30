<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Notifications\Services;

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
 * The one message in this system whose body carries a working credential
 * (migration 058).
 *
 * Two things are being held to here. That the link *is in the message* — a
 * builder that renders a beautifully worded invitation with no usable link is
 * the failure this feature cannot survive, and it would look fine in every
 * other test. And that the builder **refuses** rather than improvises whenever
 * the link cannot be rebuilt: a message sent with a broken link reaches
 * somebody who has no account yet, no way to tell a bad link from a bad system,
 * and nobody to ask.
 */
class AdminInvitationMailTest extends TestCase
{
    private AdminUsersRepository $admins;
    private AdminInvitationsRepository $invitations;
    private InvitationTokenCipher $cipher;
    private AdminSecurityMailBuilder $builder;
    private MailConfigDto $mailConfig;

    protected function setUp(): void
    {
        $this->admins = $this->createMock(AdminUsersRepository::class);
        $this->invitations = $this->createMock(AdminInvitationsRepository::class);
        $this->cipher = $this->createMock(InvitationTokenCipher::class);

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
            $this->createMock(AdminUserRolesRepository::class),
            $this->invitations,
            $this->cipher,
            'https://club.example.org',
        );

        $this->admins->method('findById')->willReturn([
            'id' => 'admin-9',
            'email' => 'neu@example.org',
            'display_name' => 'Neue Kassenwartin',
            'locale' => 'de',
        ]);
    }

    /**
     * The row as the drain claims it. `dedup_key` is the invitation's id and
     * nothing else — `MailRequestDto::forInvitation()` appends no recipient,
     * because the recipient and the subject are the same account — and it is
     * how the builder finds the invitation whose token it has to unseal.
     *
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'kind' => MailKind::ADMIN_INVITATION->value,
            'subject_id' => 'admin-9',
            'dedup_key' => 'inv-1',
            'recipient' => 'neu@example.org',
            'language' => 'de',
            'queued_at' => '2026-08-30 09:30:00',
        ];
    }

    /** @param array<string,mixed> $overrides */
    private static function invitation(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'inv-1',
            'admin_user_id' => 'admin-9',
            'token_cipher' => 'sealed',
            'expires_at' => '2026-09-06 09:30:00',
            'accepted_at' => null,
            'revoked_at' => null,
        ];
    }

    public function test_the_message_carries_a_working_link_in_both_bodies(): void
    {
        $this->invitations->method('findById')->with('inv-1')->willReturn(self::invitation());
        $this->cipher->method('open')->with('sealed')->willReturn('t0k3n-abc');

        $message = $this->builder->build(self::row(), $this->mailConfig);

        $expected = 'https://club.example.org/invite/t0k3n-abc';
        $this->assertStringContainsString($expected, $message->html);
        // Also as plain text: a button is not a link everybody can use, and the
        // fallback for a dead link is asking for a whole new invitation.
        $this->assertStringContainsString($expected, $message->text);
        $this->assertSame('neu@example.org', $message->to);
    }

    /**
     * The invitee has to know the link will stop working, and when. A link that
     * has quietly expired reads as a broken system to the one person who cannot
     * ask anybody about it.
     */
    public function test_the_message_names_the_expiry_and_the_sign_in_address(): void
    {
        $this->invitations->method('findById')->willReturn(self::invitation());
        $this->cipher->method('open')->willReturn('t0k3n-abc');

        $message = $this->builder->build(self::row(), $this->mailConfig);

        $this->assertStringContainsString('06.09.2026', $message->text);
        $this->assertStringContainsString('neu@example.org', $message->text);
    }

    /** The German default; the language comes off the row, as everywhere else. */
    public function test_it_renders_in_the_language_on_the_row(): void
    {
        $this->invitations->method('findById')->willReturn(self::invitation());
        $this->cipher->method('open')->willReturn('t0k3n-abc');

        $german = $this->builder->build(self::row(), $this->mailConfig);
        $english = $this->builder->build(self::row(['language' => 'en']), $this->mailConfig);

        $this->assertStringContainsString('Passwort festlegen', $german->text);
        $this->assertStringContainsString('Set your password', $english->text);
    }

    /**
     * The likeliest way to get here is a resend queued while the first message
     * was still waiting for the drain. Sending both would put a dead link in
     * somebody's inbox beside a live one, with nothing to tell them apart.
     *
     * @dataProvider unsendable
     * @param array<string,mixed>|null $invitation
     */
    public function test_it_refuses_rather_than_mailing_a_link_that_will_not_work(
        ?array $invitation,
        string|false $unsealed,
    ): void {
        $this->invitations->method('findById')->willReturn($invitation);
        $this->cipher->method('open')->willReturn($unsealed);

        $this->expectException(\RuntimeException::class);

        $this->builder->build(self::row(), $this->mailConfig);
    }

    /** @return array<string, array{0: array<string,mixed>|null, 1: string|false}> */
    public static function unsendable(): array
    {
        return [
            'the invitation row is gone' => [null, 't0k3n-abc'],
            'already accepted' => [self::invitation(['accepted_at' => '2026-08-30 10:00:00']), 't0k3n-abc'],
            'revoked by a resend' => [self::invitation(['revoked_at' => '2026-08-30 10:00:00']), 't0k3n-abc'],
            // secretbox authenticates, so a wrong key or a tampered row is a
            // detected failure rather than plausible garbage that would render
            // as a link-shaped string.
            'the token will not unseal' => [self::invitation(), false],
        ];
    }
}
