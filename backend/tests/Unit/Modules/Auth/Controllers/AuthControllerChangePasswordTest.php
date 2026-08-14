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
    private AuthController $controller;

    protected function setUp(): void
    {
        $this->adminUsersService = $this->createMock(AdminUsersService::class);

        $this->controller = new AuthController(
            $this->createMock(AuthService::class),
            $this->adminUsersService,
            $this->createMock(AdminUsersRepository::class),
            $this->createMock(TotpService::class),
            $this->createMock(AuditService::class),
            new Validator($this->createMock(PDO::class)),
            $this->createMock(LoginAttemptsRepository::class),
            new AppConfig(),
            $this->createMock(StepUpAuthService::class),
        );
    }

    /** @param array<string, mixed> $body */
    private function patch(array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('PATCH', '/api/auth/change-password')
            ->withAttribute('admin_user_id', 'admin-1')
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
}
