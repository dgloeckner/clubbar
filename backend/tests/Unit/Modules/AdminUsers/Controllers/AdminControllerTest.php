<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\AdminUsers\Controllers;

use App\Modules\AdminUsers\Controllers\AdminController;
use App\Modules\AdminUsers\DTOs\AdminUserDto;
use App\Modules\AdminUsers\DTOs\AdminInvitationDto;
use App\Modules\AdminUsers\Services\AdminInvitationService;
use App\Modules\AdminUsers\Services\AdminUsersService;
use App\Modules\Auth\Services\StepUpAuthService;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Exceptions\InvalidQueryParameterException;
use App\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tests\Unit\Shared\Http\ListEndpointAssertions;

/**
 * The admin-users list endpoint (#119).
 *
 * It used to answer `{data, pagination:{total,page,per_page,has_more}}` — a
 * `pagination` block that shared three of its four keys with the canonical one
 * and disagreed on the fourth, which is exactly the kind of near-miss the
 * frontend cannot detect.
 */
class AdminControllerTest extends TestCase
{
    use ListEndpointAssertions;

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

    public function test_index_answers_with_the_canonical_envelope(): void
    {
        $this->service->method('listAdminUsers')
            ->willReturn(new PaginatedResultDto([['id' => 'a-1']], total: 1, limit: 20, offset: 0));

        $body = $this->decode($this->controller->index($this->get('/api/admin/admin-users'), new Response()));

        $this->assertSame([['id' => 'a-1']], $body['data']);
        $this->assertSame(['page' => 1, 'per_page' => 20, 'total' => 1, 'total_pages' => 1], $body['pagination']);
    }

    public function test_index_defaults_to_twenty_per_page(): void
    {
        $this->service->expects($this->once())
            ->method('listAdminUsers')
            ->with(20, 0, [])
            ->willReturn(new PaginatedResultDto([], total: 0, limit: 20, offset: 0));

        $this->controller->index($this->get('/api/admin/admin-users'), new Response());
    }

    public function test_index_translates_page_into_an_offset(): void
    {
        $this->service->expects($this->once())
            ->method('listAdminUsers')
            ->with(10, 20, [])
            ->willReturn(new PaginatedResultDto([], total: 0, limit: 10, offset: 20));

        $this->controller->index($this->get('/api/admin/admin-users', ['page' => '3', 'per_page' => '10']), new Response());
    }

    public function test_index_passes_the_status_filter_through(): void
    {
        $this->service->expects($this->once())
            ->method('listAdminUsers')
            ->with(20, 0, ['status' => 'active'])
            ->willReturn(new PaginatedResultDto([], total: 0, limit: 20, offset: 0));

        $this->controller->index($this->get('/api/admin/admin-users', ['status' => 'active']), new Response());
    }

    public function test_index_accepts_the_nested_is_active_filter(): void
    {
        $this->service->expects($this->once())
            ->method('listAdminUsers')
            ->with(20, 0, ['status' => 'inactive'])
            ->willReturn(new PaginatedResultDto([], total: 0, limit: 20, offset: 0));

        $request = $this->get('/api/admin/admin-users', ['filters' => ['is_active' => 'false']]);

        $this->controller->index($request, new Response());
    }

    public function test_index_refuses_a_per_page_over_the_cap(): void
    {
        $this->service->expects($this->never())->method('listAdminUsers');

        $this->expectException(InvalidQueryParameterException::class);

        $this->controller->index($this->get('/api/admin/admin-users', ['per_page' => '500']), new Response());
    }

    /**
     * Both write paths ask the same question of the same service method (#117),
     * so the answer cannot drift between them the way it had between this
     * endpoint and `PATCH /api/auth/profile`.
     */
    public function test_store_rejects_a_missing_email(): void
    {
        $this->service->expects($this->never())->method('createAdminUser');

        $response = $this->controller->store(
            $this->post(['display_name' => 'Someone', 'locale' => 'de']),
            new Response(),
        );

        $this->assertSame(422, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('validation_failed', $body['error']);
        $this->assertArrayHasKey('email', $body['messages']);
    }

    public function test_store_refuses_an_address_that_is_already_registered(): void
    {
        $this->stepUpAuthService->method('verify')->willReturn(true);
        $this->service->method('emailTakenByAnother')->with('taken@example.org')->willReturn(true);
        $this->service->expects($this->never())->method('createAdminUser');

        $response = $this->controller->store(
            $this->post([
                'email' => 'taken@example.org',
                'display_name' => 'Someone',
                'locale' => 'de',
                'current_password' => 'correct',
            ]),
            new Response(),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['Email already exists'], $this->decode($response)['messages']['email']);
    }

    /**
     * Creating an admin user mints a persistent, full-privilege peer account
     * (ADR-0015's flat admin model), so it now demands the same step-up
     * credential from the caller as reset-password and terminal enrolment
     * (#499).
     */
    public function test_store_rejects_a_missing_current_password(): void
    {
        $this->service->expects($this->never())->method('createAdminUser');

        $response = $this->controller->store(
            $this->post(['email' => 'new@example.org', 'display_name' => 'Someone', 'locale' => 'de']),
            new Response(),
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('current_password', $this->decode($response)['messages']);
    }

    public function test_store_rejects_a_failed_step_up_with_no_state_change(): void
    {
        $this->stepUpAuthService->method('verify')->willReturn(false);
        $this->service->expects($this->never())->method('createAdminUser');

        $response = $this->controller->store(
            $this->post([
                'email' => 'new@example.org',
                'display_name' => 'Someone',
                'locale' => 'de',
                'current_password' => 'wrong',
            ]),
            new Response(),
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('invalid_credentials', $this->decode($response)['error']);
    }

    /**
     * The response carries the invitation, never a password (migration 058).
     *
     * This test used to assert `password`, which was the whole problem: the
     * endpoint minted a live credential and handed it to the caller to pass on
     * by whatever means they had.
     */
    public function test_store_a_correct_step_up_verifies_against_the_caller_and_creates_the_admin(): void
    {
        $this->stepUpAuthService->expects($this->once())
            ->method('verify')
            ->with(
                $this->callback(fn(array $caller) => $caller['id'] === 'admin-1'),
                $this->callback(fn(array $body) => $body['current_password'] === 'correct'),
                $this->anything(),
            )
            ->willReturn(true);

        $this->service->method('emailTakenByAnother')->willReturn(false);
        $this->service->expects($this->once())
            ->method('createAdminUser')
            ->with('new@example.org', 'Someone', 'de', 'admin-1')
            ->willReturn([
                'admin' => $this->admin(),
                'invitation' => new AdminInvitationDto(
                    adminUserId: 'admin-9',
                    email: 'new@example.org',
                    expiresAt: '2026-09-06 12:00:00',
                    url: 'https://club.example.org/invite/token-abc',
                ),
            ]);

        $response = $this->controller->store(
            $this->post([
                'email' => 'new@example.org',
                'display_name' => 'Someone',
                'locale' => 'de',
                'current_password' => 'correct',
            ]),
            new Response(),
        );

        $this->assertSame(201, $response->getStatusCode());

        $body = $this->decode($response);
        $this->assertArrayNotHasKey('password', $body);
        $this->assertSame('https://club.example.org/invite/token-abc', $body['invitation']['url']);
        $this->assertSame('new@example.org', $body['invitation']['email']);
    }

    public function test_update_rejects_an_unsupported_locale(): void
    {
        $this->service->expects($this->never())->method('updateAdminUser');

        $response = $this->controller->update(
            $this->post(['locale' => 'xx']),
            new Response(),
            ['id' => 'a-1'],
        );

        $this->assertSame(422, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('validation_failed', $body['error']);
        $this->assertArrayHasKey('locale', $body['messages']);
    }

    public function test_update_refuses_an_address_another_admin_holds(): void
    {
        $this->service->method('emailTakenByAnother')->with('taken@example.org', 'a-1')->willReturn(true);
        $this->service->expects($this->never())->method('updateAdminUser');

        $response = $this->controller->update(
            $this->post(['email' => 'taken@example.org']),
            new Response(),
            ['id' => 'a-1'],
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(['Email already exists'], $this->decode($response)['messages']['email']);
    }

    public function test_update_lets_an_admin_keep_their_own_address(): void
    {
        $this->service->method('emailTakenByAnother')->willReturn(false);
        $this->service->expects($this->once())->method('updateAdminUser')->willReturn($this->admin());

        $response = $this->controller->update(
            $this->post(['email' => 'mine@example.org']),
            new Response(),
            ['id' => 'a-1'],
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_update_rejects_invalid_role_values(): void
    {
        $this->service->expects($this->never())->method('setRoles');

        $response = $this->controller->update(
            $this->post(['roles' => ['superuser']]),
            new Response(),
            ['id' => 'a-2'],
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('roles', $this->decode($response)['messages']);
    }

    /**
     * CONTEXT.md's Role entry: no account can change its own role set. The
     * request-scoped id (`admin_user_id`) is what "own account" means here,
     * matched against the path id — never trust the client to omit `roles`
     * on itself.
     */
    public function test_update_refuses_a_caller_changing_their_own_roles(): void
    {
        $this->service->expects($this->never())->method('setRoles');
        $this->stepUpAuthService->expects($this->never())->method('verify');

        $response = $this->controller->update(
            $this->post(['roles' => ['kassenwart']]),
            new Response(),
            ['id' => 'admin-1'], // matches the caller's own id set in post()
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('cannot_edit_own_roles', $this->decode($response)['error']);
    }

    public function test_update_lets_an_admin_change_someone_elses_roles(): void
    {
        $this->stepUpAuthService->method('verify')->willReturn(true);
        $this->service->method('emailTakenByAnother')->willReturn(false);
        $this->service->method('rolesWouldChange')->willReturn(true);
        $this->service->method('updateAdminUser')->willReturn($this->admin());
        $this->service->method('findAdminUserById')->willReturn($this->admin());
        $this->service->expects($this->once())
            ->method('setRoles')
            ->with('a-2', $this->anything(), 'admin-1');

        $response = $this->controller->update(
            $this->post(['roles' => ['kassenwart'], 'current_password' => 'correct']),
            new Response(),
            ['id' => 'a-2'],
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/admin-users')
            ->withParsedBody($body)
            ->withAttribute('admin_user_id', 'admin-1')
            ->withAttribute('admin_user', ['id' => 'admin-1', 'email' => 'admin@example.org', 'totp_enabled' => 0]);
    }

    private function admin(): AdminUserDto
    {
        return AdminUserDto::fromRow([
            'id' => 'a-1',
            'email' => 'mine@example.org',
            'display_name' => 'Someone',
            'locale' => 'de',
            'is_active' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }
}
