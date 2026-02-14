<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Products\DTOs;

use App\Modules\Products\DTOs\ProductDto;
use PHPUnit\Framework\TestCase;

class ProductDtoTest extends TestCase
{
    public function test_product_dto_includes_requires_dispenser_field(): void
    {
        // Arrange: Create test data row matching database schema
        $row = [
            'id' => 'uuid-123',
            'category_id' => 'cat-uuid',
            'names' => json_encode(['de' => 'Token', 'en' => 'Token']),
            'descriptions' => json_encode(['de' => 'Test description', 'en' => 'Test description']),
            'price_cents' => 300,
            'is_active' => 1,
            'icon_name' => 'token',
            'requires_dispenser' => 1, // NEW FIELD
            'created_at' => '2026-02-14 10:00:00',
            'updated_at' => '2026-02-14 10:00:00',
            'deleted_at' => null,
        ];

        // Act: Create DTO from row
        $dto = ProductDto::fromRow($row);

        // Assert: Verify requiresDispenser is correctly mapped
        $this->assertTrue($dto->requiresDispenser);
        $this->assertEquals(1, $dto->toArray()['requires_dispenser']);
    }

    public function test_product_dto_handles_false_requires_dispenser(): void
    {
        // Arrange: Create test data with requires_dispenser = 0
        $row = [
            'id' => 'uuid-456',
            'category_id' => 'cat-uuid',
            'names' => json_encode(['de' => 'Beer', 'en' => 'Beer']),
            'descriptions' => json_encode(['de' => 'Refreshing beer', 'en' => 'Refreshing beer']),
            'price_cents' => 250,
            'is_active' => 1,
            'icon_name' => 'beer',
            'requires_dispenser' => 0, // Should be false
            'created_at' => '2026-02-14 10:00:00',
            'updated_at' => '2026-02-14 10:00:00',
            'deleted_at' => null,
        ];

        // Act: Create DTO from row
        $dto = ProductDto::fromRow($row);

        // Assert: Verify requiresDispenser is false
        $this->assertFalse($dto->requiresDispenser);
        $this->assertEquals(0, $dto->toArray()['requires_dispenser']);
    }
}
