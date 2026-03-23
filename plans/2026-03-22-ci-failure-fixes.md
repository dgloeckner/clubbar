# CI Failure Fixes Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix six independent CI failures identified in build run https://github.com/dgloeckner/clubbar/actions/runs/23398947913.

**Architecture:** Each failure is a self-contained bug with a targeted fix. Issues A–C have confirmed root causes and minimal fixes. Issues D–F have likely root causes and require local verification before fixing.

**Tech Stack:** Flutter/Dart (build_runner), TypeScript/Playwright (E2E page objects + tests), GitHub Actions YAML.

---

## File Map

| File | Change |
|------|--------|
| `.github/workflows/build.yaml:439` | Add `flutter pub run build_runner build` step before `flutter test` |
| `e2etests/pages/CategoriesPage.ts:270` | Fix wrong `/status` suffix in `toggleCategoryStatus` waitForResponse URL |
| `e2etests/pages/ProfilePage.ts` | Add `fillCurrentPassword()` method |
| `e2etests/tests/admin/profile.spec.ts:48-53` | Add `fillCurrentPassword` call before `clickChangePassword` |
| `e2etests/pages/MembersPage.ts:232-234` | Make `submitForm()` wait for the API response (POST 201 or PATCH 200) |
| `e2etests/tests/admin/i18n-language-switch.spec.ts:24-35` | Replace `authenticatedRequest.patch` with `page.request.patch` + csrfHeaders |
| `e2etests/pages/AuditLogPage.ts:101-115, 137-152` | Add loading-indicator wait after `filterByAction` and `search` |

---

## Chunk 1: Confirmed Root-Cause Fixes (Issues A–C)

### Task 1: Add build_runner to CI workflow (Issue A)

**Root cause:** `lib/generated/` is in `.gitignore`. CI checks out the repo and runs `flutter test` without first regenerating the generated files, causing compile errors.

**Files:**
- Modify: `.github/workflows/build.yaml:439-441`

- [ ] **Step 1: Insert build_runner step**

In `.github/workflows/build.yaml`, locate the `build-terminal` job. Between the `Get dependencies` step (`flutter pub get`) and the `Run unit tests` step (`flutter test`), add:

```yaml
      - name: Generate code (build_runner)
        run: flutter pub run build_runner build --delete-conflicting-outputs
        working-directory: terminal-frontend
```

The final sequence of steps in `build-terminal` should be:
```yaml
      - name: Get dependencies
        run: flutter pub get
        working-directory: terminal-frontend

      - name: Generate code (build_runner)
        run: flutter pub run build_runner build --delete-conflicting-outputs
        working-directory: terminal-frontend

      - name: Run unit tests
        run: flutter test
        working-directory: terminal-frontend

      - name: Run integration tests
        run: xvfb-run flutter test integration_test/ --exclude-tags=walkthrough
        ...
```

- [ ] **Step 2: Verify the YAML is syntactically valid**

```bash
# Confirm indentation is correct (2-space, consistent with rest of file)
head -450 .github/workflows/build.yaml | tail -20
```

Expected: new step visible with correct 6-space indent under `steps:`.

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/build.yaml
git commit -m "fix(ci): run build_runner before flutter test in build-terminal job

lib/generated/ is gitignored — generated files must be regenerated
before tests can compile. Without this step the CI unit test job fails
with 'Target of URI doesn't exist' errors."
```

---

### Task 2: Fix CategoriesPage status toggle URL (Issue B)

**Root cause:** `CategoriesPage.ts:270` waits for a PATCH to `/admin/categories/${categoryId}/status`, but the orval-generated API client (`updateCategory`) sends PATCH to `/admin/categories/${categoryId}` — no `/status` suffix. The waitForResponse promise times out at 10 seconds and the test fails.

**Files:**
- Modify: `e2etests/pages/CategoriesPage.ts:268-275`

- [ ] **Step 1: Verify the actual API URL used by the frontend**

```bash
grep -n "updateCategory\|categories.*PATCH\|PATCH.*categories" \
  admin-frontend/src/api/generated/products/products.ts \
  admin-frontend/src/pages/CategoriesPage.tsx
```

Expected output: `updateCategory` sends PATCH to `/admin/categories/${categoryId}` (no `/status`).

- [ ] **Step 2: Fix the waitForResponse URL matcher**

In `e2etests/pages/CategoriesPage.ts`, change `toggleCategoryStatus` from:

```typescript
  async toggleCategoryStatus(categoryId: string) {
    const responsePromise = this.page.waitForResponse(
      (r) => r.url().includes(`/admin/categories/${categoryId}/status`) && r.request().method() === 'PATCH',
      { timeout: 10000 }
    )
    await this.page.getByTestId(`categories-status-toggle-${categoryId}`).click()
    await responsePromise
  }
```

To:

```typescript
  async toggleCategoryStatus(categoryId: string) {
    const responsePromise = this.page.waitForResponse(
      (r) => {
        const url = new URL(r.url())
        return url.pathname.endsWith(`/admin/categories/${categoryId}`) && r.request().method() === 'PATCH'
      },
      { timeout: 10000 }
    )
    await this.page.getByTestId(`categories-status-toggle-${categoryId}`).click()
    await responsePromise
  }
```

Using `url.pathname.endsWith(...)` avoids false matches from query strings and is the same URL-safe pattern used elsewhere in the codebase.

- [ ] **Step 3: Run the relevant E2E test**

```bash
cd e2etests
npm test -- --grep "UC-A44\|toggleStatus\|category status" --workers=1
```

Expected: Tests that call `toggleCategoryStatus` pass. If no test name matches, run the full categories suite:

```bash
npm test -- tests/admin/categories.spec.ts --workers=1
```

Expected: All categories tests pass.

- [ ] **Step 4: Commit**

```bash
git add e2etests/pages/CategoriesPage.ts
git commit -m "fix(e2e): fix toggleCategoryStatus to wait for correct PATCH URL

orval-generated updateCategory sends PATCH to /admin/categories/:id
(no /status suffix). The waitForResponse was matching the wrong URL
and timing out after 10s."
```

---

### Task 3: Fix profile mismatch test + add fillCurrentPassword (Issue C)

**Root cause:** `ProfilePage.tsx handleChangePassword` validates `currentPassword` first (`required` check). The test at `profile.spec.ts:48` fills only `newPassword` and `confirmPassword`, then clicks the button expecting a "stimmen nicht überein" (mismatch) error. But the handler returns "Pflichtfeld" (required) for `currentPassword` before even reaching the mismatch check.

Fix: add a `currentPassword` fill to the test (any non-empty value works; the mismatch check fires after required checks pass).

**Files:**
- Modify: `e2etests/pages/ProfilePage.ts` (add `fillCurrentPassword` method)
- Modify: `e2etests/tests/admin/profile.spec.ts:48-53`

- [ ] **Step 1: Add `fillCurrentPassword` to ProfilePage**

In `e2etests/pages/ProfilePage.ts`, after the existing `fillNewPassword` method (around line 139), add:

```typescript
  async fillCurrentPassword(password: string) {
    const input = this.page.locator('[data-testid="password-current"]')
    await input.fill(password)
  }
```

The `data-testid="password-current"` matches the input at `ProfilePage.tsx:310`.

- [ ] **Step 2: Update the profile test to fill currentPassword**

In `e2etests/tests/admin/profile.spec.ts`, change the `should reject mismatched passwords` test from:

```typescript
  test('should reject mismatched passwords with error', async ({ authenticatedProfilePage }) => {
    await authenticatedProfilePage.fillNewPassword('NewPassword123')
    await authenticatedProfilePage.fillConfirmPassword('DifferentPassword456')
    await authenticatedProfilePage.clickChangePassword()
    await authenticatedProfilePage.expectPasswordError('stimmen nicht überein')
  })
```

To:

```typescript
  test('should reject mismatched passwords with error', async ({ authenticatedProfilePage }) => {
    await authenticatedProfilePage.fillCurrentPassword('AnyValue1!')    // satisfies required check
    await authenticatedProfilePage.fillNewPassword('NewPassword123')
    await authenticatedProfilePage.fillConfirmPassword('DifferentPassword456')
    await authenticatedProfilePage.clickChangePassword()
    await authenticatedProfilePage.expectPasswordError('stimmen nicht überein')
  })
```

- [ ] **Step 3: Also fix `should reject weak password`** (same issue — also missing currentPassword)

In `profile.spec.ts`, change the `should reject weak password` test from:

```typescript
  test('should reject weak password', async ({ authenticatedProfilePage }) => {
    await authenticatedProfilePage.fillNewPassword('weak')
    await authenticatedProfilePage.fillConfirmPassword('weak')
    await authenticatedProfilePage.clickChangePassword()
    await authenticatedProfilePage.expectPasswordError()
  })
```

To:

```typescript
  test('should reject weak password', async ({ authenticatedProfilePage }) => {
    await authenticatedProfilePage.fillCurrentPassword('AnyValue1!')    // satisfies required check
    await authenticatedProfilePage.fillNewPassword('weak')
    await authenticatedProfilePage.fillConfirmPassword('weak')
    await authenticatedProfilePage.clickChangePassword()
    await authenticatedProfilePage.expectPasswordError()
  })
```

- [ ] **Step 4: Run the profile tests**

```bash
cd e2etests
npm test -- tests/admin/profile.spec.ts --workers=1
```

Expected: All profile tests pass.

- [ ] **Step 5: Commit**

```bash
git add e2etests/pages/ProfilePage.ts e2etests/tests/admin/profile.spec.ts
git commit -m "fix(e2e): add currentPassword to profile password validation tests

handleChangePassword validates currentPassword (required) before checking
mismatch. Tests were missing currentPassword fill, getting 'required' error
instead of the expected mismatch/weak-password errors."
```

---

## Chunk 2: Race Condition Fixes (Issues D–F)

### Task 4: Fix MembersPage.submitForm race condition (Issue E)

**Root cause:** `MembersPage.ts submitForm()` only clicks the button — it does NOT wait for the API response. The test immediately calls `search(firstName)` after `submitForm()`, which fires before the POST/PATCH response is received. The member list is then re-fetched with the search term while the create is still in-flight, potentially returning empty results.

**Files:**
- Modify: `e2etests/pages/MembersPage.ts:232-234`

- [ ] **Step 1: Update submitForm to wait for API response**

In `e2etests/pages/MembersPage.ts`, change `submitForm` from:

```typescript
  async submitForm() {
    await this.formSubmitBtn().click()
  }
```

To:

```typescript
  async submitForm() {
    // Wait for the API response (POST 201 for create, PATCH 200 for edit).
    // This prevents race conditions where the subsequent search fires before
    // the backend has committed the new/updated member.
    const responsePromise = this.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/members') &&
        (resp.request().method() === 'POST' || resp.request().method() === 'PATCH') &&
        (resp.status() === 200 || resp.status() === 201),
      { timeout: 15000 }
    )
    await this.formSubmitBtn().click()
    await responsePromise
  }
```

- [ ] **Step 2: Run the members E2E tests**

```bash
cd e2etests
npm test -- tests/admin/members.spec.ts --workers=4
```

Expected: All members tests pass, including "member CRUD lifecycle" which calls `submitForm()` then `search()`.

- [ ] **Step 3: Commit**

```bash
git add e2etests/pages/MembersPage.ts
git commit -m "fix(e2e): make MembersPage.submitForm wait for API response

submitForm was returning immediately after clicking the button, causing
race conditions when the subsequent search fired before the POST/PATCH
completed. Now waits for the API response before returning."
```

---

### Task 5: Fix i18n beforeEach to avoid extra login attempts (Issue D)

**Root cause:** The `i18n-language-switch.spec.ts` `beforeEach` uses `authenticatedRequest.patch(...)` to reset the admin locale. The `authenticatedRequest` fixture creates a fresh login for every test. In parallel mode (4 workers) the i18n describe block's 4 tests each create a fresh login in `beforeEach` simultaneously — in addition to the fresh logins from other test suites running in parallel. This can trigger the auth rate limiter.

The simpler fix: use `page.request.patch(...)` + `csrfHeaders(page)`. The `page` fixture already holds a valid session (from the `storageState` in `auth.json`), so no extra login is needed for a locale reset.

**Files:**
- Modify: `e2etests/tests/admin/i18n-language-switch.spec.ts:1-35`

- [ ] **Step 1: Add csrfHeaders import**

At the top of `e2etests/tests/admin/i18n-language-switch.spec.ts`, the file currently imports from `auth.fixture`:

```typescript
import { test } from '../../fixtures/auth.fixture'
```

Change this to also import `csrfHeaders`:

```typescript
import { test } from '../../fixtures/auth.fixture'
import { csrfHeaders } from '../../utils/csrf'
```

- [ ] **Step 2: Change beforeEach to use page.request**

Replace the `beforeEach` block (lines 24–35):

```typescript
  test.beforeEach(async ({ page, authenticatedRequest }) => {
    // Reset admin's locale to German via API before each test
    await authenticatedRequest.patch(`${API_BASE}/auth/profile`, {
      data: { locale: 'de' },
    })

    // Clear localStorage to ensure clean state (i18n reads from localStorage)
    await page.goto('/members', { waitUntil: 'domcontentloaded' })
    await page.evaluate(() => {
      localStorage.setItem('adminLocale', 'de')
    })
  })
```

With:

```typescript
  test.beforeEach(async ({ page }) => {
    // Navigate to a page first so the page context has loaded cookies+localStorage
    await page.goto('/members', { waitUntil: 'domcontentloaded' })

    // Reset admin's locale to German via the existing browser session.
    // Using page.request avoids a fresh login (which risks hitting the rate limiter
    // when multiple tests run in parallel).
    const headers = await csrfHeaders(page)
    await page.request.patch(`${API_BASE}/auth/profile`, {
      data: { locale: 'de' },
      headers,
    })

    // Sync localStorage so the frontend picks up the reset locale
    await page.evaluate(() => {
      localStorage.setItem('adminLocale', 'de')
    })
  })
```

Note: this removes `authenticatedRequest` from the fixture list for `beforeEach`. The test functions that use `authenticatedRequest` directly still get it.

- [ ] **Step 3: Run the i18n tests**

```bash
cd e2etests
npm test -- tests/admin/i18n-language-switch.spec.ts --workers=4
```

Expected: All 4 i18n tests pass without 401 errors.

- [ ] **Step 4: Check backend logs for any auth errors**

```bash
TODAY=$(date +%Y-%m-%d)
docker compose exec backend tail -50 /app/logs/$TODAY.log | jq 'select(.level == "ERROR")'
```

Expected: No auth-related errors.

- [ ] **Step 5: Commit**

```bash
git add e2etests/tests/admin/i18n-language-switch.spec.ts
git commit -m "fix(e2e): use page.request for i18n beforeEach locale reset

authenticatedRequest created a fresh login per test, risking rate-limit
errors when 4 parallel workers all login simultaneously. page.request
reuses the existing session stored in auth.json (no extra login needed)."
```

---

### Task 6: Fix AuditLogPage race condition after filterByAction and search (Issue F)

**Root cause:** `filterByEntityType` correctly waits for the loading indicator to disappear after the API response (`audit-log-loading`). But `filterByAction` and `search` do NOT. When tests call `filterByAction` then `filterByEntityType` then `search` in sequence, the loading-wait in `filterByEntityType` partially compensates — but `search` returns as soon as the response arrives, before React has committed the filtered result to the DOM. `expectEntryExists` then runs against stale DOM.

**Files:**
- Modify: `e2etests/pages/AuditLogPage.ts:101-115` (`filterByAction`)
- Modify: `e2etests/pages/AuditLogPage.ts:137-152` (`search`)

- [ ] **Step 1: Investigate locally first**

Run the audit log E2E tests to confirm the failure pattern:

```bash
cd e2etests
npm test -- tests/admin/audit-log-e2e.spec.ts --workers=1
```

Look for which specific test step fails (step 3 "Verify entry exists" or earlier). If it fails at `expectEntryExists`, the fix below applies. If it fails earlier (e.g., `search()` timeout), there may be a different root cause — check backend logs:

```bash
TODAY=$(date +%Y-%m-%d)
docker compose exec backend tail -100 /app/logs/$TODAY.log | jq 'select(.level != "INFO")'
```

- [ ] **Step 2: Add loading-indicator wait to filterByAction**

In `e2etests/pages/AuditLogPage.ts`, change `filterByAction` from:

```typescript
  async filterByAction(action: string) {
    const responsePromise = this.page.waitForResponse((resp) => {
      if (!resp.url().includes('/api/admin/audit-log') || resp.status() !== 200) return false
      try {
        return new URL(resp.url()).searchParams.get('action') === action
      } catch {
        return false
      }
    })
    const select = this.page.getByTestId('audit-log-filter-action')
    await select.selectOption(action)
    await responsePromise
  }
```

To:

```typescript
  async filterByAction(action: string) {
    const responsePromise = this.page.waitForResponse((resp) => {
      if (!resp.url().includes('/api/admin/audit-log') || resp.status() !== 200) return false
      try {
        return new URL(resp.url()).searchParams.get('action') === action
      } catch {
        return false
      }
    })
    const select = this.page.getByTestId('audit-log-filter-action')
    await select.selectOption(action)
    await responsePromise
    // Pattern 008: wait for loading indicator to clear, confirming React has rendered the filtered data
    await expect(this.page.getByTestId('audit-log-loading')).toBeHidden({ timeout: 10000 })
  }
```

- [ ] **Step 3: Add loading-indicator wait to search**

In `e2etests/pages/AuditLogPage.ts`, change `search` from:

```typescript
  async search(text: string) {
    const responsePromise = this.page.waitForResponse((resp) => {
      if (!resp.url().includes('/api/admin/audit-log') || resp.status() !== 200) return false
      try {
        return new URL(resp.url()).searchParams.get('search') === text
      } catch {
        return false
      }
    })
    const input = this.page.getByTestId('audit-log-search-input')
    await input.clear()
    await input.fill(text)
    await responsePromise
  }
```

To:

```typescript
  async search(text: string) {
    const responsePromise = this.page.waitForResponse((resp) => {
      if (!resp.url().includes('/api/admin/audit-log') || resp.status() !== 200) return false
      try {
        return new URL(resp.url()).searchParams.get('search') === text
      } catch {
        return false
      }
    })
    const input = this.page.getByTestId('audit-log-search-input')
    await input.clear()
    await input.fill(text)
    await responsePromise
    // Pattern 008: wait for loading indicator to clear before assertions
    await expect(this.page.getByTestId('audit-log-loading')).toBeHidden({ timeout: 10000 })
  }
```

- [ ] **Step 4: Run the audit log E2E tests**

```bash
cd e2etests
npm test -- tests/admin/audit-log-e2e.spec.ts --workers=4
```

Expected: All audit log E2E tests pass.

If tests still fail after the loading-indicator fix, check the backend search implementation:

```bash
# Check if audit log search actually searches entity_id
grep -n "search\|entity_id" backend/src/Admin/AuditLog/AuditLogRepository.php | head -20
```

If the search parameter doesn't cover `entity_id` fields, the test's `auditLog.search(memberId)` will return no results regardless. In that case, remove the `search(memberId)` call from the test steps and rely only on the `filterByAction` + `filterByEntityType` filters, then verify by checking that `findRowByEntityId(memberId)` finds a row.

- [ ] **Step 5: Run the full E2E suite to verify no regressions**

```bash
cd e2etests
npm test -- --workers=4
```

Expected: All tests pass (or pre-existing failures only — do not regress anything that was green before).

- [ ] **Step 6: Commit**

```bash
git add e2etests/pages/AuditLogPage.ts
git commit -m "fix(e2e): add loading-indicator wait after filterByAction and search

filterByEntityType already waited for audit-log-loading to hide after
the API response, ensuring React had rendered new data. filterByAction
and search were missing the same wait, causing subsequent assertions to
read stale DOM data."
```

---

## Final Verification

- [ ] **Run full test suite**

```bash
cd e2etests
npm test -- --workers=4
```

Expected: Build-terminal fix is in CI only (local Flutter not needed). All E2E tests that were failing pass.

- [ ] **Push and verify CI**

```bash
git push
```

Watch the GitHub Actions run at https://github.com/dgloeckner/clubbar/actions — confirm `build-terminal`, `test-e2e`, and downstream jobs are green.
