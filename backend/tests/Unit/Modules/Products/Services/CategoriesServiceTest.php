<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Products\Services;

use App\Modules\Products\Services\CategoriesService;
use App\Modules\Products\Repositories\CategoriesRepository;
use App\Shared\Services\AuditService;
use PHPUnit\Framework\TestCase;

class CategoriesServiceTest extends TestCase
{
    private CategoriesRepository $categoriesRepository;
    private AuditService $auditService;
    private CategoriesService $categoriesService;

    protected function setUp(): void
    {
        parent::setUp();

        // Create mocks
        $this->categoriesRepository = $this->createMock(CategoriesRepository::class);
        $this->auditService = $this->createMock(AuditService::class);

        // Create service instance
        $this->categoriesService = new CategoriesService(
            $this->categoriesRepository,
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

        // When no rows found, cursor echoes back $since to avoid race conditions
        // (items created during query execution won't be lost on next sync)
        $this->assertSame(9999999999999, $result->cursor);
    }
}
