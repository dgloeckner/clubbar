<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Auth\Services;

use App\Modules\Auth\Services\AuthService;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Shared\Logging\Logger;
use PHPUnit\Framework\TestCase;

class AuthServiceTest extends TestCase
{
    private AdminUsersRepository $adminUsersRepository;
    private Logger $logger;
    private AuthService $authService;

    protected function setUp(): void
    {
        $this->adminUsersRepository = $this->createMock(AdminUsersRepository::class);
        $this->logger = $this->createMock(Logger::class);
        $this->authService = new AuthService($this->adminUsersRepository, $this->logger);
    }

    private function admin(array $overrides = []): array
    {
        return array_merge([
            'id' => 'admin-1',
            'email' => 'admin@example.com',
            'password_hash' => password_hash('correct-password', PASSWORD_BCRYPT),
            'is_active' => 1,
        ], $overrides);
    }

    public function test_authenticate_performs_dummy_password_verify_for_unknown_email(): void
    {
        $this->adminUsersRepository->method('findByEmail')->with('nobody@example.com')->willReturn(null);
        $this->adminUsersRepository->expects($this->never())->method('updateById');

        $start = microtime(true);
        $result = $this->authService->authenticate('nobody@example.com', 'some-password');
        $duration = microtime(true) - $start;

        $this->assertNull($result);
        // password_verify on dummy hash takes non-zero time (bcrypt execution)
        $this->assertGreaterThan(0, $duration);
    }

    public function test_authenticate_returns_null_for_wrong_password(): void
    {
        $this->adminUsersRepository->method('findByEmail')->willReturn($this->admin());
        $this->adminUsersRepository->expects($this->never())->method('updateById');

        $result = $this->authService->authenticate('admin@example.com', 'wrong-password');

        $this->assertNull($result);
    }

    public function test_authenticate_returns_null_for_inactive_account_even_with_correct_password(): void
    {
        $this->adminUsersRepository->method('findByEmail')->willReturn($this->admin(['is_active' => 0]));
        $this->adminUsersRepository->expects($this->never())->method('updateById');

        $result = $this->authService->authenticate('admin@example.com', 'correct-password');

        $this->assertNull($result);
    }

    public function test_authenticate_returns_admin_and_touches_last_login_at_on_success(): void
    {
        $admin = $this->admin();
        $this->adminUsersRepository->method('findByEmail')->willReturn($admin);
        $this->adminUsersRepository->expects($this->once())
            ->method('updateById')
            ->with('admin-1', $this->callback(fn(array $data) => isset($data['last_login_at'])));

        $result = $this->authService->authenticate('admin@example.com', 'correct-password');

        $this->assertSame($admin, $result);
    }

    public function test_getActiveAdmin_returns_null_when_admin_not_found(): void
    {
        $this->adminUsersRepository->method('findById')->with('missing')->willReturn(null);

        $this->assertNull($this->authService->getActiveAdmin('missing'));
    }

    public function test_getActiveAdmin_returns_null_when_admin_inactive(): void
    {
        $this->adminUsersRepository->method('findById')->willReturn($this->admin(['is_active' => 0]));

        $this->assertNull($this->authService->getActiveAdmin('admin-1'));
    }

    public function test_getActiveAdmin_returns_admin_when_active(): void
    {
        $admin = $this->admin();
        $this->adminUsersRepository->method('findById')->willReturn($admin);

        $this->assertSame($admin, $this->authService->getActiveAdmin('admin-1'));
    }
}
