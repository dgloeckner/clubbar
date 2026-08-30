<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\AdminUsers\Services;

use App\Modules\AdminUsers\Domain\InvitationLink;
use App\Modules\AdminUsers\Repositories\AdminInvitationsRepository;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\AdminUsers\Services\AdminInvitationService;
use App\Modules\AdminUsers\Services\InvitationTokenCipher;
use App\Modules\Notifications\Services\AdminNotifier;
use App\Shared\Config\AppConfig;
use App\Shared\Enums\AuditAction;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Logging\Logger;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * The rules that make an invitation a *credential* rather than a convenience
 * (migration 058, UC-A68).
 *
 * Each of these is a way the feature could quietly become weaker than the
 * password handover it replaced: two live links to one account, a link that
 * outlives its use, a mail failure that takes the account down with it, or an
 * emailed path back into an established admin's account.
 */
class AdminInvitationServiceTest extends TestCase
{
    private AdminInvitationsRepository $invitations;
    private AdminUsersRepository $admins;
    private InvitationTokenCipher $cipher;
    private AdminNotifier $notifier;
    private AuditService $audit;
    private AdminInvitationService $service;

    protected function setUp(): void
    {
        $this->invitations = $this->createMock(AdminInvitationsRepository::class);
        $this->admins = $this->createMock(AdminUsersRepository::class);
        $this->cipher = $this->createMock(InvitationTokenCipher::class);
        $this->notifier = $this->createMock(AdminNotifier::class);
        $this->audit = $this->createMock(AuditService::class);

        $config = $this->createMock(AppConfig::class);
        // `appUrl` is a readonly promoted property, so it cannot be stubbed
        // like a method; the double is given the value directly.
        $reflection = new \ReflectionProperty(AppConfig::class, 'appUrl');
        $reflection->setValue($config, 'https://club.example.org');

        $this->cipher->method('seal')->willReturnCallback(static fn(string $t): string => 'sealed:' . $t);

        $this->service = new AdminInvitationService(
            $this->invitations,
            $this->admins,
            $this->cipher,
            $this->notifier,
            $this->audit,
            $config,
            $this->createMock(Logger::class),
        );
    }

    /** @param array<string,mixed> $overrides */
    private function givenAccount(array $overrides = []): void
    {
        $this->admins->method('findById')->willReturn($overrides + [
            'id' => 'admin-9',
            'email' => 'neu@example.org',
            'display_name' => 'Neue Kassenwartin',
            'locale' => 'de',
            'is_active' => 1,
        ]);
    }

    /** @param array<string,mixed> $overrides */
    private static function invitationRow(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'inv-1',
            'admin_user_id' => 'admin-9',
            'expires_at' => date('Y-m-d H:i:s', time() + 86400),
            'accepted_at' => null,
            'revoked_at' => null,
        ];
    }

    // ── Issuing ─────────────────────────────────────────────────────────────

    /**
     * The link goes out, and what is *stored* is a digest — not the token.
     * A database dump must not be a set of working invitations.
     */
    public function test_issuing_stores_a_hash_and_returns_a_link_that_matches_it(): void
    {
        $this->givenAccount();

        $storedHash = null;
        $this->invitations->expects($this->once())
            ->method('create')
            ->willReturnCallback(function (string $adminUserId, string $hash, string $cipher) use (&$storedHash): array {
                $storedHash = $hash;
                $this->assertStringStartsWith('sealed:', $cipher);
                return self::invitationRow();
            });

        $result = $this->service->issue('admin-9', 'admin-1');

        $this->assertStringStartsWith('https://club.example.org/invite/', $result->url);

        $token = substr($result->url, strlen('https://club.example.org/invite/'));
        $this->assertSame($storedHash, InvitationLink::hash($token), 'the link must resolve to the row that was written');
        $this->assertNotSame($storedHash, $token, 'the raw token must never be what is stored');
    }

    /**
     * A resend must not leave the previous link alive. An admin who believes
     * they have replaced something has.
     */
    public function test_issuing_revokes_every_outstanding_invitation_first(): void
    {
        $this->givenAccount();
        $this->invitations->method('create')->willReturn(self::invitationRow());

        $this->invitations->expects($this->once())
            ->method('revokeOutstandingFor')
            ->with('admin-9');

        $this->service->issue('admin-9', 'admin-1');
    }

    public function test_issuing_queues_the_mail_to_the_accounts_own_address(): void
    {
        $this->givenAccount();
        $this->invitations->method('create')->willReturn(self::invitationRow());

        $this->notifier->expects($this->once())
            ->method('inviteAdmin')
            ->with('admin-9', 'neu@example.org', 'inv-1', $this->anything(), 'admin-1');

        $this->service->issue('admin-9', 'admin-1');
    }

    /**
     * The mail is best effort; the link is not.
     *
     * A queue that will not take the message leaves the caller with a working
     * link and no email — recoverable. A 500 here would leave an account nobody
     * can reach and a link nobody was ever shown.
     */
    public function test_a_queue_that_refuses_the_mail_does_not_fail_the_invitation(): void
    {
        $this->givenAccount();
        $this->invitations->method('create')->willReturn(self::invitationRow());
        $this->notifier->method('inviteAdmin')->willThrowException(new \RuntimeException('outbox is unhappy'));

        $result = $this->service->issue('admin-9', 'admin-1');

        $this->assertNotNull($result->url);
    }

    public function test_issuing_is_audited_without_the_token(): void
    {
        $this->givenAccount();
        $this->invitations->method('create')->willReturn(self::invitationRow());

        $this->audit->expects($this->once())
            ->method('log')
            ->with(
                AuditAction::INVITATION_SENT,
                $this->anything(),
                'admin-9',
                null,
                $this->callback(function (array $values): bool {
                    $this->assertSame('inv-1', $values['invitation_id']);
                    $this->assertArrayNotHasKey('token', $values);
                    $this->assertArrayNotHasKey('url', $values);
                    return true;
                }),
                'admin-1',
            );

        $this->service->issue('admin-9', 'admin-1');
    }

    public function test_inviting_an_account_that_does_not_exist_is_a_404(): void
    {
        $this->admins->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->service->issue('nobody');
    }

    // ── Reissuing ───────────────────────────────────────────────────────────

    /**
     * The load-bearing refusal. An emailed link that can re-credential an
     * established admin is a second way past the step-up guarding
     * `POST /admin-users/{id}/reset-password` — and the weaker of the two
     * paths, which is the one an attacker picks.
     */
    public function test_an_account_that_has_accepted_cannot_be_invited_again(): void
    {
        $this->givenAccount();
        $this->invitations->method('hasAccepted')->willReturn(true);
        $this->invitations->expects($this->never())->method('create');

        try {
            $this->service->reissue('admin-9', 'admin-1');
            $this->fail('expected a refusal');
        } catch (BusinessRuleException $e) {
            $this->assertSame(BusinessRuleReason::ADMIN_ALREADY_ONBOARDED, $e->getReason());
        }
    }

    /**
     * Without this, deactivating a colleague would not stop their outstanding
     * invitation from being renewed.
     */
    public function test_a_deactivated_account_cannot_be_invited(): void
    {
        $this->givenAccount(['is_active' => 0]);
        $this->invitations->expects($this->never())->method('create');

        try {
            $this->service->reissue('admin-9', 'admin-1');
            $this->fail('expected a refusal');
        } catch (BusinessRuleException $e) {
            $this->assertSame(BusinessRuleReason::ADMIN_ACCOUNT_INACTIVE, $e->getReason());
        }
    }

    public function test_a_pending_account_can_be_sent_a_replacement(): void
    {
        $this->givenAccount();
        $this->invitations->method('hasAccepted')->willReturn(false);
        $this->invitations->method('create')->willReturn(self::invitationRow());

        $this->assertNotNull($this->service->reissue('admin-9', 'admin-1')->url);
    }

    // ── Following a link ────────────────────────────────────────────────────

    public function test_a_valid_link_names_the_account_and_nothing_more(): void
    {
        $this->givenAccount();
        $this->invitations->method('findByTokenHash')->willReturn(self::invitationRow());

        $invitee = $this->service->describe(InvitationLink::mintToken());

        $this->assertSame(['email', 'display_name', 'locale'], array_keys($invitee));
        $this->assertSame('neu@example.org', $invitee['email']);
    }

    /**
     * Unknown, expired, accepted and revoked leave by the same door.
     *
     * Telling an anonymous caller that a token is "expired" rather than
     * "unknown" confirms that it existed, which turns this endpoint into an
     * oracle for guessing them.
     *
     * @dataProvider unusableLinks
     * @param array<string,mixed>|null $row
     */
    public function test_every_unusable_link_answers_identically(?array $row): void
    {
        $this->givenAccount();
        $this->invitations->method('findByTokenHash')->willReturn($row);

        try {
            $this->service->describe(InvitationLink::mintToken());
            $this->fail('expected a refusal');
        } catch (BusinessRuleException $e) {
            $this->assertSame(BusinessRuleReason::INVITATION_INVALID, $e->getReason());
        }
    }

    /** @return array<string, array{0: array<string,mixed>|null}> */
    public static function unusableLinks(): array
    {
        return [
            'unknown' => [null],
            'expired' => [self::invitationRow(['expires_at' => date('Y-m-d H:i:s', time() - 60)])],
            'already accepted' => [self::invitationRow(['accepted_at' => '2026-08-01 10:00:00'])],
            'revoked by a newer one' => [self::invitationRow(['revoked_at' => '2026-08-01 10:00:00'])],
        ];
    }

    public function test_a_malformed_token_never_reaches_the_database(): void
    {
        $this->invitations->expects($this->never())->method('findByTokenHash');

        $this->expectException(BusinessRuleException::class);

        $this->service->describe('../../etc/passwd');
    }

    // ── Accepting ───────────────────────────────────────────────────────────

    public function test_accepting_sets_a_verifiable_password_and_ends_older_sessions(): void
    {
        $this->givenAccount();
        $this->invitations->method('findByTokenHash')->willReturn(self::invitationRow());
        $this->invitations->method('markAccepted')->willReturn(true);

        $written = null;
        $this->admins->method('updateById')->willReturnCallback(
            function (string $id, array $data) use (&$written): array {
                $written = $data['password'];
                return ['id' => $id, 'email' => 'neu@example.org'];
            }
        );

        $this->admins->expects($this->once())->method('touchCredentialsEpoch')->with('admin-9');

        $result = $this->service->accept(InvitationLink::mintToken(), 'Str0ngPassword');

        $this->assertSame('neu@example.org', $result['email']);
        $this->assertNotSame('Str0ngPassword', $written, 'the password must be hashed before it is stored');
        $this->assertTrue(password_verify('Str0ngPassword', $written));
    }

    /**
     * The single-use guard lives in the UPDATE's `WHERE`, and this is what
     * proves the service honours its answer: two requests carrying one link
     * must not both set a password, or the second person silently overwrites
     * the first.
     */
    public function test_a_link_that_lost_the_race_writes_no_password(): void
    {
        $this->givenAccount();
        $this->invitations->method('findByTokenHash')->willReturn(self::invitationRow());
        $this->invitations->method('markAccepted')->willReturn(false);

        $this->admins->expects($this->never())->method('updateById');

        try {
            $this->service->accept(InvitationLink::mintToken(), 'Str0ngPassword');
            $this->fail('expected a refusal');
        } catch (BusinessRuleException $e) {
            $this->assertSame(BusinessRuleReason::INVITATION_INVALID, $e->getReason());
        }
    }

    /**
     * Attributed to the invitee, who has no session while doing it — the only
     * entry in the log written by a request that carries none.
     */
    public function test_accepting_is_audited_against_the_invitee(): void
    {
        $this->givenAccount();
        $this->invitations->method('findByTokenHash')->willReturn(self::invitationRow());
        $this->invitations->method('markAccepted')->willReturn(true);
        $this->admins->method('updateById')->willReturn(['id' => 'admin-9', 'email' => 'neu@example.org']);

        $this->audit->expects($this->once())
            ->method('log')
            ->with(
                AuditAction::INVITATION_ACCEPTED,
                $this->anything(),
                'admin-9',
                null,
                $this->callback(function (array $values): bool {
                    // Masked, never the password itself.
                    $this->assertSame('[SET]', $values['password']);
                    return true;
                }),
                'admin-9',
            );

        $this->service->accept(InvitationLink::mintToken(), 'Str0ngPassword');
    }
}
