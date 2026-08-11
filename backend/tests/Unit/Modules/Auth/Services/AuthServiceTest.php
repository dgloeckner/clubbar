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

    public function test_authenticate_returns_null_for_unknown_email(): void
    {
        $this->adminUsersRepository->method('findByEmail')->with('nobody@example.com')->willReturn(null);
        $this->adminUsersRepository->expects($this->never())->method('updateById');

        $result = $this->authService->authenticate('nobody@example.com', 'irrelevant');

        $this->assertNull($result);
    }

    public function test_authenticate_verifies_a_dummy_hash_for_unknown_email(): void
    {
        $this->adminUsersRepository->method('findByEmail')->willReturn(null);

        $authService = $this->getMockBuilder(AuthService::class)
            ->setConstructorArgs([$this->adminUsersRepository, $this->logger])
            ->onlyMethods(['verifyPassword'])
            ->getMock();
        $authService->expects($this->once())
            ->method('verifyPassword')
            ->with('irrelevant', $this->matchesRegularExpression('/^\$2y\$12\$/'))
            ->willReturn(false);

        $this->assertNull($authService->authenticate('nobody@example.com', 'irrelevant'));
    }

    public function test_authenticate_dummy_hash_never_matches_a_real_password(): void
    {
        $this->adminUsersRepository->method('findByEmail')->willReturn(null);
        $this->adminUsersRepository->expects($this->never())->method('updateById');

        $this->assertNull($this->authService->authenticate('nobody@example.com', 'correct-password'));
        $this->assertNull($this->authService->authenticate('nobody@example.com', ''));
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
