<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Products\Repositories;

use App\Modules\Products\Repositories\CategoriesRepository;
use Tests\Feature\DatabaseTestCase;

class CategoriesRepositoryTest extends DatabaseTestCase
{
    private CategoriesRepository $categoriesRepository;
    private array $testCategoryIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->categoriesRepository = new CategoriesRepository($this->db, $this->logger);
    }

    protected function tearDown(): void
    {
        // Clean up test categories
        $this->cleanupTestData('categories', $this->testCategoryIds);
        parent::tearDown();
    }

    public function test_findModifiedSince_accepts_milliseconds_and_converts_correctly(): void
    {
        // Create test category with known timestamp
        $testCategoryId = $this->generateUuid();
        $testCategory = [
            'id' => $testCategoryId,
            'names' => ['de' => 'Millisekunden Test', 'en' => 'Milliseconds Test'],
            'is_active' => true,
            'icon_name' => 'test',
        ];

        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create($testCategory);

        // Get category's updated_at timestamp
        $category = $this->categoriesRepository->findById($testCategoryId);
        $this->assertNotNull($category, 'Test category should be created');

        $updatedAt = new \DateTime($category['updated_at']);

        // Convert to milliseconds and subtract 1 second (to ensure we query before the category's timestamp)
        $sinceMs = ($updatedAt->getTimestamp() - 1) * 1000;

        // Query with milliseconds
        $results = $this->categoriesRepository->findModifiedSince($sinceMs);

        // Should find the test category (and possibly others created during other tests)
        $found = false;
        foreach ($results as $result) {
            if ($result['id'] === $testCategoryId) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, "Test category {$testCategoryId} should be found in results when querying with milliseconds");
    }

    public function test_findModifiedSince_includes_rows_written_in_the_cursor_second(): void
    {
        // #84: `updated_at` has second precision, so a category written at
        // 12:00:00.8 is stored the same as one written at 12:00:00.2. A cursor
        // of 12:00:00 with a strict `>` would skip the second one forever.
        $testCategoryId = $this->generateUuid();
        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create([
            'id' => $testCategoryId,
            'names' => ['de' => 'Sekundengrenze', 'en' => 'Second Boundary'],
            'is_active' => true,
            'icon_name' => 'test',
        ]);

        $category = $this->categoriesRepository->findById($testCategoryId);
        $this->assertNotNull($category, 'Test category should be created');

        // Cursor sits exactly on the category's own second.
        $cursorMs = (new \DateTime($category['updated_at']))->getTimestamp() * 1000;

        $results = $this->categoriesRepository->findModifiedSince($cursorMs);

        $this->assertContains(
            $testCategoryId,
            array_column($results, 'id'),
            "Category {$testCategoryId} must still be returned when the cursor sits on its own second",
        );
    }

    public function test_findModifiedSince_returns_empty_for_timestamp_in_future(): void
    {
        // Create test category
        $testCategoryId = $this->generateUuid();
        $testCategory = [
            'id' => $testCategoryId,
            'names' => ['de' => 'Zukunft Test', 'en' => 'Future Test'],
            'is_active' => true,
        ];

        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create($testCategory);

        // Query with timestamp 1 day in the future (in milliseconds)
        $futureMs = (time() + 86400) * 1000;
        $results = $this->categoriesRepository->findModifiedSince($futureMs);

        // Should not find the test category (created now, but query is for future)
        $found = false;
        foreach ($results as $result) {
            if ($result['id'] === $testCategoryId) {
                $found = true;
                break;
            }
        }

        $this->assertFalse($found, "Test category should not be found when querying with future timestamp");
    }

    public function test_findModifiedSince_does_not_return_year_57123_bug(): void
    {
        // This test verifies Bug #2 from timestamp protocol analysis is fixed.
        // Before fix: passing milliseconds to date() causes year 57123 (interprets ms as seconds)
        // After fix: milliseconds are divided by 1000 before date(), producing correct year

        // Create test category
        $testCategoryId = $this->generateUuid();
        $testCategory = [
            'id' => $testCategoryId,
            'names' => ['de' => 'Bug Test', 'en' => 'Bug Test'],
            'is_active' => true,
        ];

        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create($testCategory);

        // Get category timestamp
        $category = $this->categoriesRepository->findById($testCategoryId);
        $this->assertNotNull($category);

        $updatedAt = new \DateTime($category['updated_at']);
        $sinceMs = ($updatedAt->getTimestamp() - 1) * 1000;

        // Query with milliseconds
        $results = $this->categoriesRepository->findModifiedSince($sinceMs);

        // If bug exists, date() would generate '57123-06-16 00:13:20' (from treating 1738872000000 as seconds)
        // This would never match any real records, so results would be empty
        // After fix, we should find our category

        $found = false;
        foreach ($results as $result) {
            if ($result['id'] === $testCategoryId) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, "Bug #2: Category should be found after milliseconds-to-seconds conversion fix (year 57123 bug)");
    }

    public function test_findModifiedSince_includes_deleted_categories_tombstones(): void
    {
        // Create test category
        $testCategoryId = $this->generateUuid();
        $testCategory = [
            'id' => $testCategoryId,
            'names' => json_encode(['de' => 'Tombstone Category', 'en' => 'Tombstone Category']),
            'is_active' => 1,
        ];
        $this->categoriesRepository->create($testCategory);
        $this->testCategoryIds[] = $testCategoryId;

        // Get timestamp before deletion
        $beforeDelete = time() * 1000;
        sleep(1);

        // Soft delete the category
        $this->categoriesRepository->updateById($testCategoryId, ['deleted_at' => date('Y-m-d H:i:s')]);

        // Query with timestamp before deletion
        $results = $this->categoriesRepository->findModifiedSince($beforeDelete);

        // Should include the deleted category (tombstone)
        $found = false;
        foreach ($results as $result) {
            if ($result['id'] === $testCategoryId) {
                $found = true;
                $this->assertNotNull($result['deleted_at'], 'Deleted category should have deleted_at set');
                break;
            }
        }

        $this->assertTrue($found, 'Deleted category (tombstone) should be included in sync results');
    }

    public function test_findModifiedSince_includes_both_updated_and_deleted_categories(): void
    {
        // Create two test categories
        $updatedCategoryId = $this->generateUuid();
        $deletedCategoryId = $this->generateUuid();

        $this->categoriesRepository->create([
            'id' => $updatedCategoryId,
            'names' => json_encode(['de' => 'Updated Category', 'en' => 'Updated Category']),
            'is_active' => 1,
        ]);

        $this->categoriesRepository->create([
            'id' => $deletedCategoryId,
            'names' => json_encode(['de' => 'Deleted Category', 'en' => 'Deleted Category']),
            'is_active' => 1,
        ]);

        $this->testCategoryIds[] = $updatedCategoryId;
        $this->testCategoryIds[] = $deletedCategoryId;

        // Get timestamp before modifications
        $beforeModifications = time() * 1000;
        sleep(1);

        // Update one category
        $this->categoriesRepository->updateById($updatedCategoryId, [
            'names' => json_encode(['de' => 'Updated Name', 'en' => 'Updated Name'])
        ]);

        // Delete the other category
        $this->categoriesRepository->updateById($deletedCategoryId, ['deleted_at' => date('Y-m-d H:i:s')]);

        // Query with timestamp before modifications
        $results = $this->categoriesRepository->findModifiedSince($beforeModifications);

        // Should include both
        $foundUpdated = false;
        $foundDeleted = false;

        foreach ($results as $result) {
            if ($result['id'] === $updatedCategoryId) {
                $foundUpdated = true;
                $this->assertNull($result['deleted_at']);
            }
            if ($result['id'] === $deletedCategoryId) {
                $foundDeleted = true;
                $this->assertNotNull($result['deleted_at']);
            }
        }

        $this->assertTrue($foundUpdated, 'Updated category should be included');
        $this->assertTrue($foundDeleted, 'Deleted category (tombstone) should be included');
    }

    public function test_findById_returns_null_for_soft_deleted_category(): void
    {
        // #416: findById() must not resolve a soft-deleted category, otherwise
        // it stays fetchable, updatable and re-deletable through the admin API.
        $testCategoryId = $this->generateUuid();
        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create([
            'id' => $testCategoryId,
            'names' => ['de' => 'Gelöschte Kategorie', 'en' => 'Deleted Category'],
            'is_active' => true,
        ]);

        $this->categoriesRepository->updateById($testCategoryId, ['deleted_at' => date('Y-m-d H:i:s')]);

        $this->assertNull(
            $this->categoriesRepository->findById($testCategoryId),
            'findById() should not resolve a soft-deleted category'
        );
    }

    public function test_hasProducts_ignores_soft_deleted_products(): void
    {
        // #367: hasProducts() previously counted soft-deleted products, so a
        // category whose products were all deleted was permanently undeletable.
        $categoriesRepository = $this->categoriesRepository;
        $productsRepository = new \App\Modules\Products\Repositories\ProductsRepository($this->db, $this->logger);

        $testCategoryId = $this->generateUuid();
        $this->testCategoryIds[] = $testCategoryId;
        $categoriesRepository->create([
            'id' => $testCategoryId,
            'names' => ['de' => 'Kategorie mit Produkten', 'en' => 'Category with Products'],
            'is_active' => true,
        ]);

        $testProductId = $this->generateUuid();
        $productsRepository->create([
            'id' => $testProductId,
            'category_id' => $testCategoryId,
            'names' => ['de' => 'Gelöschtes Produkt', 'en' => 'Deleted Product'],
            'price_cents' => 100,
            'is_active' => true,
        ]);

        $this->assertTrue(
            $categoriesRepository->hasProducts($testCategoryId),
            'Category should be reported as having products while the product is active'
        );

        $productsRepository->updateById($testProductId, ['deleted_at' => date('Y-m-d H:i:s')]);
        $this->cleanupTestData('products', [$testProductId]);

        $this->assertFalse(
            $categoriesRepository->hasProducts($testCategoryId),
            'Category should no longer be reported as having products once they are all soft-deleted'
        );
    }

    public function test_getWithProductCount_excludes_soft_deleted_categories(): void
    {
        $testCategoryId = $this->generateUuid();
        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create([
            'id' => $testCategoryId,
            'names' => ['de' => 'Wird gelöscht', 'en' => 'Will be deleted'],
            'is_active' => true,
        ]);

        $this->categoriesRepository->updateById($testCategoryId, ['deleted_at' => date('Y-m-d H:i:s')]);

        $rows = $this->categoriesRepository->getWithProductCount();

        $this->assertNotContains(
            $testCategoryId,
            array_column($rows, 'id'),
            'Soft-deleted category must not appear in getWithProductCount()'
        );
    }

    public function test_getWithProductCount_excludes_soft_deleted_products_from_count(): void
    {
        $productsRepository = new \App\Modules\Products\Repositories\ProductsRepository($this->db, $this->logger);

        $testCategoryId = $this->generateUuid();
        $this->testCategoryIds[] = $testCategoryId;
        $this->categoriesRepository->create([
            'id' => $testCategoryId,
            'names' => ['de' => 'Zählkategorie', 'en' => 'Count Category'],
            'is_active' => true,
        ]);

        $keptProductId = $this->generateUuid();
        $deletedProductId = $this->generateUuid();
        $productsRepository->create([
            'id' => $keptProductId,
            'category_id' => $testCategoryId,
            'names' => ['de' => 'Bleibt', 'en' => 'Kept'],
            'price_cents' => 100,
            'is_active' => true,
        ]);
        $productsRepository->create([
            'id' => $deletedProductId,
            'category_id' => $testCategoryId,
            'names' => ['de' => 'Gelöscht', 'en' => 'Deleted'],
            'price_cents' => 100,
            'is_active' => true,
        ]);

        $productsRepository->updateById($deletedProductId, ['deleted_at' => date('Y-m-d H:i:s')]);

        $rows = $this->categoriesRepository->getWithProductCount();
        $row = null;
        foreach ($rows as $candidate) {
            if ($candidate['id'] === $testCategoryId) {
                $row = $candidate;
                break;
            }
        }

        $this->assertNotNull($row, 'Category should still be listed');
        $this->assertSame(1, (int) $row['product_count'], 'product_count must exclude soft-deleted products');

        $this->cleanupTestData('products', [$keptProductId, $deletedProductId]);
    }
}
