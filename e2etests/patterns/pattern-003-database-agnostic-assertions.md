# Pattern 003: Database-Agnostic Assertions

**Status**: Established and Verified
**Derived From**: Flaky tests that failed due to assumptions about database state
**Test Coverage**: All 123 E2E tests use this pattern for list/filter assertions

---

## Problem

Tests fail when they make assumptions about database state instead of verifying specific data:

```typescript
// ❌ Problem: Assumes position in array
test('filter returns French members', async ({ authenticatedRequest }) => {
  const response = await authenticatedRequest.get('/api/admin/members?filters[language]=fr');
  const body = await response.json();

  // Assumes filtered member is first in list
  expect(body.items[0].preferred_language).toBe('fr');  // Fails if another member is first

  // Assumes exact count
  expect(body.items.length).toBe(5);  // Fails if database has different count
});

// Failure Scenarios:
// 1. Other tests create members in same language → position changes
// 2. Database is not empty when test runs → count is wrong
// 3. Tests run in different order → different data
```

**Failure Symptoms**:
- "AssertionError: expected undefined to equal 'fr'"
- Tests pass individually but fail in batch
- Different results in parallel vs sequential execution

---

## Solution: Search for Specific Data

Instead of assuming position or count, search for specific records by unique identifier.

### Core Pattern

```typescript
test('filter returns members by language', async ({ authenticatedRequest }) => {
  // Step 1: CREATE test member with specific language
  const createResponse = await authenticatedRequest.post('/api/admin/members', {
    data: {
      first_name: 'FrenchTest',
      email: `fr-${Date.now()}@example.com`,
      preferred_language: 'fr',
    },
  });

  const testMember = await createResponse.json();
  const testMemberId = testMember.id;

  // Step 2: QUERY with filter (may return many members)
  const listResponse = await authenticatedRequest.get(
    '/api/admin/members?filters[language]=fr&limit=100'
  );

  const body = await listResponse.json();

  // Step 3: SEARCH for our specific member by ID (database-agnostic)
  const foundMember = body.items.find(m => m.id === testMemberId);

  // Step 4: VERIFY our specific member
  expect(foundMember).toBeDefined();
  expect(foundMember.preferred_language).toBe('fr');
  expect(foundMember.email).toContain('fr-');

  // Works regardless of:
  // - How many other French members exist
  // - What order they appear in array
  // - How many tests have run
});
```

---

## Implementation Guidelines

### Pattern 1: Searching in Arrays

**❌ Bad: Position-based assertions**
```typescript
const response = await request.get('/api/admin/members');
const items = await response.json();

// Dangerous: assumes specific order
expect(items.items[0].id).toBe(testMemberId);
expect(items.items[0].first_name).toBe('Test');
```

**✅ Good: Search by ID**
```typescript
const response = await request.get('/api/admin/members?limit=100');
const items = await response.json();

// Safe: finds specific member regardless of position
const member = items.items.find(m => m.id === testMemberId);
expect(member).toBeDefined();
expect(member.first_name).toBe('Test');
```

### Pattern 2: Verifying Presence in Filtered Results

```typescript
test('created member appears in filtered list', async ({ authenticatedRequest }) => {
  // Create test member
  const createResponse = await authenticatedRequest.post('/api/admin/members', {
    data: {
      first_name: 'ActiveTest',
      email: `active-${Date.now()}@example.com`,
      preferred_language: 'en',
      is_active: true,
    },
  });

  const member = await createResponse.json();

  // Query with filter
  const listResponse = await authenticatedRequest.get(
    '/api/admin/members?filters[is_active]=true&limit=100'
  );

  const body = await listResponse.json();

  // Search for specific member
  const foundMember = body.items.find(m => m.id === member.id);
  expect(foundMember).toBeDefined();
  expect(foundMember.is_active).toBe(true);
});
```

### Pattern 3: Counting Specific Records

Instead of counting total records, count specific query results:

```typescript
// ❌ Bad: Total count assertion
test('list shows correct count', async ({ authenticatedRequest }) => {
  const response = await authenticatedRequest.get('/api/admin/members');
  const body = await response.json();
  expect(body.total).toBe(5);  // Fails if database has different count
});

// ✅ Good: Verify count of filtered results
test('list returns members in correct format', async ({ authenticatedRequest }) => {
  const response = await authenticatedRequest.get('/api/admin/members?limit=10');
  const body = await response.json();

  // Verify response structure (not absolute count)
  expect(body.items).toBeDefined();
  expect(Array.isArray(body.items)).toBe(true);
  expect(body.total).toBeGreaterThanOrEqual(0);
  expect(body.limit).toBe(10);
  expect(body.offset).toBe(0);

  // All assertions pass regardless of actual member count
});
```

### Pattern 4: Pagination-Safe Assertions

```typescript
test('pagination works correctly', async ({ authenticatedRequest }) => {
  // Create multiple test members
  const members = [];
  for (let i = 0; i < 5; i++) {
    const response = await authenticatedRequest.post('/api/admin/members', {
      data: {
        email: `pagination-${Date.now()}-${i}@example.com`,
        first_name: `Member${i}`,
        preferred_language: 'en',
      },
    });
    members.push(await response.json());
  }

  // Query with limit
  const page1Response = await authenticatedRequest.get(
    '/api/admin/members?limit=3&offset=0'
  );

  const page1 = await page1Response.json();

  // Verify our members appear (don't assume they're the only ones)
  const foundMembers = members.filter(m =>
    page1.items.some(item => item.id === m.id)
  );

  expect(foundMembers.length).toBeGreaterThan(0);
  expect(page1.items.length).toBeLessThanOrEqual(3);
});
```

### Pattern 5: Audit Log Assertions (Real-World Example)

```typescript
test('audit log entry created for member creation', async ({ authenticatedRequest }) => {
  // Create test member
  const createResponse = await authenticatedRequest.post('/api/admin/members', {
    data: {
      first_name: 'AuditTest',
      email: `audit-${Date.now()}@example.com`,
      preferred_language: 'en',
    },
  });

  const member = await createResponse.json();

  // Query audit log (request large limit to ensure we get the entry)
  const auditResponse = await authenticatedRequest.get(
    '/api/admin/audit-log?filters[entity_type]=member&filters[action]=create&limit=100'
  );

  const auditData = await auditResponse.json();

  // Search for audit entry for THIS specific member (database-agnostic)
  const auditEntry = auditData.items.find(entry => entry.entity_id === member.id);

  // Verify the specific entry
  expect(auditEntry).toBeDefined();
  expect(auditEntry.action).toBe('create');
  expect(auditEntry.entity_type).toBe('member');
  expect(auditEntry.new_values.first_name).toBe('AuditTest');
  expect(auditEntry.old_values).toBeNull();

  // Works regardless of other audit entries in database
});
```

---

## Real-World Examples

### Example 1: Filter Multiple Criteria

```typescript
test('filter members by multiple criteria', async ({ authenticatedRequest }) => {
  // Create test member
  const testMember = await createTestMember({
    preferred_language: 'fr',
    is_active: true,
    email: `multi-${Date.now()}@example.com`,
  });

  // Query with multiple filters
  const response = await authenticatedRequest.get(
    '/api/admin/members?filters[language]=fr&filters[is_active]=true&limit=100'
  );

  const body = await response.json();

  // Find our specific member
  const found = body.items.find(m => m.id === testMember.id);

  expect(found).toBeDefined();
  expect(found.preferred_language).toBe('fr');
  expect(found.is_active).toBe(true);
});
```

### Example 2: Date Range Filtering

```typescript
test('filter audit log by date range', async ({ authenticatedRequest }) => {
  // Create test data
  const today = new Date().toISOString().split('T')[0];
  const member = await createTestMember();

  // Create audit entry by modifying member
  await authenticatedRequest.patch(`/api/admin/members/${member.id}`, {
    data: { last_name: 'Corrected' }
  });

  // Query audit log for today
  const response = await authenticatedRequest.get(
    `/api/admin/audit-log?filters[date_from]=${today}&filters[date_to]=${today}&limit=100`
  );

  const body = await response.json();

  // Find audit entry for our member
  const auditEntry = body.items.find(
    entry => entry.entity_id === member.id && entry.action === 'update'
  );

  expect(auditEntry).toBeDefined();
  expect(auditEntry.created_at).toContain(today);
});
```

### Example 3: Verify Deletion

```typescript
test('deleted member not in list', async ({ authenticatedRequest }) => {
  // Create and delete test member
  const member = await createTestMember();
  await authenticatedRequest.delete(`/api/admin/members/${member.id}`);

  // Query members list
  const response = await authenticatedRequest.get('/api/admin/members?limit=100');
  const body = await response.json();

  // Verify deleted member not in results
  const found = body.items.find(m => m.id === member.id);
  expect(found).toBeUndefined();

  // Also verify 404 when fetching directly
  const getResponse = await authenticatedRequest.get(`/api/admin/members/${member.id}`);
  expect(getResponse.status()).toBe(404);
});
```

---

## Verification Checklist

When writing assertions on list/filter endpoints:

- [ ] Are you searching for specific records by ID?
- [ ] Or assuming position in array? (❌ Bad)
- [ ] Are you using `.find()` to search?
- [ ] Or using `[0]` to access first item? (❌ Bad)
- [ ] Is the test independent of database state?
- [ ] Or does it depend on exact count? (❌ Bad)
- [ ] Does the test create its own test data?
- [ ] Or assume seeded data exists? (❌ Bad)

---

## Benefits

✅ **Database-State Independent**: Works with empty or full database
✅ **Order-Independent**: Doesn't care about array order
✅ **Scalable**: Works as database grows
✅ **Reliable**: No flaky assertions
✅ **Maintainable**: Easy to understand test intent
✅ **Parallel-Safe**: Works with multiple tests running simultaneously

---

## Anti-Patterns to Avoid

```typescript
// ❌ DON'T: Assume exact count
expect(body.items.length).toBe(5);

// ✅ DO: Verify boundaries
expect(body.items.length).toBeLessThanOrEqual(body.limit);
expect(body.items.length).toBeGreaterThan(0);

// ❌ DON'T: Access by position
expect(body.items[0].id).toBe(testMemberId);

// ✅ DO: Search by ID
const item = body.items.find(m => m.id === testMemberId);
expect(item).toBeDefined();

// ❌ DON'T: Assume only your data
expect(body.items).toEqual([testData]);

// ✅ DO: Find your data in results
expect(body.items.some(m => m.id === testMemberId.id)).toBe(true);

// ❌ DON'T: Test absolute counts
expect(body.total).toBe(10);

// ✅ DO: Test structure and boundaries
expect(body.total).toBeGreaterThanOrEqual(body.items.length);
expect(body.offset).toBeGreaterThanOrEqual(0);
```

---

## Related Patterns

- [Pattern 001: Test Data Isolation](pattern-001-test-data-isolation.md)
- [Pattern 004: Parallel Execution Safety](pattern-004-parallel-execution-safety.md)
