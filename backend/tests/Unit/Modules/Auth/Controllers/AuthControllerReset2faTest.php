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
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * POST /api/auth/2fa/reset now demands a step-up credential from the caller
 * before it will touch a target account's TOTP (#337). A session used to be
 * enough on its own to strip 2FA off any admin, including the most
 * privileged one — these tests pin the reject-without-side-effects and
 * accept-with-audit paths around the new gate.
 */
class AuthControllerReset2faTest extends TestCase
{
    private AdminUsersRepository $adminUsersRepository;
    private AuditService $auditService;
    private StepUpAuthService $stepUpAuthService;
    private AuthController $controller;

    protected function setUp(): void
    {
        $this->adminUsersRepository = $this->createMock(AdminUsersRepository::class);
        $this->auditService = $this->createMock(AuditService::class);
        $this->stepUpAuthService = $this->createMock(StepUpAuthService::class);

        $this->controller = new AuthController(
            $this->createMock(AuthService::class),
            $this->createMock(AdminUsersService::class),
            $this->adminUsersRepository,
            $this->createMock(TotpService::class),
            $this->auditService,
            new Validator($this->createMock(PDO::class)),
            $this->createMock(LoginAttemptsRepository::class),
            new AppConfig(),
            $this->stepUpAuthService,
        );
    }

    private function post(array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/auth/2fa/reset', ['REMOTE_ADDR' => '203.0.113.7'])
            ->withParsedBody($body)
            ->withAttribute('admin_user_id', 'caller-1')
            ->withAttribute('admin_user', ['id' => 'caller-1', 'email' => 'caller@example.com', 'totp_enabled' => 0]);
    }

    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return json_decode((string) $response->getBody(), true);
    }

    public function test_rejects_a_missing_current_password_before_touching_the_target(): void
    {
        $this->adminUsersRepository->expects($this->never())->method('findById');
        $this->adminUsersRepository->expects($this->never())->method('clearTotp');

        $response = $this->controller->reset2fa($this->post(['userId' => 'target-1']), new Response());

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_rejects_a_failed_step_up_with_no_state_change(): void
    {
        $this->stepUpAuthService->method('verify')->willReturn(false);
        $this->adminUsersRepository->expects($this->never())->method('findById');
        $this->adminUsersRepository->expects($this->never())->method('clearTotp');
        $this->auditService->expects($this->never())->method('log');

        $response = $this->controller->reset2fa(
            $this->post(['userId' => 'target-1', 'current_password' => 'wrong']),
            new Response(),
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('invalid_credentials', $this->decode($response)['error']);
    }

    public function test_a_correct_step_up_verifies_against_the_caller_not_the_target(): void
    {
        $this->stepUpAuthService->expects($this->once())
            ->method('verify')
            ->with(
                $this->callback(fn(array $caller) => $caller['id'] === 'caller-1'),
                $this->callback(fn(array $body) => $body['current_password'] === 'correct'),
                $this->anything(),
            )
            ->willReturn(true);

        $this->adminUsersRepository->method('findById')->with('target-1')->willReturn(['id' => 'target-1']);

        $response = $this->controller->reset2fa(
            $this->post(['userId' => 'target-1', 'current_password' => 'correct']),
            new Response(),
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_success_still_clears_the_targets_totp_and_writes_the_existing_audit_entry(): void
    {
        $this->stepUpAuthService->method('verify')->willReturn(true);
        $this->adminUsersRepository->method('findById')->with('target-1')->willReturn(['id' => 'target-1']);

        $this->adminUsersRepository->expects($this->once())->method('clearTotp')->with('target-1');
        $this->auditService->expects($this->once())
            ->method('log')
            ->with(AuditAction::TOTP_RESET, EntityType::ADMIN_USER, 'target-1', null, null, 'caller-1');

        $this->controller->reset2fa(
            $this->post(['userId' => 'target-1', 'current_password' => 'correct']),
            new Response(),
        );
    }

    public function test_a_missing_target_is_404_even_after_a_correct_step_up(): void
    {
        $this->stepUpAuthService->method('verify')->willReturn(true);
        $this->adminUsersRepository->method('findById')->willReturn(null);
        $this->adminUsersRepository->expects($this->never())->method('clearTotp');

        $response = $this->controller->reset2fa(
            $this->post(['userId' => 'missing', 'current_password' => 'correct']),
            new Response(),
        );

        $this->assertSame(404, $response->getStatusCode());
    }
}
