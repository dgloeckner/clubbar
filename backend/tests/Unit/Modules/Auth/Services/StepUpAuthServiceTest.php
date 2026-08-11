<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Auth\Services;

use App\Modules\AdminUsers\Services\AdminUsersService;
use App\Modules\Auth\Repositories\LoginAttemptsRepository;
use App\Modules\Auth\Services\StepUpAuthService;
use App\Modules\Auth\Services\TotpService;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * Step-up re-authentication for admin-on-admin 2FA reset and password reset
 * (#337). Before this, an active session alone was enough to strip 2FA off
 * any admin account or reset any admin's password — this service is the
 * gate that now sits in front of both, verifying the *caller's* own
 * credentials rather than the target's.
 */
class StepUpAuthServiceTest extends TestCase
{
    private AdminUsersService $adminUsersService;
    private TotpService $totpService;
    private AuditService $auditService;
    private LoginAttemptsRepository $loginAttempts;
    private StepUpAuthService $service;

    protected function setUp(): void
    {
        $this->adminUsersService = $this->createMock(AdminUsersService::class);
        $this->totpService = $this->createMock(TotpService::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->loginAttempts = $this->createMock(LoginAttemptsRepository::class);

        $this->service = new StepUpAuthService(
            $this->adminUsersService,
            $this->totpService,
            $this->auditService,
            $this->loginAttempts,
        );
    }

    private function caller(array $overrides = []): array
    {
        return array_merge([
            'id' => 'admin-1',
            'email' => 'admin@example.com',
            'totp_enabled' => 0,
            'totp_secret' => null,
        ], $overrides);
    }

    private function request(string $ip = '203.0.113.7'): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('POST', '/api/auth/2fa/reset', ['REMOTE_ADDR' => $ip]);
    }

    // ─── No TOTP enrolled — password only ──────────────────────────────────

    public function test_correct_password_passes_when_caller_has_no_totp(): void
    {
        $this->adminUsersService->method('verifyCurrentPassword')
            ->with('admin-1', 'correct')
            ->willReturn(true);

        $this->loginAttempts->expects($this->never())->method('record');
        $this->auditService->expects($this->never())->method('log');

        $result = $this->service->verify($this->caller(), ['current_password' => 'correct'], $this->request());

        $this->assertTrue($result);
    }

    public function test_wrong_password_fails_and_is_audited(): void
    {
        $this->adminUsersService->method('verifyCurrentPassword')->willReturn(false);

        $this->loginAttempts->expects($this->once())
            ->method('record')
            ->with('203.0.113.7', 'admin@example.com');

        $this->auditService->expects($this->once())
            ->method('log')
            ->with(AuditAction::LOGIN_FAILED, EntityType::ADMIN_USER, 'admin-1', null, null, 'admin-1');

        $result = $this->service->verify($this->caller(), ['current_password' => 'wrong'], $this->request());

        $this->assertFalse($result);
    }

    public function test_missing_password_field_fails_without_calling_the_verifier_with_null(): void
    {
        $this->adminUsersService->expects($this->once())
            ->method('verifyCurrentPassword')
            ->with('admin-1', '')
            ->willReturn(false);

        $result = $this->service->verify($this->caller(), [], $this->request());

        $this->assertFalse($result);
    }

    // ─── Caller has TOTP enrolled — password AND a fresh code required ────

    public function test_correct_password_and_code_passes_when_caller_has_totp(): void
    {
        $this->adminUsersService->method('verifyCurrentPassword')->willReturn(true);
        $this->totpService->method('decrypt')->with('encrypted-secret')->willReturn('plain-secret');
        $this->totpService->method('verifyCode')->with('plain-secret', '123456')->willReturn(true);

        $result = $this->service->verify(
            $this->caller(['totp_enabled' => 1, 'totp_secret' => 'encrypted-secret']),
            ['current_password' => 'correct', 'totp_code' => '123456'],
            $this->request(),
        );

        $this->assertTrue($result);
    }

    public function test_correct_password_but_wrong_code_fails_when_caller_has_totp(): void
    {
        $this->adminUsersService->method('verifyCurrentPassword')->willReturn(true);
        $this->totpService->method('decrypt')->willReturn('plain-secret');
        $this->totpService->method('verifyCode')->willReturn(false);

        $this->loginAttempts->expects($this->once())->method('record');
        $this->auditService->expects($this->once())->method('log');

        $result = $this->service->verify(
            $this->caller(['totp_enabled' => 1, 'totp_secret' => 'encrypted-secret']),
            ['current_password' => 'correct', 'totp_code' => '000000'],
            $this->request(),
        );

        $this->assertFalse($result);
    }

    public function test_correct_password_but_missing_code_fails_when_caller_has_totp(): void
    {
        $this->adminUsersService->method('verifyCurrentPassword')->willReturn(true);
        $this->totpService->expects($this->never())->method('verifyCode');

        $result = $this->service->verify(
            $this->caller(['totp_enabled' => 1, 'totp_secret' => 'encrypted-secret']),
            ['current_password' => 'correct'],
            $this->request(),
        );

        $this->assertFalse($result);
    }

    public function test_totp_is_never_checked_when_the_password_is_already_wrong(): void
    {
        $this->adminUsersService->method('verifyCurrentPassword')->willReturn(false);
        $this->totpService->expects($this->never())->method('verifyCode');

        $result = $this->service->verify(
            $this->caller(['totp_enabled' => 1, 'totp_secret' => 'encrypted-secret']),
            ['current_password' => 'wrong', 'totp_code' => '123456'],
            $this->request(),
        );

        $this->assertFalse($result);
    }

    public function test_failure_is_recorded_against_the_callers_own_ip_and_email_not_the_targets(): void
    {
        $this->adminUsersService->method('verifyCurrentPassword')->willReturn(false);

        $this->loginAttempts->expects($this->once())
            ->method('record')
            ->with('198.51.100.9', 'admin@example.com');

        $this->service->verify($this->caller(), ['current_password' => 'wrong'], $this->request('198.51.100.9'));
    }
}
