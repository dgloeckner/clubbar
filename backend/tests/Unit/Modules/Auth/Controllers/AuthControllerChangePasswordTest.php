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
use App\Shared\Services\AuditService;
use App\Shared\Validation\Validator;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * PATCH /api/auth/change-password — the caller changing their own password.
 */
class AuthControllerChangePasswordTest extends TestCase
{
    private AdminUsersService $adminUsersService;
    private StepUpAuthService $stepUpAuthService;
    private AuthController $controller;

    protected function setUp(): void
    {
        $this->adminUsersService = $this->createMock(AdminUsersService::class);
        $this->stepUpAuthService = $this->createMock(StepUpAuthService::class);

        $this->controller = new AuthController(
            $this->createMock(AuthService::class),
            $this->adminUsersService,
            $this->createMock(AdminUsersRepository::class),
            $this->createMock(TotpService::class),
            $this->createMock(AuditService::class),
            new Validator($this->createMock(PDO::class)),
            $this->createMock(LoginAttemptsRepository::class),
            new AppConfig(),
            $this->stepUpAuthService,
        );
    }

    /** @param array<string, mixed> $body */
    private function patch(array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('PATCH', '/api/auth/change-password')
            ->withAttribute('admin_user_id', 'admin-1')
            ->withAttribute('admin_user', ['id' => 'admin-1', 'email' => 'me@example.org', 'totp_enabled' => 1])
            ->withParsedBody($body);
    }

    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return json_decode((string) $response->getBody(), true);
    }

    public function test_rejects_a_new_password_that_does_not_meet_complexity_rules(): void
    {
        $this->adminUsersService->expects($this->never())->method('verifyCurrentPassword');

        $response = $this->controller->changePassword($this->patch([
            'current_password' => 'oldpassword1',
            'new_password' => 'alllowercase1',
            'new_password_confirmation' => 'alllowercase1',
        ]), new Response());

        $this->assertSame(422, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('validation_failed', $body['error']);
        $this->assertArrayHasKey('new_password', $body['messages']);
    }

    public function test_rejects_a_confirmation_that_does_not_match(): void
    {
        $this->adminUsersService->expects($this->never())->method('verifyCurrentPassword');

        $response = $this->controller->changePassword($this->patch([
            'current_password' => 'oldpassword1',
            'new_password' => 'NewPassword1',
            'new_password_confirmation' => 'Mismatch1',
        ]), new Response());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('new_password_confirmation', $this->decode($response)['messages']);
    }

    /* ─────────────── Step-up re-authentication (PR #469) ─────────────── */

    /**
     * The password is the credential a hijacked session would rotate first, and
     * re-proving it alone is exactly what such a session already implies. The
     * gate is therefore the same one the cross-account resets carry: the
     * caller's own password *and* their own fresh TOTP code.
     */
    public function test_a_rejected_step_up_changes_nothing(): void
    {
        $this->stepUpAuthService->method('verify')->willReturn(false);
        $this->adminUsersService->expects($this->never())->method('changeOwnPassword');

        $response = $this->controller->changePassword($this->patch([
            'current_password' => 'oldpassword1',
            'new_password' => 'NewPassword1',
            'new_password_confirmation' => 'NewPassword1',
            'totp_code' => '000000',
        ]), new Response());

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('invalid_credentials', $this->decode($response)['error']);
    }

    public function test_a_full_step_up_changes_the_password(): void
    {
        $this->stepUpAuthService->method('verify')->willReturn(true);
        $this->adminUsersService->expects($this->once())
            ->method('changeOwnPassword')
            ->with('admin-1', 'NewPassword1');

        $response = $this->controller->changePassword($this->patch([
            'current_password' => 'oldpassword1',
            'new_password' => 'NewPassword1',
            'new_password_confirmation' => 'NewPassword1',
            'totp_code' => '123456',
        ]), new Response());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Password changed', $this->decode($response)['message']);
    }

    /**
     * `AdminSessionAuth` always attaches the caller's row, so its absence is a
     * state the application should not be in — and must not be read as
     * permission. Without this the null reaches the verifier and 500s.
     */
    public function test_a_request_without_a_caller_row_is_refused_rather_than_crashing(): void
    {
        $this->stepUpAuthService->expects($this->never())->method('verify');
        $this->adminUsersService->expects($this->never())->method('changeOwnPassword');

        $request = (new ServerRequestFactory())
            ->createServerRequest('PATCH', '/api/auth/change-password')
            ->withAttribute('admin_user_id', 'admin-1')
            ->withParsedBody([
                'current_password' => 'oldpassword1',
                'new_password' => 'NewPassword1',
                'new_password_confirmation' => 'NewPassword1',
            ]);

        $response = $this->controller->changePassword($request, new Response());

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('PATCH', '/api/auth/change-password')
            ->withParsedBody([]);

        $response = $this->controller->changePassword($request, new Response());

        $this->assertSame(401, $response->getStatusCode());
    }
}
