# E2E Playwright Assertions & Flow-Based Tests

**Context**: Playwright API and E2E tests for the Club Bar backend (Slim 4, PDO, PHP 8.3).

Use this when writing assertions, handling waits, or designing test structure for Playwright E2E tests.

Source: `e2etests/patterns/` (Patterns 008, 009).

---

## Critical Rules

1. **NEVER use `waitForTimeout()`** — every wait must have an explicit expectation
2. **NEVER use try-catch for visibility checks** — use `await expect(locator).toBeVisible()`
3. **NEVER use `waitForLoadState('networkidle')`** — React's `useEffect` fires API calls AFTER idle
4. **Use `expect.poll()` for count assertions** after search/filter operations
5. **Use `page.waitForResponse()` to wait for API calls** after UI actions

---

## Assertions & Auto-Waiting (Pattern 008)

Playwright's `expect()` API auto-waits up to 30s with intelligent polling and gives clear error messages.

### Visibility & Presence

```typescript
// ✅ Auto-waits, clear error on failure
await expect(locator).toBeVisible()
await expect(locator).toBeHidden()
await expect(locator).toBeEnabled()
await expect(locator).toBeDisabled()
await expect(locator).toHaveCount(5)
```

### Content

```typescript
await expect(locator).toContainText('Expected text')
await expect(locator).toHaveText('Exact text')
await expect(locator).toHaveValue('input value')
await expect(locator).toHaveAttribute('data-testid', 'my-element')
```

### Navigation

```typescript
await expect(page).toHaveURL('**/dashboard')
await expect(page).toHaveURL(/members\?page=2/)
```

### The Anti-Pattern

```typescript
// ❌ ANTI-PATTERN: try-catch swallows errors, hides real problems
async isLoaded(): Promise<boolean> {
  try {
    return await this.heading().isVisible({ timeout: 1000 })
  } catch {
    return false  // Silent failure — no feedback on WHY
  }
}

// ❌ ANTI-PATTERN: Page objects should NOT expose isVisible() helpers
async isElementVisible(locator: Locator): Promise<boolean> { ... }

// ✅ CORRECT: Use expect() directly in tests
await expect(page.getByTestId('members-page')).toBeVisible()
```

---

## Waiting Strategies

### After UI Actions (Sort, Filter, Search)

```typescript
// ❌ WRONG: networkidle fires before React useEffect API calls
await page.waitForLoadState('networkidle')

// ✅ CORRECT: Wait for the specific API response
await page.waitForResponse(resp =>
  resp.url().includes('/api/admin/members') && resp.status() === 200
)
```

### After Search/Filter — DOM Rendering

```typescript
// ❌ Fragile: DOM may still show stale data
await journalPage.search(`${prefix}A`)
expect(await journalPage.getTransactionCount()).toBe(3)

// ✅ Robust: waitForTableToLoad() + expect.poll() with retry
await journalPage.search(`${prefix}A`)
await journalPage.waitForTableToLoad()
await expect.poll(
  () => journalPage.getTransactionCount(),
  { timeout: 10000 }
).toBe(3)
```

### The `waitForTimeout()` Ban

```typescript
// ❌ ABSOLUTELY FORBIDDEN
await page.waitForTimeout(2000)  // What are we waiting for?
await loginBtn.click()
await page.waitForTimeout(1000)  // Did something break?

// ✅ Replace with explicit expectations
await loginPage.login('admin@example.com', 'password123')
await page.waitForURL('**/members', { timeout: 5000 })

await loginBtn.click()
await expect(page.getByTestId('success-message')).toBeVisible()
```

**Only acceptable `waitForTimeout()` use** — documented debounce with immediate assertion after:
```typescript
await searchInput.fill('query')
await page.waitForTimeout(500)  // Debounce (documented)
await expect(page.getByTestId('results')).toBeVisible()  // Must verify
```

---

## `expect.poll()` for Async Count Assertions

After any operation that changes table contents (search, filter, create, delete), use `expect.poll()`:

```typescript
// ✅ Retries for up to 10s until count matches
await expect.poll(
  () => membersPage.getTableRowCount(),
  { timeout: 10000 }
).toBe(3)

// ✅ Works with any async getter
await expect.poll(
  () => journalPage.getTransactionCount(),
  { timeout: 10000 }
).toBeGreaterThanOrEqual(1)
```

Use regular `expect()` for values that are already stable (form field contents, static text).

---

## Flow-Based Test Design (Pattern 009)

Chain related operations into a single test that exercises a complete user workflow. Setup once, verify each step.

### Structure

```typescript
test('domain: action sequence description', async ({
  page, authenticatedRequest, testTransactions,
}) => {
  const ts = Date.now()
  const prefix = `Jrn${ts}`  // Short, unique, searchable

  // ── Setup: create test data via API ──────────────────────────────
  const memberA = await testTransactions.createMember(`${prefix}A`, 'Alpha')
  await testTransactions.createCorrection(memberA.id, 500, `${prefix} corr1`)

  // ── Navigate and verify display ──────────────────────────────────
  const journalPage = new JournalPage(page)
  await journalPage.navigate()
  await journalPage.waitForPageLoad()

  // ── Search: isolate test data ────────────────────────────────────
  await journalPage.search(`${prefix}A`)
  await journalPage.waitForTableToLoad()
  await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 }).toBe(1)

  // ── Sort: verify toggle ──────────────────────────────────────────
  expect(await journalPage.getHeaderText('date')).toContain('↓')
  await journalPage.sortBy('date')
  expect(await journalPage.getHeaderText('date')).toContain('↑')
})
```

### Flow Rules

1. **Timestamp prefix for isolation** — `const prefix = \`Xxx${ts}\`` (3-4 chars)
2. **Search by prefix, not `member.first_name`** — underscore `_` is a SQL LIKE wildcard
3. **Section comments** — `// ── Section Name ──────────────────`
4. **`waitForTableToLoad()` after every search/filter**
5. **`expect.poll()` for count assertions**
6. **API setup, UI verification** — create data via API (fast), verify via UI (tests UX)
7. **Descriptive names** — `domain: action sequence` format

### When to Use Flows vs Atomic Tests

**Use flows when:**
- Operations are naturally sequential (create → edit → verify → search)
- Tests share expensive setup (member + transactions + navigation)
- Features are on the same page (search, sort, filter)
- Testing cross-page interactions (Journal → Settlements → export)

**Keep atomic tests when:**
- Operations are truly independent (health check, login)
- Error/edge cases that abort the flow
- Different fixture configurations needed
- A failure in one step makes subsequent steps meaningless

### Flow Boundaries

Each flow covers one coherent user story. Split when:
- Setup data requirements diverge
- Page or user role changes
- Flow would exceed ~100 lines

**Good flow examples:**

| Flow | Steps |
|------|-------|
| CRUD lifecycle | Create → verify persistence → edit → verify changes |
| Filters & sort | Setup 3 members → test each filter → test sort |
| Settlement lifecycle | Create data → settle UI → verify Settlements page → export |
| Settle-all + undo | Batch settle → verify → cancel → verify restoration |

### File Header Convention

```typescript
/**
 * Admin Frontend - Members Page E2E Tests (Consolidated)
 *
 * Three flow-based tests covering UC-A10 through UC-A15:
 * 1. CRUD lifecycle: create → verify persistence → edit → verify changes
 * 2. Filters: SEPA, card, status, sort, card-edit interaction
 * 3. Card UID validation: format checks, auto-format, duplicate detection
 *
 * Patterns: 001, 004, 005, 006, 007, 008, 009
 */
```

---

## Verification Checklist

Before committing E2E tests:

- [ ] **NO `waitForTimeout()` calls** (use condition-based waiting)
- [ ] **NO `waitForLoadState('networkidle')`** (use `waitForResponse()`)
- [ ] **NO try-catch visibility checks** (use `await expect(locator).toBeVisible()`)
- [ ] **NO page objects exposing `isVisible()` helpers**
- [ ] Count assertions use `expect.poll()` with timeout
- [ ] `waitForTableToLoad()` follows every `search()` and filter change
- [ ] Each flow has unique timestamp prefix
- [ ] Searches use prefix, never `member.first_name` (underscore LIKE issue)
- [ ] Section comments mark each phase: `// ── Section ──────`
- [ ] Test names use `domain: action sequence` format
- [ ] Setup via API; verification via UI
- [ ] Test passes with `--workers=4` and `--workers=1`

---

## Quick Reference

```typescript
// Wait for API response (not networkidle)
await page.waitForResponse(r => r.url().includes('/api/admin/members'))

// Polling count assertion
await expect.poll(() => page.getRowCount(), { timeout: 10000 }).toBe(3)

// Visibility assertion (not try-catch)
await expect(page.getByTestId('modal')).toBeVisible()
await expect(page.getByTestId('modal')).toBeHidden()

// URL assertion after navigation
await expect(page).toHaveURL('**/members')

// Flow test prefix pattern
const ts = Date.now()
const prefix = `Flt${ts}`
const member = await testTransactions.createMember(`${prefix}A`, 'Alpha')
await journalPage.search(`${prefix}A`)  // Not member.first_name
```
