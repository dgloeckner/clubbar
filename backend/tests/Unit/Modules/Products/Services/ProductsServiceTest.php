<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Products\Services;

use App\Modules\Products\Services\ProductsService;
use App\Modules\Products\Repositories\ProductsRepository;
use App\Modules\Products\Repositories\CategoriesRepository;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

class ProductsServiceTest extends TestCase
{
    private ProductsRepository $productsRepository;
    private CategoriesRepository $categoriesRepository;
    private AuditService $auditService;
    private ProductsService $productsService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mocks
        $this->productsRepository = $this->createMock(ProductsRepository::class);
        $this->categoriesRepository = $this->createMock(CategoriesRepository::class);
        $this->auditService = $this->createMock(AuditService::class);

        // Create service instance
        $this->productsService = new ProductsService(
            $this->productsRepository,
            $this->categoriesRepository,
            $this->auditService
        );
    }

    public function test_syncSince_returns_cursor_in_milliseconds_when_no_rows(): void
    {
        // Mock repository to return empty array (no rows)
        $this->productsRepository
            ->expects($this->once())
            ->method('findModifiedSince')
            ->with($this->anything())
            ->willReturn([]);

        $result = $this->productsService->syncSince(9999999999999);

        // When no rows found, cursor echoes back $since to avoid race conditions
        // (items created during query execution won't be lost on next sync)
        $this->assertSame(9999999999999, $result->cursor);
    }

    public function test_syncSince_cursor_covers_every_row_it_returned(): void
    {
        $newest = (new \DateTime('2026-01-01 10:00:03'))->getTimestamp();

        $this->productsRepository
            ->method('findModifiedSince')
            ->willReturn([
                $this->productRow('2026-01-01 10:00:01'),
                $this->productRow('2026-01-01 10:00:03'),
            ]);

        $result = $this->productsService->syncSince(0);

        // Those seconds are long over, so the cursor steps past the newest one
        // rather than re-sending it forever (#84).
        $this->assertSame(($newest + 1) * 1000, $result->cursor);
    }

    private function productRow(string $updatedAt): array
    {
        return [
            'id' => 'prod-' . $updatedAt,
            'category_id' => 'cat-1',
            'names' => ['de' => 'Bier', 'en' => 'Beer'],
            'descriptions' => [],
            'price_cents' => 250,
            'is_active' => 1,
            'requires_dispenser' => 0,
            'icon_name' => null,
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
            'deleted_at' => null,
        ];
    }
}
