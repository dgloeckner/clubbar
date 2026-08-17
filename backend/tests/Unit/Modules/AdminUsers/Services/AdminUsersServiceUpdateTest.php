<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\AdminUsers\Services;

use App\Modules\AdminUsers\Repositories\AdminUserRolesRepository;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\AdminUsers\Services\AdminUsersService;
use App\Modules\Notifications\Services\NotificationsService;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * `updateAdminUser` applying the whole body, and the shared duplicate-email
 * check both write paths ask (issue #117).
 *
 * A PATCH carrying `is_active` alongside `display_name` used to (de)activate
 * and return early, silently discarding the other fields — the request
 * answered 200 with a record that had ignored most of what was sent.
 */
class AdminUsersServiceUpdateTest extends TestCase
{
    private AdminUsersRepository $repository;
    private NotificationsService $notifications;
    private AdminUsersService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AdminUsersRepository::class);
        $this->notifications = $this->createMock(NotificationsService::class);
        $this->service = new AdminUsersService(
            $this->repository,
            $this->createMock(AuditService::class),
            $this->notifications,
            $this->createMock(AdminUserRolesRepository::class),
        );
    }

    /** @param array<string, mixed> $overrides */
    private static function row(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'admin-2',
            'email' => 'someone@example.org',
            'display_name' => 'Someone',
            'locale' => 'de',
            'is_active' => 1,
            'last_login_at' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ];
    }

    public function test_deactivation_and_the_other_fields_are_both_applied(): void
    {
        $this->repository->method('countActive')->willReturn(5);

        $writes = [];
        $this->repository->method('updateById')
            ->willReturnCallback(function (string $id, array $data) use (&$writes) {
                $writes[] = $data;

                return self::row(['display_name' => 'Renamed', 'is_active' => 0]);
            });

        $admin = $this->service->updateAdminUser(
            'admin-2',
            ['is_active' => false, 'display_name' => 'Renamed', 'locale' => 'en'],
            'admin-1',
        );

        $this->assertSame([
            ['is_active' => 0],
            ['display_name' => 'Renamed', 'locale' => 'en'],
        ], $writes, 'the activation change and the named fields must both reach the repository');

        $this->assertSame('Renamed', $admin->displayName);
    }

    public function test_reactivation_and_the_other_fields_are_both_applied(): void
    {
        $writes = [];
        $this->repository->method('updateById')
            ->willReturnCallback(function (string $id, array $data) use (&$writes) {
                $writes[] = $data;

                return self::row(['email' => 'new@example.org']);
            });

        $this->service->updateAdminUser('admin-2', ['is_active' => true, 'email' => 'new@example.org'], 'admin-1');

        $this->assertSame([
            ['is_active' => 1],
            ['email' => 'new@example.org'],
        ], $writes);
    }

    /**
     * The activation change runs first so its guard rails throw before any
     * write happens — a refused request must leave the record untouched rather
     * than half-updated.
     */
    public function test_a_refused_deactivation_writes_nothing_at_all(): void
    {
        $this->repository->method('countActive')->willReturn(1);
        $this->repository->expects($this->never())->method('updateById');

        $this->expectException(BusinessRuleException::class);

        $this->service->updateAdminUser('admin-2', ['is_active' => false, 'display_name' => 'Renamed'], 'admin-1');
    }

    public function test_a_body_without_is_active_still_updates_the_named_fields(): void
    {
        $this->repository->expects($this->once())
            ->method('updateById')
            ->with('admin-2', ['display_name' => 'Renamed'])
            ->willReturn(self::row(['display_name' => 'Renamed']));

        $admin = $this->service->updateAdminUser('admin-2', ['display_name' => 'Renamed'], 'admin-1');

        $this->assertSame('Renamed', $admin->displayName);
    }

    /**
     * Moving the login identifier has three consequences beyond the write, and
     * all three are the point of the change: the previous owner is told at the
     * address they still control, the account's other sessions stop working,
     * and the event is findable in the audit log under its own name.
     */
    public function test_moving_the_email_notifies_the_former_address_and_ends_other_sessions(): void
    {
        $this->repository->method('findById')->willReturn(self::row(['email' => 'before@example.org']));
        $this->repository->method('updateById')->willReturn(self::row(['email' => 'after@example.org']));

        $this->notifications->expects($this->once())
            ->method('notifyFormerAddress')
            ->with('admin-2', 'before@example.org', $this->stringStartsWith('changed:'), 'admin-1');

        $this->repository->expects($this->once())
            ->method('touchCredentialsEpoch')
            ->with('admin-2');

        $this->service->updateAdminUser('admin-2', ['email' => 'after@example.org'], 'admin-1');
    }

    /**
     * The conditionality the whole design rests on. A display-name edit is not
     * a credential change: nobody is written to, and no session is ended.
     */
    public function test_a_non_email_update_notifies_nobody_and_ends_no_session(): void
    {
        $this->repository->method('findById')->willReturn(self::row());
        $this->repository->method('updateById')->willReturn(self::row(['display_name' => 'Renamed']));

        $this->notifications->expects($this->never())->method('notifyFormerAddress');
        $this->repository->expects($this->never())->method('touchCredentialsEpoch');

        $this->service->updateAdminUser('admin-2', ['display_name' => 'Renamed'], 'admin-1');
    }

    /**
     * `admin_users.email` is UNIQUE under a case-insensitive collation, so
     * re-submitting the same address in different case changes nothing — and
     * must not sign the account out of its own sessions.
     */
    public function test_a_case_only_email_difference_is_not_a_credential_change(): void
    {
        $this->repository->method('findById')->willReturn(self::row(['email' => 'someone@example.org']));
        $this->repository->method('updateById')->willReturn(self::row());

        $this->notifications->expects($this->never())->method('notifyFormerAddress');
        $this->repository->expects($this->never())->method('touchCredentialsEpoch');

        $this->service->updateAdminUser('admin-2', ['email' => 'SOMEONE@EXAMPLE.ORG'], 'admin-1');
    }

    /**
     * The notification is best effort by construction: the change is already
     * committed when it runs, so a queue that will not take the notice must not
     * turn a successful change into a failure.
     */
    public function test_a_failed_notification_does_not_fail_the_change(): void
    {
        $this->repository->method('findById')->willReturn(self::row(['email' => 'before@example.org']));
        $this->repository->method('updateById')->willReturn(self::row(['email' => 'after@example.org']));

        $this->notifications->method('notifyFormerAddress')
            ->willThrowException(new \RuntimeException('outbox unavailable'));

        $admin = $this->service->updateAdminUser('admin-2', ['email' => 'after@example.org'], 'admin-1');

        $this->assertSame('after@example.org', $admin->email);
    }

    public function test_a_body_carrying_only_is_active_still_toggles(): void
    {
        $this->repository->expects($this->once())
            ->method('updateById')
            ->with('admin-2', ['is_active' => 1])
            ->willReturn(self::row());

        $this->service->updateAdminUser('admin-2', ['is_active' => true], 'admin-1');
    }

    public function test_the_string_zero_a_form_post_sends_deactivates(): void
    {
        $this->repository->method('countActive')->willReturn(5);

        $this->repository->expects($this->once())
            ->method('updateById')
            ->with('admin-2', ['is_active' => 0])
            ->willReturn(self::row(['is_active' => 0]));

        $this->service->updateAdminUser('admin-2', ['is_active' => '0'], 'admin-1');
    }

    public function test_email_taken_by_another_is_true_only_for_a_different_owner(): void
    {
        $this->repository->method('findByEmail')->willReturn(self::row(['id' => 'admin-2']));

        $this->assertTrue($this->service->emailTakenByAnother('someone@example.org', 'admin-9'));
        $this->assertTrue($this->service->emailTakenByAnother('someone@example.org'));
        $this->assertFalse(
            $this->service->emailTakenByAnother('someone@example.org', 'admin-2'),
            'keeping your own address is not a duplicate'
        );
    }

    public function test_email_taken_by_another_is_false_for_an_unused_address(): void
    {
        $this->repository->method('findByEmail')->willReturn(null);

        $this->assertFalse($this->service->emailTakenByAnother('nobody@example.org', 'admin-2'));
    }

    /* ─────────────── Password changes (PR #469) ─────────────── */

    /**
     * The self-service path. Neither of these two methods had a unit test
     * before; both hash with bcrypt, audit under the credential-specific action
     * rather than a plain `update`, and end the account's other sessions.
     */
    public function test_changing_your_own_password_hashes_audits_and_ends_other_sessions(): void
    {
        $this->repository->method('findById')->willReturn(self::row());

        $stored = null;
        $this->repository->expects($this->once())
            ->method('updateById')
            ->willReturnCallback(function (string $id, array $data) use (&$stored) {
                $stored = $data['password'] ?? null;
                return self::row();
            });

        $this->repository->expects($this->once())
            ->method('touchCredentialsEpoch')
            ->with('admin-2');

        $this->service->changeOwnPassword('admin-2', 'BrandNewPass1');

        $this->assertNotSame('BrandNewPass1', $stored, 'never stored in the clear');
        $this->assertTrue(password_verify('BrandNewPass1', $stored));
    }

    public function test_changing_a_password_for_an_unknown_admin_throws(): void
    {
        $this->repository->method('findById')->willReturn(null);
        $this->repository->expects($this->never())->method('updateById');

        $this->expectException(NotFoundException::class);

        $this->service->changeOwnPassword('nobody', 'BrandNewPass1');
    }

    /**
     * The cross-account reset. UC-A63 has said "target user's sessions
     * invalidated" since before anything enforced it; the epoch is what makes
     * that true.
     */
    public function test_resetting_a_peers_password_returns_it_once_and_ends_their_sessions(): void
    {
        $stored = null;
        $this->repository->method('updateById')
            ->willReturnCallback(function (string $id, array $data) use (&$stored) {
                $stored = $data['password'] ?? null;
                return self::row();
            });

        $this->repository->expects($this->once())
            ->method('touchCredentialsEpoch')
            ->with('admin-2');

        $result = $this->service->resetAdminPassword('admin-2', 'admin-1');

        $this->assertNotSame('', $result['password'], 'shown once to the resetter');
        $this->assertTrue(
            password_verify($result['password'], $stored),
            'the hash stored is of the password handed back',
        );
    }

    public function test_resetting_the_password_of_an_unknown_admin_throws(): void
    {
        $this->repository->method('updateById')->willReturn(null);
        $this->repository->expects($this->never())->method('touchCredentialsEpoch');

        $this->expectException(NotFoundException::class);

        $this->service->resetAdminPassword('nobody', 'admin-1');
    }
}
