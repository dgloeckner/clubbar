# Pattern 004: Parallel Execution Safety

**Status**: Established and Verified
**Derived From**: Multi-worker test execution requiring robust isolation
**Test Coverage**: All 123 E2E tests pass with 6 parallel workers

---

## Problem

When tests run in parallel, they can interfere with each other:

```typescript
// Scenario: Tests running on workers 1-6 simultaneously

// Worker 1: Creates member with ID 'abc123'
test('test A', async ({ authenticatedRequest }) => {
  const member = await authenticatedRequest.post('/api/admin/members', {
    data: { email: 'test@example.com' }
  });
  const memberId = member.id;  // 'abc123'
});

// Worker 2: Creates member with SAME email (race condition!)
test('test B', async ({ authenticatedRequest }) => {
  const member = await authenticatedRequest.post('/api/admin/members', {
    data: { email: 'test@example.com' }  // ❌ Duplicate email!
  });
});

// Worker 3: Modifies seeded member that Worker 5 is reading
test('test C', async ({ authenticatedRequest }) => {
  await authenticatedRequest.patch('/api/admin/members/shared-id', {
    data: { is_active: false }  // ❌ Breaks test E!
  });
});

// Worker 5: Expects seeded member to be active
test('test E', async ({ authenticatedRequest }) => {
  const member = await authenticatedRequest.get('/api/admin/members/shared-id');
  expect(member.is_active).toBe(true);  // ❌ Fails because test C modified it!
});
```

**Symptoms of Unsafe Parallel Tests**:
- Tests pass individually but fail in batch
- Tests pass with `--workers=1` but fail with `--workers=6`
- Intermittent failures (race conditions)
- Different results on different runs

---

## Solution: Complete Test Isolation

Design tests so they work safely in parallel by following these principles:

### Core Principle: Each Test is Independent

```typescript
// ✅ Good: Completely independent tests
test('test 1', async ({ authenticatedRequest }) => {
  // Each test creates completely unique data
  const member1 = await createTestMember({ email: `test1-${Date.now()}@ex.com` });
  // Test uses member1
});

test('test 2', async ({ authenticatedRequest }) => {
  // Independent test data
  const member2 = await createTestMember({ email: `test2-${Date.now()}@ex.com` });
  // Test uses member2
});

// Both tests can run on different workers without interference
```

---

## Implementation Guidelines

### Rule 1: Unique Data per Test

```typescript
// ❌ Bad: Shared static email
test('create member 1', async ({ authenticatedRequest }) => {
  const m = await authenticatedRequest.post('/api/admin/members', {
    data: { email: 'shared@example.com' }  // Same in every run!
  });
});

test('create member 2', async ({ authenticatedRequest }) => {
  const m = await authenticatedRequest.post('/api/admin/members', {
    data: { email: 'shared@example.com' }  // Collision!
  });
});

// Race condition: Which test creates first?
// Second test fails with "email already exists"

// ✅ Good: Unique data per test
test('create member 1', async ({ authenticatedRequest }) => {
  const m = await authenticatedRequest.post('/api/admin/members', {
    data: { email: `test1-${Date.now()}@example.com` }  // Unique per run
  });
});

test('create member 2', async ({ authenticatedRequest }) => {
  const m = await authenticatedRequest.post('/api/admin/members', {
    data: { email: `test2-${Date.now()}@example.com` }  // Unique per run
  });
});

// Both always succeed because emails don't collide
```

### Rule 2: No Shared Test State

```typescript
// ❌ Bad: Tests share global state
let sharedMemberId;

beforeAll(async () => {
  sharedMemberId = await createMember();
});

test('test 1', async ({ authenticatedRequest }) => {
  const m = await authenticatedRequest.get(`/api/admin/members/${sharedMemberId}`);
  // Race condition: What if test 2 deletes this member?
});

test('test 2', async ({ authenticatedRequest }) => {
  await authenticatedRequest.delete(`/api/admin/members/${sharedMemberId}`);
});

// ❌ Bad: Tests depend on execution order
test('create member', async ({ authenticatedRequest }) => {
  globalMember = await authenticatedRequest.post('/api/admin/members', {...});
});

test('use member', async ({ authenticatedRequest }) => {
  // Depends on "create member" running first!
  const m = await authenticatedRequest.get(`/api/admin/members/${globalMember.id}`);
});

// ✅ Good: Each test is self-contained
test('test 1', async ({ authenticatedRequest }) => {
  const member = await createTestMember();  // Local to this test
  const m = await authenticatedRequest.get(`/api/admin/members/${member.id}`);
  expect(m.ok()).toBeTruthy();
});

test('test 2', async ({ authenticatedRequest }) => {
  const member = await createTestMember();  // Independent
  await authenticatedRequest.delete(`/api/admin/members/${member.id}`);
  const m = await authenticatedRequest.get(`/api/admin/members/${member.id}`);
  expect(m.status()).toBe(404);
});

// Both can run on different workers without issues
```

### Rule 3: Database-Level Constraints Prevent Race Conditions

Rely on database constraints rather than test coordination:

```typescript
// ✅ Good: Email uniqueness constraint prevents duplicates
test('email must be unique', async ({ authenticatedRequest }) => {
  const email = `unique-${Date.now()}@example.com`;

  // First create succeeds
  const create1 = await authenticatedRequest.post('/api/admin/members', {
    data: { email }
  });
  expect(create1.ok()).toBeTruthy();

  // Second create with same email fails (constraint)
  const create2 = await authenticatedRequest.post('/api/admin/members', {
    data: { email }
  });
  expect(create2.status()).toBe(422);  // Validation error
});

// Even if this test runs 10 times in parallel, constraint handles it
```

### Rule 4: Immutable Database State

Fresh database before each test run ensures clean state:

```typescript
// Setup (before all tests)
// docker compose down -v                    (remove old volume)
// docker compose up -d                      (fresh containers)
// install.php?action=migrate                (create schema)
// install.php?action=seed                   (seed base data)

// Now run tests
// ✅ All tests start with clean database
// ✅ No stale data from previous runs
// ✅ No cleanup needed between tests
```

---

## Parallel Execution Configuration

### Playwright Config

```typescript
// playwright.config.ts
export default defineConfig({
  testDir: './tests',

  // Parallel execution settings
  fullyParallel: true,          // Run all tests in parallel
  workers: process.env.CI ? 1 : undefined,  // 1 worker in CI, default (6) locally

  // Retry failed tests
  retries: process.env.CI ? 2 : 0,

  // Other config...
});
```

### Running Tests in Parallel

```bash
# Default: Use system CPU count (usually 6 workers)
npx playwright test

# Explicit worker count
npx playwright test --workers=4
npx playwright test --workers=6

# Sequential (1 worker) for debugging
npx playwright test --workers=1
```

### Debugging Parallel Issues

```bash
# If tests fail in parallel but pass sequentially
npx playwright test --workers=1          # Try sequential
npx playwright test --workers=2          # Try 2 workers
npx playwright test --headed --workers=1 # See what's happening
npx playwright test --debug               # Step through
```

---

## Real-World Parallel Test Examples

### Example 1: Concurrent Member Creation

```typescript
test('concurrent member creation 1', async ({ authenticatedRequest }) => {
  // Each test creates unique member
  const response = await authenticatedRequest.post('/api/admin/members', {
    data: {
      email: `concurrent1-${Date.now()}-${Math.random()}@example.com`,
      first_name: 'Test1',
    },
  });

  expect(response.ok()).toBeTruthy();
  const member = await response.json();
  expect(member.first_name).toBe('Test1');
});

test('concurrent member creation 2', async ({ authenticatedRequest }) => {
  // Independent data
  const response = await authenticatedRequest.post('/api/admin/members', {
    data: {
      email: `concurrent2-${Date.now()}-${Math.random()}@example.com`,
      first_name: 'Test2',
    },
  });

  expect(response.ok()).toBeTruthy();
  const member = await response.json();
  expect(member.first_name).toBe('Test2');
});

// Can run simultaneously - no conflicts
```

### Example 2: Concurrent Transactions

```typescript
test('transaction upload 1', async ({ request }) => {
  const response = await request.post('/api/sync/transactions', {
    headers: authHeaders,
    data: {
      transactions: [
        {
          id: randomUUID(),
          member_id: '123...',
          product_id: '456...',
          amount_cents: 350,
          created_at: new Date().toISOString(),
        },
      ],
    },
  });

  expect(response.ok()).toBeTruthy();
});

test('transaction upload 2', async ({ request }) => {
  const response = await request.post('/api/sync/transactions', {
    headers: authHeaders,
    data: {
      transactions: [
        {
          id: randomUUID(),  // Different UUID
          member_id: '789...',
          product_id: '012...',
          amount_cents: 500,
          created_at: new Date().toISOString(),
        },
      ],
    },
  });

  expect(response.ok()).toBeTruthy();
});

// UUIDs ensure no collision even in parallel
```

### Example 3: Audit Log Concurrent Verification

```typescript
test('audit log - create 1', async ({ authenticatedRequest }) => {
  const member = await createTestMember({ email: `audit1-${Date.now()}@ex.com` });

  const auditResponse = await authenticatedRequest.get(
    '/api/admin/audit-log?filters[entity_type]=member&filters[action]=create&limit=100'
  );

  const body = await auditResponse.json();
  const entry = body.items.find(e => e.entity_id === member.id);

  expect(entry).toBeDefined();
  expect(entry.new_values.email).toContain('audit1');
});

test('audit log - create 2', async ({ authenticatedRequest }) => {
  const member = await createTestMember({ email: `audit2-${Date.now()}@ex.com` });

  const auditResponse = await authenticatedRequest.get(
    '/api/admin/audit-log?filters[entity_type]=member&filters[action]=create&limit=100'
  );

  const body = await auditResponse.json();
  const entry = body.items.find(e => e.entity_id === member.id);  // Searches by ID

  expect(entry).toBeDefined();
  expect(entry.new_values.email).toContain('audit2');
});

// Each test finds its own entry by ID, works in any order/parallel
```

---

## Monitoring Parallel Execution

### Check Worker Status

```bash
# Run with verbose output to see worker assignments
npx playwright test --verbose

# Output shows which test runs on which worker:
# [chromium] › tests/api/test1.spec.ts:5 (worker 1)
# [chromium] › tests/api/test2.spec.ts:10 (worker 2)
# [chromium] › tests/api/test3.spec.ts:15 (worker 3)
```

### Performance Monitoring

```bash
# Measure test times
time npx playwright test

# With 6 workers: ~35 seconds total
# With 1 worker: ~210 seconds total (6x slower)
```

### Identify Flaky Tests

```bash
# Run tests multiple times to find flakiness
for i in {1..5}; do
  echo "=== Run $i ==="
  npx playwright test --workers=6
done

# If test fails sometimes but not always, it's flaky (isolation issue)
```

---

## Troubleshooting Parallel Issues

### Issue 1: "Email Already Exists" Error

**Cause**: Multiple tests using same email

**Solution**:
```typescript
// Add timestamp or random suffix
const email = `test-${Date.now()}-${Math.random()}@example.com`;
```

### Issue 2: "Member Not Found" in Update Test

**Cause**: Shared member ID deleted by another test

**Solution**:
```typescript
// Create test-specific member
const member = await createTestMember();
const memberId = member.id;  // Local to this test
```

### Issue 3: Audit Log Assertions Fail

**Cause**: Not searching by entity_id

**Solution**:
```typescript
// Search for specific entry
const entry = auditLog.items.find(e => e.entity_id === testMemberId);
expect(entry).toBeDefined();

// Not this:
expect(auditLog.items[0].entity_id).toBe(testMemberId);  // ❌ Position matters
```

### Issue 4: Different Results in Parallel vs Sequential

**Cause**: Order-dependent tests

**Solution**: Run with `--workers=1` first to debug:
```bash
npx playwright test --workers=1 --grep "flaky test"
```

Then check:
- Does test create its own data?
- Does test depend on other tests?
- Are there hardcoded IDs?

---

## Verification Checklist

Before considering a test safe for parallel execution:

- [ ] Test creates its own unique data (not using hardcoded IDs)
- [ ] Test data has unique identifiers (timestamp, UUID, random suffix)
- [ ] Test doesn't modify shared seeded data
- [ ] Test doesn't depend on other tests running first
- [ ] Test doesn't depend on other tests NOT running
- [ ] Database assertions search by ID, not position
- [ ] No global/shared state between tests
- [ ] Test passes with `--workers=1`
- [ ] Test passes with `--workers=6`
- [ ] Test passes when run 5 times in a row

---

## Benefits

✅ **Faster Execution**: 6 workers = 6x faster than sequential
✅ **Better Resource Use**: Parallelizes I/O-bound operations
✅ **Catches Race Conditions**: Flaky tests surface quickly
✅ **Scalable**: Easy to add more tests without slowing suite
✅ **Reliable**: Consistent results across runs

---

## Best Practices Summary

1. **Unique Test Data**: Always use unique identifiers
2. **No Shared State**: Each test is completely independent
3. **Database Constraints**: Rely on schema constraints
4. **Fresh Database**: Start clean before test run
5. **Search by ID**: Database-agnostic assertions
6. **No Cleanup Needed**: Fresh database handles teardown
7. **Monitor Performance**: Track test execution times
8. **Debug Systematically**: Use `--workers=1` when issues arise

---

## Related Patterns

- [Pattern 001: Test Data Isolation](pattern-001-test-data-isolation.md)
- [Pattern 003: Database-Agnostic Assertions](pattern-003-database-agnostic-assertions.md)
