<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Http;

use App\Shared\Http\ListQuery;
use App\Shared\Http\PaginatedResponse;
use PHPUnit\Framework\TestCase;

class PaginatedResponseTest extends TestCase
{
    public function test_it_emits_the_canonical_envelope(): void
    {
        $body = PaginatedResponse::of([['id' => 'a']], total: 1, page: 1, perPage: 50);

        $this->assertSame(
            [
                'data' => [['id' => 'a']],
                'pagination' => [
                    'page' => 1,
                    'per_page' => 50,
                    'total' => 1,
                    'total_pages' => 1,
                ],
            ],
            $body
        );
    }

    public function test_total_pages_rounds_up(): void
    {
        $this->assertSame(4, PaginatedResponse::of([], total: 31, page: 1, perPage: 10)['pagination']['total_pages']);
        $this->assertSame(3, PaginatedResponse::of([], total: 30, page: 1, perPage: 10)['pagination']['total_pages']);
    }

    public function test_an_empty_result_set_has_no_pages(): void
    {
        $this->assertSame(0, PaginatedResponse::of([], total: 0, page: 1, perPage: 10)['pagination']['total_pages']);
    }

    public function test_a_zero_per_page_cannot_divide_by_zero(): void
    {
        $this->assertSame(1, PaginatedResponse::of([], total: 5, page: 1, perPage: 0)['pagination']['total_pages']);
    }

    public function test_data_is_always_a_json_array_never_an_object(): void
    {
        // Filtering a list can leave holes in the keys; json_encode would then
        // emit an object and the frontend's Array.isArray() check would fail.
        $sparse = [2 => ['id' => 'c'], 5 => ['id' => 'f']];

        $body = PaginatedResponse::of($sparse, total: 2, page: 1, perPage: 10);

        $this->assertSame('[{"id":"c"},{"id":"f"}]', json_encode($body['data']));
    }

    public function test_it_unwraps_dtos_that_expose_to_array(): void
    {
        $dto = new class {
            public function toArray(): array
            {
                return ['id' => 'from-dto'];
            }
        };

        $body = PaginatedResponse::of([$dto], total: 1, page: 1, perPage: 10);

        $this->assertSame([['id' => 'from-dto']], $body['data']);
    }

    public function test_it_leaves_plain_rows_untouched(): void
    {
        $body = PaginatedResponse::of([['id' => 'raw', 'nested' => ['x' => 1]]], total: 1, page: 1, perPage: 10);

        $this->assertSame([['id' => 'raw', 'nested' => ['x' => 1]]], $body['data']);
    }

    // ── Convenience constructors ──────────────────────────────────────

    public function test_it_can_be_built_from_a_list_query(): void
    {
        $query = ListQuery::fromParams(['page' => '2', 'per_page' => '10']);

        $body = PaginatedResponse::fromQuery([['id' => 'a']], total: 25, query: $query);

        $this->assertSame(
            ['page' => 2, 'per_page' => 10, 'total' => 25, 'total_pages' => 3],
            $body['pagination']
        );
    }
}
