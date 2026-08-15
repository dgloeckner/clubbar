<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Auth\Controllers;

use App\Modules\AdminUsers\DTOs\AdminUserDto;
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
 * PATCH /api/auth/profile — changing your own address (issue #117).
 *
 * The endpoint validated the format of `email` and nothing else, so an address
 * already registered to another admin sailed past into the `admin_users.email`
 * UNIQUE constraint and came back as a 500. The admin-users endpoint next door
 * had always checked; this asks the same question through the same service
 * method.
 */
class AuthControllerProfileTest extends TestCase
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
    /**
     * Both attributes, because `AdminSessionAuth` attaches both — and the
     * `admin_user` row is what decides whether the email is actually moving,
     * and therefore whether a step-up is demanded. A request carrying only the
     * id would make every address look like a change away from the empty
     * string.
     */
    private function patch(array $body, string $currentEmail = 'mine@example.org'): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('PATCH', '/api/auth/profile')
            ->withParsedBody($body)
            ->withAttribute('admin_user_id', 'admin-1')
            ->withAttribute('admin_user', ['id' => 'admin-1', 'email' => $currentEmail, 'totp_enabled' => 0]);
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return json_decode((string) $response->getBody(), true);
    }

    public function test_a_duplicate_address_is_a_422_naming_the_field(): void
    {
        $this->adminUsersService->method('emailTakenByAnother')
            ->with('taken@example.org', 'admin-1')
            ->willReturn(true);

        $this->adminUsersService->expects($this->never())->method('updateAdminUser');

        $response = $this->controller->updateProfile($this->patch(['email' => 'taken@example.org']), new Response());

        $this->assertSame(422, $response->getStatusCode());

        $body = $this->decode($response);
        $this->assertSame('validation_failed', $body['error']);
        $this->assertSame(['Email already exists'], $body['messages']['email']);
    }

    public function test_keeping_an_address_nobody_else_holds_still_saves(): void
    {
        $this->adminUsersService->method('emailTakenByAnother')->willReturn(false);
        $this->adminUsersService->expects($this->once())
            ->method('updateAdminUser')
            ->willReturn($this->admin());

        $response = $this->controller->updateProfile($this->patch(['email' => 'mine@example.org']), new Response());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_a_body_without_an_email_never_asks(): void
    {
        $this->adminUsersService->expects($this->never())->method('emailTakenByAnother');
        $this->adminUsersService->method('updateAdminUser')->willReturn($this->admin());

        $response = $this->controller->updateProfile($this->patch(['display_name' => 'Renamed']), new Response());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_an_address_past_the_column_width_is_rejected_before_the_lookup(): void
    {
        $this->adminUsersService->expects($this->never())->method('emailTakenByAnother');

        $response = $this->controller->updateProfile(
            $this->patch(['email' => str_repeat('a', 250) . '@example.org']),
            new Response(),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('email', $this->decode($response)['messages']);
    }

    private function admin(): AdminUserDto
    {
        return AdminUserDto::fromRow([
            'id' => 'admin-1',
            'email' => 'mine@example.org',
            'display_name' => 'Me',
            'locale' => 'de',
            'is_active' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }
}
