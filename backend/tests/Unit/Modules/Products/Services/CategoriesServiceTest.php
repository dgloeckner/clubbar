<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Products\Services;

use App\Modules\Products\Services\CategoriesService;
use App\Modules\Products\Repositories\CategoriesRepository;
use App\Modules\Products\Repositories\ProductsRepository;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

class CategoriesServiceTest extends TestCase
{
    private CategoriesRepository $categoriesRepository;
    private ProductsRepository $productsRepository;
    private AuditService $auditService;
    private CategoriesService $categoriesService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mocks
        $this->categoriesRepository = $this->createMock(CategoriesRepository::class);
        $this->productsRepository = $this->createMock(ProductsRepository::class);
        $this->auditService = $this->createMock(AuditService::class);

        // Create service instance
        $this->categoriesService = new CategoriesService(
            $this->categoriesRepository,
            $this->productsRepository,
            $this->auditService
        );
    }

    public function test_syncSince_returns_cursor_in_milliseconds_when_no_rows(): void
    {
        // Mock repository to return empty array (no rows)
        $this->categoriesRepository
            ->expects($this->once())
            ->method('findModifiedSince')
            ->with($this->anything())
            ->willReturn([]);

        $result = $this->categoriesService->syncSince(9999999999999);

        // Cursor should be in milliseconds (13 digits, > 1700000000000)
        $this->assertGreaterThan(1700000000000, $result->cursor);
        $this->assertLessThan(2000000000000, $result->cursor);
    }
}
