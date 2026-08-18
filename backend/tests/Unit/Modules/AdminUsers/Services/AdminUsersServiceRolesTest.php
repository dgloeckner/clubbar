<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\AdminUsers\Services;

use App\Modules\AdminUsers\Enums\AdminRole;
use App\Modules\AdminUsers\Repositories\AdminUserRolesRepository;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\AdminUsers\Services\AdminUsersService;
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

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AdminUsersRepository::class);
        $this->roles = $this->createMock(AdminUserRolesRepository::class);
        $this->service = new AdminUsersService(
            $this->repository,
            $this->createMock(AuditService::class),
            $this->createMock(NotificationsService::class),
            $this->roles,
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

        $this->service->createAdminUser('new@example.org', 'New', 'de', 'admin-1');
    }
}
