<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Auth\Controllers;

use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\AdminUsers\Services\AdminUsersService;
use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Auth\Repositories\LoginAttemptsRepository;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\StepUpAuthService;
use App\Modules\Auth\Services\TotpService;
use App\Shared\Config\AppConfig;
use App\Shared\Enums\AuditAction;
use App\Shared\Enums\EntityType;
use App\Shared\Services\AuditService;
use App\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * Bounding TOTP guessing (#78, ruling #145).
 *
 * Before this, `/api/auth/mfa` took unlimited 6-digit guesses inside a 300s
 * window, and a correct *password* wiped the attempt counter for the whole
 * source IP — so an attacker holding one password could re-mint windows for as
 * long as they liked. These tests pin the three moving parts: failures are
 * persisted, the pending session dies at the cap, and forgiveness happens only
 * after a full authentication and only for the account that completed it.
 */
class AuthControllerMfaTest extends TestCase
{
    private AuthService $authService;
    private AdminUsersRepository $adminUsersRepository;
    private TotpService $totpService;
    private AuditService $auditService;
    private LoginAttemptsRepository $loginAttempts;
    private AuthController $controller;

    protected function setUp(): void
    {
        $this->authService = $this->createMock(AuthService::class);
        $this->adminUsersRepository = $this->createMock(AdminUsersRepository::class);
        $this->totpService = $this->createMock(TotpService::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->loginAttempts = $this->createMock(LoginAttemptsRepository::class);

        $this->controller = new AuthController(
            $this->authService,
            $this->createMock(AdminUsersService::class),
            $this->adminUsersRepository,
            $this->totpService,
            $this->auditService,
            new Validator($this->createMock(PDO::class)),
            $this->loginAttempts,
            new AppConfig(),
            $this->createMock(StepUpAuthService::class),
        );

        // The controller only starts a session when none is active; start one up
        // front so it never runs mid-test and clobbers what a test just set.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    private function post(string $path, array $body, string $ip = '203.0.113.7'): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path, ['REMOTE_ADDR' => $ip])
            ->withParsedBody($body);
    }

    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return json_decode((string) $response->getBody(), true);
    }

    private function admin(array $overrides = []): array
    {
        return array_merge([
            'id' => 'admin-1',
            'email' => 'admin@example.com',
            'display_name' => 'Admin',
            'locale' => 'de',
            'is_active' => 1,
            'totp_enabled' => 1,
            'totp_secret' => 'encrypted-secret',
        ], $overrides);
    }

    private function pendingSession(int $failures = 0): void
    {
        $_SESSION['mfa_pending_user_id'] = 'admin-1';
        $_SESSION['mfa_pending_email'] = 'admin@example.com';
        $_SESSION['mfa_pending_expires'] = time() + 300;
        $_SESSION['mfa_failed_attempts'] = $failures;
    }

    /**
     * @param bool $codeValid what TotpService reports for the submitted code
     * @param ?int $totpLastTimestep the admin's previously-recorded time-step (#338)
     */
    private function expectTotpVerification(bool $codeValid, ?int $totpLastTimestep = null): void
    {
        $this->adminUsersRepository->method('findById')->willReturn($this->admin(['totp_last_timestep' => $totpLastTimestep]));
        $this->totpService->method('decrypt')->willReturn('plain-secret');
        $this->totpService->method('verifyCodeWithTimestep')->willReturn($codeValid ? 1000 : null);
    }

    // ─── Password step ────────────────────────────────────────────────────────

    public function test_login_rejects_a_missing_password(): void
    {
        $this->authService->expects($this->never())->method('authenticate');

        $response = $this->controller->login(
            $this->post('/api/auth/login', ['email' => 'admin@example.com']),
            new Response(),
        );

        $this->assertSame(422, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('validation_failed', $body['error']);
        $this->assertArrayHasKey('password', $body['messages']);
    }

    public function test_failed_password_is_recorded_against_ip_and_account(): void
    {
        $this->authService->method('authenticate')->willReturn(null);

        $this->loginAttempts->expects($this->once())
            ->method('record')
            ->with('203.0.113.7', 'admin@example.com');
        $this->loginAttempts->expects($this->never())->method('clearForEmail');

        $response = $this->controller->login(
            $this->post('/api/auth/login', ['email' => 'admin@example.com', 'password' => 'wrong']),
            new Response(),
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('invalid_credentials', $this->decode($response)['error']);
    }

    public function test_correct_password_alone_does_not_clear_the_counter(): void
    {
        $this->authService->method('authenticate')->willReturn($this->admin());

        // The re-mint loop lived here: clearing on password success handed an
        // attacker a fresh five-minute MFA window whenever they wanted one.
        $this->loginAttempts->expects($this->never())->method('clearForEmail');

        $response = $this->controller->login(
            $this->post('/api/auth/login', ['email' => 'admin@example.com', 'password' => 'correct']),
            new Response(),
        );

        $this->assertTrue($this->decode($response)['requiresMfa']);
        $this->assertSame('admin@example.com', $_SESSION['mfa_pending_email']);
        $this->assertSame(0, $_SESSION['mfa_failed_attempts']);
    }

    public function test_password_success_without_totp_enrolled_is_a_full_authentication(): void
    {
        $this->authService->method('authenticate')->willReturn($this->admin(['totp_enabled' => 0]));

        // No second factor exists for this account yet, so the password *is* the
        // whole authentication and the account's attempts are forgiven.
        $this->loginAttempts->expects($this->once())
            ->method('clearForEmail')
            ->with('admin@example.com');

        $response = $this->controller->login(
            $this->post('/api/auth/login', ['email' => 'admin@example.com', 'password' => 'correct']),
            new Response(),
        );

        $this->assertTrue($this->decode($response)['requiresTotpSetup']);
    }

    // ─── MFA step ─────────────────────────────────────────────────────────────

    public function test_mfa_rejects_a_code_that_is_not_six_digits(): void
    {
        $this->pendingSession();
        $this->totpService->expects($this->never())->method('verifyCodeWithTimestep');

        $response = $this->controller->mfa($this->post('/api/auth/mfa', ['code' => '12345']), new Response());

        $this->assertSame(422, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('validation_failed', $body['error']);
        $this->assertArrayHasKey('code', $body['messages']);
    }

    public function test_wrong_code_is_persisted_so_it_outlives_the_session(): void
    {
        $this->pendingSession();
        $this->expectTotpVerification(false);

        $this->loginAttempts->expects($this->once())
            ->method('record')
            ->with('203.0.113.7', 'admin@example.com');

        $response = $this->controller->mfa($this->post('/api/auth/mfa', ['code' => '000000']), new Response());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('invalid_credentials', $this->decode($response)['error']);
    }

    public function test_wrong_code_is_audited(): void
    {
        $this->pendingSession();
        $this->expectTotpVerification(false);

        $this->auditService->expects($this->once())
            ->method('log')
            ->with(AuditAction::LOGIN_FAILED, EntityType::ADMIN_USER, 'admin-1');

        $this->controller->mfa($this->post('/api/auth/mfa', ['code' => '000000']), new Response());
    }

    public function test_pending_session_survives_below_the_cap(): void
    {
        $this->pendingSession(failures: 3);
        $this->expectTotpVerification(false);

        $this->controller->mfa($this->post('/api/auth/mfa', ['code' => '000000']), new Response());

        $this->assertSame(4, $_SESSION['mfa_failed_attempts']);
        $this->assertSame('admin-1', $_SESSION['mfa_pending_user_id']);
    }

    public function test_fifth_wrong_code_destroys_the_pending_session(): void
    {
        $this->pendingSession(failures: AuthController::MFA_MAX_ATTEMPTS - 1);
        $this->expectTotpVerification(false);

        $response = $this->controller->mfa($this->post('/api/auth/mfa', ['code' => '000000']), new Response());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('mfa_attempts_exceeded', $this->decode($response)['error']);
        $this->assertArrayNotHasKey('mfa_pending_user_id', $_SESSION);
        $this->assertArrayNotHasKey('mfa_pending_email', $_SESSION);
        $this->assertArrayNotHasKey('mfa_pending_expires', $_SESSION);
    }

    public function test_a_destroyed_pending_session_rejects_even_a_valid_code(): void
    {
        $this->pendingSession(failures: AuthController::MFA_MAX_ATTEMPTS - 1);
        $this->adminUsersRepository->method('findById')->willReturn($this->admin());
        $this->totpService->method('decrypt')->willReturn('plain-secret');
        // First code wrong (trips the cap), then the attacker gets lucky.
        $this->totpService->method('verifyCodeWithTimestep')->willReturnOnConsecutiveCalls(null, 1000);

        $this->controller->mfa($this->post('/api/auth/mfa', ['code' => '000000']), new Response());
        $response = $this->controller->mfa($this->post('/api/auth/mfa', ['code' => '123456']), new Response());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('mfa_session_expired', $this->decode($response)['error']);
        $this->assertArrayNotHasKey('admin_user_id', $_SESSION);
    }

    public function test_expired_pending_session_is_reported_as_such(): void
    {
        $_SESSION['mfa_pending_user_id'] = 'admin-1';
        $_SESSION['mfa_pending_expires'] = time() - 1;

        $response = $this->controller->mfa($this->post('/api/auth/mfa', ['code' => '123456']), new Response());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('mfa_session_expired', $this->decode($response)['error']);
    }

    public function test_valid_code_completes_authentication_and_forgives_that_account(): void
    {
        $this->pendingSession(failures: 2);
        $this->expectTotpVerification(true);

        $this->loginAttempts->expects($this->once())
            ->method('clearForEmail')
            ->with('admin@example.com');
        $this->loginAttempts->expects($this->never())->method('record');

        $response = $this->controller->mfa($this->post('/api/auth/mfa', ['code' => '123456']), new Response());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('admin-1', $_SESSION['admin_user_id']);
        $this->assertArrayNotHasKey('mfa_pending_user_id', $_SESSION);
        $this->assertArrayNotHasKey('mfa_failed_attempts', $_SESSION);
    }

    public function test_the_cap_does_not_narrow_the_accepted_code_window(): void
    {
        // A code at the ±1 step boundary is TotpService's business; the cap must
        // not reject anything TotpService accepts, even after earlier failures.
        $this->pendingSession(failures: AuthController::MFA_MAX_ATTEMPTS - 1);
        $this->expectTotpVerification(true);

        $response = $this->controller->mfa($this->post('/api/auth/mfa', ['code' => '123456']), new Response());

        $this->assertSame(200, $response->getStatusCode());
    }

    // ─── Replay protection (#338) ────────────────────────────────────────────
    //
    // TotpService::verifyCode allows a ±1 time-step window (~90s), so a captured
    // code stays structurally valid for its whole window. These pin that the
    // controller tracks the last time-step it accepted per admin and refuses
    // any code at or below it — through the exact same rejectMfaCode() path as
    // a wrong code, so a replay counts against both the rate limiter and the
    // pending session's attempt cap — while a genuinely newer code still works.

    public function test_valid_code_records_the_accepted_time_step(): void
    {
        $this->pendingSession();
        $this->expectTotpVerification(true, totpLastTimestep: null);

        $this->adminUsersRepository->expects($this->once())
            ->method('updateTotpLastTimestep')
            ->with('admin-1', 1000);

        $response = $this->controller->mfa($this->post('/api/auth/mfa', ['code' => '123456']), new Response());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_a_time_step_newer_than_the_one_last_recorded_is_accepted(): void
    {
        $this->pendingSession();
        $this->expectTotpVerification(true, totpLastTimestep: 999);

        $this->adminUsersRepository->expects($this->once())
            ->method('updateTotpLastTimestep')
            ->with('admin-1', 1000);

        $response = $this->controller->mfa($this->post('/api/auth/mfa', ['code' => '123456']), new Response());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_a_replayed_code_at_the_same_time_step_as_last_recorded_is_rejected(): void
    {
        $this->pendingSession();
        $this->expectTotpVerification(true, totpLastTimestep: 1000);

        $this->adminUsersRepository->expects($this->never())->method('updateTotpLastTimestep');
        // Goes through rejectMfaCode(), exactly like a wrong code (#338).
        $this->loginAttempts->expects($this->once())
            ->method('record')
            ->with('203.0.113.7', 'admin@example.com');
        $this->auditService->expects($this->once())
            ->method('log')
            ->with(AuditAction::LOGIN_FAILED, EntityType::ADMIN_USER, 'admin-1');

        $response = $this->controller->mfa($this->post('/api/auth/mfa', ['code' => '123456']), new Response());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('invalid_credentials', $this->decode($response)['error']);
        // The MFA-pending session must survive — the user may retry with a newer code.
        $this->assertSame('admin-1', $_SESSION['mfa_pending_user_id'] ?? null);
    }

    public function test_a_code_older_than_the_one_last_recorded_is_rejected(): void
    {
        $this->pendingSession();
        // verifyCodeWithTimestep matched an earlier step (899) than the one
        // already recorded (1000) — clock skew replaying a stale code.
        $this->adminUsersRepository->method('findById')->willReturn($this->admin(['totp_last_timestep' => 1000]));
        $this->totpService->method('decrypt')->willReturn('plain-secret');
        $this->totpService->method('verifyCodeWithTimestep')->willReturn(899);

        $this->adminUsersRepository->expects($this->never())->method('updateTotpLastTimestep');

        $response = $this->controller->mfa($this->post('/api/auth/mfa', ['code' => '123456']), new Response());

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_a_replayed_code_counts_toward_the_pending_sessions_attempt_cap(): void
    {
        $this->pendingSession(failures: AuthController::MFA_MAX_ATTEMPTS - 1);
        $this->expectTotpVerification(true, totpLastTimestep: 1000);

        $response = $this->controller->mfa($this->post('/api/auth/mfa', ['code' => '123456']), new Response());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('mfa_attempts_exceeded', $this->decode($response)['error']);
        $this->assertArrayNotHasKey('mfa_pending_user_id', $_SESSION);
    }
}
