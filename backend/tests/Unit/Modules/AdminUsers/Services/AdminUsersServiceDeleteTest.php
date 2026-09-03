<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\AdminUsers\Services;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\AdminUsers\Repositories\AdminUserRolesRepository;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\AdminUsers\Services\AdminInvitationService;
use App\Modules\AdminUsers\Services\AdminUsersService;
use App\Modules\Notifications\Services\AdminNotifier;
use App\Modules\Notifications\Services\NotificationsService;
use App\Shared\Enums\AuditAction;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Exceptions\BusinessRuleReason;
use App\Shared\Exceptions\NotFoundException;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * Deleting an admin account (UC-A61).
 *
 * The rule under test is narrow on purpose: an account is deletable only if it
 * has never signed in and never authored an audit row. `admin_users.id` is
 * referenced across the schema with two incompatible meanings — `settlements`
 * and `mandate_documents` use `ON DELETE RESTRICT`, while
 * `audit_log.admin_user_id` has no constraint at all — so a wider delete would
 * either be refused by the database or silently blank the actor on every row
 * that admin ever wrote. These tests are what keep the guard from being
 * loosened by someone who only sees the first half of that.
 */
class AdminUsersServiceDeleteTest extends TestCase
{
    private AdminUsersRepository $repository;
    private AdminUserRolesRepository $roles;
    private AuditService $audit;
    private AdminUsersService $service;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AdminUsersRepository::class);
        $this->roles = $this->createMock(AdminUserRolesRepository::class);
        $this->audit = $this->createMock(AuditService::class);
        $this->service = new AdminUsersService(
            $this->repository,
            $this->audit,
            $this->createMock(NotificationsService::class),
            $this->roles,
            $this->createMock(AdminNotifier::class),
            $this->createMock(AdminInvitationService::class),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function account(array $overrides = []): array
    {
        return array_merge([
            'id' => 'target',
            'email' => 'newcomer@club.test',
            'display_name' => 'Newcomer',
            'is_active' => 1,
            'last_login_at' => null,
        ], $overrides);
    }

    public function test_an_account_that_never_signed_in_is_deleted(): void
    {
        $this->repository->method('findById')->with('target')->willReturn($this->account());
        $this->roles->method('rolesFor')->willReturn([AdminRole::KASSENWART]);
        $this->audit->method('hasEntriesByActor')->with('target')->willReturn(false);

        $this->repository->expects($this->once())
            ->method('deleteById')
            ->with('target')
            ->willReturn(true);

        $this->service->deleteAdminUser('target', 'caller');
    }

    /**
     * Once the row is gone the audit entry is the only thing that still says
     * *who* was removed — `entity_id` alone is a UUID nobody can resolve back
     * to a person. So the identity rides on the row, and the row is written
     * before the delete rather than after it.
     */
    public function test_the_deletion_is_audited_with_the_identity_it_destroys(): void
    {
        $this->repository->method('findById')->willReturn($this->account());
        $this->roles->method('rolesFor')->willReturn([AdminRole::KASSENWART]);
        $this->audit->method('hasEntriesByActor')->willReturn(false);
        $this->repository->method('deleteById')->willReturn(true);

        $this->audit->expects($this->once())
            ->method('log')
            ->with(
                AuditAction::DELETE,
                $this->anything(),
                'target',
                $this->callback(function (array $old): bool {
                    return $old['email'] === 'newcomer@club.test'
                        && $old['display_name'] === 'Newcomer'
                        && $old['roles'] === ['kassenwart'];
                }),
                null,
                'caller',
            );

        $this->service->deleteAdminUser('target', 'caller');
    }

    /**
     * The common refusal. A colleague who has signed in has, by definition,
     * been able to act, and the trail of what they did outlives the account.
     */
    public function test_an_account_that_has_signed_in_is_refused(): void
    {
        $this->repository->method('findById')
            ->willReturn($this->account(['last_login_at' => '2026-08-01 10:00:00']));
        $this->roles->method('rolesFor')->willReturn([AdminRole::KASSENWART]);

        $this->repository->expects($this->never())->method('deleteById');

        try {
            $this->service->deleteAdminUser('target', 'caller');
            $this->fail('Expected a BusinessRuleException');
        } catch (BusinessRuleException $e) {
            $this->assertSame(BusinessRuleReason::ADMIN_USER_HAS_HISTORY, $e->getReason());
        }
    }

    /**
     * The half that `last_login_at` does not cover: accepting an invitation
     * audits under the invitee's own name before any login timestamp exists.
     * It is the audit rows, not the timestamp, that a delete would strand.
     */
    public function test_an_account_that_authored_an_audit_row_is_refused(): void
    {
        $this->repository->method('findById')->willReturn($this->account());
        $this->roles->method('rolesFor')->willReturn([AdminRole::KASSENWART]);
        $this->audit->method('hasEntriesByActor')->with('target')->willReturn(true);

        $this->repository->expects($this->never())->method('deleteById');

        try {
            $this->service->deleteAdminUser('target', 'caller');
            $this->fail('Expected a BusinessRuleException');
        } catch (BusinessRuleException $e) {
            $this->assertSame(BusinessRuleReason::ADMIN_USER_HAS_HISTORY, $e->getReason());
        }
    }

    public function test_deleting_your_own_account_is_refused(): void
    {
        $this->repository->expects($this->never())->method('deleteById');

        try {
            $this->service->deleteAdminUser('caller', 'caller');
            $this->fail('Expected a BusinessRuleException');
        } catch (BusinessRuleException $e) {
            $this->assertSame(BusinessRuleReason::CANNOT_DEACTIVATE_SELF, $e->getReason());
        }
    }

    /**
     * All but unreachable — the last active admin has signed in, so the history
     * guard would catch it anyway — but a system that can be left with no way
     * in is worth refusing twice, and the refusal must name the right reason.
     */
    public function test_the_last_active_admin_cannot_be_deleted(): void
    {
        $this->repository->method('findById')->willReturn($this->account());
        $this->roles->method('rolesFor')->willReturn([AdminRole::ADMIN]);
        $this->roles->method('countActiveHolders')->with(AdminRole::ADMIN)->willReturn(1);

        $this->repository->expects($this->never())->method('deleteById');

        try {
            $this->service->deleteAdminUser('target', 'caller');
            $this->fail('Expected a BusinessRuleException');
        } catch (BusinessRuleException $e) {
            $this->assertSame(BusinessRuleReason::LAST_ACTIVE_ADMIN, $e->getReason());
        }
    }

    public function test_an_admin_is_deletable_while_another_admin_remains(): void
    {
        $this->repository->method('findById')->willReturn($this->account());
        $this->roles->method('rolesFor')->willReturn([AdminRole::ADMIN]);
        $this->roles->method('countActiveHolders')->with(AdminRole::ADMIN)->willReturn(2);
        $this->audit->method('hasEntriesByActor')->willReturn(false);

        $this->repository->expects($this->once())->method('deleteById')->willReturn(true);

        $this->service->deleteAdminUser('target', 'caller');
    }

    public function test_an_unknown_account_is_not_found(): void
    {
        $this->repository->method('findById')->willReturn(null);

        $this->expectException(NotFoundException::class);

        $this->service->deleteAdminUser('ghost', 'caller');
    }

    /**
     * Defense in depth for a `RESTRICT` the guards did not anticipate — a
     * reference added later to a table nobody thought of. The admin gets the
     * rule the system actually has, not a 500.
     */
    public function test_a_foreign_key_refusal_surfaces_as_the_history_rule(): void
    {
        $this->repository->method('findById')->willReturn($this->account());
        $this->roles->method('rolesFor')->willReturn([AdminRole::KASSENWART]);
        $this->audit->method('hasEntriesByActor')->willReturn(false);
        $this->repository->method('deleteById')
            ->willThrowException(new \PDOException('Cannot delete or update a parent row'));

        try {
            $this->service->deleteAdminUser('target', 'caller');
            $this->fail('Expected a BusinessRuleException');
        } catch (BusinessRuleException $e) {
            $this->assertSame(BusinessRuleReason::ADMIN_USER_HAS_HISTORY, $e->getReason());
        }
    }
}
