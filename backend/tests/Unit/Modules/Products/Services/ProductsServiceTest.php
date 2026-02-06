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

        // Cursor should be in milliseconds (13 digits, > 1700000000000)
        $this->assertGreaterThan(1700000000000, $result->cursor);
        $this->assertLessThan(2000000000000, $result->cursor);
    }
}
