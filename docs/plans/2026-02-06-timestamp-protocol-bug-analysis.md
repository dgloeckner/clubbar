# Timestamp Protocol Bug Analysis

**Date**: 2026-02-06

**Status**: Critical Bugs Identified

---

## Executive Summary

The delta sync timestamp protocol has **multiple critical bugs** that cause sync failures:

1. **Backend fallback cursor uses seconds instead of milliseconds** (3 services affected)
2. **Backend query converts milliseconds as if they were seconds** (date overflow to year 57123)
3. **API spec contradicts itself** (response says int, request says ISO 8601 string)
4. **Frontend comments are incorrect** (say ISO 8601 but code uses int)

**Impact:** Delta sync works by accident when data exists, but breaks completely when no rows are returned (fallback case).

---

## Bug 1: Backend Fallback Cursor Uses Seconds

**Affected Files:**
- `backend/src/Modules/Members/Services/MembersService.php:33`
- `backend/src/Modules/Products/Services/CategoriesService.php:30`
- `backend/src/Modules/Products/Services/ProductsService.php:34`

**Code:**
```php
$cursor = !empty($rows)
    ? SyncResultDto::dateToTimestamp(end($rows)['updated_at'])  // ✅ Returns milliseconds
    : time();  // ❌ Returns SECONDS (not milliseconds)
```

**Problem:**
- When rows exist: `SyncResultDto::dateToTimestamp()` returns Unix timestamp in **milliseconds** (e.g., `1738761600000`)
- When no rows: `time()` returns Unix timestamp in **SECONDS** (e.g., `1738761600`)
- Values differ by factor of 1000!

**Example:**
```php
// Case 1: Rows exist
$cursor = 1738761600000;  // 2025-02-06 00:00:00.000 (milliseconds)

// Case 2: No rows (fallback)
$cursor = 1738761600;     // Interpreted as 1970-01-21 02:06:01.600 (milliseconds)
                          // OR: Year 57123 if treated as seconds
```

**Correct Fix:**
```php
$cursor = !empty($rows)
    ? SyncResultDto::dateToTimestamp(end($rows)['updated_at'])
    : (int) (microtime(true) * 1000);  // ✅ Milliseconds
```

---

## Bug 2: Backend Query Misinterprets Milliseconds as Seconds

**Affected File:**
- `backend/src/Modules/Members/Repositories/MembersRepository.php:35`
- (Similar bugs likely in CategoriesRepository.php and ProductsRepository.php)

**Code:**
```php
public function findModifiedSince(int $sinceTimestamp): array
{
    $stmt = $this->db->prepare(
        'SELECT * FROM members WHERE updated_at >= ? ORDER BY updated_at ASC'
    );
    $stmt->execute([date('Y-m-d H:i:s', $sinceTimestamp)]);  // ❌ BUG HERE
    return $stmt->fetchAll();
}
```

**Problem:**
- `$sinceTimestamp` is in **milliseconds** (e.g., `1738761600000`)
- `date('Y-m-d H:i:s', $sinceTimestamp)` expects **seconds**
- PHP interprets `1738761600000` as seconds → converts to year **57123**
- Query: `WHERE updated_at >= '57123-01-07 00:00:00'`
- Result: **No rows match** (all dates are before year 57123)
- Fallback cursor triggered → returns `time()` (seconds) → wrong format
- Next sync fails completely

**Example Failure Scenario:**
```
Sync 1: Terminal sends since=null (first sync)
  → Backend returns members with cursor=1738761600000 (milliseconds)

Sync 2: Terminal sends since=1738761600000
  → Backend converts: date('Y-m-d H:i:s', 1738761600000) → '57123-01-07 00:00:00'
  → Query finds 0 rows (all members updated before year 57123)
  → Fallback: cursor = time() → 1738761600 (SECONDS, wrong format)
  → Terminal stores cursor=1738761600

Sync 3: Terminal sends since=1738761600
  → Backend converts: date('Y-m-d H:i:s', 1738761600) → '2025-02-06 00:00:00' (correct by accident!)
  → Query works
  → Returns cursor=1738761600000 (milliseconds again)

Sync 4: Back to milliseconds → Bug repeats
```

**System only works when:**
- Data changes frequently enough that fallback is never triggered
- First sync after deployment returns rows (no empty response)

**Correct Fix:**
```php
public function findModifiedSince(int $sinceTimestamp): array
{
    // Convert milliseconds to seconds for date()
    $sinceSeconds = (int) ($sinceTimestamp / 1000);

    $stmt = $this->db->prepare(
        'SELECT * FROM members WHERE updated_at >= ? ORDER BY updated_at ASC'
    );
    $stmt->execute([date('Y-m-d H:i:s', $sinceSeconds)]);
    return $stmt->fetchAll();
}
```

---

## Bug 3: API Spec Self-Contradiction

**Affected File:** `api/terminal.yaml`

**Request Parameter (lines 130-139):**
```yaml
- name: since
  in: query
  required: true
  schema:
    type: string           # ❌ Says STRING
    format: date-time      # ❌ Says ISO 8601
  description: |
    ISO 8601 timestamp. Return members with `updated_at >= since`.
    On first sync, use epoch (1970-01-01T00:00:00Z) or current time.
  example: '2025-01-23T14:00:00Z'  # ❌ ISO 8601 example
```

**Response Cursor (lines 922-927):**
```yaml
cursor:
  type: integer          # ✅ Says INTEGER
  format: int64
  description: |
    Next sync cursor (Unix timestamp in milliseconds). Terminal should store this
    and use directly as `since` parameter in next sync request. No parsing needed.
```

**The Contradiction:**
- Response returns `cursor` as **integer (milliseconds)**
- Spec says "use directly as `since` parameter in next sync request"
- But `since` parameter expects **string (ISO 8601)**

**Reality Check:**
- Backend code accepts `int` (milliseconds) for `since`
- Frontend sends `int` (milliseconds) for `since`
- API spec is **wrong** - should document `since` as `type: integer, format: int64`

**Correct API Spec:**
```yaml
- name: since
  in: query
  required: true
  schema:
    type: integer
    format: int64
  description: |
    Unix timestamp in milliseconds. Return items with `updated_at >= since`.
    On first sync, omit parameter or use 0.
    Use the `cursor` value from previous response directly.
  example: 1738761600000
```

---

## Bug 4: Frontend Repository Comments Are Incorrect

**Affected File:** `terminal-frontend/lib/repository/sync_repository.dart`

**Incorrect Comments (lines 47, 57, 67):**
```dart
/// Get last sync cursor for categories (ISO 8601 string from API response)
Future<String?> getLastCategoriesSyncCursor() async {
  return getSyncState('last_categories_sync_cursor');
}

/// Get last sync cursor for members (ISO 8601 string from API response)
Future<String?> getLastMembersSyncCursor() async {
  return getSyncState('last_members_sync_cursor');
}
```

**Actual Behavior:**
- API returns cursor as **integer (milliseconds)**
- Frontend converts to string for storage
- Converts back to int when sending

**Correct Comments:**
```dart
/// Get last sync cursor for categories (Unix timestamp in milliseconds, stored as string)
Future<String?> getLastCategoriesSyncCursor() async {
  return getSyncState('last_categories_sync_cursor');
}

/// Get last sync cursor for members (Unix timestamp in milliseconds, stored as string)
Future<String?> getLastMembersSyncCursor() async {
  return getSyncState('last_members_sync_cursor');
}
```

---

## Root Cause Analysis

### Why System Works (By Accident)

1. **On startup:** Terminal sends `since=null` (first sync)
2. **Backend:** Returns all data + cursor in milliseconds
3. **Terminal:** Stores cursor, sends it back in next sync
4. **Backend Bug 2:** Misinterprets milliseconds as seconds → year 57123
5. **Backend:** No rows match → fallback cursor (Bug 1) returns seconds
6. **Terminal:** Next sync sends cursor in seconds (wrong format)
7. **Backend:** By coincidence, interprets seconds correctly
8. **System:** Works until fallback is triggered again

### Why System Eventually Breaks

- If no data changes for extended period → empty response triggers fallback
- Fallback returns seconds instead of milliseconds
- Next sync breaks the timestamp chain
- Protocol oscillates between seconds and milliseconds
- Unpredictable behavior

---

## Impact Assessment

### Severity: **Critical**

**Current State:**
- System works only when data changes frequently
- Protocol breaks on empty responses (fallback)
- Unpredictable sync behavior
- Data staleness when sync fails

**Affected Components:**
- Member sync (GET /sync/members)
- Category sync (GET /sync/categories)
- Product sync (GET /sync/products)

**User Impact:**
- Terminals may show stale member/product data
- Sync failures not visible to users
- Balance discrepancies if member data stale

---

## Proposed Fixes

### Fix 1: Backend Service Fallback (3 files)

**Files:**
- `backend/src/Modules/Members/Services/MembersService.php`
- `backend/src/Modules/Products/Services/CategoriesService.php`
- `backend/src/Modules/Products/Services/ProductsService.php`

**Change:**
```php
// BEFORE (WRONG)
$cursor = !empty($rows)
    ? SyncResultDto::dateToTimestamp(end($rows)['updated_at'])
    : time();

// AFTER (CORRECT)
$cursor = !empty($rows)
    ? SyncResultDto::dateToTimestamp(end($rows)['updated_at'])
    : (int) (microtime(true) * 1000);
```

### Fix 2: Backend Repository Query (3 files)

**Files:**
- `backend/src/Modules/Members/Repositories/MembersRepository.php`
- `backend/src/Modules/Products/Repositories/CategoriesRepository.php`
- `backend/src/Modules/Products/Repositories/ProductsRepository.php`

**Change:**
```php
// BEFORE (WRONG)
public function findModifiedSince(int $sinceTimestamp): array
{
    $stmt = $this->db->prepare(
        'SELECT * FROM members WHERE updated_at >= ? ORDER BY updated_at ASC'
    );
    $stmt->execute([date('Y-m-d H:i:s', $sinceTimestamp)]);
    return $stmt->fetchAll();
}

// AFTER (CORRECT)
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

### Fix 3: API Spec Correction

**File:** `api/terminal.yaml`

**Update all sync endpoints:**
```yaml
# Members, Categories, Products sync endpoints
parameters:
  - name: since
    in: query
    required: true
    schema:
      type: integer     # CHANGED from string
      format: int64
    description: |
      Unix timestamp in milliseconds. Return items with `updated_at >= since`.
      On first sync, omit parameter or use 0.
      Use the `cursor` value from previous response directly.
    example: 1738761600000  # CHANGED from ISO 8601
```

### Fix 4: Frontend Comment Corrections

**File:** `terminal-frontend/lib/repository/sync_repository.dart`

**Update comments (lines 47, 57, 67):**
```dart
/// Get last sync cursor for categories (Unix timestamp in milliseconds, stored as string)
Future<String?> getLastCategoriesSyncCursor() async {
  return getSyncState('last_categories_sync_cursor');
}

/// Get last sync cursor for members (Unix timestamp in milliseconds, stored as string)
Future<String?> getLastMembersSyncCursor() async {
  return getSyncState('last_members_sync_cursor');
}

/// Get last sync cursor for products (Unix timestamp in milliseconds, stored as string)
Future<String?> getLastProductsSyncCursor() async {
  return getSyncState('last_products_sync_cursor');
}
```

---

## Testing Strategy

### Unit Tests (Backend)

```php
// Test SyncResultDto::dateToTimestamp()
test('dateToTimestamp converts MySQL datetime to milliseconds', function () {
    $timestamp = SyncResultDto::dateToTimestamp('2025-02-06 12:00:00');
    expect($timestamp)->toBe(1738761600000);
});

test('dateToTimestamp fallback returns milliseconds', function () {
    $timestamp = SyncResultDto::dateToTimestamp(null);
    expect($timestamp)->toBeGreaterThan(1700000000000); // After 2023 in milliseconds
    expect($timestamp)->toBeLessThan(2000000000000);    // Before 2033 in milliseconds
});

// Test MembersService::syncSince()
test('syncSince returns cursor in milliseconds when rows exist', function () {
    $result = $this->membersService->syncSince(0);
    expect($result->cursor)->toBeGreaterThan(1700000000000);
});

test('syncSince returns cursor in milliseconds when no rows', function () {
    // Setup: Empty database
    $result = $this->membersService->syncSince(9999999999999);
    expect($result->cursor)->toBeGreaterThan(1700000000000);
});

// Test MembersRepository::findModifiedSince()
test('findModifiedSince accepts milliseconds and converts correctly', function () {
    $sinceMs = 1738761600000; // 2025-02-06 00:00:00 in milliseconds
    $members = $this->membersRepo->findModifiedSince($sinceMs);
    // Should find members updated after 2025-02-06
});
```

### Integration Tests (E2E)

```typescript
// e2etests/tests/api/sync-timestamp-protocol.spec.ts
test('sync cursor uses consistent millisecond format', async () => {
  // Sync 1: First sync
  const response1 = await fetch('/api/sync/members?since=0', {
    headers: { Authorization: `Bearer ${terminalToken}` },
  });
  const data1 = await response1.json();

  expect(data1.cursor).toBeGreaterThan(1700000000000); // Milliseconds
  expect(data1.cursor).toBeLessThan(2000000000000);

  // Sync 2: Use cursor from Sync 1
  const response2 = await fetch(`/api/sync/members?since=${data1.cursor}`, {
    headers: { Authorization: `Bearer ${terminalToken}` },
  });
  const data2 = await response2.json();

  expect(data2.cursor).toBeGreaterThan(data1.cursor); // Monotonically increasing
  expect(data2.cursor).toBeLessThan(2000000000000);   // Still milliseconds
});

test('sync returns consistent cursor when no data changes', async () => {
  // Sync with timestamp far in future (no rows)
  const futureTimestamp = Date.now() + 86400000; // +1 day
  const response = await fetch(`/api/sync/members?since=${futureTimestamp}`, {
    headers: { Authorization: `Bearer ${terminalToken}` },
  });
  const data = await response.json();

  expect(data.members).toHaveLength(0);
  expect(data.cursor).toBeGreaterThan(1700000000000); // Fallback still milliseconds
});
```

---

## Acceptance Criteria

### Bug Fixes Complete When:

1. ✅ Backend fallback cursor uses `microtime(true) * 1000` (milliseconds)
2. ✅ Backend repository query divides timestamp by 1000 before `date()`
3. ✅ API spec documents `since` parameter as `type: integer, format: int64`
4. ✅ Frontend comments updated to reflect Unix milliseconds (not ISO 8601)
5. ✅ All unit tests pass (verify millisecond format)
6. ✅ All E2E tests pass (verify sync protocol works)
7. ✅ Empty response (no rows) returns cursor in correct format
8. ✅ Sync works after extended period with no data changes

---

## Migration/Rollout Plan

### Phase 1: Backend Fixes
1. Fix fallback cursor (3 service files)
2. Fix repository queries (3 repository files)
3. Deploy backend update
4. Verify via unit tests

### Phase 2: Documentation Updates
1. Update API spec (`api/terminal.yaml`)
2. Update frontend comments (`sync_repository.dart`)
3. Commit documentation changes

### Phase 3: Verification
1. Run E2E sync tests
2. Monitor production sync success rate
3. Verify no errors in logs related to date parsing

**No breaking changes:** Frontend already sends/receives int correctly, just comments were wrong.

---

**End of Bug Analysis**
