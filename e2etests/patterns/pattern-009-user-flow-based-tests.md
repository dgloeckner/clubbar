# Pattern 009: User-Flow-Based Test Design

**Status**: Established and Verified
**Derived From**: Consolidation of members, journal, and settlements test suites (72 tests → 7 tests, ~4000 lines → ~660 lines)
**Test Coverage**: `members.spec.ts` (3 tests), `journal-and-settlements.spec.ts` (4 tests)

---

## Problem

Atomic "one test per feature" design leads to bloated test suites with redundant setup, overlapping coverage, and slow execution:

```typescript
// ❌ 17 separate tests, each creating its own member + transaction
test('search filters by member name', async ({ page }) => {
  const member = await createMember(page)           // 500ms setup
  await createTransaction(member.id)                 // 200ms setup
  await journalPage.navigate()                       // 300ms navigation
  await journalPage.search(member.first_name)
  expect(await journalPage.getTransactionCount()).toBe(1)
})

test('sort by date toggles direction', async ({ page }) => {
  const member = await createMember(page)           // 500ms setup (again!)
  await createTransaction(member.id)                 // 200ms setup (again!)
  await journalPage.navigate()                       // 300ms navigation (again!)
  await journalPage.sortBy('date')
  expect(await journalPage.getHeaderText('date')).toContain('↑')
})

test('period picker defaults to 3m', async ({ page }) => {
  // Another 1000ms of identical setup...
})

// ... 14 more tests, each with the same setup overhead
```

**Symptoms**:
- 1000+ line test files with 80% setup duplication
- 60-second suite for 17 tests that could run in 8 seconds as 4 flows
- Maintenance burden: changing a fixture requires updating dozens of tests
- False sense of granularity — most tests exercise one assertion after identical setup

---

## Solution: Flow-Based Tests

Chain related operations into a single test that exercises a complete user workflow. Each test tells a story: setup → sequence of user actions → verify each step along the way.

```typescript
// ✅ 1 flow test replaces 6 atomic tests
test('journal: display, search, sort, period picker, settlement filter', async ({
  page, testTransactions,
}) => {
  const ts = Date.now()
  const prefix = `Jrn${ts}`

  // ── Setup: create test data via API ──────────────────────────────
  const memberA = await testTransactions.createMember(`${prefix}A`, 'Alpha')
  const memberB = await testTransactions.createMember(`${prefix}B`, 'Beta')
  await testTransactions.createCorrection(memberA.id, 500, `${prefix} corr1`)
  await testTransactions.createCorrection(memberA.id, 2500, `${prefix} corr2`)

  const journalPage = new JournalPage(page)
  await journalPage.navigate()
  await journalPage.waitForPageLoad()

  // ── Search: isolates member A's transactions ─────────────────────
  await journalPage.search(`${prefix}A`)
  await journalPage.waitForTableToLoad()
  await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 }).toBe(2)

  // ── Sort: toggle date direction ──────────────────────────────────
  expect(await journalPage.getHeaderText('date')).toContain('↓')
  await journalPage.sortBy('date')
  expect(await journalPage.getHeaderText('date')).toContain('↑')

  // ── Period picker ────────────────────────────────────────────────
  await journalPage.navigate() // reset filters
  await journalPage.waitForPageLoad()
  await journalPage.expectPeriodButtonActive('3m')
  await journalPage.selectPeriod('1m')
  await journalPage.expectPeriodButtonActive('1m')
})
```

**Result**: Same coverage, 1/6th the code, 1/3rd the runtime.

---

## When to Use Flows vs Atomic Tests

### Use flow tests when:
- Operations are **naturally sequential** (create → edit → verify → search)
- Tests share **expensive setup** (member + transactions + navigation)
- Features are **part of the same page/workflow** (search, sort, filter on Journal page)
- You're testing **cross-page interactions** (Journal settle → Settlements page → export)

### Keep atomic tests when:
- Operations are **truly independent** (health check, login)
- **Error/edge cases** that abort the flow (invalid input, duplicate detection)
- Tests need **different fixture configurations** (different auth, different page)
- A failure in one step would make subsequent steps meaningless

### Deciding flow boundaries

Each flow test should cover one **coherent user story**. Split when:
- The setup data requirements diverge significantly
- The page or user role changes
- A flow would exceed ~100 lines (readability limit)

**Good flow boundaries** (from real tests):
| Flow | Story | Steps |
|------|-------|-------|
| CRUD lifecycle | Create → verify persistence → edit → verify changes | 4 phases |
| Filters & sort | Setup 3 members → test each filter → test sort → cross-feature interaction | 6 phases |
| Settlement lifecycle | Create data → settle via UI → verify on Settlements page → export CSV/SEPA | 7 phases |
| Settle-all + undo | Create data → batch settle → verify → cancel → verify restoration | 5 phases |

---

## Implementation Guidelines

### Rule 1: Timestamp Prefix for Data Isolation

Every flow starts with a unique prefix. All test entities include this prefix so they can be found by search and don't collide with parallel tests.

```typescript
test('my flow', async ({ page, testTransactions }) => {
  const ts = Date.now()
  const prefix = `Flt${ts}`  // Short, unique, searchable

  // All entities include the prefix
  const memberA = await testTransactions.createMember(`${prefix}A`, 'Alpha')
  const memberB = await testTransactions.createMember(`${prefix}B`, 'Beta')
  const product = await testTransactions.createProduct(`${prefix}Bier`, 350)
})
```

**Prefix naming conventions**:
- Keep prefixes short (3-4 chars) — they appear in every entity name
- Use the flow's domain: `Jrn` (journal), `Stl` (settlement), `Flt` (filter), `SaU` (settle-all-undo)
- Append letter suffixes for related entities: `${prefix}A`, `${prefix}B`

### Rule 2: Search by Prefix, Not Full Entity Name

The backend SQL LIKE operator treats `_` as a single-character wildcard. Since `createTestMember()` appends `_${timestamp}` to first names, searching by full `member.first_name` returns 0 results.

```typescript
// ❌ Bad: member.first_name contains underscore → LIKE wildcard → 0 results
await journalPage.search(member.first_name)  // e.g. "Jrn1772xxxA_1772xxx"

// ✅ Good: search by prefix (no underscore)
await journalPage.search(`${prefix}A`)        // e.g. "Jrn1772xxxA"
await journalPage.search(prefix)              // e.g. "Jrn1772xxx" — matches all test members
```

### Rule 3: Section Comments for Readability

Use visual section separators to mark each phase of the flow. This makes long tests scannable and helps identify which section failed.

```typescript
// ── Setup: create test data via API ──────────────────────────────
// ── Search: isolates member A's transactions ─────────────────────
// ── Sort: toggle date direction ──────────────────────────────────
// ── Period picker ────────────────────────────────────────────────
// ── Settlement filter + date column ──────────────────────────────
```

### Rule 4: `waitForTableToLoad()` After Every Search or Filter

`search()` and filter methods wait for the API response but NOT for DOM re-render. The table body may still show stale data when assertions run.

```typescript
// ❌ Bad: assertion may run before table re-renders
await journalPage.search(`${prefix}A`)
expect(await journalPage.getTransactionCount()).toBe(3)  // May see old data

// ✅ Good: wait for DOM to settle before asserting
await journalPage.search(`${prefix}A`)
await journalPage.waitForTableToLoad()
await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 }).toBe(3)
```

### Rule 5: `expect.poll()` for Count Assertions

Even after `waitForTableToLoad()`, counts may briefly be stale. Use `expect.poll()` to auto-retry until the expected count is reached.

```typescript
// ❌ Fragile: single snapshot may catch mid-render state
expect(await journalPage.getTransactionCount()).toBe(3)

// ✅ Robust: retries for up to 10s
await expect.poll(
  () => journalPage.getTransactionCount(),
  { timeout: 10000 }
).toBe(3)
```

Use `expect.poll()` for any assertion on a value that changes after an async operation (search, filter, navigation). Use regular `expect()` for values that are already stable (form field contents, static text).

### Rule 6: API Setup, UI Verification

Create test data via API for speed and reliability. Verify results via UI to test the actual user experience.

```typescript
// ── Setup via API (fast, reliable) ─────────────────────────────
const member = await testTransactions.createMember(`${prefix}A`, 'Alpha')
await testTransactions.createCorrection(member.id, 500, `${prefix} corr1`)
await testTransactions.createSettlement([txnId])

// ── Verify via UI (tests what users see) ───────────────────────
const journalPage = new JournalPage(page)
await journalPage.navigate()
await journalPage.waitForPageLoad()
await journalPage.search(`${prefix}A`)
await journalPage.waitForTableToLoad()
await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 }).toBe(1)
```

Exception: When the UI interaction IS the thing being tested (e.g., settle via Journal UI modal), use the UI for that step.

### Rule 7: Descriptive Test Names Tell the Story

Name tests with a colon-separated format: `domain: action sequence`.

```typescript
// ✅ Good: tells you what the flow covers
test('journal: display transactions, search, sort, period picker, settlement filter, create correction')
test('settlement lifecycle: Journal UI settle → Settlements page → CSV + SEPA export')
test('settle-all + undo: batch settlement, cancel, verify restoration')
test('member CRUD lifecycle: create with all fields, verify persistence, edit, verify changes')
test('filters and sort: SEPA, card, status, created-date column, card-edit interaction')

// ❌ Bad: too vague or too specific
test('journal page tests')
test('should display transactions in the journal table after searching')
```

---

## Real-World Examples

### Example 1: Members CRUD Lifecycle (members.spec.ts)

One test replaces ~15 atomic tests (create, verify each field, edit, verify each changed field, search):

```typescript
test('member CRUD lifecycle: create with all fields, verify persistence, edit, verify changes', async ({
  authenticatedMembersPage, page,
}) => {
  const ts = Date.now()

  // ── CREATE with all fields ──────────────────────────────────────
  const createData = {
    firstName: `CNew${ts}`, lastName: `Last${ts}`,
    email: `cnew-${ts}@test.com`, iban: 'DE89370400440532013050',
    mandateDate: '2025-02-01', accountHolder: `Holder${ts}`,
    mandateRef: `REF${ts}`, cardUid: `000${ts.toString().slice(-8)}`,
    language: 'de' as const,
  }

  await authenticatedMembersPage.openCreateModal()
  await authenticatedMembersPage.fillMemberForm(/* ... */)
  await authenticatedMembersPage.submitForm()
  await authenticatedMembersPage.expectFormModalHidden()

  // ── Verify member appears in list ───────────────────────────────
  await authenticatedMembersPage.search(createData.firstName)
  await authenticatedMembersPage.expectMemberVisibleInTable(createData.firstName)

  // ── Verify ALL fields persisted via edit modal ──────────────────
  await authenticatedMembersPage.clickEditButtonForMember(createData.firstName)
  expect(await authenticatedMembersPage.getFormFirstNameValue()).toBe(createData.firstName)
  expect(await authenticatedMembersPage.getFormLastNameValue()).toBe(createData.lastName)
  // ... verify all fields ...
  await authenticatedMembersPage.cancelForm()

  // ── EDIT several fields ─────────────────────────────────────────
  const editData = { firstName: `CEdit${ts}`, /* ... */ }
  await authenticatedMembersPage.clickEditButtonForMember(createData.firstName)
  await authenticatedMembersPage.fillMemberForm(/* editData */)
  await authenticatedMembersPage.submitForm()
  await authenticatedMembersPage.expectFormModalHidden()

  // ── Verify edited member in list, original gone ─────────────────
  await authenticatedMembersPage.search(editData.firstName)
  await authenticatedMembersPage.expectMemberVisibleInTable(editData.firstName)
  await authenticatedMembersPage.clearSearch()
  await authenticatedMembersPage.search(createData.firstName)
  await authenticatedMembersPage.expectMemberNotVisibleInTable(createData.firstName)
})
```

### Example 2: Cross-Page Settlement Flow (journal-and-settlements.spec.ts)

One test exercises Journal → Settlements → CSV → SEPA export:

```typescript
test('settlement lifecycle: Journal UI settle → Settlements page → CSV + SEPA export', async ({
  page, authenticatedRequest, testTransactions,
}) => {
  const ts = Date.now()
  const prefix = `Stl${ts}`

  // ── Setup via API ────────────────────────────────────────────────
  const member1 = await testTransactions.createMember(`${prefix}1`, 'Ruderer')
  const member2 = await testTransactions.createMember(`${prefix}2`, 'Steuermann')
  const txn1Id = await testTransactions.createSyncTransaction(member1.id, 2500, ...)
  const txn2Id = await testTransactions.createCorrection(member1.id, 1000, ...)

  // ── Settle via Journal UI ────────────────────────────────────────
  const journalPage = new JournalPage(page)
  await journalPage.navigate()
  await journalPage.filterBySettlementStatus('open')
  await journalPage.enterSettlementMode()
  await journalPage.selectTransactionById(txn1Id)
  await journalPage.selectTransactionById(txn2Id)
  const settlementId = await journalPage.concludeSettlement()

  // ── Verify on Settlements page ───────────────────────────────────
  const settlementsPage = new SettlementsPage(page)
  await settlementsPage.navigate()
  await settlementsPage.expectSettlementRowVisible(settlementId)
  expect((await settlementsPage.getSettlementStatusText(settlementId))?.trim()).toBe('Aktiv')

  // ── Export CSV ───────────────────────────────────────────────────
  const csv = await authenticatedRequest.get(`/api/admin/settlements/${settlementId}/export-csv`)
  expect(csv.status()).toBe(200)
  expect(await csv.text()).toContain('Ruderer')

  // ── Export SEPA XML ──────────────────────────────────────────────
  const sepa = await authenticatedRequest.get(`/api/admin/settlements/${settlementId}/export-sepa`)
  expect(sepa.status()).toBe(200)
  expect(await sepa.text()).toContain('pain.008')
})
```

### Example 3: Filter Matrix (members.spec.ts)

One test replaces 8 atomic filter tests by creating members with known traits and testing each filter:

```typescript
test('filters and sort: SEPA, card, status, created-date column, card-edit interaction', async ({
  authenticatedMembersPage, page,
}) => {
  const ts = Date.now()
  const prefix = `Flt${ts}`

  // ── Setup: 3 members with known traits ───────────────────────────
  // A: active, SEPA-valid, with card
  // B: active, SEPA-missing, no card
  // C: inactive, SEPA-valid, no card
  await createMemberViaPage(page, { firstName: `${prefix}A`, cardUid: '...' })
  await createMemberViaPage(page, { firstName: `${prefix}B`, withSepa: false })
  // ... create C, PATCH to inactive ...

  await authenticatedMembersPage.navigate()
  await authenticatedMembersPage.search(prefix)  // Isolate test data

  // ── SEPA filter ──────────────────────────────────────────────────
  await authenticatedMembersPage.setSepaFilter('valid')
  await authenticatedMembersPage.expectMemberVisibleInTable(`${prefix}A`)
  await authenticatedMembersPage.expectMemberNotVisibleInTable(`${prefix}B`)

  await authenticatedMembersPage.setSepaFilter('missing')
  await authenticatedMembersPage.expectMemberVisibleInTable(`${prefix}B`)
  await authenticatedMembersPage.expectMemberNotVisibleInTable(`${prefix}A`)

  // ── Card filter ──────────────────────────────────────────────────
  // ... same pattern ...

  // ── Card-edit interaction: clear card → verify filter changes ────
  await authenticatedMembersPage.clickEditButtonForMember(`${prefix}A`)
  await authenticatedMembersPage.clearCardUid()
  await authenticatedMembersPage.submitForm()
  await authenticatedMembersPage.setCardFilter('without')
  await authenticatedMembersPage.expectMemberVisibleInTable(`${prefix}A`)
})
```

---

## File Structure Convention

One consolidated file per domain area, named `<domain>.spec.ts`:

```
tests/admin/
  members.spec.ts                    # 3 flow tests (CRUD, filters, validation)
  journal-and-settlements.spec.ts    # 4 flow tests (journal, lifecycle, integrity, undo)
  categories.spec.ts                 # future consolidation candidate
  products.spec.ts                   # future consolidation candidate
```

### File Header Comment

Every consolidated file starts with a header documenting the flows and patterns used:

```typescript
/**
 * Admin Frontend - Members Page E2E Tests (Consolidated)
 *
 * Three flow-based tests covering UC-A10 through UC-A15:
 * 1. CRUD lifecycle: create → verify persistence → edit → verify changes → search
 * 2. Filters: SEPA, card, status, sort, card-edit interaction
 * 3. Card UID validation: format checks, auto-format, duplicate detection
 *
 * Patterns: 001 (test data isolation), 004 (parallel safety),
 *           005 (test IDs), 006 (page object), 007 (fixtures), 008 (expect assertions)
 */
```

---

## Anti-Patterns Eliminated

| Anti-Pattern | Example | Replaced By |
|-------------|---------|-------------|
| Redundant setup | 17 tests each creating a member + navigating | 1 setup block shared across flow phases |
| One-assert tests | `test('sort indicator shows ↓')` | Inline assertion within sort phase |
| Overlapping coverage | `settle-all` tested in both journal.spec.ts and settlements-e2e.spec.ts | Single `settle-all + undo` flow |
| Inline API helpers | UUID generation + fetch calls copy-pasted per file | `testTransactions` fixture + `authenticatedRequest` |
| Position-based assertions | `getTransactionRow(0)` assuming newest first | `expect.poll()` + search isolation |
| `waitForTimeout` | `await page.waitForTimeout(2000)` for table load | `waitForTableToLoad()` + `expect.poll()` |

---

## Verification Checklist

When writing or reviewing flow-based tests, verify:

- [ ] Each flow has a unique timestamp prefix (`const ts = Date.now(); const prefix = \`Xxx${ts}\``)
- [ ] All searches use the prefix, never `member.first_name` (underscore LIKE wildcard issue)
- [ ] `waitForTableToLoad()` follows every `search()` and filter change
- [ ] Count assertions use `expect.poll()` with timeout
- [ ] Sections are marked with `// ── Section Name ──────` comments
- [ ] Test name uses `domain: action sequence` format
- [ ] Setup is via API; verification is via UI
- [ ] No `waitForTimeout()` calls (use condition-based waiting instead)
- [ ] Flow phases are logically ordered (setup → action → verify → next action → verify)
- [ ] File header documents the flows and patterns used
- [ ] Test passes with 4 workers (parallel safe)
- [ ] Test passes with 1 worker (no accidental parallel-only dependencies)

---

## Consolidation Metrics

| Suite | Before | After | Reduction |
|-------|--------|-------|-----------|
| Members | ~45 tests, ~1500 lines | 3 tests, ~300 lines | 93% fewer tests, 80% fewer lines |
| Journal + Settlements | 27 tests, ~2370 lines | 4 tests, ~370 lines | 85% fewer tests, 84% fewer lines |
| **Total** | **~72 tests, ~3870 lines** | **7 tests, ~670 lines** | **90% fewer tests, 83% fewer lines** |
| Execution time (4 workers) | ~25s | ~10s | 60% faster |

---

## Related Patterns

- [Pattern 001: Test Data Isolation](pattern-001-test-data-isolation.md) — prefix-based isolation is an extension of Pattern 001
- [Pattern 004: Parallel Execution Safety](pattern-004-parallel-execution-safety.md) — flow tests must be parallel-safe
- [Pattern 006: Page Object Model](pattern-006-page-object-model.md) — flow tests rely on page objects for readability
- [Pattern 007: Page Object Fixtures](pattern-007-page-object-fixtures.md) — fixtures provide authenticated, navigated page objects
- [Pattern 008: Playwright Assertions](pattern-008-playwright-assertions.md) — `expect.poll()` for async count assertions
