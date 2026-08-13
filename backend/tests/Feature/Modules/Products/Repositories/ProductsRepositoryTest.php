<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Products\Repositories;

use App\Modules\Products\Repositories\ProductsRepository;
use App\Modules\Products\Repositories\CategoriesRepository;
use Tests\Feature\DatabaseTestCase;

class ProductsRepositoryTest extends DatabaseTestCase
{
    private ProductsRepository $productsRepository;
    private CategoriesRepository $categoriesRepository;
    private array $testProductIds = [];
    private array $testCategoryIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->productsRepository = new ProductsRepository($this->db, $this->logger);
        $this->categoriesRepository = new CategoriesRepository($this->db, $this->logger);
    }

    protected function tearDown(): void
    {
        // Clean up test products and categories
        $this->cleanupTestData('products', $this->testProductIds);
        $this->cleanupTestData('categories', $this->testCategoryIds);
        parent::tearDown();
    }

    public function test_findModifiedSince_accepts_milliseconds_and_converts_correctly(): void
    {
        // Create test category first (products require a category)
        $testCategoryId = $this->generateUuid();
        $testCategory = [
            'id' => $testCategoryId,
            'names' => ['de' => 'Test Kategorie', 'en' => 'Test Category'],
            'is_active' => true,
        ];
        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create($testCategory);

        // Create test product with known timestamp
        $testProductId = $this->generateUuid();
        $testProduct = [
            'id' => $testProductId,
            'category_id' => $testCategoryId,
            'names' => ['de' => 'Millisekunden Test', 'en' => 'Milliseconds Test'],
            'descriptions' => ['de' => 'Test Beschreibung', 'en' => 'Test Description'],
            'price_cents' => 250,
            'is_active' => true,
            'icon_name' => 'test',
        ];

        $this->testProductIds[] = $testProductId;
        $this->productsRepository->create($testProduct);

        // Get product's updated_at timestamp
        $product = $this->productsRepository->findById($testProductId);
        $this->assertNotNull($product, 'Test product should be created');

        $updatedAt = new \DateTime($product['updated_at']);

        // Convert to milliseconds and subtract 1 second (to ensure we query before the product's timestamp)
        $sinceMs = ($updatedAt->getTimestamp() - 1) * 1000;

        // Query with milliseconds
        $results = $this->productsRepository->findModifiedSince($sinceMs);

        // Should find the test product (and possibly others created during other tests)
        $found = false;
        foreach ($results as $result) {
            if ($result['id'] === $testProductId) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, "Test product {$testProductId} should be found in results when querying with milliseconds");
    }

    public function test_findModifiedSince_includes_rows_written_in_the_cursor_second(): void
    {
        // #84: `updated_at` has second precision, so a price written at
        // 12:00:00.8 is stored the same as one written at 12:00:00.2. A cursor
        // of 12:00:00 with a strict `>` would skip the later price forever —
        // the terminal would keep selling at the stale one.
        $testCategoryId = $this->generateUuid();
        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create([
            'id' => $testCategoryId,
            'names' => ['de' => 'Sekundengrenze', 'en' => 'Second Boundary'],
            'is_active' => true,
        ]);

        $testProductId = $this->generateUuid();
        $this->testProductIds[] = $testProductId;
        $this->productsRepository->create([
            'id' => $testProductId,
            'category_id' => $testCategoryId,
            'names' => ['de' => 'Grenzfall', 'en' => 'Boundary Case'],
            'price_cents' => 275,
            'is_active' => true,
        ]);

        $product = $this->productsRepository->findById($testProductId);
        $this->assertNotNull($product, 'Test product should be created');

        // Cursor sits exactly on the product's own second.
        $cursorMs = (new \DateTime($product['updated_at']))->getTimestamp() * 1000;

        $results = $this->productsRepository->findModifiedSince($cursorMs);

        $this->assertContains(
            $testProductId,
            array_column($results, 'id'),
            "Product {$testProductId} must still be returned when the cursor sits on its own second",
        );
    }

    public function test_findModifiedSince_returns_empty_for_timestamp_in_future(): void
    {
        // Create test category first
        $testCategoryId = $this->generateUuid();
        $testCategory = [
            'id' => $testCategoryId,
            'names' => ['de' => 'Zukunft Kategorie', 'en' => 'Future Category'],
            'is_active' => true,
        ];
        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create($testCategory);

        // Create test product
        $testProductId = $this->generateUuid();
        $testProduct = [
            'id' => $testProductId,
            'category_id' => $testCategoryId,
            'names' => ['de' => 'Zukunft Test', 'en' => 'Future Test'],
            'price_cents' => 300,
            'is_active' => true,
        ];

        $this->testProductIds[] = $testProductId;
        $this->productsRepository->create($testProduct);

        // Query with timestamp 1 day in the future (in milliseconds)
        $futureMs = (time() + 86400) * 1000;
        $results = $this->productsRepository->findModifiedSince($futureMs);

        // Should not find the test product (created now, but query is for future)
        $found = false;
        foreach ($results as $result) {
            if ($result['id'] === $testProductId) {
                $found = true;
                break;
            }
        }

        $this->assertFalse($found, "Test product should not be found when querying with future timestamp");
    }

    public function test_findModifiedSince_does_not_return_year_57123_bug(): void
    {
        // This test verifies Bug #2 from timestamp protocol analysis is fixed.
        // Before fix: passing milliseconds to date() causes year 57123 (interprets ms as seconds)
        // After fix: milliseconds are divided by 1000 before date(), producing correct year

        // Create test category first
        $testCategoryId = $this->generateUuid();
        $testCategory = [
            'id' => $testCategoryId,
            'names' => ['de' => 'Bug Kategorie', 'en' => 'Bug Category'],
            'is_active' => true,
        ];
        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create($testCategory);

        // Create test product
        $testProductId = $this->generateUuid();
        $testProduct = [
            'id' => $testProductId,
            'category_id' => $testCategoryId,
            'names' => ['de' => 'Bug Test', 'en' => 'Bug Test'],
            'price_cents' => 150,
            'is_active' => true,
        ];

        $this->testProductIds[] = $testProductId;
        $this->productsRepository->create($testProduct);

        // Get product timestamp
        $product = $this->productsRepository->findById($testProductId);
        $this->assertNotNull($product);

        $updatedAt = new \DateTime($product['updated_at']);
        $sinceMs = ($updatedAt->getTimestamp() - 1) * 1000;

        // Query with milliseconds
        $results = $this->productsRepository->findModifiedSince($sinceMs);

        // If bug exists, date() would generate '57123-06-16 00:13:20' (from treating 1738872000000 as seconds)
        // This would never match any real records, so results would be empty
        // After fix, we should find our product

        $found = false;
        foreach ($results as $result) {
            if ($result['id'] === $testProductId) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, "Bug #2: Product should be found after milliseconds-to-seconds conversion fix (year 57123 bug)");
    }

    public function test_findModifiedSince_includes_deleted_products_tombstones(): void
    {
        // Create test category first
        $testCategoryId = $this->generateUuid();
        $this->categoriesRepository->create([
            'id' => $testCategoryId,
            'names' => json_encode(['de' => 'Test Category', 'en' => 'Test Category']),
            'is_active' => 1,
        ]);
        $this->testCategoryIds[] = $testCategoryId;

        // Create test product
        $testProductId = $this->generateUuid();
        $testProduct = [
            'id' => $testProductId,
            'category_id' => $testCategoryId,
            'names' => json_encode(['de' => 'Tombstone Product', 'en' => 'Tombstone Product']),
            'price_cents' => 500,
            'is_active' => 1,
        ];
        $this->productsRepository->create($testProduct);
        $this->testProductIds[] = $testProductId;

        // Get timestamp before deletion
        $beforeDelete = time() * 1000;
        sleep(1);

        // Soft delete the product
        $this->productsRepository->updateById($testProductId, ['deleted_at' => date('Y-m-d H:i:s')]);

        // Query with timestamp before deletion
        $results = $this->productsRepository->findModifiedSince($beforeDelete);

        // Should include the deleted product (tombstone)
        $found = false;
        foreach ($results as $result) {
            if ($result['id'] === $testProductId) {
                $found = true;
                $this->assertNotNull($result['deleted_at'], 'Deleted product should have deleted_at set');
                break;
            }
        }

        $this->assertTrue($found, 'Deleted product (tombstone) should be included in sync results');
    }

    public function test_findModifiedSince_includes_both_updated_and_deleted_products(): void
    {
        // Create test category
        $testCategoryId = $this->generateUuid();
        $this->categoriesRepository->create([
            'id' => $testCategoryId,
            'names' => json_encode(['de' => 'Test Category', 'en' => 'Test Category']),
            'is_active' => 1,
        ]);
        $this->testCategoryIds[] = $testCategoryId;

        // Create two test products
        $updatedProductId = $this->generateUuid();
        $deletedProductId = $this->generateUuid();

        $this->productsRepository->create([
            'id' => $updatedProductId,
            'category_id' => $testCategoryId,
            'names' => json_encode(['de' => 'Updated Product', 'en' => 'Updated Product']),
            'price_cents' => 600,
            'is_active' => 1,
        ]);

        $this->productsRepository->create([
            'id' => $deletedProductId,
            'category_id' => $testCategoryId,
            'names' => json_encode(['de' => 'Deleted Product', 'en' => 'Deleted Product']),
            'price_cents' => 700,
            'is_active' => 1,
        ]);

        $this->testProductIds[] = $updatedProductId;
        $this->testProductIds[] = $deletedProductId;

        // Get timestamp before modifications
        $beforeModifications = time() * 1000;
        sleep(1);

        // Update one product
        $this->productsRepository->updateById($updatedProductId, [
            'names' => json_encode(['de' => 'Updated Name', 'en' => 'Updated Name'])
        ]);

        // Delete the other product
        $this->productsRepository->updateById($deletedProductId, ['deleted_at' => date('Y-m-d H:i:s')]);

        // Query with timestamp before modifications
        $results = $this->productsRepository->findModifiedSince($beforeModifications);

        // Should include both
        $foundUpdated = false;
        $foundDeleted = false;

        foreach ($results as $result) {
            if ($result['id'] === $updatedProductId) {
                $foundUpdated = true;
                $this->assertNull($result['deleted_at']);
            }
            if ($result['id'] === $deletedProductId) {
                $foundDeleted = true;
                $this->assertNotNull($result['deleted_at']);
            }
        }

        $this->assertTrue($foundUpdated, 'Updated product should be included');
        $this->assertTrue($foundDeleted, 'Deleted product (tombstone) should be included');
    }

    public function test_findById_returns_null_for_soft_deleted_product(): void
    {
        // #416: findById() must not resolve a soft-deleted product, otherwise
        // it stays fetchable, updatable and re-deletable through the admin API.
        $testCategoryId = $this->generateUuid();
        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create([
            'id' => $testCategoryId,
            'names' => ['de' => 'Test Kategorie', 'en' => 'Test Category'],
            'is_active' => true,
        ]);

        $testProductId = $this->generateUuid();
        $this->testProductIds[] = $testProductId;
        $this->productsRepository->create([
            'id' => $testProductId,
            'category_id' => $testCategoryId,
            'names' => ['de' => 'Gelöschtes Produkt', 'en' => 'Deleted Product'],
            'price_cents' => 100,
            'is_active' => true,
        ]);

        $this->productsRepository->updateById($testProductId, ['deleted_at' => date('Y-m-d H:i:s')]);

        $this->assertNull(
            $this->productsRepository->findById($testProductId),
            'findById() should not resolve a soft-deleted product'
        );
    }
}
