# Timestamp Protocol Bug Fixes - Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix critical bugs in delta sync timestamp protocol that cause sync failures when no data changes.

**Architecture:** Fix backend services to consistently use milliseconds for cursors, fix repository queries to correctly interpret millisecond timestamps, update API spec to reflect actual integer format, correct frontend documentation comments.

**Tech Stack:** PHP 8.3, PHPUnit, OpenAPI 3.0, Dart/Flutter, Playwright

---

## Background

Delta sync protocol has 4 critical bugs identified in `docs/plans/2026-02-06-timestamp-protocol-bug-analysis.md`:

1. Backend fallback cursor uses `time()` (seconds) instead of `microtime(true) * 1000` (milliseconds)
2. Backend repository queries misinterpret millisecond timestamps as seconds → year 57123
3. API spec contradicts itself (response says int, request says ISO 8601 string)
4. Frontend comments incorrectly say "ISO 8601 string" when code uses Unix milliseconds

**Current Impact:** System works by accident when data changes frequently, breaks on empty responses.

---

## Task 1: Fix Backend Service Fallback (MembersService)

**Files:**
- Modify: `backend/src/Modules/Members/Services/MembersService.php:26-36`
- Test: `backend/tests/Unit/Modules/Members/Services/MembersServiceTest.php`

**Step 1: Write failing test for fallback cursor format**

Add to `backend/tests/Unit/Modules/Members/Services/MembersServiceTest.php`:

```php
public function test_syncSince_returns_cursor_in_milliseconds_when_no_rows(): void
{
    // Mock repository to return empty array (no rows)
    $this->membersRepository
        ->expects($this->once())
        ->method('findModifiedSince')
        ->with($this->anything())
        ->willReturn([]);

    $result = $this->membersService->syncSince(9999999999999);

    // Cursor should be in milliseconds (13 digits, > 1700000000000)
    $this->assertGreaterThan(1700000000000, $result->cursor);
    $this->assertLessThan(2000000000000, $result->cursor);
}
```

**Step 2: Run test to verify it fails**

```bash
cd backend
php artisan test --filter=test_syncSince_returns_cursor_in_milliseconds_when_no_rows
```

Expected: FAIL - cursor is ~10 digits (seconds) not ~13 digits (milliseconds)

**Step 3: Fix MembersService.php fallback cursor**

Modify `backend/src/Modules/Members/Services/MembersService.php` line 31-33:

```php
// BEFORE
$cursor = !empty($rows)
    ? SyncResultDto::dateToTimestamp(end($rows)['updated_at'])
    : time();

// AFTER
$cursor = !empty($rows)
    ? SyncResultDto::dateToTimestamp(end($rows)['updated_at'])
    : (int) (microtime(true) * 1000);
```

**Step 4: Run test to verify it passes**

```bash
php artisan test --filter=test_syncSince_returns_cursor_in_milliseconds_when_no_rows
```

Expected: PASS

**Step 5: Commit**

```bash
git add backend/src/Modules/Members/Services/MembersService.php backend/tests/Unit/Modules/Members/Services/MembersServiceTest.php
git commit -m "fix(backend): use milliseconds in MembersService fallback cursor

Changed time() to microtime(true) * 1000 to return milliseconds consistently.
Fixes Bug #1 from timestamp protocol analysis.

Test: MembersServiceTest::test_syncSince_returns_cursor_in_milliseconds_when_no_rows"
```

---

## Task 2: Fix Backend Service Fallback (CategoriesService)

**Files:**
- Modify: `backend/src/Modules/Products/Services/CategoriesService.php:23-32`
- Test: `backend/tests/Unit/Modules/Products/Services/CategoriesServiceTest.php`

**Step 1: Write failing test for fallback cursor format**

Add to `backend/tests/Unit/Modules/Products/Services/CategoriesServiceTest.php`:

```php
public function test_syncSince_returns_cursor_in_milliseconds_when_no_rows(): void
{
    $this->categoriesRepository
        ->expects($this->once())
        ->method('findModifiedSince')
        ->with($this->anything())
        ->willReturn([]);

    $result = $this->categoriesService->syncSince(9999999999999);

    $this->assertGreaterThan(1700000000000, $result->cursor);
    $this->assertLessThan(2000000000000, $result->cursor);
}
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --filter=test_syncSince_returns_cursor_in_milliseconds_when_no_rows
```

Expected: FAIL

**Step 3: Fix CategoriesService.php fallback cursor**

Modify `backend/src/Modules/Products/Services/CategoriesService.php` line 28-30:

```php
// BEFORE
$cursor = !empty($rows)
    ? SyncResultDto::dateToTimestamp(end($rows)['updated_at'])
    : time();

// AFTER
$cursor = !empty($rows)
    ? SyncResultDto::dateToTimestamp(end($rows)['updated_at'])
    : (int) (microtime(true) * 1000);
```

**Step 4: Run test to verify it passes**

```bash
php artisan test --filter=test_syncSince_returns_cursor_in_milliseconds_when_no_rows
```

Expected: PASS

**Step 5: Commit**

```bash
git add backend/src/Modules/Products/Services/CategoriesService.php backend/tests/Unit/Modules/Products/Services/CategoriesServiceTest.php
git commit -m "fix(backend): use milliseconds in CategoriesService fallback cursor

Changed time() to microtime(true) * 1000 to return milliseconds consistently.
Fixes Bug #1 from timestamp protocol analysis.

Test: CategoriesServiceTest::test_syncSince_returns_cursor_in_milliseconds_when_no_rows"
```

---

## Task 3: Fix Backend Service Fallback (ProductsService)

**Files:**
- Modify: `backend/src/Modules/Products/Services/ProductsService.php:26-35`
- Test: `backend/tests/Unit/Modules/Products/Services/ProductsServiceTest.php`

**Step 1: Write failing test for fallback cursor format**

Add to `backend/tests/Unit/Modules/Products/Services/ProductsServiceTest.php`:

```php
public function test_syncSince_returns_cursor_in_milliseconds_when_no_rows(): void
{
    $this->productsRepository
        ->expects($this->once())
        ->method('findModifiedSince')
        ->with($this->anything())
        ->willReturn([]);

    $result = $this->productsService->syncSince(9999999999999);

    $this->assertGreaterThan(1700000000000, $result->cursor);
    $this->assertLessThan(2000000000000, $result->cursor);
}
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --filter=test_syncSince_returns_cursor_in_milliseconds_when_no_rows
```

Expected: FAIL

**Step 3: Fix ProductsService.php fallback cursor**

Modify `backend/src/Modules/Products/Services/ProductsService.php` line 31-33:

```php
// BEFORE
$cursor = !empty($rows)
    ? SyncResultDto::dateToTimestamp(end($rows)['updated_at'])
    : time();

// AFTER
$cursor = !empty($rows)
    ? SyncResultDto::dateToTimestamp(end($rows)['updated_at'])
    : (int) (microtime(true) * 1000);
```

**Step 4: Run test to verify it passes**

```bash
php artisan test --filter=test_syncSince_returns_cursor_in_milliseconds_when_no_rows
```

Expected: PASS

**Step 5: Commit**

```bash
git add backend/src/Modules/Products/Services/ProductsService.php backend/tests/Unit/Modules/Products/Services/ProductsServiceTest.php
git commit -m "fix(backend): use milliseconds in ProductsService fallback cursor

Changed time() to microtime(true) * 1000 to return milliseconds consistently.
Fixes Bug #1 from timestamp protocol analysis.

Test: ProductsServiceTest::test_syncSince_returns_cursor_in_milliseconds_when_no_rows"
```

---

## Task 4: Fix Backend Repository Query (MembersRepository)

**Files:**
- Modify: `backend/src/Modules/Members/Repositories/MembersRepository.php:30-37`
- Test: `backend/tests/Unit/Modules/Members/Repositories/MembersRepositoryTest.php`

**Step 1: Write failing test for millisecond timestamp handling**

Add to `backend/tests/Unit/Modules/Members/Repositories/MembersRepositoryTest.php`:

```php
public function test_findModifiedSince_accepts_milliseconds_and_converts_correctly(): void
{
    // Create test member with known timestamp
    $testMember = [
        'id' => $this->generateUuid(),
        'card_uid' => '04:AA:BB:CC:DD:EE:FF',
        'first_name' => 'Test',
        'last_name' => 'User',
        'preferred_language' => 'de',
        'is_active' => 1,
        'iban' => 'DE89370400440532013000',
        'mandate_reference' => 'MANDATE123',
        'mandate_signed_at' => '2025-01-01',
    ];
    $this->membersRepository->create($testMember);

    // Get member's updated_at timestamp
    $member = $this->membersRepository->findById($testMember['id']);
    $updatedAt = new \DateTime($member['updated_at']);

    // Convert to milliseconds and subtract 1 second
    $sinceMs = ($updatedAt->getTimestamp() - 1) * 1000;

    // Query with milliseconds
    $results = $this->membersRepository->findModifiedSince($sinceMs);

    // Should find the test member
    $this->assertCount(1, $results);
    $this->assertEquals($testMember['id'], $results[0]['id']);
}
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --filter=test_findModifiedSince_accepts_milliseconds_and_converts_correctly
```

Expected: FAIL - No rows returned (query date is year 57123)

**Step 3: Fix MembersRepository.php query**

Modify `backend/src/Modules/Members/Repositories/MembersRepository.php` line 30-36:

```php
// BEFORE
public function findModifiedSince(int $sinceTimestamp): array
{
    $stmt = $this->db->prepare(
        'SELECT * FROM members WHERE updated_at >= ? ORDER BY updated_at ASC'
    );
    $stmt->execute([date('Y-m-d H:i:s', $sinceTimestamp)]);
    return $stmt->fetchAll();
}

// AFTER
public function findModifiedSince(int $sinceTimestamp): array
{
    // Convert milliseconds to seconds for date() function
    $sinceSeconds = (int) ($sinceTimestamp / 1000);

    $stmt = $this->db->prepare(
        'SELECT * FROM members WHERE updated_at >= ? ORDER BY updated_at ASC'
    );
    $stmt->execute([date('Y-m-d H:i:s', $sinceSeconds)]);
    return $stmt->fetchAll();
}
```

**Step 4: Run test to verify it passes**

```bash
php artisan test --filter=test_findModifiedSince_accepts_milliseconds_and_converts_correctly
```

Expected: PASS

**Step 5: Commit**

```bash
git add backend/src/Modules/Members/Repositories/MembersRepository.php backend/tests/Unit/Modules/Members/Repositories/MembersRepositoryTest.php
git commit -m "fix(backend): convert milliseconds to seconds in MembersRepository query

Added division by 1000 before date() call to correctly interpret millisecond timestamps.
Fixes Bug #2 from timestamp protocol analysis (year 57123 issue).

Test: MembersRepositoryTest::test_findModifiedSince_accepts_milliseconds_and_converts_correctly"
```

---

## Task 5: Fix Backend Repository Query (CategoriesRepository)

**Files:**
- Modify: `backend/src/Modules/Products/Repositories/CategoriesRepository.php` (findModifiedSince method)
- Test: `backend/tests/Unit/Modules/Products/Repositories/CategoriesRepositoryTest.php`

**Step 1: Write failing test for millisecond timestamp handling**

Add to `backend/tests/Unit/Modules/Products/Repositories/CategoriesRepositoryTest.php`:

```php
public function test_findModifiedSince_accepts_milliseconds_and_converts_correctly(): void
{
    $testCategory = [
        'id' => $this->generateUuid(),
        'names' => json_encode(['de' => 'Test Kategorie', 'en' => 'Test Category']),
        'display_order' => 1,
        'is_active' => 1,
    ];
    $this->categoriesRepository->create($testCategory);

    $category = $this->categoriesRepository->findById($testCategory['id']);
    $updatedAt = new \DateTime($category['updated_at']);
    $sinceMs = ($updatedAt->getTimestamp() - 1) * 1000;

    $results = $this->categoriesRepository->findModifiedSince($sinceMs);

    $this->assertCount(1, $results);
    $this->assertEquals($testCategory['id'], $results[0]['id']);
}
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --filter=test_findModifiedSince_accepts_milliseconds_and_converts_correctly
```

Expected: FAIL

**Step 3: Fix CategoriesRepository.php query**

Modify `backend/src/Modules/Products/Repositories/CategoriesRepository.php` findModifiedSince method:

```php
public function findModifiedSince(int $sinceTimestamp): array
{
    // Convert milliseconds to seconds for date() function
    $sinceSeconds = (int) ($sinceTimestamp / 1000);

    $stmt = $this->db->prepare(
        'SELECT * FROM categories WHERE updated_at >= ? ORDER BY updated_at ASC'
    );
    $stmt->execute([date('Y-m-d H:i:s', $sinceSeconds)]);
    return $stmt->fetchAll();
}
```

**Step 4: Run test to verify it passes**

```bash
php artisan test --filter=test_findModifiedSince_accepts_milliseconds_and_converts_correctly
```

Expected: PASS

**Step 5: Commit**

```bash
git add backend/src/Modules/Products/Repositories/CategoriesRepository.php backend/tests/Unit/Modules/Products/Repositories/CategoriesRepositoryTest.php
git commit -m "fix(backend): convert milliseconds to seconds in CategoriesRepository query

Added division by 1000 before date() call to correctly interpret millisecond timestamps.
Fixes Bug #2 from timestamp protocol analysis.

Test: CategoriesRepositoryTest::test_findModifiedSince_accepts_milliseconds_and_converts_correctly"
```

---

## Task 6: Fix Backend Repository Query (ProductsRepository)

**Files:**
- Modify: `backend/src/Modules/Products/Repositories/ProductsRepository.php` (findModifiedSince method)
- Test: `backend/tests/Unit/Modules/Products/Repositories/ProductsRepositoryTest.php`

**Step 1: Write failing test for millisecond timestamp handling**

Add to `backend/tests/Unit/Modules/Products/Repositories/ProductsRepositoryTest.php`:

```php
public function test_findModifiedSince_accepts_milliseconds_and_converts_correctly(): void
{
    // Assume test category exists from fixture
    $categoryId = $this->getTestCategoryId();

    $testProduct = [
        'id' => $this->generateUuid(),
        'category_id' => $categoryId,
        'names' => json_encode(['de' => 'Test Produkt', 'en' => 'Test Product']),
        'descriptions' => json_encode(['de' => 'Beschreibung', 'en' => 'Description']),
        'price_cents' => 350,
        'is_active' => 1,
    ];
    $this->productsRepository->create($testProduct);

    $product = $this->productsRepository->findById($testProduct['id']);
    $updatedAt = new \DateTime($product['updated_at']);
    $sinceMs = ($updatedAt->getTimestamp() - 1) * 1000;

    $results = $this->productsRepository->findModifiedSince($sinceMs);

    $this->assertCount(1, $results);
    $this->assertEquals($testProduct['id'], $results[0]['id']);
}
```

**Step 2: Run test to verify it fails**

```bash
php artisan test --filter=test_findModifiedSince_accepts_milliseconds_and_converts_correctly
```

Expected: FAIL

**Step 3: Fix ProductsRepository.php query**

Modify `backend/src/Modules/Products/Repositories/ProductsRepository.php` findModifiedSince method:

```php
public function findModifiedSince(int $sinceTimestamp): array
{
    // Convert milliseconds to seconds for date() function
    $sinceSeconds = (int) ($sinceTimestamp / 1000);

    $stmt = $this->db->prepare(
        'SELECT * FROM products WHERE updated_at >= ? ORDER BY updated_at ASC'
    );
    $stmt->execute([date('Y-m-d H:i:s', $sinceSeconds)]);
    return $stmt->fetchAll();
}
```

**Step 4: Run test to verify it passes**

```bash
php artisan test --filter=test_findModifiedSince_accepts_milliseconds_and_converts_correctly
```

Expected: PASS

**Step 5: Commit**

```bash
git add backend/src/Modules/Products/Repositories/ProductsRepository.php backend/tests/Unit/Modules/Products/Repositories/ProductsRepositoryTest.php
git commit -m "fix(backend): convert milliseconds to seconds in ProductsRepository query

Added division by 1000 before date() call to correctly interpret millisecond timestamps.
Fixes Bug #2 from timestamp protocol analysis.

Test: ProductsRepositoryTest::test_findModifiedSince_accepts_milliseconds_and_converts_correctly"
```

---

## Task 7: Restart PHP-FPM and Verify Backend

**Files:**
- None (verification task)

**Step 1: Restart PHP-FPM to apply code changes**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
sleep 2
```

**Step 2: Run all backend tests to verify no regressions**

```bash
cd backend
php artisan test
```

Expected: All tests PASS

**Step 3: Verify health endpoint**

```bash
curl -s http://localhost:8080/api/health | jq .
```

Expected: `{"status": "ok"}`

---

## Task 8: Update API Spec (Members Sync Endpoint)

**Files:**
- Modify: `api/terminal.yaml:130-139`

**Step 1: Update members sync `since` parameter definition**

Modify `api/terminal.yaml` lines 130-139:

```yaml
# BEFORE
- name: since
  in: query
  required: true
  schema:
    type: string
    format: date-time
  description: |
    ISO 8601 timestamp. Return members with `updated_at >= since`.
    On first sync, use epoch (1970-01-01T00:00:00Z) or current time.
  example: '2025-01-23T14:00:00Z'

# AFTER
- name: since
  in: query
  required: true
  schema:
    type: integer
    format: int64
  description: |
    Unix timestamp in milliseconds. Return members with `updated_at >= since`.
    On first sync, omit parameter or use 0.
    Use the `cursor` value from previous response directly.
  example: 1738761600000
```

**Step 2: Commit**

```bash
git add api/terminal.yaml
git commit -m "fix(api): correct members sync 'since' parameter to integer

Changed from 'string (ISO 8601)' to 'integer (Unix milliseconds)' to match
actual backend implementation and response cursor format.
Fixes Bug #3 from timestamp protocol analysis.

The response already returns cursor as integer, and backend expects integer.
This corrects the API spec to match reality."
```

---

## Task 9: Update API Spec (Categories Sync Endpoint)

**Files:**
- Modify: `api/terminal.yaml:186-195`

**Step 1: Update categories sync `since` parameter definition**

Modify `api/terminal.yaml` lines 186-195:

```yaml
# BEFORE
- name: since
  in: query
  required: true
  schema:
    type: string
    format: date-time
  description: |
    ISO 8601 timestamp. Return categories with `updated_at >= since`.
    On first sync, use epoch (1970-01-01T00:00:00Z) or current time.
  example: '2025-01-23T14:00:00Z'

# AFTER
- name: since
  in: query
  required: true
  schema:
    type: integer
    format: int64
  description: |
    Unix timestamp in milliseconds. Return categories with `updated_at >= since`.
    On first sync, omit parameter or use 0.
    Use the `cursor` value from previous response directly.
  example: 1738761600000
```

**Step 2: Commit**

```bash
git add api/terminal.yaml
git commit -m "fix(api): correct categories sync 'since' parameter to integer

Changed from 'string (ISO 8601)' to 'integer (Unix milliseconds)' to match
actual backend implementation and response cursor format.
Fixes Bug #3 from timestamp protocol analysis."
```

---

## Task 10: Update API Spec (Products Sync Endpoint)

**Files:**
- Modify: `api/terminal.yaml` (products sync endpoint `since` parameter)

**Step 1: Find and update products sync `since` parameter**

Search for `/sync/products` endpoint in `api/terminal.yaml` and update `since` parameter:

```yaml
# BEFORE
- name: since
  in: query
  required: true
  schema:
    type: string
    format: date-time
  description: |
    ISO 8601 timestamp. Return products with `updated_at >= since`.
    On first sync, use epoch (1970-01-01T00:00:00Z) or current time.
  example: '2025-01-23T14:00:00Z'

# AFTER
- name: since
  in: query
  required: true
  schema:
    type: integer
    format: int64
  description: |
    Unix timestamp in milliseconds. Return products with `updated_at >= since`.
    On first sync, omit parameter or use 0.
    Use the `cursor` value from previous response directly.
  example: 1738761600000
```

**Step 2: Commit**

```bash
git add api/terminal.yaml
git commit -m "fix(api): correct products sync 'since' parameter to integer

Changed from 'string (ISO 8601)' to 'integer (Unix milliseconds)' to match
actual backend implementation and response cursor format.
Fixes Bug #3 from timestamp protocol analysis."
```

---

## Task 11: Update Frontend Comments (SyncRepository)

**Files:**
- Modify: `terminal-frontend/lib/repository/sync_repository.dart:47,57,67`

**Step 1: Update method documentation comments**

Modify `terminal-frontend/lib/repository/sync_repository.dart`:

```dart
// BEFORE (line 47)
/// Get last sync cursor for categories (ISO 8601 string from API response)
Future<String?> getLastCategoriesSyncCursor() async {
  return getSyncState('last_categories_sync_cursor');
}

// AFTER
/// Get last sync cursor for categories (Unix timestamp in milliseconds, stored as string)
Future<String?> getLastCategoriesSyncCursor() async {
  return getSyncState('last_categories_sync_cursor');
}

// BEFORE (line 57)
/// Get last sync cursor for members (ISO 8601 string from API response)
Future<String?> getLastMembersSyncCursor() async {
  return getSyncState('last_members_sync_cursor');
}

// AFTER
/// Get last sync cursor for members (Unix timestamp in milliseconds, stored as string)
Future<String?> getLastMembersSyncCursor() async {
  return getSyncState('last_members_sync_cursor');
}

// BEFORE (line 67)
/// Get last sync cursor for products (ISO 8601 string from API response)
Future<String?> getLastProductsSyncCursor() async {
  return getSyncState('last_products_sync_cursor');
}

// AFTER
/// Get last sync cursor for products (Unix timestamp in milliseconds, stored as string)
Future<String?> getLastProductsSyncCursor() async {
  return getSyncState('last_products_sync_cursor');
}
```

**Step 2: Commit**

```bash
git add terminal-frontend/lib/repository/sync_repository.dart
git commit -m "fix(frontend): correct sync cursor documentation comments

Changed from 'ISO 8601 string' to 'Unix timestamp in milliseconds, stored as string'
to accurately reflect the actual data format.
Fixes Bug #4 from timestamp protocol analysis.

Code behavior unchanged - only comments corrected."
```

---

## Task 12: Create E2E Test for Timestamp Protocol

**Files:**
- Create: `e2etests/tests/api/sync-timestamp-protocol.spec.ts`

**Step 1: Create E2E test file**

Create `e2etests/tests/api/sync-timestamp-protocol.spec.ts`:

```typescript
import { test, expect } from '@playwright/test';
import { authenticateTerminal } from '../../fixtures/auth.fixture';

test.describe('Sync Timestamp Protocol', () => {
  let terminalToken: string;

  test.beforeAll(async () => {
    terminalToken = await authenticateTerminal();
  });

  test('member sync returns cursor in milliseconds (rows exist)', async ({ request }) => {
    const response = await request.get('http://localhost:8080/api/sync/members?since=0', {
      headers: { Authorization: `Bearer ${terminalToken}` },
    });

    expect(response.status()).toBe(200);
    const data = await response.json();

    // Cursor should be 13-digit Unix milliseconds (between 2023-2033)
    expect(data.cursor).toBeGreaterThan(1700000000000);
    expect(data.cursor).toBeLessThan(2000000000000);
  });

  test('member sync returns cursor in milliseconds (no rows - fallback)', async ({ request }) => {
    // Request with timestamp far in future (no rows will match)
    const futureTimestamp = Date.now() + 86400000; // +1 day

    const response = await request.get(
      `http://localhost:8080/api/sync/members?since=${futureTimestamp}`,
      { headers: { Authorization: `Bearer ${terminalToken}` } }
    );

    expect(response.status()).toBe(200);
    const data = await response.json();

    expect(data.members).toHaveLength(0);
    // Fallback cursor should still be in milliseconds
    expect(data.cursor).toBeGreaterThan(1700000000000);
    expect(data.cursor).toBeLessThan(2000000000000);
  });

  test('member sync accepts millisecond cursor from previous response', async ({ request }) => {
    // Sync 1: Get initial cursor
    const response1 = await request.get('http://localhost:8080/api/sync/members?since=0', {
      headers: { Authorization: `Bearer ${terminalToken}` },
    });
    const data1 = await response1.json();

    // Sync 2: Use cursor from Sync 1
    const response2 = await request.get(
      `http://localhost:8080/api/sync/members?since=${data1.cursor}`,
      { headers: { Authorization: `Bearer ${terminalToken}` } }
    );

    expect(response2.status()).toBe(200);
    const data2 = await response2.json();

    // Cursor should be monotonically increasing or equal
    expect(data2.cursor).toBeGreaterThanOrEqual(data1.cursor);
    expect(data2.cursor).toBeLessThan(2000000000000);
  });

  test('category sync returns consistent millisecond format', async ({ request }) => {
    const response = await request.get('http://localhost:8080/api/sync/categories?since=0', {
      headers: { Authorization: `Bearer ${terminalToken}` },
    });

    expect(response.status()).toBe(200);
    const data = await response.json();

    expect(data.cursor).toBeGreaterThan(1700000000000);
    expect(data.cursor).toBeLessThan(2000000000000);
  });

  test('product sync returns consistent millisecond format', async ({ request }) => {
    const response = await request.get('http://localhost:8080/api/sync/products?since=0', {
      headers: { Authorization: `Bearer ${terminalToken}` },
    });

    expect(response.status()).toBe(200);
    const data = await response.json();

    expect(data.cursor).toBeGreaterThan(1700000000000);
    expect(data.cursor).toBeLessThan(2000000000000);
  });
});
```

**Step 2: Run E2E test to verify all fixes**

```bash
cd e2etests
npm test -- tests/api/sync-timestamp-protocol.spec.ts --workers=4
```

Expected: All tests PASS

**Step 3: Commit**

```bash
git add e2etests/tests/api/sync-timestamp-protocol.spec.ts
git commit -m "test(e2e): add timestamp protocol verification tests

Tests verify:
- Cursor returned in milliseconds when rows exist
- Cursor returned in milliseconds in fallback (no rows)
- Cursor from previous response accepted correctly
- All sync endpoints (members, categories, products) consistent

Verifies fixes for timestamp protocol bugs #1-4."
```

---

## Task 13: Run Full Test Suite and Verify

**Files:**
- None (verification task)

**Step 1: Run full backend test suite**

```bash
cd backend
php artisan test
```

Expected: All tests PASS

**Step 2: Run full E2E test suite**

```bash
cd e2etests
npm test -- --workers=4
```

Expected: All tests PASS

**Step 3: Check backend logs for errors**

```bash
docker compose exec backend tail -100 /app/storage/logs/laravel.log | grep -i error
```

Expected: No timestamp-related errors

**Step 4: Manual smoke test - trigger sync cycle**

```bash
# From terminal frontend (if running), trigger manual sync
# OR: Wait for periodic sync cycle (60 seconds)
# Monitor logs to verify sync completes successfully
```

Expected: Sync completes without errors, cursors stored correctly

---

## Acceptance Criteria

### All Fixes Complete When:

1. ✅ Backend fallback cursor uses `microtime(true) * 1000` (3 services)
2. ✅ Backend repository queries divide timestamp by 1000 (3 repositories)
3. ✅ API spec documents `since` as `type: integer, format: int64` (3 endpoints)
4. ✅ Frontend comments say "Unix milliseconds" not "ISO 8601" (3 methods)
5. ✅ All unit tests pass (6 new tests for fallback + query fixes)
6. ✅ All E2E tests pass (new timestamp protocol test suite)
7. ✅ Sync protocol works correctly when no data changes (fallback case)
8. ✅ No errors in backend logs related to date parsing

---

## Rollback Plan

If issues arise after deployment:

1. **Revert commits in reverse order** (Task 13 → Task 1)
2. **Restart PHP-FPM** to load previous code
3. **Verify terminals still sync** with old backend
4. **Investigate issue** before re-attempting fixes

**Note:** No database schema changes, so rollback is safe.

---

## Post-Deployment Verification

Monitor for 24 hours after deployment:

1. **Backend logs:** No timestamp-related errors
2. **Sync success rate:** Should remain 100% (or improve)
3. **Terminal sync cycles:** Verify cursors stored correctly in SQLite
4. **Empty response scenario:** Create test with no data changes, verify fallback works

---

**End of Implementation Plan**
