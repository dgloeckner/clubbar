<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Terminals\Controllers;

use App\Modules\Auth\Services\StepUpAuthService;
use App\Modules\Terminals\Controllers\AdminController;
use App\Modules\Terminals\Services\TerminalsService;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Exceptions\InvalidQueryParameterException;
use App\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;
use Tests\Unit\Shared\Http\ListEndpointAssertions;

/**
 * The terminals list endpoint (#119) — the seventh list shape, which spelled
 * the page `current_page` and the page count `last_page`.
 */
class AdminControllerTest extends TestCase
{
    use ListEndpointAssertions;

    private TerminalsService $service;
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->service = $this->createMock(TerminalsService::class);
        $this->controller = new AdminController(
            $this->service,
            new Validator($this->createMock(\PDO::class)),
            $this->createMock(StepUpAuthService::class),
        );
    }

    public function test_index_answers_with_the_canonical_envelope(): void
    {
        $this->service->method('listTerminals')
            ->willReturn(new PaginatedResultDto([['id' => 't-1']], total: 3, limit: 50, offset: 0));

        $body = $this->decode($this->controller->index($this->get('/api/admin/terminals'), new Response()));

        $this->assertSame([['id' => 't-1']], $body['data']);
        $this->assertSame(['page' => 1, 'per_page' => 50, 'total' => 3, 'total_pages' => 1], $body['pagination']);
    }

    public function test_index_reports_the_page_the_caller_asked_for(): void
    {
        $this->service->method('listTerminals')
            ->willReturn(new PaginatedResultDto([], total: 25, limit: 10, offset: 10));

        $body = $this->decode($this->controller->index($this->get('/api/admin/terminals', ['page' => '2', 'per_page' => '10']), new Response()));

        $this->assertSame(['page' => 2, 'per_page' => 10, 'total' => 25, 'total_pages' => 3], $body['pagination']);
    }

    public function test_index_converts_the_is_active_filter_to_a_boolean(): void
    {
        $this->service->expects($this->once())
            ->method('listTerminals')
            ->with(50, 0, false)
            ->willReturn(new PaginatedResultDto([], total: 0, limit: 50, offset: 0));

        $this->controller->index($this->get('/api/admin/terminals', ['is_active' => 'false']), new Response());
    }

    public function test_index_leaves_the_filter_unset_when_it_is_absent(): void
    {
        $this->service->expects($this->once())
            ->method('listTerminals')
            ->with(50, 0, null)
            ->willReturn(new PaginatedResultDto([], total: 0, limit: 50, offset: 0));

        $this->controller->index($this->get('/api/admin/terminals'), new Response());
    }

    public function test_index_refuses_a_per_page_over_the_cap(): void
    {
        $this->expectException(InvalidQueryParameterException::class);

        $this->controller->index($this->get('/api/admin/terminals', ['per_page' => '500']), new Response());
    }
}
