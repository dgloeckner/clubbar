<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Products\Controllers;

use App\Modules\Products\Controllers\AdminController;
use App\Modules\Products\Services\CategoriesService;
use App\Modules\Products\Services\ProductsService;
use App\Shared\DTOs\PaginatedResultDto;
use App\Shared\Exceptions\InvalidQueryParameterException;
use App\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Response;
use Tests\Unit\Shared\Http\ListEndpointAssertions;

/**
 * The products and categories list endpoints (#119).
 *
 * Products answered `{items,total,limit,offset,has_more}` and silently clamped
 * an over-cap `per_page`; categories answered `{categories: […]}`, a fifth
 * shape for one endpoint. Products was also the only endpoint that understood
 * `sort_by`, via a private lookup table that has moved into ListQuery.
 */
class AdminControllerListTest extends TestCase
{
    use ListEndpointAssertions;

    private CategoriesService $categoriesService;
    private ProductsService $productsService;
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->categoriesService = $this->createMock(CategoriesService::class);
        $this->productsService = $this->createMock(ProductsService::class);

        $this->controller = new AdminController(
            $this->categoriesService,
            $this->productsService,
            new Validator($this->createMock(\PDO::class)),
        );
    }

    private function expectList(int $limit, int $offset, array $filters, string $sortBy, string $sortOrder): void
    {
        $this->productsService->expects($this->once())
            ->method('listProducts')
            ->with($limit, $offset, $filters, $sortBy, $sortOrder)
            ->willReturn(new PaginatedResultDto([], total: 0, limit: $limit, offset: $offset));
    }

    // ── Products ──────────────────────────────────────────────────────

    public function test_list_products_answers_with_the_canonical_envelope(): void
    {
        $this->productsService->method('listProducts')
            ->willReturn(new PaginatedResultDto([['id' => 'p-1']], total: 1, limit: 50, offset: 0));

        $body = $this->decode($this->controller->listProducts($this->get('/api/admin/products'), new Response()));

        $this->assertSame([['id' => 'p-1']], $body['data']);
        $this->assertSame(['page' => 1, 'per_page' => 50, 'total' => 1, 'total_pages' => 1], $body['pagination']);
        $this->assertArrayNotHasKey('items', $body);
        $this->assertArrayNotHasKey('has_more', $body);
    }

    public function test_list_products_defaults_to_newest_first(): void
    {
        $this->expectList(50, 0, [], 'created_at', 'desc');

        $this->controller->listProducts($this->get('/api/admin/products'), new Response());
    }

    public function test_list_products_splits_the_combined_sort_by(): void
    {
        $this->expectList(50, 0, [], 'price', 'asc');

        $this->controller->listProducts($this->get('/api/admin/products', ['sort_by' => 'price_asc']), new Response());
    }

    public function test_list_products_splits_a_multi_word_sort_by(): void
    {
        $this->expectList(50, 0, [], 'created_at', 'asc');

        $this->controller->listProducts($this->get('/api/admin/products', ['sort_by' => 'created_at_asc']), new Response());
    }

    public function test_list_products_passes_category_status_and_search_filters(): void
    {
        $categoryId = '11111111-2222-3333-4444-555555555555';
        $this->expectList(50, 0, ['category_id' => $categoryId, 'status' => 'active', 'search' => 'bier'], 'created_at', 'desc');

        $request = $this->get('/api/admin/products', [
            'category_id' => $categoryId,
            'status' => 'active',
            'search' => 'bier',
        ]);

        $this->controller->listProducts($request, new Response());
    }

    public function test_list_products_rejects_an_unknown_status_with_422(): void
    {
        $this->productsService->expects($this->never())->method('listProducts');

        $response = $this->controller->listProducts($this->get('/api/admin/products', ['status' => 'maybe']), new Response());

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('validation_failed', $this->decode($response)['error']);
    }

    public function test_list_products_rejects_a_category_id_that_is_not_a_uuid(): void
    {
        $response = $this->controller->listProducts($this->get('/api/admin/products', ['category_id' => 'nope']), new Response());

        $this->assertSame(422, $response->getStatusCode());
    }

    /**
     * Products used to clamp: asking for 500 returned 100 and said nothing.
     */
    public function test_list_products_refuses_a_per_page_over_the_cap_instead_of_clamping(): void
    {
        $this->productsService->expects($this->never())->method('listProducts');

        $this->expectException(InvalidQueryParameterException::class);

        $this->controller->listProducts($this->get('/api/admin/products', ['per_page' => '500']), new Response());
    }

    public function test_list_products_translates_page_into_an_offset(): void
    {
        $this->expectList(10, 20, [], 'created_at', 'desc');

        $this->controller->listProducts($this->get('/api/admin/products', ['page' => '3', 'per_page' => '10']), new Response());
    }

    // ── Categories ────────────────────────────────────────────────────

    public function test_list_categories_answers_under_the_data_key(): void
    {
        $this->categoriesService->method('listCategories')->willReturn([['id' => 'c-1']]);

        $body = $this->decode($this->controller->listCategories($this->get('/api/admin/categories'), new Response()));

        $this->assertSame([['id' => 'c-1']], $body['data']);
        $this->assertArrayNotHasKey('categories', $body);
    }

    public function test_list_categories_returns_an_empty_array_when_there_are_none(): void
    {
        $this->categoriesService->method('listCategories')->willReturn([]);

        $body = $this->decode($this->controller->listCategories($this->get('/api/admin/categories'), new Response()));

        $this->assertSame([], $body['data']);
    }
}
