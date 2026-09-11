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

    /**
     * The volume is nullable for the same reason `min_age` is: NULL means the
     * product has no size at all — a Sauna-Token, a Kaffee — which a cast would
     * turn into a zero-millilitre drink (ADR-0056).
     */
    public function test_product_dto_carries_volume_ml_through_the_round_trip(): void
    {
        $row = [
            'id' => 'uuid-vol-1',
            'category_id' => 'cat-uuid',
            'names' => json_encode(['de' => 'Weizenbier', 'en' => 'Wheat beer']),
            'descriptions' => json_encode([]),
            'price_cents' => 420,
            'is_active' => 1,
            'icon_name' => 'beer',
            'requires_dispenser' => 0,
            'min_age' => 16,
            'volume_ml' => 500,
            'created_at' => '2026-09-10 10:00:00',
            'updated_at' => '2026-09-10 10:00:00',
            'deleted_at' => null,
        ];

        $dto = ProductDto::fromRow($row);

        $this->assertSame(500, $dto->volumeMl);
        $this->assertSame(500, $dto->toArray()['volume_ml']);
    }

    public function test_product_dto_reads_a_string_volume_from_the_driver_as_an_int(): void
    {
        // PDO hands back column values as strings under some drivers, so the
        // DTO is what guarantees clients get a number rather than "500".
        $dto = ProductDto::fromRow($this->rowWithVolume('500'));

        $this->assertSame(500, $dto->volumeMl);
        $this->assertSame(500, $dto->toArray()['volume_ml']);
    }

    public function test_product_dto_keeps_a_missing_volume_null_rather_than_zero(): void
    {
        $dto = ProductDto::fromRow($this->rowWithVolume(null));

        $this->assertNull($dto->volumeMl);
        $this->assertNull($dto->toArray()['volume_ml']);
        $this->assertArrayHasKey('volume_ml', $dto->toArray(), 'the key travels even when the value is null, so clients can tell "no size" from an older backend');
    }

    public function test_product_dto_survives_a_row_from_before_the_volume_column(): void
    {
        // A row read by code that predates migration 067 simply has no key.
        $row = $this->rowWithVolume(null);
        unset($row['volume_ml']);

        $this->assertNull(ProductDto::fromRow($row)->volumeMl);
    }

    private function rowWithVolume(int|string|null $volumeMl): array
    {
        return [
            'id' => 'uuid-vol-2',
            'category_id' => 'cat-uuid',
            'names' => json_encode(['de' => 'Kaffee', 'en' => 'Coffee']),
            'descriptions' => json_encode([]),
            'price_cents' => 150,
            'is_active' => 1,
            'icon_name' => 'coffee',
            'requires_dispenser' => 0,
            'min_age' => null,
            'volume_ml' => $volumeMl,
            'created_at' => '2026-09-10 10:00:00',
            'updated_at' => '2026-09-10 10:00:00',
            'deleted_at' => null,
        ];
    }
}
