# E2E Test Coverage Gaps — Fix Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix all broken export URLs in tests, eliminate meaningless assertions, and add missing UI-driven tests for settlement exports, journal correction modal, category confirm flows, and reports CSV export.

**Architecture:** All changes are in `e2etests/` only — no backend or frontend changes. The root cause pattern was tests written against backend route paths instead of the OpenAPI spec paths; the new UI-driven export tests use page object methods that click real buttons and capture network responses via `waitForResponse`, which would have caught the URL mismatch on day one.

**Tech Stack:** Playwright, TypeScript. Page Object Model (Pattern 006). Run tests from `e2etests/` with `npm test -- <file> --workers=4`.

---

## Chunk 1: Fix Broken URLs + Weak Assertions

### Task 1: Fix broken export URLs in `settlements.spec.ts`

**Files:**
- Modify: `e2etests/tests/api/settlements.spec.ts`

Eight occurrences of wrong paths must be changed. The backend routes (since the fix in commit 9503a93) are:
- `/export/sepa-xml` (was `/export-sepa`)
- `/export/csv` (was `/export-csv`)

- [ ] **Step 1: Replace all 8 occurrences**

  In `e2etests/tests/api/settlements.spec.ts`, replace every occurrence:

  | Old string | New string |
  |---|---|
  | `/export-sepa` | `/export/sepa-xml` |
  | `/export-csv` | `/export/csv` |

  Lines affected: F1 (385), F2 (401), F3 (420), F4 (430), G1 (448), G2 (462), G3 (481), plus auth test (432).

- [ ] **Step 2: Run tests and verify they pass**

  ```bash
  cd /Users/dg/dev/frgs-vereinsbar/e2etests
  npm test -- tests/api/settlements.spec.ts --workers=4
  ```

  Expected: All F-group and G-group tests pass. F2/F3/G1/G2/G3 may skip if no settlements exist in the database — that is acceptable.

- [ ] **Step 3: Commit**

  ```bash
  git add e2etests/tests/api/settlements.spec.ts
  git commit -m "fix(e2e): update settlement export API test URLs to match OAS spec"
  ```

---

### Task 2: Fix broken export URLs in `journal-and-settlements.spec.ts`

**Files:**
- Modify: `e2etests/tests/admin/journal-and-settlements.spec.ts`

- [ ] **Step 1: Replace 2 URL strings**

  Line 215:
  ```typescript
  // Before:
  const csvSummary = await authenticatedRequest.get(`/api/admin/settlements/${settlementId}/export-csv`)
  // After:
  const csvSummary = await authenticatedRequest.get(`/api/admin/settlements/${settlementId}/export/csv`)
  ```

  Line 242:
  ```typescript
  // Before:
  const sepaResp = await authenticatedRequest.get(`/api/admin/settlements/${settlementId}/export-sepa`)
  // After:
  const sepaResp = await authenticatedRequest.get(`/api/admin/settlements/${settlementId}/export/sepa-xml`)
  ```

- [ ] **Step 2: Run tests and verify they pass**

  ```bash
  cd /Users/dg/dev/frgs-vereinsbar/e2etests
  npm test -- tests/admin/journal-and-settlements.spec.ts --workers=4
  ```

  Expected: All 5 tests pass (or skip if no SEPA config in DB).

- [ ] **Step 3: Commit**

  ```bash
  git add e2etests/tests/admin/journal-and-settlements.spec.ts
  git commit -m "fix(e2e): update settlement export URLs in journal-and-settlements test"
  ```

---

### Task 3: Fix broken export URL in `admin-walkthrough.spec.ts`

**Files:**
- Modify: `e2etests/tests/walkthrough/admin-walkthrough.spec.ts`

- [ ] **Step 1: Fix URL match predicate**

  Around line 250:
  ```typescript
  // Before:
  resp => resp.url().includes('/export-sepa')
  // After:
  resp => resp.url().includes('/export/sepa-xml')
  ```

- [ ] **Step 2: Run walkthrough test**

  ```bash
  cd /Users/dg/dev/frgs-vereinsbar/e2etests
  npm test -- tests/walkthrough/admin-walkthrough.spec.ts --workers=1
  ```

  Expected: All walkthrough steps pass (serial mode since walkthrough uses `test.describe.configure({ mode: 'serial' })`).

- [ ] **Step 3: Commit**

  ```bash
  git add e2etests/tests/walkthrough/admin-walkthrough.spec.ts
  git commit -m "fix(e2e): update SEPA export URL match in admin walkthrough test"
  ```

---

### Task 4: Fix meaningless array assertion in `audit-log.spec.ts`

**Files:**
- Modify: `e2etests/tests/admin/audit-log.spec.ts`

The bug: `expect([value1, value2]).toBeTruthy()` — an array literal is always truthy, so this assertion proves nothing.

- [ ] **Step 1: Replace with meaningful assertion**

  Around line 261, find:
  ```typescript
  expect([firstTimestampBefore, firstTimestampAfter]).toBeTruthy()
  ```

  Replace with:
  ```typescript
  // Verify the sort interaction did not crash the page and values are readable.
  // (Both timestamps may be equal when there are ≤2 audit entries.)
  expect(typeof firstTimestampBefore).toBe('string')
  expect(firstTimestampBefore!.length).toBeGreaterThan(0)
  expect(typeof firstTimestampAfter).toBe('string')
  expect(firstTimestampAfter!.length).toBeGreaterThan(0)
  ```

- [ ] **Step 2: Run tests and verify they pass**

  ```bash
  cd /Users/dg/dev/frgs-vereinsbar/e2etests
  npm test -- tests/admin/audit-log.spec.ts --workers=4
  ```

  Expected: All audit-log tests pass.

- [ ] **Step 3: Commit**

  ```bash
  git add e2etests/tests/admin/audit-log.spec.ts
  git commit -m "fix(e2e): replace meaningless array toBeTruthy with real string assertions in audit-log sort test"
  ```

---

### Task 5: Fix weak assertions + remove dead code in `categories.spec.ts`

**Files:**
- Modify: `e2etests/tests/admin/categories.spec.ts`

Two issues:
1. `expect(message).toBeTruthy()` doesn't verify message has meaningful content
2. Empty `describe('UC-A44: Edit Category')` block (has `beforeEach` but zero test cases)

- [ ] **Step 1: Fix confirm dialog message assertion**

  Around line 213 (in `should show confirmation before delete`), find:
  ```typescript
  const message = await authenticatedCategoriesPage.getConfirmMessage()
  expect(message).toBeTruthy()
  ```

  Replace with:
  ```typescript
  const message = await authenticatedCategoriesPage.getConfirmMessage()
  expect(message).toBeTruthy()
  expect(message!.length).toBeGreaterThan(5) // meaningful dialog text, not just whitespace
  ```

- [ ] **Step 2: Remove dead empty describe block**

  Find and remove lines 133–142 (the `describe('UC-A44: Edit Category')` block that has `beforeEach` but no test cases inside):

  ```typescript
  // DELETE this entire block:
  test.describe('UC-A44: Edit Category', () => {
    test.beforeEach(async ({ authenticatedCategoriesPage }) => {
      // Create a test category for edit tests
      const categoryName = `Editable ${Date.now()}`
      await authenticatedCategoriesPage.createCategory({
        de: categoryName,
      })
    })

  })
  ```

- [ ] **Step 3: Run tests and verify they pass**

  ```bash
  cd /Users/dg/dev/frgs-vereinsbar/e2etests
  npm test -- tests/admin/categories.spec.ts --workers=4
  ```

  Expected: All category tests pass.

- [ ] **Step 4: Commit**

  ```bash
  git add e2etests/tests/admin/categories.spec.ts
  git commit -m "fix(e2e): fix weak dialog assertion and remove dead empty describe in categories test"
  ```

---

### Note: Loose auth status code checks (`[301, 302, 401, 403]`)

`settlements.spec.ts` uses `expect([301, 302, 401, 403]).toContain(response.status())` for auth tests. This is deliberately left unchanged — the broad set accommodates possible redirect-based auth behaviour in certain server configurations. Tightening to 401-only is a separate clean-up with risk of flakiness, and the auth endpoint logic is already covered by dedicated auth tests in `api/admin-auth.spec.ts`.

---

### Task 6: Fix weak `toBeTruthy()` assertions in `journal-and-settlements.spec.ts`

**Files:**
- Modify: `e2etests/tests/admin/journal-and-settlements.spec.ts`

- [ ] **Step 1: Fix date and amount row assertions**

  Around line 61:
  ```typescript
  // Before:
  expect(row0.date).toBeTruthy()
  // After:
  expect(row0.date).toMatch(/\d{2}[./]\d{2}[./]\d{4}/)
  ```

  Around line 65:
  ```typescript
  // Before:
  expect(row0.amount).toBeTruthy()
  // After:
  expect(row0.amount).toMatch(/[\d.,]+/)
  ```

  Around line 202:
  ```typescript
  // Before:
  expect(settlementId).toBeTruthy()
  // After:
  expect(settlementId).toMatch(/^[0-9a-f-]{36}$/) // UUID format
  ```

- [ ] **Step 2: Run tests and verify they pass**

  ```bash
  cd /Users/dg/dev/frgs-vereinsbar/e2etests
  npm test -- tests/admin/journal-and-settlements.spec.ts --workers=4
  ```

  Expected: All 5 tests pass.

- [ ] **Step 3: Commit**

  ```bash
  git add e2etests/tests/admin/journal-and-settlements.spec.ts
  git commit -m "fix(e2e): replace toBeTruthy() with format-specific assertions in journal test"
  ```

---

## Chunk 2: UI-Driven Settlement Exports

### Task 7: Add export button methods to `SettlementsPage.ts`

**Files:**
- Modify: `e2etests/pages/SettlementsPage.ts`

These page object methods click the real UI buttons and capture the network response. Tests that use them exercise the full frontend→API chain, making URL mismatches immediately visible.

- [ ] **Step 1: Add three export methods to SettlementsPage**

  Add after the `expectUndoButtonDisabled` method (after line 101):

  ```typescript
  /**
   * Click the "Export SEPA XML" button for a settlement and wait for the download response.
   * Returns the Playwright Response so tests can assert on content-type and body.
   */
  async clickExportSepa(settlementId: string): Promise<import('@playwright/test').Response> {
    const responsePromise = this.page.waitForResponse(
      (resp) =>
        resp.url().includes(`/export/sepa-xml`) &&
        resp.status() === 200
    )
    await this.page.getByTestId(`settlements-export-sepa-btn-${settlementId}`).click()
    return responsePromise
  }

  /**
   * Click the "Export CSV" (summary) button for a settlement and wait for the download response.
   */
  async clickExportCsv(settlementId: string): Promise<import('@playwright/test').Response> {
    const responsePromise = this.page.waitForResponse(
      (resp) =>
        resp.url().includes(`/export/csv`) &&
        resp.status() === 200
    )
    await this.page.getByTestId(`settlements-export-csv-btn-${settlementId}`).click()
    return responsePromise
  }

  /**
   * Click the "Export Transactions CSV" button for a settlement and wait for the download response.
   */
  async clickExportTransactionsCsv(settlementId: string): Promise<import('@playwright/test').Response> {
    const responsePromise = this.page.waitForResponse(
      (resp) =>
        resp.url().includes(`/export-transactions`) &&
        resp.status() === 200
    )
    await this.page.getByTestId(`settlements-export-transactions-btn-${settlementId}`).click()
    return responsePromise
  }
  ```

- [ ] **Step 2: Verify TypeScript compiles**

  ```bash
  cd /Users/dg/dev/frgs-vereinsbar/e2etests
  npx tsc --noEmit
  ```

  Expected: No type errors.

- [ ] **Step 3: Commit**

  ```bash
  git add e2etests/pages/SettlementsPage.ts
  git commit -m "feat(e2e): add clickExportSepa/Csv/TransactionsCsv methods to SettlementsPage"
  ```

---

### Task 8: Replace direct API export calls with UI button clicks in `journal-and-settlements.spec.ts`

**Files:**
- Modify: `e2etests/tests/admin/journal-and-settlements.spec.ts`

> **Note:** Task 6 already fixed the URL strings in this file (lines 215 and 242) to the correct `/export/csv` and `/export/sepa-xml` paths. Task 8 now supersedes those intermediate direct-API calls entirely by replacing them with UI button clicks. Apply Task 6 before Task 8.

This replaces the `authenticatedRequest.get(…/export-…)` calls in the settlement lifecycle test with real button clicks via the SettlementsPage page object.

**Why this matters:** The old approach bypassed the frontend entirely. A button wired to the wrong URL would never be caught. The new approach makes that impossible — if the button calls the wrong URL, `waitForResponse` times out.

- [ ] **Step 1: Replace CSV summary export**

  Find (around line 214):
  ```typescript
  // ── Export summary CSV ────────────────────────────────────────────
  const csvSummary = await authenticatedRequest.get(`/api/admin/settlements/${settlementId}/export/csv`)
  expect(csvSummary.status()).toBe(200)
  const csvText = await csvSummary.text()
  ```

  Replace with:
  ```typescript
  // ── Export summary CSV (via UI button) ────────────────────────────
  const csvSummary = await settlementsPage.clickExportCsv(settlementId)
  expect(csvSummary.headers()['content-type']).toContain('csv')
  const csvText = await csvSummary.text()
  ```

- [ ] **Step 2: Replace transactions CSV export**

  Find (around line 225):
  ```typescript
  // ── Export detail CSV ─────────────────────────────────────────────
  const csvDetail = await authenticatedRequest.get(`/api/admin/settlements/${settlementId}/export-transactions`)
  expect(csvDetail.status()).toBe(200)
  ```

  Replace with:
  ```typescript
  // ── Export transactions CSV (via UI button) ───────────────────────
  const csvDetail = await settlementsPage.clickExportTransactionsCsv(settlementId)
  expect(csvDetail.headers()['content-type']).toContain('csv')
  ```

- [ ] **Step 3: Replace SEPA XML export**

  Find (around line 242):
  ```typescript
  const sepaResp = await authenticatedRequest.get(`/api/admin/settlements/${settlementId}/export/sepa-xml`)
  expect(sepaResp.status()).toBe(200)
  expect(sepaResp.headers()['content-type']).toContain('xml')
  ```

  Replace with:
  ```typescript
  // ── Export SEPA XML (via UI button) ───────────────────────────────
  const sepaResp = await settlementsPage.clickExportSepa(settlementId)
  expect(sepaResp.headers()['content-type']).toContain('xml')
  ```

  Leave the remaining assertions on `xml` body content unchanged.

- [ ] **Step 4: Run tests and verify they pass**

  ```bash
  cd /Users/dg/dev/frgs-vereinsbar/e2etests
  npm test -- tests/admin/journal-and-settlements.spec.ts --workers=4
  ```

  Expected: All 5 tests pass. The settlement lifecycle test now exercises the actual export buttons.

- [ ] **Step 5: Commit**

  ```bash
  git add e2etests/tests/admin/journal-and-settlements.spec.ts
  git commit -m "feat(e2e): replace direct API export calls with UI button clicks in settlement lifecycle test"
  ```

---

## Chunk 3: Missing Modal and Confirm Flow Coverage

### Task 9: Add correction modal methods to `JournalPage.ts`

**Files:**
- Modify: `e2etests/pages/JournalPage.ts`

The correction modal has these test IDs (verified in `admin-frontend/src/pages/JournalPage.tsx`):
- `journal-create-correction-btn` — opens modal
- `journal-correction-modal` — modal container
- `journal-correction-member-select` — member `<select>` element
- `journal-correction-amount-input` — amount `<input>`
- `journal-correction-reason-input` — reason `<textarea>`
- `journal-correction-submit-btn` — submit button

- [ ] **Step 1: Add private locators and public methods to JournalPage**

  Add to the private locators section:
  ```typescript
  private readonly createCorrectionBtn = () => this.page.getByTestId('journal-create-correction-btn')
  private readonly correctionModal = () => this.page.getByTestId('journal-correction-modal')
  private readonly correctionMemberSelect = () => this.page.getByTestId('journal-correction-member-select')
  private readonly correctionAmountInput = () => this.page.getByTestId('journal-correction-amount-input')
  private readonly correctionReasonInput = () => this.page.getByTestId('journal-correction-reason-input')
  private readonly correctionSubmitBtn = () => this.page.getByTestId('journal-correction-submit-btn')
  ```

  Add public methods:
  ```typescript
  async openCorrectionModal() {
    await this.createCorrectionBtn().click()
    await expect(this.correctionModal()).toBeVisible()
  }

  async fillCorrectionForm(params: {
    memberId: string
    amountEur: number  // Input renders EUR (e.g. 7.50), not cents — JournalPage.tsx: value={correctionForm.amountCents / 100}
    reason: string
  }) {
    await this.correctionMemberSelect().selectOption(params.memberId)
    await this.correctionAmountInput().fill(String(params.amountEur))
    await this.correctionReasonInput().fill(params.reason)
  }

  async submitCorrectionForm(): Promise<import('@playwright/test').Response> {
    const responsePromise = this.page.waitForResponse(
      (resp) =>
        resp.url().includes('/admin/members/') &&
        resp.url().includes('/transactions') &&
        resp.status() === 201
    )
    await this.correctionSubmitBtn().click()
    return responsePromise
  }

  async expectCorrectionModalHidden() {
    await expect(this.correctionModal()).toBeHidden()
  }
  ```

- [ ] **Step 2: Verify TypeScript compiles**

  ```bash
  cd /Users/dg/dev/frgs-vereinsbar/e2etests
  npx tsc --noEmit
  ```

  Expected: No type errors.

- [ ] **Step 3: Commit**

  ```bash
  git add e2etests/pages/JournalPage.ts
  git commit -m "feat(e2e): add correction modal methods to JournalPage page object"
  ```

---

### Task 10: Add "Create Correction via UI" test to `journal-and-settlements.spec.ts`

**Files:**
- Modify: `e2etests/tests/admin/journal-and-settlements.spec.ts`

- [ ] **Step 1: Write the test**

  Add a new test inside the `test.describe('Journal & Settlements')` block:

  ```typescript
  test('journal: create correction via UI modal', async ({
    page,
    testTransactions,
    authenticatedRequest,
  }) => {
    const ts = Date.now()
    const prefix = `CorrUI${ts}`

    // Create a member with SEPA data (correction requires member to exist)
    const member = await testTransactions.createMember(`${prefix}First`, `${prefix}Last`)

    const journalPage = new JournalPage(page)
    await journalPage.navigate()
    await journalPage.waitForPageLoad()

    // Get baseline transaction count for this member
    await journalPage.search(`${prefix}Last`)
    await journalPage.waitForTableToLoad()
    const countBefore = await journalPage.getTransactionCount()

    // Open correction modal and fill form
    await journalPage.openCorrectionModal()
    await journalPage.fillCorrectionForm({
      memberId: member.id,
      amountEur: 7.50,   // input displays EUR; backend stores 750 cents
      reason: `${prefix} UI correction test`,
    })

    // Submit and capture API response
    const response = await journalPage.submitCorrectionForm()
    expect(response.status()).toBe(201)
    const body = await response.json()
    expect(body.transaction_type).toBe('correction')
    expect(body.amount_cents).toBe(750)

    // Modal should close after successful submission
    await journalPage.expectCorrectionModalHidden()

    // New correction should appear in the journal for this member
    await journalPage.search(`${prefix}Last`)
    await journalPage.waitForTableToLoad()
    await expect.poll(() => journalPage.getTransactionCount(), { timeout: 10000 })
      .toBe(countBefore + 1)
  })
  ```

- [ ] **Step 2: Run the test to verify it passes**

  ```bash
  cd /Users/dg/dev/frgs-vereinsbar/e2etests
  npm test -- --grep "create correction via UI modal" --workers=4
  ```

  Expected: Test passes. If it fails with a selector error on `journal-correction-member-select`, check whether the member select renders member IDs or names as option values — inspect the component around line 1227 in `admin-frontend/src/pages/JournalPage.tsx`.

- [ ] **Step 3: Run full journal-and-settlements suite**

  ```bash
  cd /Users/dg/dev/frgs-vereinsbar/e2etests
  npm test -- tests/admin/journal-and-settlements.spec.ts --workers=4
  ```

  Expected: All tests pass (6 now including the new one).

- [ ] **Step 4: Commit**

  ```bash
  git add e2etests/tests/admin/journal-and-settlements.spec.ts
  git commit -m "feat(e2e): add UI-driven correction modal test to journal suite"
  ```

---

### Task 11: Add confirm-activate and confirm-delete tests to `categories.spec.ts`

**Files:**
- Modify: `e2etests/tests/admin/categories.spec.ts`

Currently, both confirm paths (click OK on the activate dialog, click OK on the delete dialog) are never exercised — only the cancel paths are tested.

The `CategoriesPage` page object already has `confirmDelete()` (line 301). Check if it also has a `confirmStatusChange()` or similar; if not, it needs to be added first (add it in `CategoriesPage.ts`).

- [ ] **Step 1: Check if `CategoriesPage.ts` has a confirmStatusChange method**

  ```bash
  grep -n "confirmStatus\|confirmActivat\|confirm-dialog-ok" \
    /Users/dg/dev/frgs-vereinsbar/e2etests/pages/CategoriesPage.ts
  ```

  If the method exists, skip Step 2. If not, proceed.

- [ ] **Step 2 (if needed): Add `confirmStatusChange()` to CategoriesPage.ts**

  ```typescript
  // Add after confirmDelete():
  async confirmStatusChange() {
    await this.page.getByTestId('confirm-dialog-ok').click()
    await expect(this.confirmDialog()).toBeHidden()
  }
  ```

- [ ] **Step 3: Add confirm-activate test**

  In the `UC-A44: Activate/Deactivate Category` describe block, add after the existing cancel test:

  ```typescript
  test('should activate category when confirm dialog is confirmed', async ({ authenticatedCategoriesPage }) => {
    const categoryName = `ActConfirm ${Date.now()}`
    await authenticatedCategoriesPage.createCategory({ de: categoryName })

    const categoryId = await authenticatedCategoriesPage.findCategoryByName(categoryName)
    expect(categoryId).toBeTruthy()

    // Deactivate first (immediate, no dialog)
    await authenticatedCategoriesPage.toggleCategoryStatus(categoryId!)
    await authenticatedCategoriesPage.expectConfirmDialogHidden()
    expect(await authenticatedCategoriesPage.getCategoryStatus(categoryId!)).toBe('Inactive')

    // Activate: shows confirm dialog
    await authenticatedCategoriesPage.clickStatusToggleExpectingDialog(categoryId!)
    await authenticatedCategoriesPage.expectConfirmDialogVisible()

    // Confirm → category becomes Active
    await authenticatedCategoriesPage.confirmStatusChange()
    const status = await authenticatedCategoriesPage.getCategoryStatus(categoryId!)
    expect(status).toBe('Active')
  })
  ```

- [ ] **Step 4: Add confirm-delete test**

  In the `UC-A44: Delete Category` describe block, add after the existing cancel test:

  ```typescript
  test('should delete category when confirm dialog is confirmed', async ({ authenticatedCategoriesPage }) => {
    const categoryName = `ConfirmDel ${Date.now()}`
    await authenticatedCategoriesPage.createCategory({ de: categoryName })

    const categoryId = await authenticatedCategoriesPage.findCategoryByName(categoryName)
    expect(categoryId).toBeTruthy()

    const countBefore = await authenticatedCategoriesPage.getCategoryCount()

    // Trigger delete → confirm dialog appears
    await authenticatedCategoriesPage.deleteCategory(categoryId!)
    await authenticatedCategoriesPage.expectConfirmDialogVisible()

    // Confirm delete
    await authenticatedCategoriesPage.confirmDelete()
    await authenticatedCategoriesPage.expectConfirmDialogHidden()

    // Category should be gone
    const countAfter = await authenticatedCategoriesPage.getCategoryCount()
    expect(countAfter).toBe(countBefore - 1)

    const deletedId = await authenticatedCategoriesPage.findCategoryByName(categoryName)
    expect(deletedId).toBeNull()
  })
  ```

- [ ] **Step 5: Run tests and verify they pass**

  ```bash
  cd /Users/dg/dev/frgs-vereinsbar/e2etests
  npm test -- tests/admin/categories.spec.ts --workers=4
  ```

  Expected: All category tests pass including the two new confirm tests.

- [ ] **Step 6: Commit**

  ```bash
  git add e2etests/pages/CategoriesPage.ts e2etests/tests/admin/categories.spec.ts
  git commit -m "feat(e2e): add confirm-activate and confirm-delete tests for categories"
  ```

---

## Chunk 4: Reports Export Click

### Task 12: Add Reports export CSV click test to `reports.spec.ts`

**Files:**
- Modify: `e2etests/tests/admin/reports.spec.ts`

Currently the export CSV button (`data-testid="report-export-csv"`) is only checked for visibility — it is never clicked. This test clicks it and verifies the API response.

The export URL is `/api/admin/reports/{reportType}/export?format=csv`. The `reportType` defaults to the active tab (`revenue` on load).

- [ ] **Step 1: Add the export test to the Revenue tab describe block**

  Inside `test.describe('Revenue Tab')`, add after the existing tests:

  ```typescript
  test('should trigger CSV export download when export button is clicked', async ({ page }) => {
    await waitForReportLoaded(page)

    // Intercept the export response before clicking
    const exportResponsePromise = page.waitForResponse(
      (resp) =>
        resp.url().includes('/reports/revenue/export') &&
        resp.status() === 200
    )

    await page.getByTestId('report-export-csv').click()
    const exportResponse = await exportResponsePromise

    expect(exportResponse.status()).toBe(200)
    const contentType = exportResponse.headers()['content-type']
    expect(contentType).toMatch(/csv|octet-stream/)
  })
  ```

- [ ] **Step 2: Run the test**

  ```bash
  cd /Users/dg/dev/frgs-vereinsbar/e2etests
  npm test -- --grep "trigger CSV export download" --workers=4
  ```

  Expected: Test passes. If it fails with a network timeout, check that the `/reports/revenue/export` backend route exists and responds with 200.

- [ ] **Step 3: Run full reports suite**

  ```bash
  cd /Users/dg/dev/frgs-vereinsbar/e2etests
  npm test -- tests/admin/reports.spec.ts --workers=4
  ```

  Expected: All reports tests pass.

- [ ] **Step 4: Commit**

  ```bash
  git add e2etests/tests/admin/reports.spec.ts
  git commit -m "feat(e2e): add report CSV export click test (was visibility-only)"
  ```

---

## Final Verification

- [ ] **Run full test suite**

  ```bash
  cd /Users/dg/dev/frgs-vereinsbar/e2etests
  npm test -- --workers=4
  ```

  Expected: All tests pass. No regressions.

- [ ] **Update plans/INDEX.md**

  Mark this plan as complete with today's date.
