<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\AdminUsers\Controllers;

use App\Modules\AdminUsers\Controllers\AdminController;
use App\Modules\AdminUsers\DTOs\AdminUserDto;
use App\Modules\AdminUsers\Services\AdminInvitationService;
use App\Modules\AdminUsers\Services\AdminUsersService;
use App\Modules\Auth\Services\StepUpAuthService;
use App\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * POST /api/admin/admin-users/{id}/reset-password now demands a step-up
 * credential from the caller before it will mint a new password for a
 * target admin (#337) — mirrors the gate added to POST /api/auth/2fa/reset.
 */
class AdminControllerResetPasswordTest extends TestCase
{
    private AdminUsersService $service;
    private StepUpAuthService $stepUpAuthService;
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(AdminUsersService::class);
        $this->stepUpAuthService = $this->createMock(StepUpAuthService::class);

        $this->controller = new AdminController(
            $this->service,
            new Validator($this->createMock(\PDO::class)),
            $this->stepUpAuthService,
            $this->createMock(AdminInvitationService::class),
        );
    }

    private function post(array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/admin-users/target-1/reset-password', ['REMOTE_ADDR' => '203.0.113.7'])
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
        $this->service->expects($this->never())->method('resetAdminPassword');

        $response = $this->controller->resetPassword($this->post([]), new Response(), ['id' => 'target-1']);

        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_rejects_a_failed_step_up_with_no_state_change(): void
    {
        $this->stepUpAuthService->method('verify')->willReturn(false);
        $this->service->expects($this->never())->method('resetAdminPassword');

        $response = $this->controller->resetPassword(
            $this->post(['current_password' => 'wrong']),
            new Response(),
            ['id' => 'target-1'],
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

        $this->service->expects($this->once())
            ->method('resetAdminPassword')
            ->with('target-1', 'caller-1')
            ->willReturn(['admin' => $this->admin(), 'password' => 'new-generated-password']);

        $response = $this->controller->resetPassword(
            $this->post(['current_password' => 'correct']),
            new Response(),
            ['id' => 'target-1'],
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('new-generated-password', $this->decode($response)['password']);
    }

    private function admin(): AdminUserDto
    {
        return AdminUserDto::fromRow([
            'id' => 'target-1',
            'email' => 'target@example.org',
            'display_name' => 'Target',
            'locale' => 'de',
            'is_active' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }
}
