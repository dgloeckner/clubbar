# Admin Frontend Pattern: Test Data IDs (data-testid)

**Version**: 1.0
**Status**: Active
**Created**: 2026-01-26

---

## Overview

Test IDs (via `data-testid` attribute) provide reliable, semantic selectors for E2E tests. They decouple tests from implementation details like CSS classes or HTML structure, making tests more stable and maintainable.

**Why Test IDs Matter**:
- ✅ Stable selectors that survive CSS/styling changes
- ✅ Semantic naming that documents UI structure
- ✅ Clear intent for developers reading test code
- ✅ Doesn't pollute production CSS/class selectors
- ✅ Works with Playwright's `getByTestId()` locator

---

## Naming Convention

### Format
```
{page|component}-{element}-{descriptor}
```

### Rules

1. **Use kebab-case** (lowercase with hyphens, no spaces or underscores)
2. **Start with semantic context** (page name or component name)
3. **Be descriptive but concise** (avoid redundant words)
4. **Include state when relevant** (e.g., `-active`, `-disabled`)
5. **Number lists/grids by index** (only if selectors vary by position)

### Examples

**Good Test IDs**:
```typescript
// Pages
data-testid="members-page"
data-testid="members-search-input"
data-testid="members-create-button"

// Navigation
data-testid="nav-members-link"
data-testid="nav-members-link-active"
data-testid="logout-button"

// Forms
data-testid="member-form-name-input"
data-testid="member-form-submit-button"
data-testid="member-form-cancel-button"

// Tables
data-testid="members-table"
data-testid="members-table-row-0"
data-testid="members-table-cell-name"
data-testid="members-table-action-edit"

// Modals/Dialogs
data-testid="modal-confirm"
data-testid="modal-confirm-title"
data-testid="modal-confirm-button-ok"
data-testid="modal-confirm-button-cancel"

// Cards & Components
data-testid="stat-card-members"
data-testid="stat-card-members-value"
data-testid="stat-card-members-icon"

// Status/States
data-testid="loading-indicator"
data-testid="error-banner"
data-testid="empty-state-message"
```

**Avoid**:
```typescript
// Too vague
data-testid="button"
data-testid="input"
data-testid="item"

// Too specific/brittle
data-testid="div-wrapper-container-main"
data-testid="class-xyz-123"

// CSS-focused
data-testid="btn-primary"
data-testid="text-lg-bold"

// Over-numbered
data-testid="row-1-cell-1-item-1"
```

---

## Implementation Patterns

### Pattern 1: Page-Level Elements

**Use case**: Top-level page containers and major sections

```typescript
export function MembersPage() {
  return (
    <div data-testid="members-page">
      <h1 data-testid="members-page-title">Members</h1>
      <div data-testid="members-search">
        <input data-testid="members-search-input" />
      </div>
      <button data-testid="members-create-button">Create Member</button>
    </div>
  )
}
```

**E2E Tests**:
```typescript
test('should display members page', async ({ page }) => {
  await page.goto('/members')

  // Use getByTestId for reliable selection
  const membersPage = page.getByTestId('members-page')
  expect(await membersPage.isVisible()).toBe(true)

  const title = page.getByTestId('members-page-title')
  expect(await title.textContent()).toContain('Members')
})

test('should search members by name', async ({ page }) => {
  const searchInput = page.getByTestId('members-search-input')
  await searchInput.fill('Max')

  // Results will appear below
})
```

### Pattern 2: Navigation

**Use case**: Navigation items that show active state

```typescript
export function MainLayout({ children }: MainLayoutProps) {
  const isActive = (path: string) => location.pathname === path

  return (
    <nav>
      <Link
        to="/members"
        data-testid={`nav-members-link${isActive('/members') ? '-active' : ''}`}
      >
        Members
      </Link>
      <Link
        to="/products"
        data-testid={`nav-products-link${isActive('/products') ? '-active' : ''}`}
      >
        Products
      </Link>
    </nav>
  )
}
```

**E2E Tests**:
```typescript
test('should show active navigation tab', async ({ page }) => {
  await page.goto('/members')

  const activeTab = page.getByTestId('nav-members-link-active')
  expect(await activeTab.isVisible()).toBe(true)

  // Click to navigate
  await page.getByTestId('nav-products-link').click()

  // Old active tab should not exist, new one should
  const newActive = page.getByTestId('nav-products-link-active')
  expect(await newActive.isVisible()).toBe(true)
})
```

### Pattern 3: Forms & Inputs

**Use case**: Form fields, labels, and validation messages

```typescript
export function MemberForm() {
  return (
    <form data-testid="member-form">
      <div data-testid="member-form-name-field">
        <label htmlFor="name" data-testid="member-form-name-label">
          Name
        </label>
        <input
          id="name"
          data-testid="member-form-name-input"
          type="text"
        />
        <span data-testid="member-form-name-error" style={{ display: 'none' }}>
          Name is required
        </span>
      </div>

      <div data-testid="member-form-email-field">
        <label htmlFor="email" data-testid="member-form-email-label">
          Email
        </label>
        <input
          id="email"
          data-testid="member-form-email-input"
          type="email"
        />
      </div>

      <button type="submit" data-testid="member-form-submit-button">
        Save
      </button>
      <button type="button" data-testid="member-form-cancel-button">
        Cancel
      </button>
    </form>
  )
}
```

**E2E Tests**:
```typescript
test('should validate required fields', async ({ page }) => {
  const form = page.getByTestId('member-form')
  const submitBtn = page.getByTestId('member-form-submit-button')

  // Try submit without filling
  await submitBtn.click()

  // Error message should show
  const nameError = page.getByTestId('member-form-name-error')
  expect(await nameError.isVisible()).toBe(true)

  // Fill form
  const nameInput = page.getByTestId('member-form-name-input')
  await nameInput.fill('Max Mustermann')

  // Error should hide
  expect(await nameError.isVisible()).toBe(false)
})

test('should submit form with valid data', async ({ page }) => {
  const nameInput = page.getByTestId('member-form-name-input')
  const emailInput = page.getByTestId('member-form-email-input')
  const submitBtn = page.getByTestId('member-form-submit-button')

  await nameInput.fill('Max Mustermann')
  await emailInput.fill('max@example.com')
  await submitBtn.click()

  // Verify submission (check for success message or redirect)
  await page.waitForURL('/members')
})
```

### Pattern 4: Tables & Lists

**Use case**: Rows, cells, and actions in data tables

```typescript
export function MembersTable({ members }: { members: Member[] }) {
  return (
    <table data-testid="members-table">
      <thead>
        <tr data-testid="members-table-header">
          <th data-testid="members-table-header-name">Name</th>
          <th data-testid="members-table-header-email">Email</th>
          <th data-testid="members-table-header-balance">Balance</th>
          <th data-testid="members-table-header-actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        {members.map((member, idx) => (
          <tr key={member.id} data-testid={`members-table-row-${member.id}`}>
            <td data-testid={`members-table-cell-name-${member.id}`}>
              {member.name}
            </td>
            <td data-testid={`members-table-cell-email-${member.id}`}>
              {member.email}
            </td>
            <td data-testid={`members-table-cell-balance-${member.id}`}>
              {member.balance}
            </td>
            <td data-testid={`members-table-cell-actions-${member.id}`}>
              <button
                data-testid={`members-table-action-edit-${member.id}`}
              >
                Edit
              </button>
              <button
                data-testid={`members-table-action-delete-${member.id}`}
              >
                Delete
              </button>
            </td>
          </tr>
        ))}
      </tbody>
    </table>
  )
}
```

**E2E Tests**:
```typescript
test('should display members in table', async ({ page }) => {
  // Wait for table to appear
  const table = page.getByTestId('members-table')
  expect(await table.isVisible()).toBe(true)

  // Find specific row by member ID
  const maxRow = page.getByTestId('members-table-row-123')
  expect(await maxRow.isVisible()).toBe(true)

  // Get data from row cells
  const nameCell = page.getByTestId('members-table-cell-name-123')
  expect(await nameCell.textContent()).toContain('Max')
})

test('should edit member from table', async ({ page }) => {
  const editBtn = page.getByTestId('members-table-action-edit-123')

  await editBtn.click()

  // Form should appear
  const form = page.getByTestId('member-form')
  expect(await form.isVisible()).toBe(true)
})

test('should delete member from table', async ({ page }) => {
  const deleteBtn = page.getByTestId('members-table-action-delete-123')

  await deleteBtn.click()

  // Confirmation dialog should appear
  const confirmBtn = page.getByTestId('modal-confirm-button-ok')
  await confirmBtn.click()

  // Row should disappear
  const deletedRow = page.getByTestId('members-table-row-123')
  await expect(deletedRow).not.toBeVisible()
})
```

### Pattern 5: Modals & Dialogs

**Use case**: Confirmation dialogs, alerts, and modal windows

```typescript
export function ConfirmDialog({
  title,
  message,
  onConfirm,
  onCancel,
  isOpen,
}: Props) {
  if (!isOpen) return null

  return (
    <div data-testid="modal-confirm">
      <div data-testid="modal-confirm-overlay" onClick={onCancel} />
      <div data-testid="modal-confirm-content">
        <h2 data-testid="modal-confirm-title">{title}</h2>
        <p data-testid="modal-confirm-message">{message}</p>
        <div data-testid="modal-confirm-actions">
          <button
            data-testid="modal-confirm-button-cancel"
            onClick={onCancel}
          >
            Cancel
          </button>
          <button
            data-testid="modal-confirm-button-ok"
            onClick={onConfirm}
          >
            Confirm
          </button>
        </div>
      </div>
    </div>
  )
}
```

**E2E Tests**:
```typescript
test('should show confirmation dialog', async ({ page }) => {
  const deleteBtn = page.getByTestId('members-table-action-delete-123')
  await deleteBtn.click()

  const dialog = page.getByTestId('modal-confirm')
  expect(await dialog.isVisible()).toBe(true)

  const title = page.getByTestId('modal-confirm-title')
  expect(await title.textContent()).toContain('Delete')
})

test('should cancel dialog', async ({ page }) => {
  const deleteBtn = page.getByTestId('members-table-action-delete-123')
  await deleteBtn.click()

  const cancelBtn = page.getByTestId('modal-confirm-button-cancel')
  await cancelBtn.click()

  const dialog = page.getByTestId('modal-confirm')
  await expect(dialog).not.toBeVisible()
})

test('should confirm dialog', async ({ page }) => {
  const deleteBtn = page.getByTestId('members-table-action-delete-123')
  await deleteBtn.click()

  const confirmBtn = page.getByTestId('modal-confirm-button-ok')
  await confirmBtn.click()

  // Verify action completed (row gone, etc)
  const deletedRow = page.getByTestId('members-table-row-123')
  await expect(deletedRow).not.toBeVisible()
})
```

### Pattern 5b: A three-band form modal

**Use case**: a dialog whose form can outgrow the viewport — a pinned header, a
scrolling body and a pinned footer (see
[Three-band modal](./components.md#three-band-modal--pinned-header-scrolling-body-pinned-footer)).

Each band gets an ID, because each is what a different assertion is about: the
body is what a test *scrolls*, and the footer is what must not move when it
does.

```typescript
<div data-testid="members-form-modal-content">
  <div data-testid="members-form-header">…title, and the compact status line on a phone…</div>
  <form>
    <div data-testid="members-form-body">…fields…</div>
    <div data-testid="members-form-footer">…Exportieren · Abbrechen · Speichern…</div>
  </form>
</div>
```

```typescript
// Assert reachability with toBeInViewport(), not toBeVisible(): the member
// dialog's Speichern button was "visible" by every DOM measure and 800px
// below the fold (#830).
await expect(page.getByTestId('members-form-submit-button')).toBeInViewport()

await page.getByTestId('members-form-body').evaluate((el) => {
  el.scrollTop = el.scrollHeight
})
await expect(page.getByTestId('members-form-submit-button')).toBeInViewport()
```

### Pattern 5c: A status region, keyed by what it says

**Use case**: a panel whose *tone* is the thing under test — the member
dialog's status strip (#830).

The text is translated and will be reworded; the tone is the behaviour. So
carry it in a `data-` attribute and assert on that, and keep the text
assertions for the one or two places where the wording itself is the point.

```typescript
<section data-testid="members-form-status" data-state="incomplete">   {/* complete | incomplete | blocked */}
  <div data-testid="members-form-status-required" data-tone="warning">  {/* success | warning | danger */}
    <span data-testid="members-form-status-required-text">…</span>
    <button data-testid="members-form-requirements-missing-date_of_birth">…</button>
  </div>
  <div data-testid="members-form-status-tile-terminal" data-tone="gap"> {/* ok | partial | gap | pending | losing */}
    <span data-testid="members-form-status-tile-terminal-message">…</span>
    <button data-testid="members-form-status-gap-card_uid">…</button>
  </div>
</section>
```

A gap button is keyed by the **form field** it jumps to (`card_uid`,
`mandate_signed_at`), not by the tile it sits in: the assertion is "the strip
sent me to the field that fixes this", and the field is what the test then
checks the caret landed in.

### Pattern 6: Components with Multiple States

**Use case**: Components that show different content based on state

```typescript
export function StatCard({
  label,
  value,
  color,
  icon,
  loading,
  error,
}: Props) {
  const testId = `stat-card-${label.toLowerCase()}`

  if (loading) {
    return <div data-testid={`${testId}-loading`}>Loading...</div>
  }

  if (error) {
    return <div data-testid={`${testId}-error`}>Failed to load</div>
  }

  return (
    <div data-testid={testId}>
      <div data-testid={`${testId}-icon`}>{icon}</div>
      <div data-testid={`${testId}-label`}>{label}</div>
      <div data-testid={`${testId}-value`}>{value}</div>
    </div>
  )
}
```

**E2E Tests**:
```typescript
test('should show loading state', async ({ page }) => {
  // Stat card is loading
  const loading = page.getByTestId('stat-card-members-loading')
  expect(await loading.isVisible()).toBe(true)

  // Wait for data to load
  const value = page.getByTestId('stat-card-members-value')
  await expect(value).toBeVisible()
})

test('should show error state', async ({ page }) => {
  // If API fails, show error
  const error = page.getByTestId('stat-card-members-error')
  expect(await error.isVisible()).toBe(true)
})

test('should display stat data', async ({ page }) => {
  const card = page.getByTestId('stat-card-members')
  expect(await card.isVisible()).toBe(true)

  const value = page.getByTestId('stat-card-members-value')
  expect(await value.textContent()).toContain('128')
})
```

---

## Best Practices

### 1. Add Test IDs During Development
Add test IDs when building components, not as an afterthought. They're part of the semantic markup.

```typescript
// ✅ Good: Test ID added with component
export function Button({ label, onClick }: Props) {
  return (
    <button data-testid="button-primary" onClick={onClick}>
      {label}
    </button>
  )
}

// ❌ Avoid: Test IDs added later
export function Button({ label, onClick }: Props) {
  return (
    <button onClick={onClick}>
      {label}
    </button>
  )
}
// (then later you hunt for where to add data-testid)
```

### 2. Use IDs Consistently with Component Props
Pass test IDs as props for reusable components:

```typescript
interface ButtonProps {
  label: string
  testId?: string
  onClick: () => void
}

export function Button({ label, testId = 'button', onClick }: ButtonProps) {
  return (
    <button data-testid={testId} onClick={onClick}>
      {label}
    </button>
  )
}

// Usage in pages
<Button testId="members-create-button" label="Create" onClick={...} />
<Button testId="members-delete-button" label="Delete" onClick={...} />
```

### 3. Scope IDs to Components
Use component context to avoid global conflicts:

```typescript
// ✅ Good: Scoped IDs
<div data-testid="form-name-input" />
<div data-testid="form-email-input" />

// ❌ Avoid: Generic IDs that could conflict
<div data-testid="name-input" />
<div data-testid="email-input" />
```

### 4. Use getByTestId for Reliable Selection
Always prefer `getByTestId()` over CSS selectors in tests:

```typescript
// ✅ Good: Reliable, semantic selector
const input = page.getByTestId('member-form-name-input')

// ⚠️ Acceptable: If test ID doesn't exist yet
const input = page.locator('input[type="text"]')

// ❌ Avoid: Brittle CSS selectors that break with styling changes
const input = page.locator('.form-container > div:nth-child(1) > input.text-lg')
```

### 5. Document Complex IDs
If IDs are dynamic or complex, document the pattern:

```typescript
/**
 * Members table row test ID format:
 * members-table-row-{memberId}
 *
 * Example: members-table-row-123
 */
<tr data-testid={`members-table-row-${member.id}`}>
```

### 6. Don't Duplicate Structure
Don't mirror HTML structure in test IDs unless it matters:

```typescript
// ✅ Good: Clear, focused ID
<input data-testid="member-form-name-input" />

// ❌ Over-detailed: Mirrors HTML
<div data-testid="form-wrapper-fields-name-container-input" />
```

### 7. Filter Data by ID, Not Position
Always filter tables and lists by ID, not by row position:

```typescript
// ✅ Good: Finds member 123 regardless of position
const row = page.getByTestId('members-table-row-123')

// ❌ Avoid: Brittle - breaks if order changes
const row = page.locator('table tbody tr').nth(0)
```

---

## Recommended Test ID Hierarchy

Use this hierarchy for organizing test IDs in larger components:

```
{page/component}
├── {page/component}-{main-section}
│   ├── {page/component}-{section}-{field}
│   ├── {page/component}-{section}-{action}
│   └── {page/component}-{section}-{status}
├── {page/component}-{modal}
│   ├── {page/component}-{modal}-{field}
│   └── {page/component}-{modal}-{action}
└── {page/component}-{table}
    ├── {page/component}-{table}-header
    ├── {page/component}-{table}-row-{id}
    └── {page/component}-{table}-action-{action}
```

**Example**:
```
members-page
├── members-search
│   ├── members-search-input
│   └── members-search-button
├── members-table
│   ├── members-table-header
│   ├── members-table-row-123
│   │   ├── members-table-cell-name-123
│   │   └── members-table-action-edit-123
│   └── members-table-row-456
├── modal-member-form
│   ├── member-form-name-input
│   ├── member-form-email-input
│   └── member-form-submit-button
└── members-pagination
    ├── members-pagination-prev
    └── members-pagination-next
```

---

## Common Test ID Patterns

### Lists with Dynamic Content
```typescript
// Parent list
data-testid="members-list"

// List items (use ID where available)
data-testid={`members-list-item-${member.id}`}

// Actions within items
data-testid={`members-list-action-edit-${member.id}`}
```

### Search/Filter Results
```typescript
// Search input
data-testid="members-search-input"

// Results count (optional)
data-testid="members-search-results-count"

// Empty state
data-testid="members-search-empty"

// Results list
data-testid="members-search-results"
```

### Pagination
```typescript
// Prev/next buttons
data-testid="pagination-prev-button"
data-testid="pagination-next-button"

// Page info
data-testid="pagination-info"

// Page buttons
data-testid={`pagination-page-${pageNumber}`}
```

### Status/Loading States
```typescript
// Loading
data-testid="members-loading"

// Error
data-testid="members-error"
data-testid="members-error-message"
data-testid="members-error-retry-button"

// Empty
data-testid="members-empty"
data-testid="members-empty-message"

// Success
data-testid="members-success"
data-testid="members-success-message"
```

---

## Playwright Tips

### Using getByTestId
```typescript
// Single element
const button = page.getByTestId('members-create-button')

// Multiple elements (filter by text)
const submitBtns = page.getByTestId('modal-confirm-button-ok').all()

// With assertions
await expect(page.getByTestId('member-form')).toBeVisible()
await expect(page.getByTestId('members-table-row-123')).toContainText('Max')
```

### Combining Test IDs with Other Selectors
```typescript
// Test ID with additional filter
const row = page.getByTestId('members-table-row-123')
const cell = row.locator('[role="cell"]')

// Test ID within role selector
const button = page.getByRole('button', { name: /save/i })
  .filter({ has: page.getByTestId('save-button') })

// Locator combination
const form = page.getByTestId('member-form')
const input = form.getByTestId('member-form-name-input')
```

### Custom Locators
```typescript
// Create reusable test helper
function getMemberRow(page: Page, memberId: number) {
  return page.getByTestId(`members-table-row-${memberId}`)
}

// Use in tests
const row = getMemberRow(page, 123)
await expect(row).toContainText('Max')
```

---

## Checklist for Adding Test IDs

When implementing a new page or component:

- [ ] Page container has test ID (e.g., `members-page`)
- [ ] Main sections have test IDs (e.g., `members-search`, `members-table`)
- [ ] Form fields have test IDs (e.g., `member-form-name-input`)
- [ ] Action buttons have test IDs (e.g., `members-create-button`)
- [ ] Dynamic elements use ID/index (e.g., `members-table-row-{id}`)
- [ ] Status states have test IDs (e.g., `members-loading`, `members-error`)
- [ ] Modal/dialog containers have test IDs
- [ ] Nested components receive test ID props
- [ ] Test IDs follow kebab-case naming
- [ ] E2E tests use `getByTestId()` instead of CSS selectors

---

## References

- [Playwright: Locators](https://playwright.dev/docs/locators)
- [Playwright: getByTestId()](https://playwright.dev/docs/locators#locate-by-test-id)
- [Testing Library: Queries - getByTestId](https://testing-library.com/docs/queries/bytestid)
- [MDN: data-* Attributes](https://developer.mozilla.org/en-US/docs/Learn/HTML/Howto/Use_data_attributes)

---

## Settings Page Test IDs Reference

The Settings page uses these test IDs for E2E testing:

### Page Structure
```typescript
data-testid="settings-page"                    // Main container
data-testid="settings-page-loading"            // Loading indicator
data-testid="settings-tabs"                    // Tab navigation container
data-testid="settings-tab-sepa"                // SEPA Configuration tab
data-testid="settings-tab-admin-users"         // Admin Users tab
```

### SEPA Configuration Tab
```typescript
// Form
data-testid="settings-sepa-form"               // Main form container
data-testid="settings-sepa-input-creditor_id"  // Creditor ID input
data-testid="settings-sepa-input-creditor_name"        // Creditor Name input
data-testid="settings-sepa-input-creditor_iban"        // IBAN input
data-testid="settings-sepa-input-creditor_address_street"   // Street input
data-testid="settings-sepa-input-creditor_address_city"     // City input
data-testid="settings-sepa-input-creditor_address_country"  // Country input

// Validation & Feedback
data-testid="settings-sepa-char-counter-creditor_id"        // Character counter (35 max)
data-testid="settings-sepa-char-counter-creditor_name"      // Character counter (70 max)
data-testid="settings-sepa-char-counter-creditor_address_street"  // Char counter (70 max)
data-testid="settings-sepa-char-counter-creditor_address_city"    // Char counter (70 max)
data-testid="settings-sepa-validation-creditor_iban"        // IBAN validation indicator
data-testid="settings-sepa-alert-warning"                   // Warning alert
data-testid="settings-error-message"                        // Page-level error banner (shared by all Settings tabs)
data-testid="settings-sepa-success-message"                 // Success message banner

// Actions
data-testid="settings-sepa-save-button"        // Save button
data-testid="settings-sepa-cancel-button"      // Cancel button
```

### Admin Users Tab
```typescript
// Table & Listing
data-testid="settings-admin-users-table"                    // Admin users table
data-testid="settings-admin-users-count-badge"              // Count badge (e.g., "5")
data-testid="settings-admin-create-button"                  // Create admin button

// User Rows
data-testid="settings-admin-user-row-{id}"                  // Admin user row (by ID)
data-testid="settings-admin-user-toggle-{id}"               // User enable/disable toggle
data-testid="settings-admin-user-name-{id}"                 // User name cell
data-testid="settings-admin-user-email-{id}"                // User email cell
data-testid="settings-admin-user-badge-{id}"                // Status badge
data-testid="settings-admin-user-status-{id}"               // Status cell

// User Actions
data-testid="settings-admin-edit-button-{id}"               // Edit button
data-testid="settings-admin-reset-password-button-{id}"     // Reset password button
data-testid="settings-admin-action-menu-{id}"               // Overflow menu (3-dot)
data-testid="settings-admin-deactivate-button-{id}"         // Deactivate button (in menu)
data-testid="settings-admin-reactivate-button-{id}"         // Reactivate button (in menu)
```

### Create Admin Modal
```typescript
data-testid="settings-admin-create-modal"                   // Modal container
data-testid="settings-admin-create-error"                   // Failure banner inside the modal
data-testid="settings-admin-create-email"                   // Email input
data-testid="settings-admin-create-email-error"             // Message under the email input
data-testid="settings-admin-create-display-name"            // Display name input
data-testid="settings-admin-create-display-name-error"      // Message under the display name input
data-testid="settings-admin-create-locale"                  // Locale dropdown
data-testid="settings-admin-create-confirm-button"          // Create button
data-testid="settings-admin-create-cancel-button"           // Cancel button
```

### Edit Admin Modal
```typescript
data-testid="settings-admin-edit-modal"                     // Modal container
data-testid="settings-admin-edit-error"                     // Failure banner inside the modal
data-testid="settings-admin-edit-email"                     // Email input
data-testid="settings-admin-edit-email-error"               // Message under the email input
data-testid="settings-admin-edit-display-name"              // Display name input
data-testid="settings-admin-edit-display-name-error"        // Message under the display name input
data-testid="settings-admin-edit-locale"                    // Locale dropdown
data-testid="settings-admin-edit-confirm-button"            // Update button
data-testid="settings-admin-edit-cancel-button"             // Cancel button
```

### Terminal Modals
```typescript
data-testid="settings-terminal-create-modal"                // Create modal container
data-testid="settings-terminal-create-error"                // Failure banner inside the modal
data-testid="settings-terminal-create-name"                 // Terminal name input
data-testid="settings-terminal-create-name-error"           // Message under the name input
data-testid="settings-terminal-create-device-id"            // Device ID input
data-testid="settings-terminal-create-device-id-error"      // Message under the device ID input
data-testid="settings-terminal-create-confirm-button"       // Create button
data-testid="settings-terminal-create-cancel-button"        // Cancel button
data-testid="settings-terminal-edit-modal"                  // Edit modal container
data-testid="settings-terminal-edit-error"                  // Failure banner inside the modal
data-testid="settings-terminal-edit-name"                   // Terminal name input
data-testid="settings-terminal-edit-name-error"             // Message under the name input
data-testid="settings-terminal-edit-confirm-button"         // Save button
data-testid="settings-terminal-edit-cancel-button"          // Cancel button
```

### Password Display Modal
```typescript
data-testid="settings-admin-password-modal"                 // Modal container
data-testid="settings-admin-password-display"               // Password text (monospace)
data-testid="settings-admin-password-copy-button"           // Copy & Close button
```

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.2 | 2026-08-08 | Settings errors: page-level banner plus modal and per-field markers (#91) |
| 1.1 | 2026-01-30 | Added Settings page test IDs reference |
| 1.0 | 2026-01-26 | Initial pattern documentation |
