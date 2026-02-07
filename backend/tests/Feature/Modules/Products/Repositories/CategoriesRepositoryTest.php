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
            'display_order' => 999,
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

    public function test_findModifiedSince_returns_empty_for_timestamp_in_future(): void
    {
        // Create test category
        $testCategoryId = $this->generateUuid();
        $testCategory = [
            'id' => $testCategoryId,
            'names' => ['de' => 'Zukunft Test', 'en' => 'Future Test'],
            'display_order' => 998,
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
            'display_order' => 997,
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
            'display_order' => 100,
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
            'display_order' => 101,
            'is_active' => 1,
        ]);

        $this->categoriesRepository->create([
            'id' => $deletedCategoryId,
            'names' => json_encode(['de' => 'Deleted Category', 'en' => 'Deleted Category']),
            'display_order' => 102,
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
}
