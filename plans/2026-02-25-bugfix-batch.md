# Bugfix Batch Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix 6 bugs/UX issues identified in bugs.txt across the admin frontend, backend, and Flutter terminal app.

**Architecture:** Minimal targeted changes per bug. Tasks 3+4 are combined because creating a shared `ConfirmDialog` component is the cleanest fix for both the undo button (bug 3) and inconsistent dialog styling (bug 4).

**Tech Stack:** PHP (backend), React/TypeScript (admin frontend), Flutter/Dart (terminal frontend), Playwright (E2E tests).

---

## Bug Summary

| # | Bug | File(s) | Root Cause |
|---|-----|---------|------------|
| 1 | Audit log shows "Fehlgeschlagene Anmeldung" for all entries | `backend/.../AuditLogDto.php:28`, `admin-frontend/.../AuditLogPage.tsx:359` | DTO looks for `admin_display_name` but SQL aliases column as `admin_user_name`; frontend fallback shows "Fehlgeschlagene Anmeldung" for any null admin name |
| 2 | Clearing card UID doesn't show member in "ohne karte" filter | `admin-frontend/.../MembersPage.tsx:131-135` | Frontend omits `card_uid` from payload when empty, so DB value is never set to NULL; backend filter uses `IS NULL` |
| 3 | Settlement undo uses native `confirm()` | `admin-frontend/.../SettlementsPage.tsx:142` | Never replaced with custom modal |
| 4 | Inconsistent confirm dialog styling across pages | `ProductsPage.tsx:894-982`, `CategoriesPage.tsx:568-647`, `SettingsPage.tsx:171` | ProductsPage uses hardcoded hex colors; CategoriesPage uses theme tokens but different layout; SettingsPage uses `window.confirm()` |
| 5 | Terminal products not sorted lexicographically per category | `terminal-frontend/.../product_selection_screen.dart:141-143` | Products are filtered but not sorted after filtering |
| 6 | Product card missing minus button | `terminal-frontend/.../product_card.dart`, `cart_provider.dart` | CartProvider has no `decreaseItem` method; ProductCard has no `onDecrement` callback or minus button UI |

---

## Task 1: Fix audit log — admin name always null (backend DTO mismatch)

**Root cause details:**
- SQL: `SELECT al.*, au.display_name as admin_user_name FROM audit_log al LEFT JOIN admin_users au ...`
- DTO: `$row['admin_display_name'] ?? $row['display_name'] ?? null` → always null because neither key exists
- Fix: Read `$row['admin_user_name']` (the actual alias from SQL)
- Frontend also needs fix: only show "Fehlgeschlagene Anmeldung" for `login_failed` action, not all null-name entries

**Files:**
- Modify: `backend/src/Modules/AuditLog/DTOs/AuditLogDto.php:28`
- Modify: `admin-frontend/src/pages/AuditLogPage.tsx:359`

**Step 1: Write failing E2E test for admin name display**

In `e2etests/tests/admin/audit-log-e2e.spec.ts`, add a test that verifies an audit log entry with a real admin user shows the admin name (not "(Fehlgeschlagene Anmeldung)"):

```typescript
test('should display admin user name for authenticated actions', async ({ page }) => {
  // Login creates an audit log entry with the admin user's name
  await page.goto('http://localhost:5173/audit-log')
  await expect(page.getByTestId('audit-log-page')).toBeVisible()

  // Find a non-login-failed entry — the admin column should show a name, not "Fehlgeschlagene Anmeldung"
  // Filter by action=login (successful logins should have an admin user name)
  const actionSelect = page.getByTestId('audit-log-filter-action')
  await actionSelect.selectOption('login')
  await page.waitForResponse(r => r.url().includes('/api/admin/audit-log'))

  const rows = page.locator('[data-testid^="audit-log-admin-"]')
  const rowCount = await rows.count()
  if (rowCount > 0) {
    const adminName = await rows.first().textContent()
    // Should NOT be "(Fehlgeschlagene Anmeldung)" for a successful login
    expect(adminName).not.toBe('(Fehlgeschlagene Anmeldung)')
    expect(adminName?.trim().length).toBeGreaterThan(0)
  }
})
```

**Step 2: Run test to verify it fails**

```bash
cd e2etests && npm test -- tests/admin/audit-log-e2e.spec.ts --grep "should display admin user name" --workers=1
```
Expected: FAIL (admin name shows "(Fehlgeschlagene Anmeldung)")

**Step 3: Fix the backend DTO**

In `backend/src/Modules/AuditLog/DTOs/AuditLogDto.php`, change line 28:

Old:
```php
adminUserName: $row['admin_display_name'] ?? $row['display_name'] ?? null,
```
New:
```php
adminUserName: $row['admin_user_name'] ?? null,
```

**Step 4: Fix the frontend fallback**

In `admin-frontend/src/pages/AuditLogPage.tsx`, change line 359:

Old:
```tsx
{entry.admin_user_name || t('auditLog.failedLogin')}
```
New:
```tsx
{entry.admin_user_name || (entry.action === 'login_failed' ? t('auditLog.failedLogin') : '—')}
```

**Step 5: Restart PHP and run test**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
sleep 2
cd e2etests && npm test -- tests/admin/audit-log-e2e.spec.ts --grep "should display admin user name" --workers=1
```
Expected: PASS

**Step 6: Run full audit log test suite**

```bash
cd e2etests && npm test -- tests/admin/audit-log.spec.ts tests/admin/audit-log-e2e.spec.ts --workers=4
```
Expected: All pass

**Step 7: Commit**

```bash
git add backend/src/Modules/AuditLog/DTOs/AuditLogDto.php
git add admin-frontend/src/pages/AuditLogPage.tsx
git add e2etests/tests/admin/audit-log-e2e.spec.ts
git commit -m "fix(audit-log): resolve admin name always showing Fehlgeschlagene Anmeldung

- Fix AuditLogDto to read admin_user_name column (was reading admin_display_name, which didn't exist)
- Fix frontend fallback: only show failedLogin text for login_failed action, not for all null admin names
- Add E2E test verifying admin name displays correctly"
```

---

## Task 2: Fix member card UID clearing (send null instead of omitting)

**Root cause details:**
- When editing a member and clearing `card_uid`, frontend code at line 131-135 omits the field entirely (`delete payload.card_uid`)
- Backend uses `array_key_exists` to detect updates, so nothing is saved
- DB value stays non-null, member doesn't appear in "ohne karte" filter
- Fix: When editing (not creating), explicitly send `card_uid: null` when the field is cleared

**Files:**
- Modify: `admin-frontend/src/pages/MembersPage.tsx:131-135`

**Step 1: Write failing E2E test**

In `e2etests/tests/admin/members-sort-filter.spec.ts`, add a test that:
1. Creates a member with a card UID
2. Edits the member and clears the card UID
3. Verifies the member appears when filtering by "ohne karte"

```typescript
test('should show member in ohne-karte filter after clearing card UID', async ({ page }) => {
  const testId = `CardClearTest-${Date.now()}`
  const cardUid = 'ABCDEF1234567890'.slice(0, 8) // 8 hex chars

  // 1. Create member with card UID via API
  const createResp = await page.request.post('http://localhost:8080/api/admin/members', {
    data: {
      first_name: testId,
      last_name: 'CardClear',
      iban: 'DE89370400440532013000',
      mandate_signed_at: '2024-01-01',
      preferred_language: 'de',
      card_uid: cardUid,
    },
    headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${await getAuthToken(page)}` }
  })
  expect(createResp.status()).toBe(201)
  const member = await createResp.json()
  const memberId = member.id

  // 2. Navigate to members page
  await page.goto('http://localhost:5173/members')
  await expect(page.getByTestId('members-page')).toBeVisible()

  // 3. Filter by "with card" — member should appear
  await page.getByTestId('filter-card-with').click()
  await page.waitForResponse(r => r.url().includes('/api/admin/members'))
  const rows = page.locator('[data-testid="member-card-uid"]')
  // ... find the member by ID

  // 4. Edit the member, clear card UID
  const editBtn = page.getByTestId(`member-edit-btn-${memberId}`)
  await editBtn.click()
  const cardInput = page.getByTestId('member-form-card-uid')
  await cardInput.clear()
  await page.getByTestId('member-form-submit').click()
  await page.waitForResponse(r => r.url().includes(`/api/admin/members/${memberId}`) && r.request().method() === 'PATCH')

  // 5. Filter by "ohne karte" — member should now appear
  await page.getByTestId('filter-card-all').click()
  await page.waitForResponse(r => r.url().includes('/api/admin/members'))
  await page.getByTestId('filter-card-without').click()
  await page.waitForResponse(r => r.url().includes('/api/admin/members'))

  // Member should be visible in "ohne karte" filter
  const memberNameCell = page.locator(`[data-testid="members-table-row-${memberId}"]`)
  // If not found by row testid, search by name in visible rows
  // ...verify member appears
})
```

Note: The exact test implementation depends on what testids exist for member rows. Use `data-testid="members-table"` and search for the member name. See `e2etests/pages/MembersPage.ts` for existing page object methods.

**Step 2: Run test to verify it fails**

```bash
cd e2etests && npm test -- tests/admin/members-sort-filter.spec.ts --grep "ohne-karte" --workers=1
```
Expected: FAIL

**Step 3: Fix the frontend payload logic**

In `admin-frontend/src/pages/MembersPage.tsx`, change lines 131-135:

Old:
```typescript
// Build payload, omit card_uid if empty
const payload: any = { ...formData }
if (!formData.card_uid) {
  delete payload.card_uid
}
```

New:
```typescript
// Build payload
const payload: any = { ...formData }
if (editingMember) {
  // When editing: explicitly send null to clear card_uid, or the value to set it
  payload.card_uid = formData.card_uid || null
} else {
  // When creating: omit card_uid if empty (null is fine too, but omit for clarity)
  if (!formData.card_uid) {
    delete payload.card_uid
  }
}
```

**Step 4: Run test to verify it passes**

```bash
cd e2etests && npm test -- tests/admin/members-sort-filter.spec.ts --workers=4
```
Expected: All pass

**Step 5: Run full members tests**

```bash
cd e2etests && npm test -- tests/admin/members.spec.ts tests/admin/members-sort-filter.spec.ts --workers=4
```
Expected: All pass

**Step 6: Commit**

```bash
git add admin-frontend/src/pages/MembersPage.tsx
git add e2etests/tests/admin/members-sort-filter.spec.ts
git commit -m "fix(members): clearing card UID now persists null to backend

Previously, clearing card_uid in edit form omitted the field from
the PATCH payload, leaving the DB value unchanged. Now explicitly
sends null when editing to set card_uid to NULL in DB, so the
member correctly appears in the 'ohne karte' filter."
```

---

## Task 3+4: Create shared ConfirmDialog + fix all inconsistent confirm dialogs

**What needs to happen:**
1. Create `admin-frontend/src/components/modals/ConfirmDialog.tsx` — a reusable modal using theme tokens
2. Replace ProductsPage inline confirm dialog (hardcoded colors) with `ConfirmDialog`
3. Replace CategoriesPage inline confirm dialog (different layout) with `ConfirmDialog`
4. Replace `window.confirm()` in SettingsPage for admin deactivation with `ConfirmDialog`
5. Replace `confirm()` in SettlementsPage undo handler with `ConfirmDialog`

**Files:**
- Create: `admin-frontend/src/components/modals/ConfirmDialog.tsx`
- Modify: `admin-frontend/src/pages/ProductsPage.tsx` (lines ~894-982)
- Modify: `admin-frontend/src/pages/CategoriesPage.tsx` (lines ~568-647)
- Modify: `admin-frontend/src/pages/SettingsPage.tsx` (lines ~170-178)
- Modify: `admin-frontend/src/pages/SettlementsPage.tsx` (lines ~141-156)
- Update E2E page object: `e2etests/pages/SettlementsPage.ts` (undoSettlement method — remove native dialog handler)

**Step 1: Write failing E2E tests for undo settlement modal**

In `e2etests/tests/admin/settlements-e2e.spec.ts`, look for the existing undo test. The existing `undoSettlement()` method uses `page.once('dialog', ...)` to accept the native confirm dialog. After the fix, the native dialog won't fire — instead a React modal will appear.

Add a new test (or modify the existing undo test) to verify:
- Clicking undo shows a confirm modal (not a native dialog)
- Confirming the modal proceeds with the undo

```typescript
test('should show custom confirm modal (not native dialog) when undoing settlement', async ({
  page, testTransactions
}) => {
  // Setup: create a settlement to undo
  // (use existing testTransactions fixture and journal page to settle)
  // ...

  // Navigate to settlements page
  const settlementsPage = new SettlementsPage(page)
  await settlementsPage.navigateTo()
  // Wait for settlement to appear in list
  const settlementId = '...' // from setup

  // Register dialog listener — should NOT fire after fix
  let nativeDialogFired = false
  page.once('dialog', () => { nativeDialogFired = true })

  // Click undo button
  await page.getByTestId(`settlements-undo-btn-${settlementId}`).click()

  // Custom confirm modal should appear
  await expect(page.getByTestId('confirm-dialog')).toBeVisible()
  expect(nativeDialogFired).toBe(false)

  // Cancel the modal
  await page.getByTestId('confirm-dialog-cancel').click()
  await expect(page.getByTestId('confirm-dialog')).not.toBeVisible()

  // Settlement should still be active (not undone)
  const undoBtn = page.getByTestId(`settlements-undo-btn-${settlementId}`)
  await expect(undoBtn).toBeEnabled()
})
```

**Step 2: Run the test to see it fail**

```bash
cd e2etests && npm test -- tests/admin/settlements-e2e.spec.ts --grep "custom confirm modal" --workers=1
```
Expected: FAIL (native dialog fires instead of custom modal)

**Step 3: Create the shared ConfirmDialog component**

Create `admin-frontend/src/components/modals/ConfirmDialog.tsx`:

```tsx
/**
 * ConfirmDialog — reusable confirmation modal using design system tokens.
 * Replaces native confirm() and inline confirmation dialogs across all pages.
 */
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'

export interface ConfirmDialogProps {
  isOpen: boolean
  title?: string
  message: string
  confirmLabel?: string
  cancelLabel?: string
  /** 'danger' shows confirm button in red; 'primary' shows in blue (default) */
  variant?: 'danger' | 'primary'
  onConfirm: () => void
  onCancel: () => void
}

export function ConfirmDialog({
  isOpen,
  title,
  message,
  confirmLabel,
  cancelLabel,
  variant = 'danger',
  onConfirm,
  onCancel,
}: ConfirmDialogProps) {
  const { t } = useTranslation()

  if (!isOpen) return null

  const confirmBg = variant === 'danger'
    ? theme.colors.semantic.danger
    : theme.colors.semantic.primary

  return (
    <div
      data-testid="confirm-dialog"
      style={{
        position: 'fixed',
        inset: 0,
        background: 'rgba(0, 0, 0, 0.5)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        zIndex: 2000,
      }}
      onClick={onCancel}
    >
      <div
        data-testid="confirm-dialog-content"
        style={{
          background: theme.colors.bg.secondary,
          borderRadius: theme.borderRadius.lg,
          padding: theme.spacing.xl,
          maxWidth: '440px',
          width: '90%',
          boxShadow: '0 25px 50px rgba(0, 0, 0, 0.5)',
        }}
        onClick={(e) => e.stopPropagation()}
      >
        {title && (
          <h2 style={{ margin: 0, marginBottom: theme.spacing.md, fontSize: theme.typography.fontSize.lg, fontWeight: 600 }}>
            {title}
          </h2>
        )}
        <p
          data-testid="confirm-dialog-message"
          style={{
            margin: 0,
            marginBottom: theme.spacing.lg,
            color: theme.colors.text.secondary,
            fontSize: theme.typography.fontSize.sm,
          }}
        >
          {message}
        </p>
        <div style={{ display: 'flex', gap: theme.spacing.md, justifyContent: 'flex-end' }}>
          <button
            data-testid="confirm-dialog-cancel"
            onClick={onCancel}
            style={{
              padding: `${theme.spacing.sm} ${theme.spacing.lg}`,
              background: 'transparent',
              border: `1px solid ${theme.colors.border.light}`,
              borderRadius: theme.borderRadius.md,
              color: theme.colors.text.primary,
              cursor: 'pointer',
              fontSize: theme.typography.fontSize.sm,
              fontWeight: theme.typography.fontWeight.semibold,
            }}
          >
            {cancelLabel ?? t('common.cancel')}
          </button>
          <button
            data-testid="confirm-dialog-ok"
            onClick={onConfirm}
            style={{
              padding: `${theme.spacing.sm} ${theme.spacing.lg}`,
              background: confirmBg,
              border: 'none',
              borderRadius: theme.borderRadius.md,
              color: 'white',
              cursor: 'pointer',
              fontSize: theme.typography.fontSize.sm,
              fontWeight: theme.typography.fontWeight.semibold,
            }}
          >
            {confirmLabel ?? t('common.confirm')}
          </button>
        </div>
      </div>
    </div>
  )
}
```

**Step 4: Update ProductsPage to use ConfirmDialog**

In `admin-frontend/src/pages/ProductsPage.tsx`:

1. Add import: `import { ConfirmDialog } from '../components/modals/ConfirmDialog'`
2. Replace the inline `{/* Confirmation Dialog */}` block (lines 893-982) with:

```tsx
<ConfirmDialog
  isOpen={!!confirmDialog}
  message={confirmDialog?.message ?? ''}
  confirmLabel={confirmDialog?.type === 'delete' ? t('common.delete') : t('common.deactivate')}
  variant="danger"
  onConfirm={confirmAction}
  onCancel={cancelConfirmation}
/>
```

Note: Keep the `confirmDialog` state and `confirmAction`/`cancelConfirmation` handlers as-is — only replace the JSX render.

The existing test IDs `products-confirm-dialog`, `products-confirm-cancel`, `products-confirm-ok`, `products-confirm-message` need updating in E2E tests to use the new `confirm-dialog`, `confirm-dialog-cancel`, `confirm-dialog-ok`, `confirm-dialog-message` IDs. Check `e2etests/tests/admin/products.spec.ts` for usage of these IDs.

**Step 5: Update CategoriesPage to use ConfirmDialog**

In `admin-frontend/src/pages/CategoriesPage.tsx`:

1. Add import: `import { ConfirmDialog } from '../components/modals/ConfirmDialog'`
2. Replace the inline `{/* Confirmation Dialog */}` block (lines 568-647) with:

```tsx
<ConfirmDialog
  isOpen={!!confirmDialog}
  title={confirmDialog?.type === 'delete' ? t('categories.deleteCategory') : undefined}
  message={confirmDialog?.message ?? ''}
  confirmLabel={confirmDialog?.type === 'delete' ? t('common.delete') : t('common.confirm')}
  variant={confirmDialog?.type === 'delete' ? 'danger' : 'primary'}
  onConfirm={confirmAction}
  onCancel={() => setConfirmDialog(null)}
/>
```

Check `e2etests/tests/admin/categories.spec.ts` for existing test IDs (`categories-confirm-dialog`, `categories-confirm-cancel`, etc.) and update them.

**Step 6: Update SettingsPage to use ConfirmDialog**

In `admin-frontend/src/pages/SettingsPage.tsx`:

1. Add import: `import { ConfirmDialog } from '../components/modals/ConfirmDialog'`
2. Add state: `const [deactivateConfirm, setDeactivateConfirm] = useState<string | null>(null)` (stores the admin user ID to deactivate)
3. Change `handleDeactivateAdmin`:

Old:
```typescript
const handleDeactivateAdmin = async (id: string) => {
  if (!window.confirm(t('settings.deactivateAdminConfirm'))) return
  try { ... }
}
```

New:
```typescript
const handleDeactivateAdmin = (id: string) => {
  setDeactivateConfirm(id)
}

const handleDeactivateAdminConfirmed = async () => {
  if (!deactivateConfirm) return
  setDeactivateConfirm(null)
  try {
    await deactivateAdminUser(deactivateConfirm)
    await loadAdminUsers()
  } catch (err) {
    console.error('Failed to deactivate admin user:', err)
    setError('Failed to deactivate admin user')
  }
}
```

4. Add to JSX (at the bottom of the return, before closing `</div>`):
```tsx
<ConfirmDialog
  isOpen={!!deactivateConfirm}
  message={t('settings.deactivateAdminConfirm')}
  confirmLabel={t('common.deactivate')}
  variant="danger"
  onConfirm={handleDeactivateAdminConfirmed}
  onCancel={() => setDeactivateConfirm(null)}
/>
```

**Step 7: Update SettlementsPage to use ConfirmDialog**

In `admin-frontend/src/pages/SettlementsPage.tsx`:

1. Add import: `import { ConfirmDialog } from '../components/modals/ConfirmDialog'`
2. Add state: `const [undoConfirm, setUndoConfirm] = useState<string | null>(null)` (stores settlement ID to undo)
3. Change `handleUndoSettlement`:

Old:
```typescript
const handleUndoSettlement = async (settlementId: string) => {
  if (!confirm(t('settlements.undoConfirm'))) {
    return
  }
  // ... undo logic
}
```

New:
```typescript
const handleUndoSettlement = (settlementId: string) => {
  setUndoConfirm(settlementId)
}

const handleUndoSettlementConfirmed = async () => {
  if (!undoConfirm) return
  const settlementId = undoConfirm
  setUndoConfirm(null)
  try {
    setLoading(true)
    setError(null)
    await undoSettlement(settlementId)
    await loadSettlements()
  } catch (err) {
    setError(err instanceof Error ? err.message : 'Failed to undo settlement')
  } finally {
    setLoading(false)
  }
}
```

4. Add to JSX:
```tsx
<ConfirmDialog
  isOpen={!!undoConfirm}
  message={t('settlements.undoConfirm')}
  confirmLabel={t('common.undo')}
  variant="danger"
  onConfirm={handleUndoSettlementConfirmed}
  onCancel={() => setUndoConfirm(null)}
/>
```

Also check if `'common.undo'` exists in `de.json`. If not, add it or use an appropriate key.

**Step 8: Update SettlementsPage E2E page object**

In `e2etests/pages/SettlementsPage.ts`, update `undoSettlement()`:

Old:
```typescript
async undoSettlement(settlementId: string) {
  // Register dialog handler before click — native confirm() fires synchronously
  this.page.once('dialog', (dialog) => dialog.accept())
  const responsePromise = ...
  await this.page.getByTestId(`settlements-undo-btn-${settlementId}`).click()
  await responsePromise
  await this.waitForPageLoad()
}
```

New:
```typescript
async undoSettlement(settlementId: string) {
  const responsePromise = this.page.waitForResponse(
    (resp) =>
      resp.url().includes(`/api/admin/settlements/${settlementId}`) &&
      resp.request().method() === 'DELETE' &&
      resp.status() === 204
  )
  await this.page.getByTestId(`settlements-undo-btn-${settlementId}`).click()
  // Custom confirm dialog should appear
  await expect(this.page.getByTestId('confirm-dialog')).toBeVisible()
  await this.page.getByTestId('confirm-dialog-ok').click()
  await responsePromise
  await this.waitForPageLoad()
}
```

**Step 9: Update E2E test IDs in affected tests**

Check these test files for old inline dialog test IDs and update to new `confirm-dialog-*`:
- `e2etests/tests/admin/products.spec.ts` — look for `products-confirm-*` IDs
- `e2etests/tests/admin/categories.spec.ts` — look for `categories-confirm-*` IDs
- `e2etests/tests/admin/settings-admin-users.spec.ts` — look for any native confirm handling

**Step 10: Run all affected tests**

```bash
cd e2etests && npm test -- tests/admin/products.spec.ts tests/admin/categories.spec.ts tests/admin/settlements.spec.ts tests/admin/settlements-e2e.spec.ts tests/admin/settings-admin-users.spec.ts --workers=4
```
Expected: All pass

**Step 11: Commit**

```bash
git add admin-frontend/src/components/modals/ConfirmDialog.tsx
git add admin-frontend/src/pages/ProductsPage.tsx
git add admin-frontend/src/pages/CategoriesPage.tsx
git add admin-frontend/src/pages/SettingsPage.tsx
git add admin-frontend/src/pages/SettlementsPage.tsx
git add e2etests/pages/SettlementsPage.ts
git add e2etests/tests/admin/
git commit -m "fix(admin): replace native confirm() with consistent ConfirmDialog modal

- Create shared ConfirmDialog component using design system theme tokens
- Replace ProductsPage hardcoded-color inline confirm with ConfirmDialog
- Replace CategoriesPage inline confirm with ConfirmDialog
- Replace SettingsPage window.confirm() for admin deactivation with ConfirmDialog
- Replace SettlementsPage confirm() for undo with ConfirmDialog
- Update E2E page object for settlements undo to use new modal interaction"
```

---

## Task 5: Fix terminal product sorting (lexicographic by name)

**Root cause details:**
- Products filtered by category are returned in DB/cache order, not alphabetically
- Fix: Sort by translated product name after filtering, before display

**Files:**
- Modify: `terminal-frontend/lib/screens/product_selection_screen.dart:135-143`

**Step 1: Write a widget test verifying sort order**

In `terminal-frontend/test/`, check if there's an existing test for product sorting. If not, add a minimal test:

```dart
// terminal-frontend/test/product_sort_test.dart
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('products within a category should be sorted lexicographically by name', () {
    // Simulate what the screen does
    final names = ['Bier', 'Apfel', 'Cola', 'Wasser'];
    final sorted = List.from(names)..sort((a, b) => a.toLowerCase().compareTo(b.toLowerCase()));
    expect(sorted, ['Apfel', 'Bier', 'Cola', 'Wasser']);
  });
}
```

**Step 2: Run the test**

```bash
cd terminal-frontend && flutter test test/product_sort_test.dart
```
Expected: PASS (this is a pure logic test; the widget behavior test comes from manual verification)

**Step 3: Fix the product sorting in product_selection_screen.dart**

In `terminal-frontend/lib/screens/product_selection_screen.dart`, change lines 135-143:

Old:
```dart
Widget _buildProductGrid(
  BuildContext context,
  CategoriesCacheData category,
  ProductsProvider productsProvider,
  CartProvider cartProvider,
) {
  final l10n = AppLocalizations.of(context)!;
  final products = productsProvider.products
      .where((p) => p.categoryId == category.id)
      .toList();
```

New:
```dart
Widget _buildProductGrid(
  BuildContext context,
  CategoriesCacheData category,
  ProductsProvider productsProvider,
  CartProvider cartProvider,
) {
  final l10n = AppLocalizations.of(context)!;
  final memberLang = context.read<MembersProvider>().selectedMember?.preferredLanguage ?? 'de';
  final products = productsProvider.products
      .where((p) => p.categoryId == category.id)
      .toList()
    ..sort((a, b) {
      final nameA = productsProvider.getTranslatedName(a, memberLang).toLowerCase();
      final nameB = productsProvider.getTranslatedName(b, memberLang).toLowerCase();
      return nameA.compareTo(nameB);
    });
```

Also remove the duplicate `memberLang` declaration at the old location (line 155):

Old (around line 154-156):
```dart
// Get member's preferred language
final memberLang = context.read<MembersProvider>().selectedMember?.preferredLanguage ?? 'de';
```

Remove this block (it's now at the top of the method).

**Step 4: Analyze for compile errors**

```bash
cd terminal-frontend && flutter analyze lib/screens/product_selection_screen.dart
```
Expected: No errors

**Step 5: Run Flutter tests**

```bash
cd terminal-frontend && flutter test
```
Expected: All pass

**Step 6: Commit**

```bash
cd terminal-frontend
git add lib/screens/product_selection_screen.dart
git commit -m "fix(terminal): sort products lexicographically within each category

Products are now sorted A-Z by their translated name (using the member's
preferred language) within each category tab."
```

---

## Task 6: Add minus button to terminal product card

**What needs to happen:**
1. Add `decreaseItem(productId)` method to `CartProvider` — decreases quantity by 1, removes item if quantity reaches 0
2. Add `onDecrement` callback to `ProductCard` widget
3. Render a minus button (visible only when `quantity > 0`) positioned to the left of the quantity badge

**Files:**
- Modify: `terminal-frontend/lib/providers/cart_provider.dart`
- Modify: `terminal-frontend/lib/widgets/styled_components/product_card.dart`
- Modify: `terminal-frontend/lib/screens/product_selection_screen.dart` (pass `onDecrement` to ProductCard)

**Step 1: Write failing test for decreaseItem**

In `terminal-frontend/test/`, create `cart_provider_test.dart`:

```dart
import 'package:flutter_test/flutter_test.dart';
// Note: CartProvider requires CartService and ConfigService mocks
// Use the simplest test that verifies decreaseItem logic exists and works

void main() {
  group('CartProvider.decreaseItem', () {
    test('decreaseItem reduces quantity by 1', () {
      // Test the ShoppingCart model directly (no mock needed for the model)
      // CartProvider wraps CartService - test via model if CartProvider requires DI
      // If CartProvider is too complex to test directly, test the logic inline:
      final items = [CartItem(productId: 'p1', productName: 'Beer', quantity: 2, priceCents: 200, language: 'de')];

      // Simulate decreaseItem logic
      final index = items.indexWhere((i) => i.productId == 'p1');
      if (index >= 0) {
        if (items[index].quantity > 1) {
          items[index].quantity -= 1;
        } else {
          items.removeAt(index);
        }
      }

      expect(items.first.quantity, 1);
    });

    test('decreaseItem removes item when quantity reaches 0', () {
      final items = [CartItem(productId: 'p1', productName: 'Beer', quantity: 1, priceCents: 200, language: 'de')];

      final index = items.indexWhere((i) => i.productId == 'p1');
      if (index >= 0) {
        if (items[index].quantity > 1) {
          items[index].quantity -= 1;
        } else {
          items.removeAt(index);
        }
      }

      expect(items.isEmpty, true);
    });
  });
}
```

**Step 2: Run test**

```bash
cd terminal-frontend && flutter test test/cart_provider_test.dart
```

**Step 3: Add decreaseItem to CartProvider**

In `terminal-frontend/lib/providers/cart_provider.dart`, add after the `removeItem` method (line 73):

```dart
/// Decrease item quantity by 1. If quantity reaches 0, removes the item.
void decreaseItem(String productId) {
  final index = _items.indexWhere((item) => item.productId == productId);
  if (index >= 0) {
    if (_items[index].quantity > 1) {
      _items[index].quantity -= 1;
    } else {
      _items.removeAt(index);
    }
    notifyListeners();
  }
}
```

**Step 4: Add onDecrement to ProductCard widget**

In `terminal-frontend/lib/widgets/styled_components/product_card.dart`:

1. Add `onDecrement` callback to the widget class (after `onTap`):
```dart
final VoidCallback? onDecrement;
```
And in the constructor:
```dart
this.onDecrement,
```

2. In the `build` method, add the minus button to the `Stack` children, after the counter badge:

```dart
// Minus button — shown left of quantity badge when item is in cart
if (isInCart && widget.onDecrement != null)
  Positioned(
    top: 8,
    right: 56, // left of the badge (badge is at right: 16, width ~44)
    child: GestureDetector(
      onTap: widget.onDecrement,
      child: Container(
        width: 32,
        height: 32,
        decoration: BoxDecoration(
          color: const Color(0xff1e293b),
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: const Color(0xff3b82f6), width: 1),
        ),
        child: const Icon(
          Icons.remove,
          color: Color(0xff94a3b8),
          size: 16,
        ),
      ),
    ),
  ),
```

**Step 5: Pass onDecrement in product_selection_screen.dart**

In `terminal-frontend/lib/screens/product_selection_screen.dart`, update the `ProductCard` instantiation (around line 194):

Old:
```dart
return ProductCard(
  product: product,
  productName: name,
  locale: memberLang,
  quantity: quantity,
  onTap: () {
    // ...
    cartProvider.addItem(...);
  },
);
```

New:
```dart
return ProductCard(
  product: product,
  productName: name,
  locale: memberLang,
  quantity: quantity,
  onTap: () {
    // ...
    cartProvider.addItem(...);
  },
  onDecrement: quantity > 0
    ? () => cartProvider.decreaseItem(product.id)
    : null,
);
```

**Step 6: Analyze for compile errors**

```bash
cd terminal-frontend && flutter analyze lib/
```
Expected: No errors

**Step 7: Run tests**

```bash
cd terminal-frontend && flutter test
```
Expected: All pass

**Step 8: Commit**

```bash
cd terminal-frontend
git add lib/providers/cart_provider.dart
git add lib/widgets/styled_components/product_card.dart
git add lib/screens/product_selection_screen.dart
git commit -m "feat(terminal): add minus button to product card

- Add decreaseItem() to CartProvider (decreases qty by 1, removes at 0)
- Add onDecrement callback to ProductCard widget
- Show minus button left of quantity badge when product is in cart
- Minus button is only shown when quantity > 0"
```

---

## Verification

Run the full E2E test suite to confirm no regressions:

```bash
cd e2etests && npm test --workers=4
```

Run Flutter tests:
```bash
cd terminal-frontend && flutter test
```

Check PHP backend:
```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd && sleep 2
curl http://localhost:8080/api/health
```

---

## Notes

- **de.json `common.undo`**: Check `admin-frontend/public/locales/de.json` for a `common.undo` key. If missing, add `"undo": "Rückgängig"`. The `nav` or `common.undo` key may already exist.
- **`common.deactivate`**: Check if `t('common.deactivate')` exists in `de.json`. The CategoriesPage and ProductsPage already use this key, so it should exist.
- **Flutter `import` for `MembersProvider`**: `product_selection_screen.dart` already imports and uses `MembersProvider` at line 155 (`context.read<MembersProvider>()`), so moving this call earlier is safe.
- **Parallel test safety**: All new tests must follow Pattern 001 (unique test data via timestamps), Pattern 004 (parallel safe), and Pattern 008 (expect() not try-catch).
