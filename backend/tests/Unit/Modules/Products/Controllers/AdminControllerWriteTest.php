<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Products\Controllers;

use App\Modules\Products\Controllers\AdminController;
use App\Modules\Products\Services\CategoriesService;
use App\Modules\Products\Services\ProductsService;
use App\Shared\Validation\Validator;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * The category and product write endpoints' `Validator` rejections (#446):
 * each goes through the shared `validationFailed()` emitter and answers 422
 * `validation_failed` with a field map.
 */
class AdminControllerWriteTest extends TestCase
{
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
            new Validator($this->createMock(PDO::class)),
        );
    }

    /** @param array<string, mixed> $body */
    private function post(string $path, array $body): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', $path)
            ->withParsedBody($body)
            ->withAttribute('admin_user_id', 'admin-1');
    }

    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return json_decode((string) $response->getBody(), true);
    }

    private function assertRejected(ResponseInterface $response, string $field): void
    {
        $this->assertSame(422, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('validation_failed', $body['error']);
        $this->assertArrayHasKey($field, $body['messages']);
    }

    public function test_store_category_rejects_missing_names(): void
    {
        $this->categoriesService->expects($this->never())->method('createCategory');

        $response = $this->controller->storeCategory($this->post('/api/admin/categories', []), new Response());

        $this->assertRejected($response, 'names');
    }

    public function test_update_category_rejects_an_empty_names_array(): void
    {
        $this->categoriesService->expects($this->never())->method('updateCategory');

        $response = $this->controller->updateCategory(
            $this->post('/api/admin/categories/cat-1', ['names' => []]),
            new Response(),
            ['categoryId' => 'cat-1'],
        );

        $this->assertRejected($response, 'names');
    }

    public function test_toggle_category_status_rejects_a_missing_is_active(): void
    {
        $this->categoriesService->expects($this->never())->method('toggleStatus');

        $response = $this->controller->toggleCategoryStatus(
            $this->post('/api/admin/categories/cat-1/status', []),
            new Response(),
            ['categoryId' => 'cat-1'],
        );

        $this->assertRejected($response, 'is_active');
    }

    public function test_store_product_rejects_a_non_positive_price(): void
    {
        $this->productsService->expects($this->never())->method('createProduct');

        $response = $this->controller->storeProduct($this->post('/api/admin/products', [
            'names' => ['de' => 'Bier'],
            'category_id' => '11111111-1111-1111-1111-111111111111',
            'price_cents' => 0,
        ]), new Response());

        $this->assertRejected($response, 'price_cents');
    }

    public function test_update_product_rejects_a_malformed_category_id(): void
    {
        $this->productsService->expects($this->never())->method('updateProduct');

        $response = $this->controller->updateProduct(
            $this->post('/api/admin/products/prod-1', ['category_id' => 'not-a-uuid']),
            new Response(),
            ['productId' => 'prod-1'],
        );

        $this->assertRejected($response, 'category_id');
    }

    public function test_toggle_product_status_rejects_a_missing_is_active(): void
    {
        $this->productsService->expects($this->never())->method('toggleStatus');

        $response = $this->controller->toggleProductStatus(
            $this->post('/api/admin/products/prod-1/status', []),
            new Response(),
            ['productId' => 'prod-1'],
        );

        $this->assertRejected($response, 'is_active');
    }
}
