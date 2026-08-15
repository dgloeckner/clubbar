<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Members\Controllers;

use App\Modules\Members\Controllers\AdminController;
use App\Modules\Members\Services\MembersService;
use App\Modules\Settlements\Services\CollectionHoldService;
use App\Modules\Settlements\Services\SettlementsService;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Exceptions\InvalidQueryParameterException;
use App\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;
use Tests\Unit\Shared\Http\ListEndpointAssertions;

/**
 * The members list endpoint (#119).
 *
 * This one already had the canonical envelope and the only per_page cap in the
 * codebase — the cap now lives in ListQuery, and the filter vocabulary that
 * stayed behind is what these tests pin down. `sort_by=name_asc`, which the
 * admin frontend has always sent here, used to be dropped on the floor.
 */
class AdminControllerListTest extends TestCase
{
    use ListEndpointAssertions;

    private MembersService $membersService;
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->membersService = $this->createMock(MembersService::class);

        $this->controller = new AdminController(
            $this->membersService,
            new Validator($this->createMock(\PDO::class)),
            $this->createMock(SettlementsService::class),
            $this->createMock(CollectionHoldService::class),
        );
    }

    private function expectList(int $limit, int $offset, array $filters, string $sortKey, string $sortOrder, ?string $search): void
    {
        $this->membersService->expects($this->once())
            ->method('listMembers')
            ->with($limit, $offset, $filters, $sortKey, $sortOrder, $search)
            ->willReturn(new PaginatedResultDto([], total: 0, limit: $limit, offset: $offset));
    }

    public function test_index_answers_with_the_canonical_envelope(): void
    {
        $this->membersService->method('listMembers')
            ->willReturn(new PaginatedResultDto([['id' => 'm-1']], total: 120, limit: 50, offset: 0));

        $body = $this->decode($this->controller->index($this->get('/api/admin/members'), new Response()));

        $this->assertSame([['id' => 'm-1']], $body['data']);
        $this->assertSame(['page' => 1, 'per_page' => 50, 'total' => 120, 'total_pages' => 3], $body['pagination']);
    }

    public function test_index_defaults_to_created_at_descending(): void
    {
        $this->expectList(50, 0, [], 'created_at', 'desc', null);

        $this->controller->index($this->get('/api/admin/members'), new Response());
    }

    /**
     * The bug this endpoint had: the frontend's `sort_by=name_asc` never
     * reached the query, so the member list ignored the sort the user picked.
     */
    public function test_index_understands_the_combined_sort_by_dialect(): void
    {
        $this->expectList(50, 0, [], 'name', 'asc', null);

        $this->controller->index($this->get('/api/admin/members', ['sort_by' => 'name_asc']), new Response());
    }

    public function test_index_understands_sort_and_order(): void
    {
        $this->expectList(50, 0, [], 'last_name', 'asc', null);

        $this->controller->index($this->get('/api/admin/members', ['sort' => 'last_name', 'order' => 'asc']), new Response());
    }

    public function test_index_maps_the_status_parameter_to_the_is_active_filter(): void
    {
        $this->expectList(50, 0, ['is_active' => false], 'created_at', 'desc', null);

        $this->controller->index($this->get('/api/admin/members', ['status' => 'inactive']), new Response());
    }

    public function test_index_maps_has_card_uid_to_a_boolean_filter(): void
    {
        $this->expectList(50, 0, ['has_card_uid' => true], 'created_at', 'desc', null);

        $this->controller->index($this->get('/api/admin/members', ['has_card_uid' => 'with']), new Response());
    }

    /** Finds the legacy members predating the required-email rule (#362). */
    public function test_index_maps_has_email_with_to_a_boolean_filter(): void
    {
        $this->expectList(50, 0, ['has_email' => true], 'created_at', 'desc', null);

        $this->controller->index($this->get('/api/admin/members', ['has_email' => 'with']), new Response());
    }

    public function test_index_maps_has_email_without_to_a_boolean_filter(): void
    {
        $this->expectList(50, 0, ['has_email' => false], 'created_at', 'desc', null);

        $this->controller->index($this->get('/api/admin/members', ['has_email' => 'without']), new Response());
    }

    public function test_index_accepts_the_sepa_status_filter(): void
    {
        $this->expectList(50, 0, ['sepa_status' => 'valid'], 'created_at', 'desc', null);

        $this->controller->index($this->get('/api/admin/members', ['sepa_status' => 'valid']), new Response());
    }

    public function test_index_ignores_an_unknown_sepa_status(): void
    {
        $this->expectList(50, 0, [], 'created_at', 'desc', null);

        $this->controller->index($this->get('/api/admin/members', ['sepa_status' => 'perhaps']), new Response());
    }

    public function test_index_accepts_the_nested_filters_dialect(): void
    {
        $this->expectList(50, 0, ['is_active' => true, 'language' => 'de'], 'created_at', 'desc', null);

        $request = $this->get('/api/admin/members', [
            'filters' => ['is_active' => 'true', 'language' => 'de'],
        ]);

        $this->controller->index($request, new Response());
    }

    public function test_index_trims_the_search_term(): void
    {
        $this->expectList(50, 0, [], 'created_at', 'desc', 'meier');

        $this->controller->index($this->get('/api/admin/members', ['search' => '  meier  ']), new Response());
    }

    public function test_index_refuses_a_per_page_over_the_cap(): void
    {
        $this->membersService->expects($this->never())->method('listMembers');

        $this->expectException(InvalidQueryParameterException::class);

        $this->controller->index($this->get('/api/admin/members', ['per_page' => '500']), new Response());
    }

    public function test_index_refuses_a_non_numeric_per_page(): void
    {
        $this->expectException(InvalidQueryParameterException::class);

        $this->controller->index($this->get('/api/admin/members', ['per_page' => 'lots']), new Response());
    }
}
