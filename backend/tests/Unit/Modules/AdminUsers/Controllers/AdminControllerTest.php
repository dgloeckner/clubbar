<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\AdminUsers\Controllers;

use App\Modules\AdminUsers\Controllers\AdminController;
use App\Modules\AdminUsers\Repositories\AdminUsersRepository;
use App\Modules\AdminUsers\Services\AdminUsersService;
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
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(AdminUsersService::class);

        $this->controller = new AdminController(
            $this->service,
            $this->createMock(AdminUsersRepository::class),
            new Validator($this->createMock(\PDO::class)),
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
}
