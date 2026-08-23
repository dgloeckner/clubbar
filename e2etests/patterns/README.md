# E2E Testing Patterns

This directory contains established patterns for writing robust, reliable E2E tests that scale with the test suite.

**All patterns are derived from real issues encountered in the Club Bar test suite and verified across 123+ passing tests.**

---

## Pattern Overview

| Pattern | Purpose | Problem Solved |
|---------|---------|-----------------|
| [Pattern 001: Test Data Isolation](pattern-001-test-data-isolation.md) | Create unique test data per test | Tests sharing/mutating data → Flaky tests |
| [Pattern 002: Authentication Isolation](pattern-002-authentication-isolation.md) | Properly authenticate different API types | Mixed auth concerns → Failed requests |
| [Pattern 003: Database-Agnostic Assertions](pattern-003-database-agnostic-assertions.md) | Search for specific data in results | Position-based assertions → Flaky tests |
| [Pattern 004: Parallel Execution Safety](pattern-004-parallel-execution-safety.md) | Design tests for safe parallel execution | Race conditions → Intermittent failures |
| [Pattern 005: Using Test IDs (data-testid)](pattern-005-test-ids.md) | Use semantic test IDs for reliable selectors | Brittle CSS selectors → Flaky UI tests |
| [Pattern 006: Page Object Model](pattern-006-page-object-model.md) | Encapsulate page interactions in reusable classes | Scattered locators → Unmaintainable tests |
| [Pattern 007: Page Object Fixtures](pattern-007-page-object-fixtures.md) | Inject ready-to-use page objects with Playwright fixtures | Manual page object initialization → Boilerplate |
| [Pattern 008: Playwright Assertions & Auto-Waiting](pattern-008-playwright-assertions.md) | Use `expect()` instead of try-catch visibility checks | Silent failures → Clear error messages |
| [Pattern 009: User-Flow-Based Tests](pattern-009-user-flow-based-tests.md) | Chain related operations into flow tests instead of one-assert-per-test | Bloated suites with redundant setup → Concise flows with shared setup |
| [Pattern 010: Asserting on Delivered Mail](pattern-010-mail-assertions.md) | Read the message a real drain delivered to a real SMTP server | Asserting on our own queue rows → Blank amounts, stub text parts and duplicates go unnoticed |
| [Pattern 011: Testing a Role You Are Not](pattern-011-role-fixtures.md) | Mint the office under test per worker and make requests as it | Demoting the shared seeded admin → unrelated specs in the same shard fail on a role they never touched |

---

## Quick Start

### For New E2E Tests

1. **Use Pattern 005**: Use test IDs for reliable selectors
   ```typescript
   // In components: add data-testid attributes
   <button data-testid="members-create-button">Create</button>

   // In tests: use getByTestId()
   const btn = page.getByTestId('members-create-button')
   await btn.click()
   ```

2. **Use Pattern 007**: Use fixtures to inject page objects
   ```typescript
   import { test, expect } from '../fixtures/pageObjects'

   test('create product', async ({ authenticatedProductsPage }) => {
     // Fixture provides logged-in, navigated page object
     await authenticatedProductsPage.createProduct('Coffee', '3.50')
   })
   ```

3. **Use Pattern 006**: Create page objects for UI tests (foundation for Pattern 007)
   ```typescript
   import { LoginPage, ProductsPage } from 'pages'

   // Page objects are created once and reused via fixtures
   const loginPage = new LoginPage(page)
   const productsPage = new ProductsPage(page)
   ```

4. **Use Pattern 001**: Create your own test data
   ```typescript
   // Don't use hardcoded IDs
   const member = await createTestMember({ email: `test-${Date.now()}@ex.com` });
   ```

3. **Use Pattern 002**: Use correct authentication
   ```typescript
   // Admin tests: use authenticatedRequest fixture
   test('admin test', async ({ authenticatedRequest }) => { ... });

   // Terminal tests: use bearer token
   const response = await request.get('/api/sync/members', {
     headers: { 'Authorization': `Bearer ${validToken}` }
   });
   ```

4. **Use Pattern 003**: Search by ID, not position
   ```typescript
   // Don't assume position
   const item = body.items.find(m => m.id === memberId);
   expect(item).toBeDefined();
   ```

5. **Use Pattern 004**: Make sure tests work in parallel
   ```bash
   npx playwright test --workers=6
   ```

---

## Real-World Test Examples

### Bad Test (Violates All Patterns)
```typescript
const MOCK_MEMBER_ID = '123e4567-e89b-12d3-a456-426614174000';

test('update member', async ({ authenticatedRequest }) => {
  const response = await authenticatedRequest.patch(
    `/api/admin/members/${MOCK_MEMBER_ID}`,  // ❌ Hardcoded ID
    { data: { phone: '+41791234567' } }
  );

  const body = await response.json();
  expect(body.phone).toBe('+41791234567');  // ❌ Assumes this member exists
});

test('member in list', async ({ authenticatedRequest }) => {
  const response = await authenticatedRequest.get('/api/admin/members');
  const body = await response.json();
  expect(body.items[0].id).toBe(MOCK_MEMBER_ID);  // ❌ Assumes position
});
```

**Issues**:
- Depends on seeded data that other tests might modify
- Position-based assertion (flaky)
- Fails in parallel execution
- Fails if database state changes

### Good Test (Follows All Patterns)
```typescript
test('create and update member', async ({ authenticatedRequest }) => {
  // Pattern 001: Create unique test data
  const createResponse = await authenticatedRequest.post('/api/admin/members', {
    data: {
      email: `test-${Date.now()}@example.com`,  // Unique per test
      first_name: 'TestMember',
      preferred_language: 'en',
    },
  });

  expect(createResponse.ok()).toBeTruthy();
  const member = await createResponse.json();

  // Pattern 004: Use local variable (safe in parallel)
  const memberId = member.id;

  // Update the member we just created
  const patchResponse = await authenticatedRequest.patch(
    `/api/admin/members/${memberId}`,
    { data: { phone: '+41791234567' } }
  );

  // Pattern 003: Verify our specific update
  const updated = await patchResponse.json();
  expect(updated.id).toBe(memberId);
  expect(updated.phone).toBe('+41791234567');
});

test('member appears in list', async ({ authenticatedRequest }) => {
  // Pattern 001: Create unique test data
  const member = await createTestMember({
    email: `list-test-${Date.now()}@example.com`,
  });

  // Pattern 002: Use authenticatedRequest for admin API
  const listResponse = await authenticatedRequest.get(
    '/api/admin/members?limit=100'
  );

  const body = await listResponse.json();

  // Pattern 003: Search by ID (database-agnostic)
  const found = body.items.find(m => m.id === member.id);
  expect(found).toBeDefined();
  expect(found.email).toContain('list-test');
});
```

**Advantages**:
- Each test is independent
- Works in any execution order
- Safe in parallel execution
- Passes consistently every time

---

## Pattern Dependencies

```
Pattern 005 (Test IDs)
    ↓
Pattern 006 (Page Object Model)
    ↓
Pattern 007 (Page Object Fixtures)

Pattern 001 (Data Isolation)
    ↓
Pattern 003 (Agnostic Assertions)
    ↓
Pattern 004 (Parallel Safety)

Pattern 009 (User-Flow-Based Tests)
    ↑ uses all of the above
    ↑ Pattern 006 (Page Object Model)
    ↑ Pattern 008 (Playwright Assertions)

Pattern 002 (Auth Isolation)
    ↓
Pattern 001 (Data Isolation)
```

- **Pattern 005** is foundational for UI tests - provides reliable selectors
- **Pattern 006** uses test IDs to find and interact with elements
- **Pattern 007** injects ready-to-use page objects with test IDs
- **Pattern 001** is foundational for API tests - everything else depends on it
- **Pattern 002** ensures proper auth without mixing concerns
- **Pattern 003** is the consequence of Pattern 001 - once data is isolated, assertions must search by ID
- **Pattern 004** is the verification - tests designed with 1-3 work in parallel

---

## When to Use Each Pattern

### Pattern 005: Using Test IDs
**Use when**: Writing UI E2E tests with Playwright
- Add `data-testid` attributes to all interactive UI elements
- Use `page.getByTestId()` in all E2E tests
- Enables reliable selectors that survive CSS/structure changes
- Works together with page objects and fixtures
- Refer to `admin-frontend/patterns/test-ids.md` for component-side implementation

### Pattern 007: Page Object Fixtures
**Use when**: Using page objects from Pattern 006 in your tests
- Always use fixtures to inject page objects instead of manual instantiation
- Eliminates ~10 lines of boilerplate per test file
- Enables composite fixtures (login + navigation in one fixture)
- Makes tests cleaner and more focused on behavior

### Pattern 006: Page Object Model
**Use when**: Writing UI E2E tests that interact with pages
- Login, navigation, form submission tests
- Any test that clicks buttons, fills inputs, or asserts on elements
- Tests for admin panel or user-facing features
- Reduces locator duplication across multiple tests
- Use Pattern 007 to inject these page objects cleanly via fixtures
- Use test IDs (Pattern 005) for reliable element selection

### Pattern 009: User-Flow-Based Tests
**Use when**: Consolidating or writing new tests for a page/domain with multiple related features
- Multiple features on the same page (search, sort, filter, CRUD)
- Cross-page workflows (Journal settle → Settlements page → export)
- Tests with expensive shared setup (create members + transactions + navigate)
- Reducing test suite bloat while maintaining coverage
- Chain setup → action → verify → next action → verify into a single flow

### Pattern 001: Test Data Isolation
**Use when**: Writing any API test that creates, reads, or modifies data
- Create test members, products, transactions, etc.
- Setup test data for assertions
- Verify data operations work correctly

### Pattern 002: Authentication Isolation
**Use when**: Testing endpoints that require authentication
- Admin API tests (use `authenticatedRequest`)
- Terminal API tests (use bearer token)
- Auth failure tests (use invalid credentials)

### Pattern 003: Database-Agnostic Assertions
**Use when**: Asserting on data from list/filter/search endpoints
- Checking if member appears in filtered list
- Verifying audit log entries
- Asserting on paginated results

### Pattern 004: Parallel Execution Safety
**Use when**: Running full test suite or CI/CD pipelines
- Verify tests work with `--workers=6`
- Design for concurrent execution
- Monitor for race conditions

---

## Testing Your Tests

### Run Locally
```bash
# Sequential (debugging)
npx playwright test --workers=1

# Parallel (production-like)
npx playwright test --workers=6

# Specific test
npx playwright test admin-members-crud.spec.ts
```

### In CI/CD
```yaml
# GitHub Actions
- name: Run E2E Tests
  run: |
    docker compose up -d
    npx playwright test --workers=1  # Sequential in CI for reliability
```

### Performance Check
```bash
# Measure time
time npx playwright test

# Should complete in ~35 seconds with 6 workers
# If slower, investigate slow tests
```

---

## Common Mistakes & Fixes

| Mistake | Pattern | Fix |
|---------|---------|-----|
| Using CSS selectors (.class, #id, [attr]) | 005 | Use `getByTestId()` instead |
| Components missing data-testid | 005 | Add `data-testid` to all interactive elements |
| Brittle selectors (nth-child, >>>) | 005 | Use `data-testid` for stable selectors |
| Using hardcoded data IDs | 001 | Use `Date.now()` or `randomUUID()` |
| Assuming data position | 003 | Use `.find(m => m.id === testId)` |
| Sharing test data | 001 | Create unique data per test |
| Position-based assertions | 003 | Search for specific records |
| Modifying seeded data | 001 | Don't modify shared data |
| Tests depend on order | 004 | Make each test independent |
| Missing auth headers | 002 | Use fixture or pass headers |
| Hardcoded credentials | 002 | Use environment variables |

---

## Pattern Verification

Each pattern includes a checklist at the end. Use these to verify your tests:

1. Before committing: Read the relevant pattern checklist
2. Before running tests: Verify checklist items are met
3. When tests fail: Check which checklist item was violated

---

## Contributing New Patterns

If you discover a recurring issue or best practice:

1. Document the problem
2. Describe the solution
3. Provide examples
4. Add verification checklist
5. Link to related patterns
6. Update this README

Patterns should be:
- ✅ Specific to E2E testing challenges
- ✅ Verified across multiple tests
- ✅ Easy to understand and apply
- ✅ Actionable with clear examples

---

## References

- [../README.md](../README.md) - Running the suite, reading a red run, layout
- [authentication-fixture.md](./authentication-fixture.md) - The `authenticatedRequest` fixture
- [../../DEV_SETUP.md](../../DEV_SETUP.md) - Bringing up the stack the tests need
- [Playwright Documentation](https://playwright.dev/docs/intro)
- [Test Automation Best Practices](https://playwright.dev/docs/best-practices)

---

## Questions?

Refer to the specific pattern for detailed guidance:
- "How do I select elements reliably?" → [Pattern 005](pattern-005-test-ids.md)
- "How do I eliminate page object boilerplate?" → [Pattern 007](pattern-007-page-object-fixtures.md)
- "How do I organize page interactions?" → [Pattern 006](pattern-006-page-object-model.md)
- "How do I isolate test data?" → [Pattern 001](pattern-001-test-data-isolation.md)
- "How do I authenticate?" → [Pattern 002](pattern-002-authentication-isolation.md)
- "How do I assert on lists?" → [Pattern 003](pattern-003-database-agnostic-assertions.md)
- "How do I make tests parallel-safe?" → [Pattern 004](pattern-004-parallel-execution-safety.md)
- "How do I reduce test suite bloat?" → [Pattern 009](pattern-009-user-flow-based-tests.md)
- "How do I structure a multi-step flow test?" → [Pattern 009](pattern-009-user-flow-based-tests.md)
