<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\AdminUsers\Services;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\AdminUsers\Repositories\AdminUserRolesRepository;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\AdminUsers\DTOs\AdminInvitationDto;
use App\Modules\AdminUsers\Services\AdminInvitationService;
use App\Modules\AdminUsers\Services\AdminUsersService;
use App\Modules\Notifications\Services\AdminNotifier;
use App\Modules\Notifications\Services\NotificationsService;
use App\Shared\Exceptions\BusinessRuleException;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

/**
 * Reading and writing an account's role set through the service (ADR-0044, #514).
 *
 * No enforcement lives here yet — that is #519. What this slice has to get
 * right is that a role set exists for every account and that nothing this
 * service creates lands without one, because an account holding no role is an
 * account that can do nothing the moment enforcement arrives.
 */
class AdminUsersServiceRolesTest extends TestCase
{
    private AdminUsersRepository $repository;
    private AdminUserRolesRepository $roles;
    private AdminUsersService $service;

    /** @var AdminInvitationService&\PHPUnit\Framework\MockObject\MockObject */
    private AdminInvitationService $invitations;

    protected function setUp(): void
    {
        $this->invitations = $this->createMock(AdminInvitationService::class);
        $this->repository = $this->createMock(AdminUsersRepository::class);
        $this->roles = $this->createMock(AdminUserRolesRepository::class);
        $this->service = new AdminUsersService(
            $this->repository,
            $this->createMock(AuditService::class),
            $this->createMock(NotificationsService::class),
            $this->roles,
            $this->createMock(AdminNotifier::class),
            $this->invitations,
        );
    }

    public function test_the_role_set_is_read_from_the_relation(): void
    {
        $this->roles->method('rolesFor')->with('admin-1')->willReturn([AdminRole::KASSENWART]);

        $this->assertSame([AdminRole::KASSENWART], $this->service->getRoles('admin-1'));
    }

    public function test_a_role_set_is_written_as_given(): void
    {
        $this->roles->expects($this->once())
            ->method('replace')
            ->with('admin-1', [AdminRole::KASSENWART, AdminRole::GETRAENKEWART]);

        $this->service->setRoles('admin-1', [AdminRole::KASSENWART, AdminRole::GETRAENKEWART]);
    }

    /**
     * An account with no roles is not "restricted", it is bricked — and
     * silently so, since nothing about the account form says a role is what
     * makes it work. The service refuses rather than storing it.
     */
    public function test_an_empty_role_set_is_refused(): void
    {
        $this->roles->expects($this->never())->method('replace');

        $this->expectException(BusinessRuleException::class);

        $this->service->setRoles('admin-1', []);
    }

    /**
     * Admin-exclusivity (CONTEXT.md's Role entry, ADR-0044): an account's role
     * set is either `admin` alone, or any non-empty subset of the two lesser
     * roles. `admin` combined with a lesser role is not "more privileged", it
     * is a state the domain does not recognise.
     */
    public function test_admin_cannot_be_combined_with_a_lesser_role(): void
    {
        $this->roles->expects($this->never())->method('replace');

        $this->expectException(BusinessRuleException::class);

        $this->service->setRoles('admin-1', [AdminRole::ADMIN, AdminRole::KASSENWART]);
    }

    /**
     * The lesser roles remain freely combinable with each other — only `admin`
     * is exclusive.
     */
    public function test_both_lesser_roles_together_is_still_allowed(): void
    {
        $this->roles->expects($this->once())
            ->method('replace')
            ->with('admin-1', [AdminRole::KASSENWART, AdminRole::GETRAENKEWART]);

        $this->service->setRoles('admin-1', [AdminRole::KASSENWART, AdminRole::GETRAENKEWART]);
    }

    /**
     * Defense in depth for the "never neuter the last admin" invariant
     * (#548). Nothing in the request path is supposed to reach this today —
     * the endpoint is `admin`-only and an account can never edit its own
     * roles — but a guard whose only backing is "nothing else calls this
     * function yet" is fragile, so `applyRoles` enforces it directly.
     */
    public function test_revoking_admin_from_the_last_holder_is_refused(): void
    {
        $this->roles->method('rolesFor')->with('admin-1')->willReturn([AdminRole::ADMIN]);
        $this->roles->method('countActiveHolders')->with(AdminRole::ADMIN)->willReturn(1);
        $this->roles->expects($this->never())->method('replace');

        $this->expectException(BusinessRuleException::class);

        $this->service->setRoles('admin-1', [AdminRole::KASSENWART]);
    }

    /**
     * The same revocation is fine as soon as another active account also
     * holds `admin`.
     */
    public function test_revoking_admin_is_allowed_when_another_admin_remains(): void
    {
        $this->roles->method('rolesFor')->with('admin-1')->willReturn([AdminRole::ADMIN]);
        $this->roles->method('countActiveHolders')->with(AdminRole::ADMIN)->willReturn(2);
        $this->roles->expects($this->once())->method('replace')->with('admin-1', [AdminRole::KASSENWART]);

        $this->service->setRoles('admin-1', [AdminRole::KASSENWART]);
    }

    /**
     * The guard only fires when `admin` is actually leaving the set — an
     * unrelated role edit on the sole admin (e.g. also picking up
     * `kassenwart`) must not be blocked by it.
     */
    public function test_the_last_admin_guard_does_not_fire_when_admin_is_kept(): void
    {
        $this->roles->method('rolesFor')->with('admin-1')->willReturn([AdminRole::ADMIN, AdminRole::KASSENWART]);
        $this->roles->expects($this->never())->method('countActiveHolders');
        $this->roles->expects($this->once())->method('replace')->with('admin-1', [AdminRole::ADMIN]);

        // Drops kassenwart but keeps admin — admin never leaves the set, so
        // the last-admin guard must not even ask how many other admins exist.
        $this->service->setRoles('admin-1', [AdminRole::ADMIN]);
    }

    /**
     * Behaviour preservation, at the one place new accounts appear.
     *
     * Every admin the panel creates today has full access; #514 must not change
     * that, so a created account holds `admin` unless a later slice says
     * otherwise. Getting this wrong would only surface once #519 lands, as an
     * account created in between that cannot open a single page.
     */
    public function test_a_created_account_holds_the_admin_role(): void
    {
        $this->repository->method('create')->willReturn([
            'id' => 'admin-9',
            'email' => 'new@example.org',
            'display_name' => 'New',
            'locale' => 'de',
            'is_active' => 1,
            'last_login_at' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);

        $this->roles->expects($this->once())
            ->method('replace')
            ->with('admin-9', [AdminRole::ADMIN]);

        // Creating an account now also mints the invitation that gives it a
        // password (migration 058), so the double has to answer for it.
        $this->invitations->expects($this->once())
            ->method('issue')
            ->with('admin-9', 'admin-1')
            ->willReturn(new AdminInvitationDto(
                adminUserId: 'admin-9',
                email: 'new@example.org',
                expiresAt: '2026-01-08 00:00:00',
                url: 'https://club.example.org/invite/token-abc',
            ));

        $result = $this->service->createAdminUser('new@example.org', 'New', 'de', 'admin-1');

        // No password anywhere in the answer: the account is unusable until
        // its owner follows the link.
        $this->assertArrayNotHasKey('password', $result);
        $this->assertSame(
            'https://club.example.org/invite/token-abc',
            $result['invitation']->url,
        );
    }

    public function test_creating_an_account_with_an_invalid_role_combination_is_refused(): void
    {
        $this->repository->method('create')->willReturn([
            'id' => 'admin-9',
            'email' => 'new@example.org',
            'display_name' => 'New',
            'locale' => 'de',
            'is_active' => 1,
            'last_login_at' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        $this->roles->expects($this->never())->method('replace');

        $this->expectException(BusinessRuleException::class);

        $this->service->createAdminUser(
            'new@example.org',
            'New',
            'de',
            'admin-1',
            [AdminRole::ADMIN, AdminRole::GETRAENKEWART],
        );
    }
}
