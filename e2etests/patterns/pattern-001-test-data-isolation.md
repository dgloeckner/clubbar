# Pattern 001: Test Data Isolation

**Status**: Established and Verified
**Derived From**: Issue analysis of failing test suite (30 failures due to shared test data)
**Test Coverage**: All 123 E2E tests use this pattern

---

## Problem

When tests share seeded or pre-created data, one test's modifications break other tests:

```typescript
// ❌ Problem: Tests depend on shared seeded data
const MOCK_MEMBER_ID = '123e4567-e89b-12d3-a456-426614174000';

test('GET returns member details', async ({ authenticatedRequest }) => {
  const response = await authenticatedRequest.get(`/api/admin/members/${MOCK_MEMBER_ID}`);
  expect(response.json().first_name).toBe('Max');  // Fails if another test modified this member
});

test('GDPR anonymize member', async ({ authenticatedRequest }) => {
  // Anonymizes MOCK_MEMBER_ID, changing first_name to 'DELETED'
  await authenticatedRequest.post(`/api/admin/members/${MOCK_MEMBER_ID}/anonymize`);
});

// If anonymize test runs first, GET test fails because 'Max' is now 'DELETED'
```

**Failure Symptoms**:
- Tests pass individually but fail when run together
- Intermittent failures in parallel execution
- Order-dependent test results
- "Flaky" tests that sometimes pass/fail

---

## Solution: Create Test Data Locally

Each test creates its own unique data instead of relying on seeded data. This ensures:
- Tests are independent
- Parallel execution is safe
- Execution order doesn't matter
- No cleanup needed between runs

### Core Pattern

```typescript
test('descriptive test name', async ({ authenticatedRequest }) => {
  // Step 1: CREATE test-specific data
  const createResponse = await authenticatedRequest.post('/api/admin/members', {
    data: {
      first_name: 'TestMember',  // Unique per test
      last_name: 'Isolation',
      email: 'test-' + Date.now() + '@example.com',  // Guaranteed unique
      preferred_language: 'en',
    },
  });

  expect(createResponse.ok()).toBeTruthy();
  const member = await createResponse.json();
  const memberId = member.id;

  // Step 2: VERIFY created data
  expect(member.first_name).toBe('TestMember');
  expect(member.email).toMatch(/^test-\d+@example\.com$/);

  // Step 3: TEST operations on this data
  const getResponse = await authenticatedRequest.get(`/api/admin/members/${memberId}`);
  expect(getResponse.ok()).toBeTruthy();
  expect(getResponse.json().first_name).toBe('TestMember');

  // Step 4: TEARDOWN happens automatically (fresh database before next test)
});
```

---

## Implementation Guidelines

### Rule 1: Unique Identifiers

Use timestamps or UUIDs to ensure uniqueness across parallel tests:

```typescript
// ✅ Good: Guaranteed unique identifiers
test('my test', async ({ authenticatedRequest }) => {
  const timestamp = Date.now();
  const response = await authenticatedRequest.post('/api/admin/members', {
    data: {
      email: `test-${timestamp}@example.com`,
      first_name: `TestMember${timestamp}`,
    },
  });

  const member = await response.json();
  // member.id is auto-generated UUID (unique per creation)
  expect(member.id).toBeDefined();
});

// ❌ Bad: Static identifiers (conflicts in parallel tests)
test('my test', async ({ authenticatedRequest }) => {
  const response = await authenticatedRequest.post('/api/admin/members', {
    data: {
      email: 'test@example.com',  // Same email in every test run
      first_name: 'TestMember',   // Same name in every test run
    },
  });
  // Fails if another test creates same email
});
```

### Rule 2: No Shared Test State

Tests must not depend on external test data or previous test execution:

```typescript
// ❌ Bad: Depends on seeded data that might change
test('GET member by ID', async ({ authenticatedRequest }) => {
  const response = await authenticatedRequest.get('/api/admin/members/123e4567...');
  expect(response.json().first_name).toBe('Max');
  // Fails if another test modified this member
});

// ✅ Good: Creates and tests own data
test('GET member by ID', async ({ authenticatedRequest }) => {
  // Create test member
  const createResponse = await authenticatedRequest.post('/api/admin/members', {
    data: { first_name: 'GetTest', email: `get-${Date.now()}@example.com`, ... }
  });
  const member = await createResponse.json();

  // Retrieve the member we just created
  const getResponse = await authenticatedRequest.get(`/api/admin/members/${member.id}`);
  expect(getResponse.json().first_name).toBe('GetTest');
  // Always passes because we control the data
});
```

### Rule 3: Avoid Hardcoded IDs

Never hardcode entity IDs in tests unless testing error cases (404, validation):

```typescript
// ❌ Bad: Hardcoded ID
const MOCK_MEMBER_ID = '123e4567-e89b-12d3-a456-426614174000';
test('update member', async ({ authenticatedRequest }) => {
  const response = await authenticatedRequest.patch(`/api/admin/members/${MOCK_MEMBER_ID}`, {
    data: { phone: '+41791234567' }
  });
  // Depends on this specific member existing in seeded data
});

// ✅ Good: Dynamic ID from test setup
test('update member', async ({ authenticatedRequest }) => {
  const createResponse = await authenticatedRequest.post('/api/admin/members', {
    data: { email: `patch-${Date.now()}@example.com`, ... }
  });
  const member = await createResponse.json();

  const patchResponse = await authenticatedRequest.patch(`/api/admin/members/${member.id}`, {
    data: { phone: '+41791234567' }
  });
  expect(patchResponse.json().phone).toBe('+41791234567');
});

// ✅ Exception: Error cases can use invalid IDs
test('404 for non-existent member', async ({ authenticatedRequest }) => {
  const response = await authenticatedRequest.get('/api/admin/members/nonexistent-id');
  expect(response.status()).toBe(404);
});
```

### Rule 4: Test Isolation at Multiple Levels

Different concerns require different isolation strategies:

```typescript
// Level 1: Data Isolation (each test has own data)
test('test with isolated data', async ({ authenticatedRequest }) => {
  const member = await createTestMember();
  // This member is unique to this test
});

// Level 2: Request Isolation (each test makes independent requests)
test('sequential operations', async ({ authenticatedRequest }) => {
  const member = await createTestMember();
  const getResponse = await authenticatedRequest.get(`/api/admin/members/${member.id}`);
  // Each operation stands alone
});

// Level 3: Assertion Isolation (assertions don't depend on test order)
test('assertions search for specific data', async ({ authenticatedRequest }) => {
  const member = await createTestMember();

  const listResponse = await authenticatedRequest.get('/api/admin/members?limit=100');
  const items = await listResponse.json();

  // Search for specific member by ID (not by position or count)
  const foundMember = items.items.find(m => m.id === member.id);
  expect(foundMember).toBeDefined();
  expect(foundMember.first_name).toBe('TestValue');
  // Works regardless of other members in database
});
```

---

## Real-World Examples

### Example 1: CRUD Operations with Isolation

```typescript
test('PATCH updates member fields', async ({ authenticatedRequest }) => {
  // Step 1: CREATE unique test member
  const createResponse = await authenticatedRequest.post('/api/admin/members', {
    data: {
      first_name: 'PatchTest',
      last_name: 'Member',
      email: `patch-${Date.now()}@example.com`,
      preferred_language: 'en',
    },
  });

  expect(createResponse.ok()).toBeTruthy();
  const member = await createResponse.json();

  // Step 2: UPDATE this specific member
  const patchResponse = await authenticatedRequest.patch(
    `/api/admin/members/${member.id}`,
    {
      data: {
        preferred_language: 'fr',
        phone: '+41798765432',
      },
    }
  );

  // Step 3: VERIFY update
  expect(patchResponse.ok()).toBeTruthy();
  const updated = await patchResponse.json();

  expect(updated.id).toBe(member.id);
  expect(updated.preferred_language).toBe('fr');
  expect(updated.phone).toBe('+41798765432');
  expect(updated.first_name).toBe('PatchTest');  // Unchanged
  expect(updated.email).toBe(member.email);      // Unchanged

  // No cleanup needed - database is fresh for next test
});
```

### Example 2: Operations on Test Data

```typescript
test('GDPR export and anonymize operations are atomic', async ({ authenticatedRequest }) => {
  // Step 1: CREATE dedicated test member (not using seeded data)
  const createResponse = await authenticatedRequest.post('/api/admin/members', {
    data: {
      first_name: 'AtomicTest',
      last_name: 'Member',
      email: `atomic-${Date.now()}@example.com`,
      preferred_language: 'en',
    },
  });

  const member = await createResponse.json();
  const memberId = member.id;

  // Step 2: EXPORT member data before anonymization
  const exportResponse = await authenticatedRequest.post(
    `/api/admin/members/${memberId}/export`
  );

  expect(exportResponse.ok()).toBeTruthy();
  const exportData = await exportResponse.json();
  expect(exportData.member.first_name).toBe('AtomicTest');
  expect(exportData.member.email).toBe(`atomic-${Date.now()}@example.com`);

  // Step 3: ANONYMIZE the member
  const anonResponse = await authenticatedRequest.post(
    `/api/admin/members/${memberId}/anonymize`
  );

  expect(anonResponse.ok()).toBeTruthy();
  const anonData = await anonResponse.json();

  // Step 4: VERIFY anonymization
  expect(anonData.first_name).toBe('DELETED');
  expect(anonData.email).toBe('deleted@example.com');

  // This test doesn't interfere with other tests because it uses its own data
});
```

### Example 3: Filtering and Pagination with Database-Agnostic Assertions

```typescript
test('filter members by language preference', async ({ authenticatedRequest }) => {
  // Step 1: CREATE test member with specific language
  const testMember = await createTestMember({
    preferred_language: 'fr',
    email: `fr-${Date.now()}@example.com`,
  });

  // Step 2: FILTER by language preference
  const response = await authenticatedRequest.get(
    '/api/admin/members?filters[language]=fr&limit=100'
  );

  expect(response.ok()).toBeTruthy();
  const body = await response.json();

  // Step 3: SEARCH for our specific member (database-agnostic)
  const foundMember = body.items.find(m => m.id === testMember.id);
  expect(foundMember).toBeDefined();
  expect(foundMember.preferred_language).toBe('fr');

  // Works regardless of how many other French-preference members exist
  // No assumptions about array position or total count
});
```

---

## Factories for expensive fixtures

Some subjects take several API calls to set up — a settlement needs a member
with a valid mandate, a purchase, and the settlement covering it. The
temptation is to read one out of a list endpoint instead:

```typescript
// ❌ ANTI-PATTERN: assert against a settlement some other test created
const list = await authenticatedRequest.get('/api/admin/settlements');
const { data } = await list.json();
if (data.length === 0) test.skip();   // silently no coverage at all (#98)
const settlement = data[0];           // whose? nobody knows
```

Wrap the setup in a factory fixture instead, so the test still owns its data
and can assert against amounts and names it chose (ruling
[#146](https://github.com/dgloeckner/clubbar/issues/146)):

```typescript
// ✅ CORRECT: this test's settlement, this test's amount
test('csv export formats amounts correctly', async ({ authenticatedRequest, settlementFactory }) => {
  const settlement = await settlementFactory.create({ amountCents: 1234 });

  const response = await authenticatedRequest.get(`/api/admin/settlements/${settlement.id}/export/csv`);

  const rows = (await response.text()).trim().split('\n').slice(1);
  expect(rows[0].split(';')[3]).toBe('12.34');
});
```

`settlementFactory` lives in [`utils/settlements.ts`](../utils/settlements.ts)
and is exposed by [`fixtures/auth.fixture.ts`](../fixtures/auth.fixture.ts).

**A data-dependent `test.skip()` is never the answer.** It reads as coverage in
a green run while testing nothing, which is how two money bugs reached `main`.
The `clubbar/no-data-dependent-skip` ESLint rule fails the build on one.

---

## Verification Checklist

When writing or reviewing tests, verify:

- [ ] Test creates its own data (not using hardcoded IDs)
- [ ] Test data has unique identifiers (timestamp, UUID, etc.)
- [ ] Test doesn't depend on other tests running first
- [ ] Test doesn't depend on other tests NOT running
- [ ] Assertions search for specific data (by ID) not positions
- [ ] Test passes in parallel execution (6+ workers)
- [ ] Test passes in sequential execution (1 worker)
- [ ] Test passes if run multiple times in sequence
- [ ] No cleanup code needed (database is fresh)

---

## Benefits

✅ **Independent**: Tests don't interfere with each other
✅ **Parallel-Safe**: Can run with multiple workers
✅ **Order-Independent**: Can run in any order
✅ **Repeatable**: Pass consistently on every run
✅ **Maintainable**: Easy to add new tests
✅ **Scalable**: Works with growing test suite
✅ **Debugging**: Easy to isolate test failures

---

## Related Patterns

- [Pattern 002: Authentication Isolation](pattern-002-authentication-isolation.md)
- [Pattern 003: Database-Agnostic Assertions](pattern-003-database-agnostic-assertions.md)
- [Pattern 004: Parallel Execution Safety](pattern-004-parallel-execution-safety.md)
