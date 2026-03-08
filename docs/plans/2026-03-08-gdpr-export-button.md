# GDPR Export Button (UC-DSGVO-01) Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Add a "Export Data" button to the member edit modal that triggers the existing GDPR export endpoint and downloads the result as a JSON file.

**Architecture:** The backend endpoint `POST /api/admin/members/{memberId}/export` is fully implemented and tested (9 E2E tests). We add a frontend-only change: a service function to call the endpoint, a download trigger using the existing blob download pattern, and an export button in the member edit modal. The button only appears when editing an existing member (not when creating).

**Tech Stack:** React 18, TypeScript, Axios (via `apiClient`), Playwright E2E tests

---

### Task 1: Add `exportMemberData` function to members service

**Files:**
- Modify: `admin-frontend/src/services/members.ts`

**Step 1: Add the export function**

Add at the end of `members.ts`, before the closing of the file:

```typescript
/**
 * Export member data (GDPR Art. 15 - Right of Access)
 * Downloads the export as a JSON file.
 */
export async function exportMemberData(memberId: string): Promise<void> {
  const response = await post<Record<string, unknown>>(`/admin/members/${memberId}/export`, {})

  // Convert JSON response to downloadable file
  const json = JSON.stringify(response, null, 2)
  const blob = new Blob([json], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `member-export-${memberId}.json`
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}
```

**Step 2: Verify build succeeds**

Run: `cd admin-frontend && npm run build`
Expected: Build succeeds with no errors

**Step 3: Commit**

```bash
git add admin-frontend/src/services/members.ts
git commit -m "feat(members): add exportMemberData service function for GDPR export"
```

---

### Task 2: Add Export Data button to member edit modal

**Files:**
- Modify: `admin-frontend/src/pages/MembersPage.tsx`

**Step 1: Add import for `exportMemberData` and `DownloadIcon`**

In the imports section of `MembersPage.tsx`:

- Add `exportMemberData` to the import from `../services/members`
- Add `DownloadIcon` to the icon imports from `../components/icons`

**Step 2: Add export handler function and loading state**

Add state variable near the other state declarations:
```typescript
const [exporting, setExporting] = useState(false)
```

Add handler function near `handleSubmit`:
```typescript
const handleExportData = async () => {
  if (!editingMember) return
  setExporting(true)
  try {
    await exportMemberData(editingMember.id)
  } catch (err) {
    setError(err instanceof Error ? err.message : 'Failed to export member data')
  } finally {
    setExporting(false)
  }
}
```

**Step 3: Add the Export Data button to the modal footer**

In the modal form button area (around line 1397), change the button container to use `justifyContent: 'space-between'` and add the export button on the left side. The export button should only render when `editingMember` is set (editing, not creating).

Replace the existing button `div` (line 1397) with:

```tsx
<div style={{ gridColumn: '1 / -1', display: 'flex', gap: theme.spacing.lg, justifyContent: editingMember ? 'space-between' : 'flex-end', marginTop: theme.spacing.lg }}>
  {editingMember && (
    <button
      data-testid="members-form-export-button"
      type="button"
      onClick={handleExportData}
      disabled={exporting}
      style={{
        display: 'flex',
        alignItems: 'center',
        gap: theme.spacing.sm,
        padding: `${theme.spacing.md} ${theme.spacing.lg}`,
        background: 'transparent',
        border: `1px solid ${theme.colors.border.light}`,
        borderRadius: theme.borderRadius.md,
        color: theme.colors.text.secondary,
        cursor: exporting ? 'not-allowed' : 'pointer',
        fontSize: theme.typography.fontSize.sm,
        fontWeight: theme.typography.fontWeight.semibold,
        opacity: exporting ? 0.6 : 1,
      }}
      title={t('common.export')}
    >
      <DownloadIcon size={16} />
      {t('common.export')}
    </button>
  )}
  <div style={{ display: 'flex', gap: theme.spacing.lg }}>
    <button
      data-testid="members-form-cancel-button"
      type="button"
      onClick={() => setShowModal(false)}
      style={{
        padding: `${theme.spacing.md} ${theme.spacing.lg}`,
        background: 'transparent',
        border: `1px solid ${theme.colors.border.light}`,
        borderRadius: theme.borderRadius.md,
        color: theme.colors.text.primary,
        cursor: 'pointer',
        fontSize: theme.typography.fontSize.sm,
        fontWeight: theme.typography.fontWeight.semibold,
      }}
    >
      {t('common.cancel')}
    </button>
    <button
      data-testid="members-form-submit-button"
      type="submit"
      style={{
        padding: `${theme.spacing.md} ${theme.spacing.lg}`,
        background: theme.colors.semantic.primary,
        border: 'none',
        borderRadius: theme.borderRadius.md,
        color: 'white',
        cursor: 'pointer',
        fontSize: theme.typography.fontSize.sm,
        fontWeight: theme.typography.fontWeight.semibold,
      }}
    >
      {t('common.save')}
    </button>
  </div>
</div>
```

**Step 4: Verify build succeeds**

Run: `cd admin-frontend && npm run build`
Expected: Build succeeds with no errors

**Step 5: Manual smoke test**

1. Open `http://localhost:5173/members`
2. Click Edit on any member
3. Verify the "Export" button appears in the modal footer (left side)
4. Click it — a JSON file should download
5. Open the JSON file and verify it contains member data + transactions

**Step 6: Commit**

```bash
git add admin-frontend/src/pages/MembersPage.tsx
git commit -m "feat(members): add GDPR Export Data button to member edit modal (UC-DSGVO-01)"
```

---

### Task 3: Add E2E test for GDPR export button

**Files:**
- Modify: `e2etests/pages/MembersPage.ts` (add export button method)
- Create: `e2etests/tests/admin/members-gdpr-export.spec.ts`

**Step 1: Add export button method to MembersPage page object**

Add to `e2etests/pages/MembersPage.ts`:

```typescript
// In the private locators section:
private readonly formExportBtn = () => this.page.getByTestId('members-form-export-button')

// In the public methods section:

/**
 * GDPR EXPORT
 */

async expectExportButtonVisible() {
  await expect(this.formExportBtn()).toBeVisible()
}

async expectExportButtonHidden() {
  await expect(this.formExportBtn()).not.toBeVisible()
}

async clickExportButton() {
  await this.formExportBtn().click()
}
```

**Step 2: Create E2E test file**

Create `e2etests/tests/admin/members-gdpr-export.spec.ts`:

```typescript
import { test, expect } from '@playwright/test'
import { MembersPage } from '../../pages/MembersPage'

test.describe('Members GDPR Export Button (UC-DSGVO-01)', () => {
  let membersPage: MembersPage

  test.beforeEach(async ({ page }) => {
    membersPage = new MembersPage(page)
    await membersPage.navigate()
    await membersPage.expectPageVisible()
  })

  test('export button is visible when editing an existing member', async ({ page }) => {
    // Click edit on first member in table
    await membersPage.clickEditButtonAtRowIndex(0)
    await membersPage.expectFormModalVisible()

    // Export button should be visible
    await membersPage.expectExportButtonVisible()
  })

  test('export button is NOT visible when creating a new member', async ({ page }) => {
    await membersPage.openCreateModal()
    await membersPage.expectFormModalVisible()

    // Export button should NOT be visible
    await membersPage.expectExportButtonHidden()
  })

  test('clicking export triggers file download', async ({ page }) => {
    // Click edit on first member
    await membersPage.clickEditButtonAtRowIndex(0)
    await membersPage.expectFormModalVisible()

    // Set up download listener before clicking
    const downloadPromise = page.waitForEvent('download')

    await membersPage.clickExportButton()

    // Verify download triggered
    const download = await downloadPromise
    expect(download.suggestedFilename()).toMatch(/^member-export-.*\.json$/)

    // Verify downloaded content is valid JSON with expected structure
    const path = await download.path()
    if (path) {
      const fs = await import('fs')
      const content = fs.readFileSync(path, 'utf-8')
      const data = JSON.parse(content)

      expect(data).toHaveProperty('member')
      expect(data).toHaveProperty('transactions')
      expect(data).toHaveProperty('export_timestamp')
      expect(data.member).toHaveProperty('id')
      expect(data.member).toHaveProperty('first_name')
      expect(data.member).toHaveProperty('last_name')
    }
  })
})
```

**Step 3: Run tests to verify they pass**

Run: `cd e2etests && npm test -- tests/admin/members-gdpr-export.spec.ts --workers=4`
Expected: 3 tests pass

**Step 4: Commit**

```bash
git add e2etests/pages/MembersPage.ts e2etests/tests/admin/members-gdpr-export.spec.ts
git commit -m "test(members): add E2E tests for GDPR export button (UC-DSGVO-01)"
```

---

## Summary

| Task | What | Files | Effort |
|------|------|-------|--------|
| 1 | Service function | `members.ts` | ~5 min |
| 2 | Export button in modal | `MembersPage.tsx` | ~10 min |
| 3 | E2E tests | `MembersPage.ts`, `members-gdpr-export.spec.ts` | ~10 min |

**Total estimated effort:** ~25 minutes

**Dependencies:** Backend endpoint is already implemented and tested. No backend changes needed.
