<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Auth\Controllers;

use App\Modules\AdminUsers\Enums\AdminRole;
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
use Slim\Psr7\Factory\ServerRequestFactory;

/**
 * GET /api/auth/profile tells the caller which roles they hold (ADR-0044, #514).
 *
 * The panel cannot decide what to render without it: the navigation is hidden
 * per role and the landing page differs per role (#516). Nothing is enforced
 * from this field — the server refuses independently — it is what stops the SPA
 * from opening on a page the caller cannot have.
 */
class AuthControllerProfileRolesTest extends TestCase
{
    private AuthService $authService;
    private AdminUsersService $adminUsersService;
    private AuthController $controller;

    protected function setUp(): void
    {
        $this->authService = $this->createMock(AuthService::class);
        $this->adminUsersService = $this->createMock(AdminUsersService::class);

        $this->controller = new AuthController(
            $this->authService,
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

    public function test_the_profile_carries_the_callers_roles(): void
    {
        $this->authService->method('getActiveAdmin')->willReturn($this->adminRow());
        $this->adminUsersService->method('getRoles')->with('admin-1')->willReturn([AdminRole::ADMIN]);

        $body = $this->decode($this->getProfile());

        $this->assertSame(['admin'], $body['admin']['roles']);
    }

    public function test_both_lesser_roles_are_reported_together(): void
    {
        $this->authService->method('getActiveAdmin')->willReturn($this->adminRow());
        $this->adminUsersService->method('getRoles')
            ->willReturn([AdminRole::KASSENWART, AdminRole::GETRAENKEWART]);

        $body = $this->decode($this->getProfile());

        $this->assertSame(['kassenwart', 'getraenkewart'], $body['admin']['roles']);
    }

    /**
     * An account that somehow holds nothing reports nothing — not a default,
     * and certainly not `admin`. Rule 1 of ADR-0044 is that this model fails
     * closed, and a client-side default of "assume full access" would render a
     * panel of pages the server then refuses one by one.
     */
    public function test_an_account_with_no_roles_reports_an_empty_list(): void
    {
        $this->authService->method('getActiveAdmin')->willReturn($this->adminRow());
        $this->adminUsersService->method('getRoles')->willReturn([]);

        $body = $this->decode($this->getProfile());

        $this->assertSame([], $body['admin']['roles']);
    }

    public function test_the_existing_profile_fields_are_untouched(): void
    {
        $this->authService->method('getActiveAdmin')->willReturn($this->adminRow());
        $this->adminUsersService->method('getRoles')->willReturn([AdminRole::ADMIN]);

        $admin = $this->decode($this->getProfile())['admin'];

        $this->assertSame('admin-1', $admin['id']);
        $this->assertSame('mine@example.org', $admin['email']);
        $this->assertSame('Mine', $admin['display_name']);
        $this->assertSame('de', $admin['locale']);
        $this->assertTrue($admin['totp_enabled']);
    }

    /** @return array<string, mixed> */
    private function adminRow(): array
    {
        return [
            'id' => 'admin-1',
            'email' => 'mine@example.org',
            'display_name' => 'Mine',
            'locale' => 'de',
            'last_login_at' => null,
            'totp_enabled' => 1,
        ];
    }

    private function getProfile(): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/api/auth/profile')
            ->withAttribute('admin_user_id', 'admin-1');

        return $this->controller->profile($request, new \Slim\Psr7\Response());
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return json_decode((string) $response->getBody(), true);
    }
}
