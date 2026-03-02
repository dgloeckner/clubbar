# E2E Test Data Isolation & Parallel Safety

**Context**: Playwright API and E2E tests for the Club Bar backend (Slim 4, PDO, PHP 8.3).

Use this when writing any Playwright test — data isolation and parallel safety are mandatory for every test.

Source: `e2etests/patterns/` (Patterns 001, 002, 003, 004).

---

## Golden Rules

1. **Every test creates its own data** — never rely on seeded/shared data
2. **Every identifier is unique** — use `Date.now()` timestamps
3. **Never assert by position** — use `.find(m => m.id === testId)` not `items[0]`
4. **Never assert exact counts** — use `toBeGreaterThanOrEqual(0)` not `toBe(5)`
5. **Tests must pass with 4+ workers AND 1 worker**

---

## Data Isolation (Pattern 001)

Each test creates unique data. No test depends on another test's data or execution order.

```typescript
test('CRUD on member', async ({ authenticatedRequest }) => {
  const ts = Date.now();

  // CREATE unique test data
  const res = await authenticatedRequest.post('/api/admin/members', {
    data: {
      first_name: `Test${ts}`,
      last_name: 'Member',
      email: `test-${ts}@example.com`,  // Guaranteed unique
      preferred_language: 'de',
    },
  });
  expect(res.status()).toBe(201);
  const member = await res.json();

  // TEST using the ID we just created
  const getRes = await authenticatedRequest.get(`/api/admin/members/${member.id}`);
  expect(getRes.ok()).toBeTruthy();
});
```

**Anti-patterns:**
```typescript
// ❌ Hardcoded ID — depends on seeded data
const MOCK_ID = '123e4567-e89b-12d3-a456-426614174000';

// ❌ Static email — collides in parallel
email: 'test@example.com'

// ❌ Shared global state
let sharedMemberId;
beforeAll(async () => { sharedMemberId = await createMember(); });
```

---

## Authentication Isolation (Pattern 002)

Three auth mechanisms, never mixed:

| API | Auth | Import | Fixture |
|-----|------|--------|---------|
| `/api/admin/*` | Session cookie | `../../fixtures/auth.fixture` | `authenticatedRequest` |
| `/api/sync/*` | Bearer token | `@playwright/test` | `request` + `Authorization` header |
| `/api/health` | None | `@playwright/test` | `request` |

### Admin API Tests

```typescript
import { test, expect } from '../../fixtures/auth.fixture';

test('admin test', async ({ authenticatedRequest }) => {
  const res = await authenticatedRequest.get('/api/admin/members');
  expect(res.ok()).toBeTruthy();
});
```

### Terminal API Tests

```typescript
import { test, expect } from '@playwright/test';

const validToken = process.env.TEST_TERMINAL_TOKEN;

test('terminal test', async ({ request }) => {
  const res = await request.get('/api/sync/members?since=0', {
    headers: { 'Authorization': `Bearer ${validToken}` },
  });
  expect(res.ok()).toBeTruthy();
});
```

### Auth Error Tests

```typescript
// Missing token → 401
const res = await request.get('/api/sync/members');
expect(res.status()).toBe(401);

// Terminal token cannot access admin routes
const res = await request.get('/api/admin/members', {
  headers: { 'Authorization': `Bearer ${terminalToken}` },
});
expect(res.status()).toBe(401);
```

---

## Database-Agnostic Assertions (Pattern 003)

Never assume position or count. Search for your specific data by ID.

```typescript
// ✅ Search by ID — works regardless of DB state
const listRes = await authenticatedRequest.get('/api/admin/members?per_page=100');
const body = await listRes.json();
const found = body.items.find(m => m.id === testMemberId);
expect(found).toBeDefined();
expect(found.preferred_language).toBe('fr');

// ✅ Structure assertion — no absolute counts
expect(body.items).toBeInstanceOf(Array);
expect(body.total).toBeGreaterThanOrEqual(0);
expect(body.limit).toBeLessThanOrEqual(100);
```

**Anti-patterns:**
```typescript
// ❌ Position-based
expect(body.items[0].id).toBe(testMemberId);

// ❌ Exact count
expect(body.items.length).toBe(5);

// ❌ Assumes only your data
expect(body.items).toEqual([testData]);
```

### Audit Log Assertions

```typescript
const auditRes = await authenticatedRequest.get(
  '/api/admin/audit-log?per_page=100'
);
const { items } = await auditRes.json();
const entry = items.find(e => e.entity_id === memberId && e.action === 'create');
expect(entry).toBeDefined();
expect(entry.entity_type).toBe('member');
```

### Verify Deletion

```typescript
// After DELETE, verify absence by searching
const listRes = await authenticatedRequest.get('/api/admin/members?per_page=100');
const found = (await listRes.json()).items.find(m => m.id === deletedId);
expect(found).toBeUndefined();

// Also verify 404 on direct fetch
const getRes = await authenticatedRequest.get(`/api/admin/members/${deletedId}`);
expect(getRes.status()).toBe(404);
```

---

## Parallel Execution Safety (Pattern 004)

Tests run with `fullyParallel: true` and 4+ workers. Every test must be independent.

### Checklist

- [ ] Test creates its own data (not using hardcoded IDs)
- [ ] Identifiers include `Date.now()` (or UUID) for uniqueness
- [ ] No global/shared state between tests (`let sharedId` is forbidden)
- [ ] Assertions search by ID, not array position
- [ ] No dependency on test execution order
- [ ] Test passes with `--workers=4` and `--workers=1`

### Debugging Parallel Failures

```bash
# If tests fail with 4 workers but pass with 1:
npm test -- tests/api/file.spec.ts --workers=1   # isolate
npm test -- tests/api/file.spec.ts --workers=4   # verify fix
```

**Common causes:**
- Same email used in multiple tests → add `Date.now()` suffix
- Shared member modified by parallel test → create own member
- Position-based assertion → switch to `.find()` by ID
- Exact count assertion → switch to `toBeGreaterThanOrEqual()`

---

## Quick Reference

```typescript
// Unique identifier
const ts = Date.now();
const email = `test-${ts}@example.com`;

// Create test data
const res = await authenticatedRequest.post('/api/admin/members', {
  data: { first_name: `Test${ts}`, email, preferred_language: 'de' }
});
const member = await res.json();

// Search by ID in list
const list = await authenticatedRequest.get('/api/admin/members?per_page=100');
const found = (await list.json()).items.find(m => m.id === member.id);
expect(found).toBeDefined();
```
