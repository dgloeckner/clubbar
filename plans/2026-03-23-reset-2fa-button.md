# Reset 2FA Button Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Reset 2FA" icon button next to "Reset Password" in the AdminUsersTab, wired to `POST /api/auth/2fa/reset`, with a confirm dialog and no effect on the user's password.

**Architecture:** The backend endpoint already exists (`POST /api/auth/2fa/reset`, body `{userId: string}`). The frontend needs: a hand-written API helper (the endpoint is under `/auth/`, outside the auto-generated admin-users client), a new prop + button in `AdminUsersTab`, a confirm dialog + handler in `SettingsPage`, i18n strings, a Page Object method, and one E2E test.

**Tech Stack:** React (TypeScript), Axios `customInstance`, Playwright E2E, i18next

---

## File Map

| File | Change |
|------|--------|
| `admin-frontend/src/api/auth-management.ts` | **Create** — `reset2fa(userId)` using `customInstance` |
| `admin-frontend/src/components/settings/AdminUsersTab.tsx` | **Modify** — add `onReset2fa` prop + icon button (desktop + mobile) |
| `admin-frontend/src/pages/SettingsPage.tsx` | **Modify** — `reset2faConfirm` state, handler, `ConfirmDialog` |
| `admin-frontend/public/locales/de.json` | **Modify** — add `reset2fa`, `reset2faConfirm` |
| `admin-frontend/public/locales/en.json` | **Modify** — add `reset2fa`, `reset2faConfirm` |
| `e2etests/pages/SettingsPage.ts` | **Modify** — add `clickReset2faButton(email)` |
| `e2etests/tests/admin/settings-admin-users.spec.ts` | **Modify** — add reset 2FA E2E test |

---

## Task 1: i18n strings

**Files:**
- Modify: `admin-frontend/public/locales/de.json`
- Modify: `admin-frontend/public/locales/en.json`

- [ ] **Step 1: Add German strings**

In `admin-frontend/public/locales/de.json`, add after the `"resetPassword"` line (line 377):

```json
"reset2fa": "2FA zurücksetzen",
"reset2faConfirm": "2FA für diesen Admin-Benutzer zurücksetzen? Der Benutzer muss sich beim nächsten Login erneut einrichten.",
```

- [ ] **Step 2: Add English strings**

In `admin-frontend/public/locales/en.json`, add after the `"resetPassword"` line (line 377):

```json
"reset2fa": "Reset 2FA",
"reset2faConfirm": "Reset 2FA for this admin user? They will need to re-enroll on next login.",
```

- [ ] **Step 3: Commit**

```bash
git add admin-frontend/public/locales/de.json admin-frontend/public/locales/en.json
git commit -m "feat(i18n): add reset2fa translation keys (de + en)"
```

---

## Task 2: API helper

**Files:**
- Create: `admin-frontend/src/api/auth-management.ts`

The auto-generated `authentication.ts` only covers the current user's auth endpoints. The 2FA reset endpoint acts on *another* user's account, so it belongs in a separate hand-written module.

- [ ] **Step 1: Create the file**

```typescript
/**
 * Auth management API — hand-written (not orval-generated)
 * Covers admin-initiated auth operations on other users.
 */
import { customInstance } from './client'

/**
 * Reset TOTP 2FA for a given admin user.
 * POST /api/auth/2fa/reset
 * Requires: active authenticated session + CSRF token (injected by customInstance)
 */
export function reset2fa(userId: string): Promise<{ message: string }> {
  return customInstance({ url: '/auth/2fa/reset', method: 'POST', data: { userId } })
}
```

- [ ] **Step 2: Verify TypeScript compiles**

```bash
cd admin-frontend && npx tsc --noEmit 2>&1 | head -20
```

Expected: no errors

- [ ] **Step 3: Commit**

```bash
git add admin-frontend/src/api/auth-management.ts
git commit -m "feat(api): add reset2fa API helper for POST /auth/2fa/reset"
```

---

## Task 3: E2E test (write it first — TDD)

**Files:**
- Modify: `e2etests/tests/admin/settings-admin-users.spec.ts`
- Modify: `e2etests/pages/SettingsPage.ts`

Write the test and page object method *before* the UI exists, so you can verify they fail for the right reason.

- [ ] **Step 1: Add `clickReset2faButton` to SettingsPage.ts**

In `e2etests/pages/SettingsPage.ts`, add after the `clickResetPasswordButton` method (around line 539):

```typescript
/**
 * Click reset 2FA button for admin user by email and confirm via ConfirmDialog.
 */
async clickReset2faButton(email: string) {
  const adminId = await this.getAdminUserIdByEmail(email)
  if (!adminId) {
    throw new Error(`Admin user with email ${email} not found`)
  }

  await this.page.getByTestId(`settings-admin-reset-2fa-button-${adminId}`).click()
  // Wait for confirm dialog
  await expect(this.page.getByTestId('confirm-dialog')).toBeVisible({ timeout: 5000 })
  // Set up response watcher before confirming
  const responsePromise = this.page.waitForResponse(
    (resp) => resp.url().includes('/api/auth/2fa/reset') && resp.request().method() === 'POST' && resp.status() === 200,
    { timeout: 10000 }
  )
  await this.page.getByTestId('confirm-dialog-ok').click()
  await responsePromise
}
```

- [ ] **Step 2: Add E2E test to settings-admin-users.spec.ts**

Add after the `should reset password for admin user` test (around line 281):

```typescript
/**
 * Test: Reset 2FA for admin user
 *
 * E2E Verification Flow:
 * 1. Create admin user (no 2FA enrolled — newly created users have no TOTP)
 * 2. Click Reset 2FA button
 * 3. Confirm dialog appears
 * 4. Confirm action
 * 5. Verify API call succeeded (POST /api/auth/2fa/reset returns 200)
 * 6. Verify admin still exists in table
 *
 * Pattern 001: Unique test data per test
 * Pattern 008: Use expect() for assertions
 */
test('should reset 2FA for admin user', async ({ authenticatedSettingsPage }) => {
  // Arrange: Navigate to admin users tab and create admin
  await authenticatedSettingsPage.waitForLoad()
  await authenticatedSettingsPage.clickAdminUsersTab()

  const testData = generateTestAdminUser()

  // Set up interceptor for admin users list refresh
  const adminUsersLoaded = authenticatedSettingsPage.page.waitForResponse(
    (resp) =>
      resp.url().includes('/api/admin/admin-users') &&
      resp.request().method() === 'GET' &&
      resp.status() === 200,
  )

  // Create admin
  await authenticatedSettingsPage.clickCreateAdminButton()
  await authenticatedSettingsPage.fillCreateAdminForm(testData)
  await authenticatedSettingsPage.clickCreateAdminConfirm()
  await authenticatedSettingsPage.waitForPasswordModal()
  await authenticatedSettingsPage.closePasswordModal()
  await adminUsersLoaded

  // Verify admin was created
  const createdAdmin = await authenticatedSettingsPage.getAdminUserByEmail(testData.email)
  expect(createdAdmin).not.toBeNull()

  // Act: Reset 2FA — confirm dialog appears, confirm it, verify API call succeeds
  await authenticatedSettingsPage.clickReset2faButton(testData.email)

  // Assert: Admin still exists in table after reset
  const adminAfterReset = await authenticatedSettingsPage.getAdminUserByEmail(testData.email)
  expect(adminAfterReset).not.toBeNull()
  expect(adminAfterReset?.email).toContain(testData.email)
})
```

- [ ] **Step 3: Run the test — verify it fails for the right reason**

```bash
cd e2etests && npm test -- --grep "should reset 2FA for admin user" --workers=1 2>&1 | tail -20
```

Expected: FAIL — something like `Error: Admin user with email ... not found` or `getByTestId('settings-admin-reset-2fa-button-...')` not found.
If it fails with a different, unexpected error (e.g. backend down), fix that first before continuing.

---

## Task 4: Add button to AdminUsersTab

**Files:**
- Modify: `admin-frontend/src/components/settings/AdminUsersTab.tsx`

- [ ] **Step 1: Add `onReset2fa` to the props interface**

In `AdminUsersTab.tsx`, find the `AdminUsersTabProps` interface (line 17) and add the new prop:

```typescript
export interface AdminUsersTabProps {
  users: AdminUser[]
  loading: boolean
  onCreateUser: () => void
  onEditUser: (admin: AdminUser) => void
  onResetPassword: (id: string) => void
  onReset2fa: (id: string) => void          // ← add this line
  onDeactivateUser: (id: string) => void
  onReactivateUser: (id: string) => void
}
```

- [ ] **Step 2: Destructure the new prop**

Find the function signature (line 28) and add `onReset2fa`:

```typescript
export function AdminUsersTab({
  users,
  loading,
  onCreateUser,
  onEditUser,
  onResetPassword,
  onReset2fa,          // ← add this line
  onDeactivateUser,
  onReactivateUser,
}: AdminUsersTabProps) {
```

- [ ] **Step 3: Add button to the mobile card view**

In the mobile card view, find the `{/* Reset Password Button */}` block (around line 176). Add this immediately after the closing `</button>` of the Reset Password button:

```tsx
{/* Reset 2FA Button */}
<button
  data-testid={`settings-admin-reset-2fa-button-${admin.id}`}
  onClick={() => onReset2fa(admin.id)}
  style={{
    width: '32px',
    height: '32px',
    borderRadius: '8px',
    border: 'none',
    background: 'transparent',
    color: theme.colors.text.secondary,
    cursor: 'pointer',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    transition: `all ${theme.transitions.default}`,
  }}
>
  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
    <rect x="5" y="11" width="14" height="10" rx="2" ry="2" />
    <path d="M8 11V7a4 4 0 0 1 8 0v4" />
    <line x1="12" y1="15" x2="12" y2="17" />
  </svg>
</button>
```

- [ ] **Step 4: Add button to the desktop table view**

In the desktop table view, find the `{/* Reset Password Button */}` tooltip block (around line 351). Add this immediately after its closing `</Tooltip>`:

```tsx
{/* Reset 2FA Button */}
<Tooltip content={t('settings.reset2fa')} position="top">
  <button
    data-testid={`settings-admin-reset-2fa-button-${admin.id}`}
    onClick={() => onReset2fa(admin.id)}
    style={{
      width: '32px',
      height: '32px',
      borderRadius: '8px',
      border: 'none',
      background: 'transparent',
      color: theme.colors.text.secondary,
      cursor: 'pointer',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      transition: `all ${theme.transitions.default}`,
    }}
    onMouseEnter={(e) => {
      e.currentTarget.style.background = 'rgba(139, 92, 246, 0.2)'
      e.currentTarget.style.color = 'rgb(139, 92, 246)'
    }}
    onMouseLeave={(e) => {
      e.currentTarget.style.background = 'transparent'
      e.currentTarget.style.color = theme.colors.text.secondary
    }}
  >
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
      <rect x="5" y="11" width="14" height="10" rx="2" ry="2" />
      <path d="M8 11V7a4 4 0 0 1 8 0v4" />
      <line x1="12" y1="15" x2="12" y2="17" />
    </svg>
  </button>
</Tooltip>
```

Note: The lock icon (padlock) is semantically correct for a 2FA/authentication reset action. Purple hover color (`rgb(139, 92, 246)`) distinguishes it from the orange Reset Password button.

- [ ] **Step 5: Verify TypeScript compiles**

```bash
cd admin-frontend && npx tsc --noEmit 2>&1 | head -20
```

Expected: error about `onReset2fa` missing where `AdminUsersTab` is used in `SettingsPage.tsx` — that's expected and will be fixed in Task 5.

---

## Task 5: Wire up SettingsPage

**Files:**
- Modify: `admin-frontend/src/pages/SettingsPage.tsx`

- [ ] **Step 1: Import `reset2fa` API helper**

Add to the imports at the top of `SettingsPage.tsx` (near the other API imports, around line 13):

```typescript
import { reset2fa } from '../api/auth-management'
```

- [ ] **Step 2: Add confirm state**

In the Admin Users State section (around line 106, after `const [deactivateConfirm, setDeactivateConfirm] = useState<string | null>(null)`), add:

```typescript
const [reset2faConfirm, setReset2faConfirm] = useState<string | null>(null)
```

- [ ] **Step 3: Add handler**

Add this function after `handleResetPassword` (around line 274):

```typescript
const handleReset2fa = (id: string) => {
  setReset2faConfirm(id)
}

const handleReset2faConfirmed = async () => {
  if (!reset2faConfirm) return
  const id = reset2faConfirm
  setReset2faConfirm(null)
  try {
    await reset2fa(id)
  } catch (err: unknown) {
    setError('Failed to reset 2FA')
  }
}
```

- [ ] **Step 4: Pass `onReset2fa` to AdminUsersTab**

Find the `<AdminUsersTab` usage (around line 612). Add the new prop:

```tsx
<AdminUsersTab
  users={adminUsers}
  loading={adminUsersLoading}
  onCreateUser={() => setShowCreateAdminModal(true)}
  onEditUser={(admin) => {
    setEditingAdmin(admin)
    setEditAdminFormData({
      email: admin.email,
      display_name: admin.display_name,
      locale: (admin.locale === 'en' ? 'en' : 'de') as 'de' | 'en',
    })
    setShowEditAdminModal(true)
  }}
  onResetPassword={handleResetPassword}
  onReset2fa={handleReset2fa}
  onDeactivateUser={handleDeactivateAdmin}
  onReactivateUser={handleReactivateAdmin}
/>
```

- [ ] **Step 5: Add ConfirmDialog for 2FA reset**

Find the `ConfirmDialog` for `deactivateConfirm` (around line 700). Add a second `ConfirmDialog` immediately after it:

```tsx
<ConfirmDialog
  isOpen={!!reset2faConfirm}
  message={t('settings.reset2faConfirm')}
  confirmLabel={t('common.confirm')}
  variant="danger"
  onConfirm={handleReset2faConfirmed}
  onCancel={() => setReset2faConfirm(null)}
/>
```

- [ ] **Step 6: Verify TypeScript compiles cleanly**

```bash
cd admin-frontend && npx tsc --noEmit 2>&1 | head -20
```

Expected: no errors

---

## Task 6: Run tests and commit

- [ ] **Step 1: Restart PHP to ensure backend is fresh**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd && sleep 2
```

- [ ] **Step 2: Run the new E2E test**

```bash
cd e2etests && npm test -- --grep "should reset 2FA for admin user" --workers=1 2>&1 | tail -30
```

Expected: PASS. If it fails, check backend logs:
```bash
docker compose exec backend tail -50 /app/logs/$(date +%Y-%m-%d).log | jq 'select(.level == "ERROR" or .level == "CRITICAL")'
```

- [ ] **Step 3: Run the full admin users test suite**

```bash
cd e2etests && npm test -- tests/admin/settings-admin-users.spec.ts --workers=4 2>&1 | tail -20
```

Expected: all tests pass (including the 7 existing + 1 new = 8 total)

- [ ] **Step 4: Commit all frontend changes**

```bash
git add \
  admin-frontend/src/api/auth-management.ts \
  admin-frontend/src/components/settings/AdminUsersTab.tsx \
  admin-frontend/src/pages/SettingsPage.tsx \
  e2etests/pages/SettingsPage.ts \
  e2etests/tests/admin/settings-admin-users.spec.ts
git commit -m "feat(settings): add Reset 2FA button to AdminUsersTab with confirm dialog and E2E test"
```

---

## Success Criteria

- [ ] "Reset 2FA" icon button appears next to "Reset Password" in both desktop table and mobile card views
- [ ] Clicking the button shows a confirm dialog with the `reset2faConfirm` message
- [ ] Confirming calls `POST /api/auth/2fa/reset` with the correct `userId`
- [ ] Cancelling does nothing
- [ ] The action does not affect the user's password
- [ ] E2E test `should reset 2FA for admin user` passes
- [ ] All 8 admin users E2E tests pass with 4 workers
- [ ] TypeScript compiles cleanly
