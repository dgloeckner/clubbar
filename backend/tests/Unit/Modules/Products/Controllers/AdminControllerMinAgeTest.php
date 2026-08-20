<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Products\Controllers;

use App\Modules\Products\Controllers\AdminController;
use App\Modules\Products\DTOs\ProductDto;
use App\Modules\Products\Services\CategoriesService;
use App\Modules\Products\Services\ProductsService;
use App\Shared\Validation\Validator;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

/**
 * `min_age` on the product write endpoints (epic #582 M3, ADR-0045).
 *
 * This is the product half of the Jugendschutz check: the number a Getränkewart
 * sets on a drink, which the terminal compares against the member's age at
 * checkout. Three properties are asserted rather than assumed:
 *
 * 1. **NULL means unrestricted, not unknown.** Most of a drinks list has no
 *    legal age, and making the Getränkewart assert that for every Apfelschorle
 *    would be friction with no safety gain. So the field is optional, and
 *    omitting it is the ordinary case.
 * 2. **The bound is 1–99, not `{16, 18}`.** JuSchG's two thresholds are German
 *    law; this software is self-hosted, and another country's club has its own
 *    numbers. What the range rules out is nonsense — a 0 that reads as
 *    "everyone" while meaning "restricted", and a 100 nobody reaches.
 * 3. **It can be cleared.** Removing a restriction has to be possible, and it
 *    is the one write here that makes a product *more* available, so it must be
 *    deliberate and it must actually reach the row.
 */
class AdminControllerMinAgeTest extends TestCase
{
    private ProductsService $productsService;
    private AdminController $controller;

    protected function setUp(): void
    {
        $this->productsService = $this->createMock(ProductsService::class);

        $this->controller = new AdminController(
            $this->createMock(CategoriesService::class),
            $this->productsService,
            new Validator($this->createMock(PDO::class)),
        );
    }

    /** @return array<string, mixed> */
    private static function validBody(array $overrides = []): array
    {
        return $overrides + [
            'names' => ['de' => 'Alt', 'en' => 'Ale'],
            'category_id' => '11111111-1111-4111-8111-111111111111',
            'price_cents' => 220,
        ];
    }

    /** @param array<string, mixed> $body */
    private function post(array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('POST', '/api/admin/products')
            ->withParsedBody($body)
            ->withAttribute('admin_user_id', 'admin-1');
    }

    /** @param array<string, mixed> $body */
    private function patch(array $body): ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('PATCH', '/api/admin/products/p-1')
            ->withParsedBody($body)
            ->withAttribute('admin_user_id', 'admin-1');
    }

    private function decode(ResponseInterface $response): array
    {
        $response->getBody()->rewind();

        return json_decode((string) $response->getBody(), true);
    }

    private function product(?int $minAge = null): ProductDto
    {
        return ProductDto::fromRow([
            'id' => 'p-1',
            'names' => '{"de":"Alt"}',
            'descriptions' => '{}',
            'price_cents' => 220,
            'category_id' => '11111111-1111-4111-8111-111111111111',
            'is_active' => 1,
            'requires_dispenser' => 0,
            'icon_name' => null,
            'min_age' => $minAge,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    // -- create ------------------------------------------------------------

    public function test_store_accepts_a_restricted_product(): void
    {
        $this->productsService->expects($this->once())
            ->method('createProduct')
            ->with($this->callback(function (array $body): bool {
                $this->assertSame(18, $body['min_age']);

                return true;
            }), 'admin-1')
            ->willReturn($this->product(18));

        $response = $this->controller->storeProduct($this->post(self::validBody(['min_age' => 18])), new Response());

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame(18, $this->decode($response)['min_age']);
    }

    /**
     * The overwhelming majority of a drinks list. Omitting the field must be
     * the frictionless path, or a forgotten input blocks the sale of a Spezi.
     */
    public function test_store_accepts_a_product_with_no_age_at_all(): void
    {
        $this->productsService->expects($this->once())
            ->method('createProduct')
            ->willReturn($this->product(null));

        $response = $this->controller->storeProduct($this->post(self::validBody()), new Response());

        $this->assertSame(201, $response->getStatusCode());
        $this->assertNull($this->decode($response)['min_age']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function rejectedAges(): array
    {
        return [
            // 0 would read as "everyone" while sitting in a field whose whole
            // purpose is to restrict — two meanings for one value.
            'zero' => [0],
            'negative' => [-1],
            'past the top of the range' => [100],
            'absurd' => [255],
            'a fraction' => [17.5],
            'a fraction as a string' => ['17.5'],
            'prose' => ['eighteen'],
            'a boolean' => [true],
        ];
    }

    #[DataProvider('rejectedAges')]
    public function test_store_rejects_an_unusable_age(mixed $value): void
    {
        $this->productsService->expects($this->never())->method('createProduct');

        $response = $this->controller->storeProduct(
            $this->post(self::validBody(['min_age' => $value])),
            new Response(),
        );

        $this->assertSame(422, $response->getStatusCode());
        $body = $this->decode($response);
        $this->assertSame('validation_failed', $body['error']);
        $this->assertArrayHasKey('min_age', $body['messages'], 'the 422 must name min_age');
    }

    /** @return array<string, array{0: int}> */
    public static function acceptedAges(): array
    {
        return [
            'the bottom of the range' => [1],
            'the JuSchG beer and wine threshold' => [16],
            'the JuSchG spirits threshold' => [18],
            'the top of the range' => [99],
        ];
    }

    #[DataProvider('acceptedAges')]
    public function test_store_accepts_an_age_inside_the_range(int $value): void
    {
        $this->productsService->expects($this->once())
            ->method('createProduct')
            ->willReturn($this->product($value));

        $response = $this->controller->storeProduct(
            $this->post(self::validBody(['min_age' => $value])),
            new Response(),
        );

        $this->assertSame(201, $response->getStatusCode());
    }

    // -- update ------------------------------------------------------------

    public function test_update_sets_an_age_on_an_existing_product(): void
    {
        $this->productsService->expects($this->once())
            ->method('updateProduct')
            ->with('p-1', ['min_age' => 16], 'admin-1')
            ->willReturn($this->product(16));

        $response = $this->controller->updateProduct($this->patch(['min_age' => 16]), new Response(), ['productId' => 'p-1']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(16, $this->decode($response)['min_age']);
    }

    #[DataProvider('rejectedAges')]
    public function test_update_rejects_an_unusable_age(mixed $value): void
    {
        $this->productsService->expects($this->never())->method('updateProduct');

        $response = $this->controller->updateProduct(
            $this->patch(['min_age' => $value]),
            new Response(),
            ['productId' => 'p-1'],
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertArrayHasKey('min_age', $this->decode($response)['messages']);
    }

    /**
     * Clearing the restriction is the write that makes a product *more*
     * available, so it has to be an explicit null rather than a side effect —
     * and it has to reach the service, not be dropped on the way.
     */
    public function test_update_clears_the_age_with_an_explicit_null(): void
    {
        $this->productsService->expects($this->once())
            ->method('updateProduct')
            ->with('p-1', ['min_age' => null], 'admin-1')
            ->willReturn($this->product(null));

        $response = $this->controller->updateProduct($this->patch(['min_age' => null]), new Response(), ['productId' => 'p-1']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($this->decode($response)['min_age']);
    }

    /** A PATCH about the price says nothing about the legal age. */
    public function test_update_of_another_field_leaves_the_age_alone(): void
    {
        $this->productsService->expects($this->once())
            ->method('updateProduct')
            ->with('p-1', ['price_cents' => 250], 'admin-1')
            ->willReturn($this->product(18));

        $response = $this->controller->updateProduct($this->patch(['price_cents' => 250]), new Response(), ['productId' => 'p-1']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(18, $this->decode($response)['min_age']);
    }
}
