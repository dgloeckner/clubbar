<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\AuditLog\Controllers;

use App\Modules\AuditLog\Controllers\AdminController;
use App\Modules\AuditLog\Repositories\AuditLogRepository;
use App\Shared\Exceptions\InvalidQueryParameterException;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;
use Tests\Unit\Shared\Http\ListEndpointAssertions;

/**
 * The audit-log list endpoint (#119).
 *
 * Its envelope was already the canonical one; what it lacked was a cap, so a
 * single request could ask for the whole audit trail. It also accepts filters
 * both nested under `filters[…]` and at the top level, and both spellings must
 * keep working.
 */
class AdminControllerTest extends TestCase
{
    use ListEndpointAssertions;

    private AuditLogRepository $repository;
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(AuditLogRepository::class);
        $this->controller = new AdminController($this->repository);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function row(array $overrides = []): array
    {
        return $overrides + [
            'id' => 17,
            'admin_user_id' => 'admin-1',
            'action' => 'create',
            'entity_type' => 'member',
            'entity_id' => 'm-1',
            'old_values' => null,
            'new_values' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'created_at' => '2026-01-01 10:00:00',
        ];
    }

    public function test_index_answers_with_the_canonical_envelope(): void
    {
        $this->repository->method('listWithFilters')
            ->willReturn(['items' => [$this->row()], 'total' => 1]);

        $body = $this->decode($this->controller->index($this->get('/api/admin/audit-log'), new Response()));

        $this->assertCount(1, $body['data']);
        $this->assertSame(17, $body['data'][0]['id']);
        $this->assertSame(['page' => 1, 'per_page' => 50, 'total' => 1, 'total_pages' => 1], $body['pagination']);
    }

    public function test_index_rounds_the_page_count_up(): void
    {
        $this->repository->method('listWithFilters')->willReturn(['items' => [], 'total' => 31]);

        $body = $this->decode($this->controller->index($this->get('/api/admin/audit-log', ['per_page' => '10']), new Response()));

        $this->assertSame(4, $body['pagination']['total_pages']);
    }

    public function test_index_accepts_top_level_filters(): void
    {
        $this->repository->expects($this->once())
            ->method('listWithFilters')
            ->with(50, 0, [
                'date_from' => '2026-01-01',
                'date_to' => '2026-01-31',
                'action' => 'create',
                'entity_type' => 'member',
                'admin_user_id' => 'admin-9',
                'search' => 'meier',
                'entity_id' => 'm-1',
            ])
            ->willReturn(['items' => [], 'total' => 0]);

        $this->controller->index($this->get('/api/admin/audit-log', [
            'date_from' => '2026-01-01',
            'date_to' => '2026-01-31',
            'action' => 'create',
            'entity_type' => 'member',
            'admin_user_id' => 'admin-9',
            'search' => 'meier',
            'entity_id' => 'm-1',
        ]), new Response());
    }

    public function test_index_accepts_the_nested_filters_dialect(): void
    {
        $this->repository->expects($this->once())
            ->method('listWithFilters')
            ->with(50, 0, ['action' => 'delete', 'entity_type' => 'settlement'])
            ->willReturn(['items' => [], 'total' => 0]);

        $this->controller->index($this->get('/api/admin/audit-log', [
            'filters' => ['action' => 'delete', 'entity_type' => 'settlement'],
        ]), new Response());
    }

    public function test_index_honours_an_explicit_offset(): void
    {
        $this->repository->expects($this->once())
            ->method('listWithFilters')
            ->with(25, 75, [])
            ->willReturn(['items' => [], 'total' => 0]);

        $body = $this->decode($this->controller->index(
            $this->get('/api/admin/audit-log', ['per_page' => '25', 'offset' => '75']),
            new Response(),
        ));

        $this->assertSame(4, $body['pagination']['page']);
    }

    public function test_index_defaults_to_newest_first(): void
    {
        $this->repository->expects($this->once())
            ->method('listWithFilters')
            ->with(50, 0, [], 'created_at', 'desc')
            ->willReturn(['items' => [], 'total' => 0]);

        $this->controller->index($this->get('/api/admin/audit-log'), new Response());
    }

    /**
     * The bug this endpoint had (#125): the audit screen's sort control was
     * decorative, because no sort parameter reached the query at all.
     */
    public function test_index_forwards_the_combined_sort_by_dialect(): void
    {
        $this->repository->expects($this->once())
            ->method('listWithFilters')
            ->with(50, 0, [], 'created_at', 'asc')
            ->willReturn(['items' => [], 'total' => 0]);

        $this->controller->index($this->get('/api/admin/audit-log', ['sort_by' => 'created_at_asc']), new Response());
    }

    public function test_index_forwards_sort_and_order(): void
    {
        $this->repository->expects($this->once())
            ->method('listWithFilters')
            ->with(50, 0, [], 'created_at', 'asc')
            ->willReturn(['items' => [], 'total' => 0]);

        $this->controller->index($this->get('/api/admin/audit-log', ['sort' => 'created_at', 'order' => 'asc']), new Response());
    }

    public function test_index_refuses_a_per_page_over_the_cap(): void
    {
        $this->repository->expects($this->never())->method('listWithFilters');

        $this->expectException(InvalidQueryParameterException::class);

        $this->controller->index($this->get('/api/admin/audit-log', ['per_page' => '1000']), new Response());
    }
}
