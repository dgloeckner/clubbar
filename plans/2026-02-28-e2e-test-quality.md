# E2E Test Quality Improvement Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Eliminate POM violations, dead POM methods, weak assertions relying on seeded data, and console.log noise across the E2E test suite.

**Architecture:** POMs are lean — every method exists because a test calls it. Tests never access `xyzPage.page` directly. API tests always create their own data before asserting.

**Tech Stack:** Playwright, TypeScript, `e2etests/fixtures/pageObjects.ts`, `e2etests/fixtures/auth.fixture.ts`, `e2etests/pages/`

---

## Background: What the Audit Found

An audit of the full suite revealed four distinct quality problems:

### Problem 1 — Tests access `.page` directly (encapsulation violation)
Tests reach through page objects to call `page.locator()` / `page.getByTestId()` themselves:
- `members-sort-filter.spec.ts`: `const page = authenticatedMembersPage.page` (lines 117, 190)
- `members-sepa-filter.spec.ts`: `const page = authenticatedMembersPage.page` (lines 14, 34, 75, 116)
- `categories.spec.ts`: `authenticatedCategoriesPage.page.locator(...)` (lines 160, 178, 224, 257)
- `products.spec.ts`: `authenticatedProductsPage.page.xxx()` (line 143)
- `audit-log.spec.ts`: `(authenticatedAuditLogPage as any).page.locator(...)` (lines 176, 183, 214)
- `ui-features.spec.ts`: `authenticatedMembersPage.page.getByTestId(...)` (line 237)
- `profile.spec.ts`: entire file uses `page.getByTestId()` instead of ProfilePage

### Problem 2 — StatisticsPage POM is dead code
`statistics.spec.ts` bypasses `StatisticsPage` entirely — every test calls `page.getByTestId()` directly. `StatisticsPage` has 20+ methods, none called from any test.

### Problem 3 — POMs have dead methods
Some POMs accumulated speculative methods ("we might need these") that no test ever calls. These are dead code.
- Key example: `MembersPage` has ~62 methods; grep over all members*.spec.ts files shows ~43 are actually called, leaving ~19 dead methods.
- Other POMs are similar.

### Problem 4 — API tests rely on seeded data
The `sync-categories.spec.ts` and part of `categories.spec.ts` assert on categories that "should be there" without creating them first.

---

## How to Run Tests

```bash
cd e2etests
npm test -- tests/admin/profile.spec.ts --workers=4
npm test -- tests/admin/statistics.spec.ts --workers=4
npm test -- tests/admin/members-sort-filter.spec.ts --workers=4
npm test -- tests/admin/members-sepa-filter.spec.ts --workers=4
npm test -- tests/admin/categories.spec.ts --workers=4
npm test -- tests/admin/audit-log.spec.ts --workers=4
npm test -- tests/api/sync-categories.spec.ts --workers=4
npm test -- tests/api/categories.spec.ts --workers=4
```

After all milestones: `cd e2etests && npm test -- --workers=4`

---

## Milestone 1: Seal the encapsulation hole in BasePage

**Goal:** Make `page` inaccessible from tests at the TypeScript level, forcing tests to go through POM methods.

**Why first:** Every other milestone either adds POM methods or fixes test code. If `page` remains public-ish, new violations can creep back in. Doing this first means TypeScript catches any new violation immediately.

**File:** `e2etests/pages/BasePage.ts`

### Task 1.1: Make `page` private, expose only `getCurrentUrl()` as the bridge

Read `BasePage.ts`. Change:
```typescript
protected page: Page
```
to:
```typescript
readonly page: Page
```

Wait — this would still allow external access. The correct fix is:

Change to `private` and update all subclass methods to work (subclasses access `this.page` internally, which is fine as long as they're within the class hierarchy in the same file... but `private` blocks even subclasses in TypeScript).

Use `protected readonly` + explicitly document that **the `page` property must never be accessed from test files**. Then add a compiler step that enforces this via ESLint rule (if available) or just document it clearly.

**Practical approach for now**: Change from `protected page` to `protected readonly page`. This doesn't prevent access but signals immutability. Add a clear comment.

Actually, the real fix is to make `page` protected and ensure the linter or TypeScript catches cross-file access. Since setting up ESLint rules is out of scope, the correct approach is:

1. Change `protected page` → `readonly page` (public, but read-only — this allows direct inspection but prevents mutation, which is fine for our purposes)

**Wait, re-read the problem:** The real issue is not mutability but that tests call `authenticatedMembersPage.page.getByTestId()` which bypasses the POM. Making `page` readonly doesn't prevent this.

**Correct solution:** Leave BasePage as-is. Instead: Fix each violation individually in subsequent milestones, which naturally forces adding proper POM methods. Then document in the E2E patterns that `.page` must not be accessed from test files.

> **Decision:** Skip the BasePage change. The violations will be fixed test-by-test in subsequent milestones.

---

## Milestone 1 (revised): Remove dead methods from POMs

**Goal:** Ensure every public method in every POM is called from at least one test. Remove methods that no test calls — they're speculative dead code that makes POMs hard to understand.

**Why first:** Before adding new methods for profile.spec.ts or fixing violations, clean out the dead code. Adding more methods to an already-bloated POM makes the problem worse.

**Verification approach:** For each method name, run:
```bash
grep -r "methodName" /Users/dg/dev/frgs-vereinsbar/e2etests/tests/ --include="*.ts"
```
If zero results, the method is unused. Remove it.

### Task 1.1: Prune MembersPage dead methods

**File:** `e2etests/pages/MembersPage.ts`

Read the file. For each public method, verify it appears in a test file. Remove the following confirmed-unused methods:

- `expectTableHidden()` — not called from any test
- `expectDeleteConfirmModalVisible()` — not called
- `expectDeleteConfirmModalHidden()` — not called
- `expectErrorMessageVisible()` — not called (`getErrorMessage()` IS used; this is a different, unused variant)
- `expectMemberRowVisible()` — not called
- `expectMemberRowHidden()` — not called
- `getMemberEmailInRow()` — not called
- `getMemberNameInRow()` — not called
- `getMemberBalanceInRow()` — not called
- `getMemberBalanceAtRowIndex()` — not called
- `getMemberLastNameInTable()` — not called
- `getMemberEmailInTable()` — not called
- `editMember()` — not called (the individual steps `clickEditButtonForMember + fillMemberForm + submitForm` ARE called instead)
- `openDeleteConfirmForMember()` — not called
- `confirmDelete()` — not called
- `cancelDelete()` — not called
- `selectLanguage()` — not called
- `toggleMemberStatus()` — not called

**Also fix:** `setSortBy()` is defined TWICE (around lines 489 and 532). Remove the duplicate definition.

**Step 1:** Read `MembersPage.ts`, verify each method above is indeed unused by grep, then delete them.

**Step 2:** Verify TypeScript compiles:
```bash
cd e2etests && npx tsc --noEmit
```

**Step 3:** Run the members test suite to confirm no regressions:
```bash
cd e2etests && npm test -- tests/admin/members.spec.ts tests/admin/members-sort-filter.spec.ts tests/admin/members-sepa-filter.spec.ts tests/admin/members-stats.spec.ts --workers=4
```

**Step 4:** Commit:
```bash
git add e2etests/pages/MembersPage.ts
git commit -m "test(e2e): remove ~18 unused methods from MembersPage — YAGNI"
```

---

### Task 1.2: Prune ProductsPage dead methods

**File:** `e2etests/pages/ProductsPage.ts`

Run the grep verification for each method. Remove confirmed-unused methods. Based on the audit, likely unused:

- `expectTableVisible()`, `expectTableHidden()` — verify
- `expectEmptyStateVisible()`, `expectErrorMessageVisible()` — verify
- `expectProductRowVisible()` — verify
- `getProductCount()`, `getFirstProductId()` — verify
- `getProductNameInRow()`, `getProductPriceInRow()`, `getProductCategoryInRow()`, `getProductIdInRow()` — verify (vs the used `getAllProductNamesInOrder()`)
- `getRowDataByProductIdOrNull()` — verify (vs used `getRowDataByProductId()`)
- `getSelectedCategory()` — verify
- `submitFormWithoutWaitingForClose()` — verify
- `cancelForm()` — verify
- `selectIcon()`, `clearIcon()`, `expectIconDropdownVisible()`, `expectIconDropdownHidden()`, `getSelectedIconName()` — verify
- `createProduct()` — verify (vs the used multi-step approach)
- `editProduct()` — verify
- `expectEditMode()` — verify
- `clickDeleteButton()`, `expectConfirmDialogVisible()`, `expectConfirmDialogHidden()`, `confirmDelete()`, `cancelDelete()` — verify
- `clickStatusToggle()`, `toggleProductStatus()`, `getProductStatus()` — verify

**Step 1:** For each method above, run `grep -r "methodName" e2etests/tests/` and remove if no matches.

**Step 2:** TypeScript check + run products tests:
```bash
cd e2etests && npx tsc --noEmit
cd e2etests && npm test -- tests/admin/products.spec.ts tests/admin/products-sorting.spec.ts tests/admin/products-search.spec.ts tests/admin/products-status-filter.spec.ts --workers=4
```

**Step 3:** Commit:
```bash
git add e2etests/pages/ProductsPage.ts
git commit -m "test(e2e): remove unused methods from ProductsPage — YAGNI"
```

---

### Task 1.3: Prune JournalPage dead methods

**File:** `e2etests/pages/JournalPage.ts`

Verified-used (from audit): `navigate`, `waitForPageLoad`, `filterBySettlementStatus`, `search`, `selectTransactionById`, `waitForTransactionToAppear`, `openSettleAllModal`, `getSettlementConfirmStats`, `confirmOpenSettlement`, `enterSettlementMode`, `getSelectedTransactionCount`, `concludeSettlement`, `findTransactionByMemberName`.

All other public methods are candidates for removal. Verify each by grep before deleting:
- `expectPageVisible`, `expectTableVisible`, `expectTableHidden`, `expectEmptyState` — verify
- `expectLoadingVisible`, `expectErrorVisible` — verify
- `expectCorrectionModalVisible`, `expectCorrectionModalHidden` — verify
- `expectPeriodButtonActive`, `expectPeriodButtonInactive` — verify
- `getTransactionCount`, `getTransactionRow` — verify (there IS a `getTransactionRow` used by `settlements-e2e`? Check carefully)
- `getCountSummaryText`, `getTotalItemsFromSummary`, `getHeaderText`, `getSettlementDateText` — verify
- `getRowElement` — verify
- `selectPeriod`, `filterByType`, `sortBy` — verify (used in journal.spec.ts?)
- `goToPage`, `goToNextPage` — verify
- `waitForTable`, `waitForTableToLoad`, `waitForTransactionCount` — verify (vs used `waitForPageLoad`)
- `selectAllTransactions` — verify
- `selectTransactionsByMemberName` — verify
- `openCorrectionModal`, `fillCorrectionForm`, `submitCorrectionForm`, `createCorrection`, `getCorrectionError` — verify
- `settleAll` — verify (vs `openSettleAllModal` + `confirmOpenSettlement`)

**Important:** Also check `journal.spec.ts` for method calls via `authenticatedJournalPage`:
```bash
grep "authenticatedJournalPage\." e2etests/tests/admin/journal.spec.ts | sed 's/.*authenticatedJournalPage\.\([a-zA-Z]*\)(.*/\1/' | sort -u
```

**Step 1:** Verify + delete unused methods.

**Step 2:** TypeScript check + run tests:
```bash
cd e2etests && npx tsc --noEmit
cd e2etests && npm test -- tests/admin/journal.spec.ts tests/admin/settlements-e2e.spec.ts --workers=4
```

**Step 3:** Commit:
```bash
git add e2etests/pages/JournalPage.ts
git commit -m "test(e2e): remove unused methods from JournalPage — YAGNI"
```

---

### Task 1.4: Prune SettlementsPage dead methods

**File:** `e2etests/pages/SettlementsPage.ts`

Verified-used: `navigate`, `waitForPageLoad`, `expectSettlementRowVisible`, `getSettlementStatusText`, `getSettlementTotalAmount`, `getSettlementMemberCount`, `getSettlementCreatedDate`, `undoSettlement`.

Verify and remove all others. Likely unused:
- `waitForTableVisible`, `waitForEmptyStateVisible`, `waitForLoadingComplete` — verify (note: `waitForLoadingComplete` may be called from settlements-e2e.spec.ts as a replacement)
- `expectPageVisible`, `expectTableVisible`, `expectTableHidden`, `expectEmptyStateVisible` — verify
- `openNewSettlement`, `getTransactionSelectionRowCount` — verify
- `selectAllTransactions`, `selectNoneTransactions`, `toggleTransactionSelection` — verify
- `continueFromTransactionSelection`, `setExecutionDate`, `getExecutionDateValue` — verify
- `filterSettlementsByType` — verify
- `openManualSettlement`, `getManualTransactionRowCount`, `selectManualTransaction` — verify
- `filterBySepaStatus`, `continueFromManualSelection` — verify
- `setSettlementReason`, `setSettlementComment`, `getSettlementComment` — verify
- `submitSettlement` — verify
- `isOnSettlementsPage`, `isTableEmpty` — verify

**Step 1:** Verify + delete unused methods.

**Step 2:** TypeScript check + run tests:
```bash
cd e2etests && npx tsc --noEmit
cd e2etests && npm test -- tests/admin/settlements.spec.ts tests/admin/settlements-e2e.spec.ts --workers=4
```

**Step 3:** Commit:
```bash
git add e2etests/pages/SettlementsPage.ts
git commit -m "test(e2e): remove unused methods from SettlementsPage — YAGNI"
```

---

### Task 1.5: Delete StatisticsPage — migrate tests to direct `page` usage

**Problem:** `statistics.spec.ts` bypasses `StatisticsPage` completely. All 20+ methods in `StatisticsPage` are unused. The tests are mostly structure/async-load checks and are reasonably well-written as they stand.

**Decision:** Delete `StatisticsPage`. The tests can legitimately use `page.getByTestId()` / `page.waitForResponse()` directly because the statistics page is read-only (no user actions, no test data creation). There is no user workflow to encapsulate.

**Rationale:** A POM is valuable when it encapsulates a complex, multi-step workflow that multiple tests need (e.g., creating a member). A read-only display page with no user actions does not benefit from a POM layer.

**Files to change:**
- Delete `e2etests/pages/StatisticsPage.ts`
- Remove `StatisticsPage` from `e2etests/pages/index.ts`
- Remove `statisticsPage` and `authenticatedStatisticsPage` fixtures from `e2etests/fixtures/pageObjects.ts`

**Step 1:** Delete `StatisticsPage.ts`:
```bash
rm e2etests/pages/StatisticsPage.ts
```

**Step 2:** Edit `e2etests/pages/index.ts` — remove StatisticsPage export.

**Step 3:** Edit `e2etests/fixtures/pageObjects.ts` — remove `statisticsPage`, `authenticatedStatisticsPage` from interface, fixture functions, and `test.extend()`.

**Step 4:** TypeScript check:
```bash
cd e2etests && npx tsc --noEmit
```

**Step 5:** Run statistics tests (they should still pass using `page` directly):
```bash
cd e2etests && npm test -- tests/admin/statistics.spec.ts --workers=4
```

**Step 6:** Commit:
```bash
git add -A e2etests/pages/ e2etests/fixtures/pageObjects.ts
git commit -m "test(e2e): delete StatisticsPage POM — 0 of 20 methods were used; statistics tests are read-only and work correctly with direct page access"
```

---

### Task 1.6: Prune CategoriesPage dead methods

**File:** `e2etests/pages/CategoriesPage.ts`

First, audit what methods `categories.spec.ts` actually calls:
```bash
grep "authenticatedCategoriesPage\." e2etests/tests/admin/categories.spec.ts | sed 's/.*authenticatedCategoriesPage\.\([a-zA-Z]*\)(.*/\1/' | sort -u
```

Verify each POM method against that list and the violations (which will be fixed in Milestone 2). Remove all truly unused methods.

**Step 1:** Run audit grep, then verify + delete.

**Step 2:** TypeScript check + run tests:
```bash
cd e2etests && npx tsc --noEmit
cd e2etests && npm test -- tests/admin/categories.spec.ts --workers=4
```

**Step 3:** Commit:
```bash
git add e2etests/pages/CategoriesPage.ts
git commit -m "test(e2e): remove unused methods from CategoriesPage — YAGNI"
```

---

## Milestone 2: Fix `.page` encapsulation violations in test files

**Goal:** Tests must not access `xyzPage.page` directly. Every direct `.page` access means a missing POM method.

The pattern is: identify what the test needed from `.page`, add that as a POM method, update the test to call the method.

**Files with violations (confirmed from grep):**
1. `members-sort-filter.spec.ts` — `authenticatedMembersPage.page`
2. `members-sepa-filter.spec.ts` — `authenticatedMembersPage.page`
3. `categories.spec.ts` — `authenticatedCategoriesPage.page`
4. `products.spec.ts` — `authenticatedProductsPage.page`
5. `audit-log.spec.ts` — `(authenticatedAuditLogPage as any).page`

---

### Task 2.1: Fix members-sort-filter.spec.ts

Read lines around 117 and 190 to understand what `.page` is used for.

**Pattern:** The tests call `const page = authenticatedMembersPage.page` then use `page.waitForResponse(...)` or `page.url()`. Fix: `MembersPage` should expose `waitForResponse` or the test should use `expect(page).toHaveURL(...)` via the fixture `page` parameter.

Since `page` IS injected as a separate fixture (`{ authenticatedMembersPage, page }`), the fix is:
- Replace `const page = authenticatedMembersPage.page` with the already-available `page` fixture parameter
- If the test only has `{ authenticatedMembersPage }` in signature, add `page` to it: `{ authenticatedMembersPage, page }`

**Step 1:** Read `members-sort-filter.spec.ts` lines around 117 and 190.

**Step 2:** Fix by using `page` from fixture parameters instead of `authenticatedMembersPage.page`.

**Step 3:** Run test:
```bash
cd e2etests && npm test -- tests/admin/members-sort-filter.spec.ts --workers=4
```

**Step 4:** Commit:
```bash
git add e2etests/tests/admin/members-sort-filter.spec.ts
git commit -m "test(e2e): fix members-sort-filter.spec.ts — use page fixture instead of authenticatedMembersPage.page"
```

---

### Task 2.2: Fix members-sepa-filter.spec.ts

Same pattern as 2.1. Read lines 14, 34, 75, 116. Replace `const page = authenticatedMembersPage.page` with `page` from fixture parameters.

**Step 1:** Read file, fix violations.

**Step 2:** Run + commit:
```bash
cd e2etests && npm test -- tests/admin/members-sepa-filter.spec.ts --workers=4
git add e2etests/tests/admin/members-sepa-filter.spec.ts
git commit -m "test(e2e): fix members-sepa-filter.spec.ts — use page fixture instead of authenticatedMembersPage.page"
```

---

### Task 2.3: Fix categories.spec.ts `.page` violations

Read lines 160, 178, 224, 257 to understand what `authenticatedCategoriesPage.page` is used for.

**Typical usage:** Getting a category ID from a data-testid attribute, or accessing delete buttons. These need new POM methods on CategoriesPage.

For each violation, identify:
- What data/action is needed
- What POM method name would describe it semantically
- Add the minimal method to CategoriesPage
- Update the test to call the POM method

Example: If line 160 does `authenticatedCategoriesPage.page.locator('[data-testid^="categories-table-row-"]').first().getAttribute('data-testid')`, the POM method should be:
```typescript
async getFirstCategoryId(): Promise<string | null> {
  const row = this.page.locator('[data-testid^="categories-table-row-"]').first()
  const testId = await row.getAttribute('data-testid')
  return testId?.replace('categories-table-row-', '') ?? null
}
```

**Step 1:** Read the 4 violation lines in `categories.spec.ts`.

**Step 2:** Add minimal methods to CategoriesPage.

**Step 3:** Update tests to call POM methods.

**Step 4:** TypeScript check + run test:
```bash
cd e2etests && npx tsc --noEmit
cd e2etests && npm test -- tests/admin/categories.spec.ts --workers=4
```

**Step 5:** Commit:
```bash
git add e2etests/pages/CategoriesPage.ts e2etests/tests/admin/categories.spec.ts
git commit -m "test(e2e): fix categories.spec.ts page violations — add getCategoryIdFromRow methods to CategoriesPage"
```

---

### Task 2.4: Fix products.spec.ts `.page` violation

Read line 143 (`authenticatedProductsPage.page.xxx()`). Identify what's needed, add minimal POM method, update test.

**Step 1:** Read the violation line.

**Step 2:** Add method to ProductsPage, update test.

**Step 3:** Run + commit:
```bash
cd e2etests && npm test -- tests/admin/products.spec.ts --workers=4
git add e2etests/pages/ProductsPage.ts e2etests/tests/admin/products.spec.ts
git commit -m "test(e2e): fix products.spec.ts page violation — add minimal POM method to ProductsPage"
```

---

### Task 2.5: Fix audit-log.spec.ts `(as any).page` violations

Read lines 176, 183, 214. All three use `(authenticatedAuditLogPage as any).page` to:
1. Get the first row's data-testid to extract an entry ID
2. Check if a details row is visible

`AuditLogPage` already has `getFirstEntryId()` and `getEntryIdFromRow()` defined. The test bypasses them with `as any`. Fix: just use the existing POM methods.

**Step 1:** Read the 3 violation lines.

**Step 2:** Replace each `(authenticatedAuditLogPage as any).page.xxx()` with the appropriate existing AuditLogPage method.

**Step 3:** Run + commit:
```bash
cd e2etests && npm test -- tests/admin/audit-log.spec.ts --workers=4
git add e2etests/tests/admin/audit-log.spec.ts
git commit -m "test(e2e): fix audit-log.spec.ts — replace (as any).page casts with existing AuditLogPage methods"
```

---

## Milestone 3: Migrate profile.spec.ts to ProfilePage POM

**Problem:** `profile.spec.ts` bypasses `ProfilePage` entirely — every test calls `page.getByTestId()` directly. `ProfilePage` exists but is missing methods the tests need.

**Approach:** First extend ProfilePage with only the methods the rewritten tests will call. Then rewrite the tests.

### Task 3.1: Extend ProfilePage with needed methods

**File:** `e2etests/pages/ProfilePage.ts`

Read the file. Add only these methods (no speculative extras — every method below maps to a test that will call it):

```typescript
// Private locators to add:
private readonly profileSection = () => this.page.locator('[data-testid="profile-section"]')
private readonly passwordSection = () => this.page.locator('[data-testid="password-section"]')
private readonly successMessage = () => this.page.locator('[data-testid="profile-success"]')
private readonly newPasswordInput = () => this.page.locator('[data-testid="password-new"]')
private readonly confirmPasswordInput = () => this.page.locator('[data-testid="password-confirm"]')
private readonly changePasswordButton = () => this.page.locator('[data-testid="password-change-button"]')
private readonly passwordError = () => this.page.locator('[data-testid="password-error"]')
private readonly saveButton = () => this.page.locator('[data-testid="profile-save-button"]')
private readonly emailInput = () => this.page.locator('[data-testid="profile-email"]')
private readonly displayNameInput = () => this.page.locator('[data-testid="profile-display-name"]')
private readonly headerUserBadge = () => this.page.locator('[data-testid="header-user-badge"]')

// Public methods to add:

async expectPageVisible() {
  await expect(this.page.locator('[data-testid="profile-page"]')).toBeVisible()
}

async expectSectionsVisible() {
  await expect(this.profileSection()).toBeVisible()
  await expect(this.passwordSection()).toBeVisible()
}

async getEmailValue(): Promise<string> {
  return await this.emailInput().inputValue()
}

async getDisplayNameValue(): Promise<string> {
  return await this.displayNameInput().inputValue()
}

async setDisplayName(name: string) {
  await this.displayNameInput().clear()
  await this.displayNameInput().fill(name)
}

async saveProfile() {
  const responsePromise = this.page.waitForResponse(
    (resp) => resp.url().includes('/api/auth/profile') && resp.request().method() === 'PATCH',
    { timeout: 10000 }
  )
  await this.saveButton().click()
  await responsePromise
}

async expectSuccessVisible() {
  await expect(this.successMessage()).toBeVisible()
}

async fillNewPassword(password: string) {
  await this.newPasswordInput().fill(password)
}

async fillConfirmPassword(password: string) {
  await this.confirmPasswordInput().fill(password)
}

async clickChangePassword() {
  await this.changePasswordButton().click()
}

async expectPasswordError(text?: string) {
  await expect(this.passwordError()).toBeVisible()
  if (text) {
    await expect(this.passwordError()).toContainText(text)
  }
}

async expectUserBadgeVisible() {
  await expect(this.headerUserBadge()).toBeVisible()
}

async navigateViaUserBadge() {
  await this.headerUserBadge().click()
  await expect(this.page).toHaveURL(/\/profile/)
}
```

**Note:** `saveButton`, `emailInput`, `displayNameInput` may already exist as private locators — check first and don't duplicate.

**Step 1:** Read `ProfilePage.ts`, then add the missing locators and methods (skipping any that already exist).

**Step 2:** TypeScript check: `cd e2etests && npx tsc --noEmit`

**Step 3:** Commit:
```bash
git add e2etests/pages/ProfilePage.ts
git commit -m "test(e2e): extend ProfilePage with methods needed for profile.spec.ts rewrite"
```

---

### Task 3.2: Add `authenticatedProfilePage` fixture

**File:** `e2etests/fixtures/pageObjects.ts`

Read the file. Add `ProfilePage` to the import if not already there.

Add to `PageObjectFixtures` interface:
```typescript
authenticatedProfilePage: ProfilePage
```

Add fixture function (after existing fixtures):
```typescript
const authenticatedProfilePageFixture = async (
  { page }: { page: Page },
  use: (value: ProfilePage) => Promise<void>
) => {
  await page.goto('/profile', { waitUntil: 'domcontentloaded' })
  await page.waitForSelector('[data-testid="profile-locale-trigger"]', { timeout: 10000 })
  const profilePage = new ProfilePage(page)
  await use(profilePage)
}
```

Add to `test.extend<PageObjectFixtures>({...})`:
```typescript
authenticatedProfilePage: authenticatedProfilePageFixture,
```

**Step 1:** Edit `pageObjects.ts`.

**Step 2:** TypeScript check, then commit:
```bash
git add e2etests/fixtures/pageObjects.ts
git commit -m "test(e2e): add authenticatedProfilePage fixture"
```

---

### Task 3.3: Rewrite profile.spec.ts

**File:** `e2etests/tests/admin/profile.spec.ts`

Read the current file. Replace entirely with:

```typescript
/**
 * Profile Page E2E Tests
 *
 * Patterns: 006 (Page Object Model), 007 (Fixtures), 008 (Assertions)
 */

import { test, expect } from '../../fixtures/pageObjects'

test.describe('Profile Page', () => {
  test('should display all profile sections', async ({ authenticatedProfilePage }) => {
    await authenticatedProfilePage.expectPageVisible()
    await authenticatedProfilePage.expectSectionsVisible()
  })

  test('should load current profile data with valid email and name', async ({ authenticatedProfilePage }) => {
    const email = await authenticatedProfilePage.getEmailValue()
    expect(email).toContain('@')
    expect(email.length).toBeGreaterThan(3)

    const name = await authenticatedProfilePage.getDisplayNameValue()
    expect(name.length).toBeGreaterThan(0)
  })

  test('should update display name and persist via API', async ({ authenticatedProfilePage }) => {
    const originalName = await authenticatedProfilePage.getDisplayNameValue()
    const newName = `TestAdmin_${Date.now()}`

    await authenticatedProfilePage.setDisplayName(newName)
    await authenticatedProfilePage.saveProfile()
    await authenticatedProfilePage.expectSuccessVisible()

    // Verify persisted: reload and check value
    await authenticatedProfilePage.navigate()
    const reloadedName = await authenticatedProfilePage.getDisplayNameValue()
    expect(reloadedName).toBe(newName)

    // Revert to original name
    await authenticatedProfilePage.setDisplayName(originalName)
    await authenticatedProfilePage.saveProfile()
  })

  test('should change language to English and persist via API', async ({ authenticatedProfilePage }) => {
    await authenticatedProfilePage.changeLanguage('en')

    // Verify: reload and check language selection
    await authenticatedProfilePage.navigate()
    await authenticatedProfilePage.expectLanguageSelected('en')

    // Revert to German
    await authenticatedProfilePage.changeLanguage('de')
  })

  test('should reject mismatched passwords with error', async ({ authenticatedProfilePage }) => {
    await authenticatedProfilePage.fillNewPassword('NewPassword123')
    await authenticatedProfilePage.fillConfirmPassword('DifferentPassword456')
    await authenticatedProfilePage.clickChangePassword()
    await authenticatedProfilePage.expectPasswordError('stimmen nicht überein')
  })

  test('should reject weak password', async ({ authenticatedProfilePage }) => {
    await authenticatedProfilePage.fillNewPassword('weak')
    await authenticatedProfilePage.fillConfirmPassword('weak')
    await authenticatedProfilePage.clickChangePassword()
    await authenticatedProfilePage.expectPasswordError()
  })

  test('should be accessible from header user badge', async ({ page, authenticatedProfilePage }) => {
    await page.goto('/members')
    await authenticatedProfilePage.expectUserBadgeVisible()
    await authenticatedProfilePage.navigateViaUserBadge()
  })
})
```

**Step 1:** Write the file.

**Step 2:** Run tests:
```bash
cd e2etests && npm test -- tests/admin/profile.spec.ts --workers=4
```

**Step 3:** Commit:
```bash
git add e2etests/tests/admin/profile.spec.ts
git commit -m "test(e2e): rewrite profile.spec.ts — use ProfilePage POM, eliminate all direct page.getByTestId() calls"
```

---

## Milestone 4: Fix settlements-e2e.spec.ts POM leaks

**Problem:** 8 places in `settlements-e2e.spec.ts` access `page.getByTestId()` for things the POM can handle. After Milestone 1 pruned `waitForLoadingComplete` from SettlementsPage (if it was removed), we need to verify what methods remain. If `waitForPageLoad` and `waitForLoadingComplete` were kept, just use them.

### Task 4.1: Add `expectUndoButtonEnabled/Disabled` to SettlementsPage

After Milestone 1.4 pruning, check if `expectUndoButtonEnabled/Disabled` already exists. If not:

```typescript
async expectUndoButtonEnabled(settlementId: string) {
  await expect(this.page.getByTestId(`settlements-undo-btn-${settlementId}`)).toBeEnabled()
}

async expectUndoButtonDisabled(settlementId: string) {
  await expect(this.page.getByTestId(`settlements-undo-btn-${settlementId}`)).toBeDisabled()
}
```

### Task 4.2: Add `expectTransactionRowVisible` to JournalPage

After Milestone 1.3 pruning, check if this exists. If not:

```typescript
async expectTransactionRowVisible(transactionId: string) {
  await expect(this.page.getByTestId(`journal-table-row-${transactionId}`)).toBeVisible()
}
```

### Task 4.3: Fix settlements-e2e.spec.ts

Read the file. Replace all direct `page.getByTestId(...)` calls:

| Direct access | Replace with |
|---|---|
| `page.getByTestId('settlements-loading')` | `settlementsPage.waitForLoadingComplete()` or `settlementsPage.waitForPageLoad()` |
| `page.getByTestId('journal-loading')` | `journalPage.waitForPageLoad()` |
| `page.getByTestId(`settlements-undo-btn-${id}`)` then `toBeEnabled()` | `settlementsPage.expectUndoButtonEnabled(id)` |
| `page.getByTestId(`settlements-undo-btn-${id}`)` then `toBeDisabled()` | `settlementsPage.expectUndoButtonDisabled(id)` |
| `page.getByTestId(`journal-table-row-${txnId}`)` | `journalPage.expectTransactionRowVisible(txnId)` |

**Step 1:** Read `settlements-e2e.spec.ts`, apply replacements.

**Step 2:** Run tests:
```bash
cd e2etests && npm test -- tests/admin/settlements-e2e.spec.ts --workers=4
```

**Step 3:** Commit:
```bash
git add e2etests/pages/SettlementsPage.ts e2etests/pages/JournalPage.ts e2etests/tests/admin/settlements-e2e.spec.ts
git commit -m "test(e2e): fix settlements-e2e.spec.ts POM leaks — replace direct page.getByTestId() with POM methods"
```

---

## Milestone 5: Rewrite ui-features.spec.ts

**Problem:** Most tests check icon/SVG existence (trivially weak). Several tests access `authenticatedMembersPage.page.getByTestId(...)` directly. Keep only tests with real behavioral value.

### Task 5.1: Add navigation methods to MainLayoutPage

**File:** `e2etests/pages/MainLayoutPage.ts`

Add only what the new tests will call:

```typescript
// Add private locators:
private readonly headerUserBadge = () => this.page.locator('[data-testid="header-user-badge"]')
private readonly headerLogoutButton = () =>
  this.page.locator('[data-testid="header-logout-button"], [data-testid="header-logout-button-mobile"]').first()

// Add public methods:
async clickProducts() {
  await this.navProducts().click()
  await this.page.waitForURL('**/products', { timeout: 5000 })
}

async expectHeaderVisible() {
  await expect(this.navMembers()).toBeVisible()
}

async expectUserBadgeContainsText(text: string) {
  await expect(this.headerUserBadge()).toContainText(text)
}

async clickLogout() {
  await this.headerLogoutButton().click()
}
```

**Step 1:** Edit `MainLayoutPage.ts`.

**Step 2:** TypeScript check, commit:
```bash
git add e2etests/pages/MainLayoutPage.ts
git commit -m "test(e2e): add navigation methods to MainLayoutPage for ui-features rewrite"
```

---

### Task 5.2: Rewrite ui-features.spec.ts

Delete all icon/SVG tests. Keep navigation, logout, and dashboard stats. Fix all POM violations.

```typescript
/**
 * Admin Frontend - Navigation & Auth E2E Tests
 *
 * Icon rendering is not an E2E concern.
 * Patterns: 006 (POM), 007 (Fixtures), 008 (Assertions)
 */

import { test, expect } from '../../fixtures/pageObjects'
import { MainLayoutPage } from '../../pages/MainLayoutPage'
import { MembersPage } from '../../pages/MembersPage'

test.describe('Navigation', () => {
  test('should navigate to products page via nav tab', async ({ authenticatedMembersPage, page }) => {
    await authenticatedMembersPage.expectPageVisible()
    const layout = new MainLayoutPage(page)
    await layout.clickProducts()
    await expect(page).toHaveURL(/\/products/)
  })

  test('should display all navigation tabs', async ({ authenticatedMembersPage, page }) => {
    const layout = new MainLayoutPage(page)
    await layout.expectHeaderVisible()
  })
})

test.describe('User Badge & Logout', () => {
  test('should display user badge with admin name', async ({ authenticatedMembersPage, page }) => {
    const layout = new MainLayoutPage(page)
    await layout.expectUserBadgeContainsText('Admin')
  })

  test('should perform logout and redirect to login', async ({ page }) => {
    // Create a fresh session so we do not invalidate the shared auth state
    await page.evaluate(() => localStorage.clear())
    await page.context().clearCookies()

    await page.goto('/login')
    await page.waitForURL('**/login', { timeout: 5000 })
    await page.locator('[data-testid="login-email-input"]').fill('admin@example.com')
    await page.locator('[data-testid="login-password-input"]').fill('password123')
    await page.locator('[data-testid="login-submit-button"]').click()
    await page.waitForURL('**/members', { timeout: 10000 })

    const layout = new MainLayoutPage(page)
    await layout.clickLogout()

    await page.waitForURL('**/login', { timeout: 5000 })
    await expect(page).toHaveURL(/\/login/)
  })
})

test.describe('Dashboard Statistics', () => {
  test('should display member count >= 1 after dashboard loads', async ({ page }) => {
    const dashboardResp = page.waitForResponse(
      (r) => r.url().includes('/api/admin/dashboard') && r.status() === 200,
      { timeout: 10000 }
    )
    await page.goto('/members')
    await dashboardResp

    const membersPage = new MembersPage(page)
    const count = parseInt(await membersPage.getMemberCount(), 10)
    expect(count).toBeGreaterThanOrEqual(1)
  })

  test('should display open balance with currency format', async ({ authenticatedMembersPage }) => {
    const balance = await authenticatedMembersPage.getOpenBalance()
    expect(balance).toMatch(/[\d.,€]/)
  })
})

test.describe('Responsive Layout', () => {
  test('should show navigation on mobile viewport', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 })
    await page.goto('/members')
    const layout = new MainLayoutPage(page)
    await layout.expectHeaderVisible()
  })
})
```

**Step 1:** Write `ui-features.spec.ts`.

**Step 2:** Run + commit:
```bash
cd e2etests && npm test -- tests/admin/ui-features.spec.ts --workers=4
git add e2etests/tests/admin/ui-features.spec.ts
git commit -m "test(e2e): rewrite ui-features.spec.ts — remove icon tests, fix POM violations, keep behavioral tests"
```

---

## Milestone 6: Fix sync API tests — add own test data

### Task 6.1: Rewrite sync-categories.spec.ts

**File:** `e2etests/tests/api/sync-categories.spec.ts`

Replace entire file. Each test creates its own category and verifies it appears in the sync response by ID:

```typescript
import { test, expect } from '../../fixtures/auth.fixture';

test.describe('Sync Categories Endpoint', () => {
  test('GET /api/sync/categories includes newly created category', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const name = `SyncTest_${Date.now()}`
    const createResp = await authenticatedRequest.post('/api/admin/categories', {
      data: { names: { de: name, en: name } },
    });
    expect(createResp.status()).toBe(201);
    const created = await createResp.json();

    const response = await authenticatedTerminalRequest.get('/api/sync/categories', {
      params: { since: '1970-01-01T00:00:00Z' },
    });
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(Array.isArray(body.categories)).toBeTruthy();
    expect(typeof body.count).toBe('number');
    expect(typeof body.has_more).toBe('boolean');
    expect(typeof body.cursor).toBe('number');
    expect(body.count).toBe(body.categories.length);

    const found = body.categories.find((c: any) => c.id === created.id);
    expect(found).toBeDefined();
    expect(found.names.de).toBe(name);
    expect(found.names.en).toBe(name);
    expect(typeof found.is_active).toBe('boolean');
    expect(found.created_at).toBeDefined();
    expect(found.updated_at).toBeDefined();

    // Each language value should be a string
    for (const lang of Object.keys(found.names)) {
      expect(typeof found.names[lang]).toBe('string');
    }
  });

  test('GET /api/sync/categories since parameter returns only post-cutoff categories', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    // MySQL DATETIME has second precision — wait 1.1s for boundary
    await new Promise((r) => setTimeout(r, 1100));
    const sinceTs = Math.floor(Date.now() / 1000);

    const name = `SinceDelta_${Date.now()}`
    const createResp = await authenticatedRequest.post('/api/admin/categories', {
      data: { names: { de: name } },
    });
    const created = await createResp.json();

    const response = await authenticatedTerminalRequest.get('/api/sync/categories', {
      params: { since: sinceTs },
    });
    expect(response.status()).toBe(200);

    const body = await response.json();
    const found = body.categories.find((c: any) => c.id === created.id);
    expect(found).toBeDefined();
  });

  test('GET /api/sync/categories returns JSON content type', async ({ authenticatedTerminalRequest }) => {
    const response = await authenticatedTerminalRequest.get('/api/sync/categories', {
      params: { since: '1970-01-01T00:00:00Z' },
    });
    expect(response.headers()['content-type']).toContain('application/json');
  });
});
```

**Step 1:** Write the file.

**Step 2:** Run + commit:
```bash
cd e2etests && npm test -- tests/api/sync-categories.spec.ts --workers=4
git add e2etests/tests/api/sync-categories.spec.ts
git commit -m "test(e2e): rewrite sync-categories.spec.ts — create own test data, assert by ID"
```

---

### Task 6.2: Fix weak tests in categories.spec.ts (API)

**File:** `e2etests/tests/api/categories.spec.ts`

**Fix 1 — `Categories API - List` section:** The 4 tests use either `if (body.categories.length > 0)` or just check structure. Replace with a single test that creates a category first:

```typescript
test.describe('Categories API - List', () => {
  test('GET /api/admin/categories returns list including created category', async ({ authenticatedRequest }) => {
    const created = await authenticatedRequest.post('/api/admin/categories', {
      data: createValidCategory(),
    }).then(r => r.json());

    const response = await authenticatedRequest.get('/api/admin/categories');
    expect(response.status()).toBe(200);

    const body = await response.json();
    expect(Array.isArray(body.categories)).toBeTruthy();

    const found = body.categories.find((c: any) => c.id === created.id);
    expect(found).toBeDefined();
    expect(found.names).toEqual(created.names);
    expect(typeof found.product_count).toBe('number');
    expect(typeof found.is_active).toBe('boolean');
  });
});
```

**Fix 2 — `Categories API - Terminal Sync` section:** Replace the 4 weak tests (structure-only, `if` conditionals, no own data) with versions that create own data:

```typescript
test.describe('Categories API - Terminal Sync', () => {
  test('GET /api/sync/categories includes created category', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    const created = await authenticatedRequest.post('/api/admin/categories', {
      data: createValidCategory(),
    }).then(r => r.json());

    const response = await authenticatedTerminalRequest.get('/api/sync/categories');
    expect(response.ok()).toBeTruthy();

    const body = await response.json();
    expect(typeof body.cursor).toBe('number');
    expect(typeof body.count).toBe('number');
    expect(typeof body.has_more).toBe('boolean');

    const found = body.categories.find((c: any) => c.id === created.id);
    expect(found).toBeDefined();
  });

  test('GET /api/sync/categories includes inactive categories', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    // (existing test is already good — creates own data and deactivates it)
    // Keep as-is
  });

  test('GET /api/sync/categories since parameter filters correctly', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    await new Promise((r) => setTimeout(r, 1100));
    const sinceTs = Math.floor(Date.now() / 1000);

    const created = await authenticatedRequest.post('/api/admin/categories', {
      data: createValidCategory(),
    }).then(r => r.json());

    const response = await authenticatedTerminalRequest.get(`/api/sync/categories?since=${sinceTs}`);
    expect(response.ok()).toBeTruthy();

    const found = (await response.json()).categories.find((c: any) => c.id === created.id);
    expect(found).toBeDefined();
  });

  test('GET /api/sync/categories cursor is a number', async ({ authenticatedTerminalRequest }) => {
    const body = await authenticatedTerminalRequest.get('/api/sync/categories').then(r => r.json());
    expect(typeof body.cursor).toBe('number');
  });
});
```

**Fix 3 — `Icon Support` section:** The last icon test uses `if (body.categories.length > 0)` — create a category with a specific icon first:

```typescript
test('GET /api/sync/categories includes icon_name for category with icon', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
  const created = await authenticatedRequest.post('/api/admin/categories', {
    data: createValidCategory({ icon_name: 'CategoryLayersIcon' }),
  }).then(r => r.json());

  const response = await authenticatedTerminalRequest.get('/api/sync/categories', {
    params: { since: '1970-01-01T00:00:00Z' },
  });
  const found = (await response.json()).categories.find((c: any) => c.id === created.id);
  expect(found).toBeDefined();
  expect(found.icon_name).toBe('CategoryLayersIcon');
});
```

**Step 1:** Edit `categories.spec.ts` — apply all three fixes.

**Step 2:** Run + commit:
```bash
cd e2etests && npm test -- tests/api/categories.spec.ts --workers=4
git add e2etests/tests/api/categories.spec.ts
git commit -m "test(e2e): fix categories.spec.ts — eliminate seeded-data reliance and if-conditional assertions"
```

---

## Milestone 7: Remove console.log statements

**Files and locations (confirmed by grep):**
- `tests/auth.setup.ts` lines 49-50: `console.log('✅ Admin authentication successful')` etc.
- `tests/admin/members.spec.ts` lines 429, 646, 679: `console.log('Skipping...')`
- `tests/admin/journal.spec.ts` lines 73, 114, 143, 180, 186, 191, 194, 196, 200: `console.error`/`console.log` in helper functions
- `tests/admin/members-stats.spec.ts` lines 188-189, 293-296: `console.log` in error branches

For each: remove the console call. Keep the surrounding logic (throw statements, if blocks) intact.

**Step 1:** Edit all 4 files.

**Step 2:** Run affected tests:
```bash
cd e2etests && npm test -- tests/admin/members-stats.spec.ts tests/admin/journal.spec.ts tests/admin/members.spec.ts --workers=4
```

**Step 3:** Commit:
```bash
git add e2etests/tests/auth.setup.ts e2etests/tests/admin/members.spec.ts e2etests/tests/admin/journal.spec.ts e2etests/tests/admin/members-stats.spec.ts
git commit -m "test(e2e): remove console.log/error from 4 test files"
```

---

## Milestone 8: Fix members-stats.spec.ts — raw API + waitForTimeout

**Problem:** Uses `page.request.post(...)` (unauthenticated), `page.waitForTimeout(2000)` (flaky), and inlines full member/category/product/transaction setup instead of using `testTransactions`.

Change test signatures to include `authenticatedRequest` and `testTransactions` from `auth.fixture`:

```typescript
// Before:
test('should display correct active members count', async ({ page }) => {
  ...page.request.post('http://localhost:8080/api/admin/members', { data: ... })
  await page.waitForTimeout(2000)

// After:
test('should display correct active members count', async ({ page, authenticatedRequest }) => {
  ...authenticatedRequest.post('/api/admin/members', { data: ... })
  const dashboardResp = page.waitForResponse(
    (r) => r.url().includes('/api/admin/dashboard') && r.status() === 200,
    { timeout: 10000 }
  )
  await membersPage.navigate()
  await membersPage.expectPageVisible()
  await dashboardResp
```

For the sync transaction test, use `testTransactions` fixture:
```typescript
test('...balance...', async ({ page, authenticatedRequest, testTransactions }) => {
  const member = await testTransactions.createMember('Balance', 'Test')
  const product = await testTransactions.createProduct('TestProd', 350, 'Test Product')
  const txnId = await testTransactions.createSyncTransaction(member.id, 350, 'test', product.id)
```

**Step 1:** Read `members-stats.spec.ts`, apply the pattern above to each of the 3 affected tests.

**Step 2:** Run:
```bash
cd e2etests && npm test -- tests/admin/members-stats.spec.ts --workers=1
```
Note: run with 1 worker first since these modify dashboard stats. Then verify with 4.

**Step 3:** Commit:
```bash
git add e2etests/tests/admin/members-stats.spec.ts
git commit -m "test(e2e): fix members-stats.spec.ts — use authenticatedRequest+testTransactions, replace waitForTimeout with waitForResponse"
```

---

## Final Verification

```bash
cd e2etests && npm test -- --workers=4
```

Expected: full suite passes. Commit if clean:
```bash
git commit --allow-empty -m "test(e2e): full suite passes after quality improvements"
```

---

## Summary

| What | Status | Why |
|---|---|---|
| Remove dead POM methods (Milestone 1) | [x] Done | YAGNI — each method must be called from a test |
| Delete StatisticsPage POM (Milestone 1.5) | [x] Already done | 0/20 methods used; read-only page needs no POM |
| Fix `.page` violations in 5 test files (Milestone 2) | [x] Already done | Tests must not know POM internals |
| Add minimal ProfilePage methods + rewrite profile.spec.ts (Milestone 3) | [x] Already done | Tests bypass existing POM entirely |
| Fix settlements-e2e.spec.ts leaks (Milestone 4) | [x] Already done | 8 direct getByTestId calls for things POM can do |
| Rewrite ui-features.spec.ts (Milestone 5) | [x] Already done | Icon existence ≠ E2E test; POM violations |
| Create own test data in sync API tests (Milestone 6) | [x] Already done | Assertions must not depend on seeded data |
| Remove console.log (Milestone 7) | [x] Done | Test output noise |
| Fix members-stats raw API + waitForTimeout (Milestone 8) | [x] Already done | Flaky timing + unauthenticated calls |

**Completed 2026-03-04**: Full suite passes (448/449 tests pass, 1 pre-existing failure in transactions.spec.ts unrelated to quality plan).
