# Pattern 005: Using Test IDs (data-testid)

**Version**: 1.0
**Status**: Active
**Created**: 2026-01-26
**Applies To**: Admin Frontend, Terminal UI, Backend API tests

---

## Overview

Test IDs (`data-testid` attributes) provide semantic, stable selectors for Playwright E2E tests. This pattern demonstrates how to effectively use test IDs to write reliable, maintainable tests that are resilient to HTML/CSS changes.

**Why Test IDs Matter for E2E Tests**:
- ✅ **Stable**: Don't break when CSS changes
- ✅ **Semantic**: Clear intent and purpose
- ✅ **Reliable**: Decouple from implementation details
- ✅ **Maintainable**: Easy to find and update
- ✅ **Discoverable**: `getByTestId()` is explicit about what's being tested

**Related Pattern**: See `admin-frontend/patterns/test-ids.md` for how to add test IDs to components.

---

## Using getByTestId() in Playwright

### Basic Selection

```typescript
import { test, expect } from '@playwright/test'

test('should find element by test ID', async ({ page }) => {
  // Single element by test ID
  const button = page.getByTestId('members-create-button')

  // Verify it exists and is visible
  expect(await button.isVisible()).toBe(true)

  // Get text content
  const text = await button.textContent()
  expect(text).toContain('Create')
})
```

### Multiple Elements

```typescript
test('should find multiple elements by test ID', async ({ page }) => {
  // Get all buttons with same test ID pattern (shouldn't happen, but...)
  const allButtons = page.getByTestId('action-button')

  // Count elements
  expect(await allButtons.count()).toBeGreaterThan(0)

  // Loop through all elements
  const count = await allButtons.count()
  for (let i = 0; i < count; i++) {
    const btn = allButtons.nth(i)
    expect(await btn.isVisible()).toBe(true)
  }
})
```

### Chaining Selectors

```typescript
test('should combine test ID with other locators', async ({ page }) => {
  // Test ID as base, then refine
  const form = page.getByTestId('member-form')
  const input = form.getByTestId('member-form-name-input')

  await input.fill('Max Mustermann')
  expect(await input.inputValue()).toBe('Max Mustermann')

  // Test ID with role selector
  const submitBtn = form.getByRole('button', { name: /save/i })
    .filter({ has: page.getByTestId('member-form-submit-button') })

  await submitBtn.click()
})
```

---

## Common E2E Test Patterns Using Test IDs

### Pattern 1: Navigation Using Test IDs

```typescript
test('should navigate using nav test IDs', async ({ page }) => {
  await page.goto('http://localhost:5173/members')

  // Verify current page is members
  const activeMembersLink = page.getByTestId('nav-members-link-active')
  expect(await activeMembersLink.isVisible()).toBe(true)

  // Click products navigation
  const productsLink = page.getByTestId('nav-products-link')
  await productsLink.click()

  // Verify navigation succeeded
  await page.waitForURL('**/products')
  const activeProductsLink = page.getByTestId('nav-products-link-active')
  expect(await activeProductsLink.isVisible()).toBe(true)
})
```

### Pattern 2: Form Input & Submission Using Test IDs

```typescript
test('should fill form and submit using test IDs', async ({ page }) => {
  // Get form elements by test ID
  const nameInput = page.getByTestId('member-form-name-input')
  const emailInput = page.getByTestId('member-form-email-input')
  const submitBtn = page.getByTestId('member-form-submit-button')

  // Fill form
  await nameInput.fill('Max Mustermann')
  await emailInput.fill('max@example.com')

  // Submit
  await submitBtn.click()

  // Verify success (wait for redirect or success message)
  await page.waitForURL('**/members')
  const successMessage = page.getByTestId('member-form-success')
  expect(await successMessage.isVisible()).toBe(true)
})
```

### Pattern 3: Search & Filter Using Test IDs

```typescript
test('should search and filter using test IDs', async ({ page }) => {
  // Get search input
  const searchInput = page.getByTestId('members-search-input')
  const searchBtn = page.getByTestId('members-search-button')

  // Search for specific member
  await searchInput.fill('Max')
  await searchBtn.click()

  // Wait for results to update
  await page.waitForLoadState('networkidle')

  // Verify results - find specific row by ID
  const maxRow = page.getByTestId('members-table-row-123')
  expect(await maxRow.isVisible()).toBe(true)

  // Verify other rows are not visible
  const otherRow = page.getByTestId('members-table-row-456')
  expect(await otherRow.isVisible()).toBe(false)
})
```

### Pattern 4: Table Interaction Using Test IDs

```typescript
test('should interact with table rows using test IDs', async ({ page }) => {
  // Get table
  const table = page.getByTestId('members-table')
  expect(await table.isVisible()).toBe(true)

  // Get specific row by member ID
  const memberRow = page.getByTestId('members-table-row-123')
  expect(await memberRow.isVisible()).toBe(true)

  // Get cell from row
  const nameCell = page.getByTestId('members-table-cell-name-123')
  expect(await nameCell.textContent()).toContain('Max')

  // Click action button in row
  const editBtn = page.getByTestId('members-table-action-edit-123')
  await editBtn.click()

  // Verify edit form opened
  const form = page.getByTestId('member-form')
  expect(await form.isVisible()).toBe(true)

  // Pre-filled values should match
  const nameInput = page.getByTestId('member-form-name-input')
  expect(await nameInput.inputValue()).toContain('Max')
})
```

### Pattern 5: Modal/Dialog Using Test IDs

```typescript
test('should handle modals using test IDs', async ({ page }) => {
  // Trigger delete action
  const deleteBtn = page.getByTestId('members-table-action-delete-123')
  await deleteBtn.click()

  // Modal should appear
  const confirmModal = page.getByTestId('modal-confirm')
  expect(await confirmModal.isVisible()).toBe(true)

  // Verify modal content
  const title = page.getByTestId('modal-confirm-title')
  expect(await title.textContent()).toContain('Delete')

  const message = page.getByTestId('modal-confirm-message')
  expect(await message.textContent()).toContain('Are you sure')

  // Click confirm
  const confirmBtn = page.getByTestId('modal-confirm-button-ok')
  await confirmBtn.click()

  // Modal should close
  await expect(confirmModal).not.toBeVisible()

  // Row should be deleted
  const deletedRow = page.getByTestId('members-table-row-123')
  await expect(deletedRow).not.toBeVisible()
})
```

### Pattern 6: State Verification Using Test IDs

```typescript
test('should verify loading and error states using test IDs', async ({ page }) => {
  await page.goto('http://localhost:5173/members')

  // Loading state - should show briefly
  const loading = page.getByTestId('members-loading')
  // May or may not be visible depending on timing, so don't assert

  // Wait for data to load
  const table = page.getByTestId('members-table')
  await expect(table).toBeVisible()

  // Try to trigger error by navigating to invalid member
  await page.goto('http://localhost:5173/members/invalid')

  // Error message should appear
  const errorMessage = page.getByTestId('members-error-message')
  await expect(errorMessage).toBeVisible()

  // Error should have retry button
  const retryBtn = page.getByTestId('members-error-retry-button')
  expect(await retryBtn.isVisible()).toBe(true)

  // Click retry
  await retryBtn.click()

  // Should attempt to reload
  await expect(errorMessage).not.toBeVisible()
})
```

### Pattern 7: List Iteration Using Test IDs

```typescript
test('should iterate through list items using test IDs', async ({ page }) => {
  // Get all members (assumes structure like members-list-item-{id})
  const membersList = page.getByTestId('members-list')
  const listItems = membersList.locator('[data-testid^="members-list-item-"]')

  // Count items
  const count = await listItems.count()
  expect(count).toBeGreaterThan(0)

  // Iterate through items
  for (let i = 0; i < count; i++) {
    const item = listItems.nth(i)

    // Get data-testid to extract ID
    const testId = await item.getAttribute('data-testid')
    const memberId = testId?.replace('members-list-item-', '')

    // Verify item has action buttons
    const editBtn = page.getByTestId(`members-list-action-edit-${memberId}`)
    expect(await editBtn.isVisible()).toBe(true)
  }
})
```

### Pattern 8: Pagination Using Test IDs

```typescript
test('should paginate using test IDs', async ({ page }) => {
  // First page
  let currentBtn = page.getByTestId('pagination-page-1-active')
  expect(await currentBtn.isVisible()).toBe(true)

  // Click next button
  const nextBtn = page.getByTestId('pagination-next-button')
  await nextBtn.click()

  // Wait for page 2 to load
  const page2Btn = page.getByTestId('pagination-page-2-active')
  await expect(page2Btn).toBeVisible()

  // Click prev button
  const prevBtn = page.getByTestId('pagination-prev-button')
  await prevBtn.click()

  // Back to page 1
  currentBtn = page.getByTestId('pagination-page-1-active')
  await expect(currentBtn).toBeVisible()
})
```

---

## Advanced Patterns

### Pattern 9: Custom Test Helpers

```typescript
// Create reusable test helpers
class AdminPage {
  constructor(private page: Page) {}

  async navigateTo(path: string) {
    await this.page.goto(`http://localhost:5173${path}`)
  }

  async getMemberRow(memberId: number) {
    return this.page.getByTestId(`members-table-row-${memberId}`)
  }

  async getMemberName(memberId: number) {
    return this.page.getByTestId(`members-table-cell-name-${memberId}`)
  }

  async editMember(memberId: number) {
    const editBtn = this.page.getByTestId(`members-table-action-edit-${memberId}`)
    await editBtn.click()
  }

  async deleteMember(memberId: number) {
    const deleteBtn = this.page.getByTestId(`members-table-action-delete-${memberId}`)
    await deleteBtn.click()

    // Handle confirmation
    const confirmBtn = this.page.getByTestId('modal-confirm-button-ok')
    await confirmBtn.click()
  }

  async searchMembers(query: string) {
    const input = this.page.getByTestId('members-search-input')
    await input.fill(query)

    const btn = this.page.getByTestId('members-search-button')
    await btn.click()

    await this.page.waitForLoadState('networkidle')
  }
}

// Use in tests
test('should perform member operations', async ({ page }) => {
  const adminPage = new AdminPage(page)

  // Navigate
  await adminPage.navigateTo('/members')

  // Search
  await adminPage.searchMembers('Max')

  // Edit
  await adminPage.editMember(123)

  // Delete
  await adminPage.deleteMember(456)
})
```

### Pattern 10: Combining Test IDs with Other Locators

```typescript
test('should combine test IDs with role selectors', async ({ page }) => {
  // Find form by test ID, then get button by role
  const form = page.getByTestId('member-form')
  const submitBtn = form.getByRole('button', { name: /save/i })
    .filter({ has: page.getByTestId('member-form-submit-button') })

  await submitBtn.click()

  // Find table by test ID, then get all rows
  const table = page.getByTestId('members-table')
  const rows = table.getByRole('row')

  expect(await rows.count()).toBeGreaterThan(1) // header + data rows
})

test('should find elements within test ID container', async ({ page }) => {
  // Get modal
  const modal = page.getByTestId('modal-confirm')

  // Find elements within modal
  const title = modal.locator('[data-testid="modal-confirm-title"]')
  const message = modal.locator('[data-testid="modal-confirm-message"]')
  const buttons = modal.locator('[role="button"]')

  expect(await title.isVisible()).toBe(true)
  expect(await message.isVisible()).toBe(true)
  expect(await buttons.count()).toBeGreaterThan(0)
})
```

---

## Best Practices for E2E Tests Using Test IDs

### 1. Always Use getByTestId() First

```typescript
// ✅ Good: Semantic test ID
const btn = page.getByTestId('members-create-button')

// ⚠️ Acceptable: When test ID doesn't exist
const btn = page.getByRole('button', { name: /create/i })

// ❌ Avoid: Brittle CSS selectors
const btn = page.locator('.action-bar > button:nth-child(2)')
```

### 2. Filter by ID, Not Position

```typescript
// ✅ Good: Finds specific member regardless of table order
const row = page.getByTestId('members-table-row-123')

// ❌ Avoid: Breaks if data order changes
const row = page.locator('table tbody tr').nth(0)
```

### 3. Verify Test IDs Exist in Component

Before writing tests, ensure component has test IDs:

```typescript
// Component
<button data-testid="members-create-button">Create</button>

// Test
const btn = page.getByTestId('members-create-button')
expect(await btn.isVisible()).toBe(true)
```

### 4. Use Consistent Test ID Naming

Follow the naming convention from `admin-frontend/patterns/test-ids.md`:

```typescript
// ✅ Consistent: All follow semantic pattern
page.getByTestId('members-page')
page.getByTestId('members-search-input')
page.getByTestId('members-table')
page.getByTestId('members-table-row-123')
page.getByTestId('members-table-action-edit-123')

// ❌ Inconsistent: Mixed naming patterns
page.getByTestId('search-members')
page.locator('.members-list')
page.getByTestId('action_edit_123')
```

### 5. Wait for Elements by Test ID

```typescript
// ✅ Good: Wait for element to appear
const form = page.getByTestId('member-form')
await expect(form).toBeVisible()

// ❌ Avoid: Check immediately without waiting
const form = page.getByTestId('member-form')
expect(await form.isVisible()).toBe(true) // May be too fast
```

### 6. Document Dynamic Test IDs

When test IDs are dynamic, document the pattern:

```typescript
/**
 * Members table row test ID format:
 * members-table-row-{memberId}
 *
 * Example: members-table-row-123
 * Example: members-table-row-456
 */
test('should find member row', async ({ page }) => {
  const memberId = 123
  const row = page.getByTestId(`members-table-row-${memberId}`)
  expect(await row.isVisible()).toBe(true)
})
```

### 7. Combine Selectors for Precision

```typescript
// ✅ Good: Precise selection
const form = page.getByTestId('member-form')
const nameInput = form.getByTestId('member-form-name-input')
await nameInput.fill('Max')

// ❌ Avoid: Global selection (could find wrong element)
const nameInput = page.getByTestId('member-form-name-input')
await nameInput.fill('Max')
```

### 8. Use Test ID for Assertions

```typescript
// ✅ Good: Assert on test ID elements
const error = page.getByTestId('member-form-name-error')
await expect(error).toBeVisible()

// ❌ Avoid: Asserting on indirect elements
const form = page.getByTestId('member-form')
const errors = form.locator('.error')
expect(await errors.count()).toBeGreaterThan(0)
```

---

## Checklist for Writing E2E Tests with Test IDs

- [ ] Component has appropriate `data-testid` attributes
- [ ] Test IDs follow naming convention from admin-frontend pattern
- [ ] All queries use `getByTestId()`
- [ ] Dynamic IDs use template literals (e.g., `${memberId}`)
- [ ] Tests wait for elements to appear (don't check immediately)
- [ ] Tests filter by ID, not position
- [ ] Error states have corresponding test IDs
- [ ] Loading states have corresponding test IDs
- [ ] Modal/dialog tests use dedicated test IDs
- [ ] Complex workflows have helper classes

---

## Debugging Test ID Issues

### Test ID Not Found

```typescript
// Problem: Element not found
const btn = page.getByTestId('members-create-button')
// Error: locator.click: Target page, context or browser has been closed

// Solution 1: Check if component is rendering
const btn = page.getByTestId('members-page')
expect(await btn.isVisible()).toBe(true)

// Solution 2: Wait for element
const btn = page.getByTestId('members-create-button')
await expect(btn).toBeVisible()

// Solution 3: Check browser console for JavaScript errors
const errors = await page.evaluate(() => {
  // Check window.errors or other error tracking
  return (window as any).errors || []
})
console.log('JS Errors:', errors)
```

### Test ID Exists But Not Visible

```typescript
// Problem: Element exists but isVisible() returns false
const btn = page.getByTestId('members-create-button')
expect(await btn.isVisible()).toBe(false) // Unexpected!

// Debugging steps:
// 1. Check if parent is visible
const parent = page.getByTestId('members-page')
console.log('Parent visible:', await parent.isVisible())

// 2. Check if element is hidden by CSS
const display = await btn.evaluate((el) => {
  return window.getComputedStyle(el).display
})
console.log('Display:', display)

// 3. Check if element is off-screen
const box = await btn.boundingBox()
console.log('Bounding box:', box)

// 4. Wait for visibility with timeout
await btn.waitFor({ state: 'visible', timeout: 5000 })
```

### Multiple Elements with Same Test ID

```typescript
// Problem: Multiple elements match same test ID (shouldn't happen)
const items = page.getByTestId('members-list-item')
const count = await items.count()

// Solution: Use specific selector
const items = page.locator('[data-testid^="members-list-item-"]')

// Or use helper to get specific ID
const item = page.getByTestId('members-list-item-123')
```

---

## Real-World Example: Complete E2E Test

```typescript
import { test, expect, Page } from '@playwright/test'

class MembersPage {
  constructor(private page: Page) {}

  async goto() {
    await this.page.goto('http://localhost:5173/members')
    await this.page.waitForLoadState('networkidle')
  }

  async searchMember(name: string) {
    const input = this.page.getByTestId('members-search-input')
    await input.fill(name)

    const btn = this.page.getByTestId('members-search-button')
    await btn.click()

    await this.page.waitForLoadState('networkidle')
  }

  async getMemberRow(memberId: number) {
    return this.page.getByTestId(`members-table-row-${memberId}`)
  }

  async editMember(memberId: number) {
    const editBtn = this.page.getByTestId(`members-table-action-edit-${memberId}`)
    await editBtn.click()

    const form = this.page.getByTestId('member-form')
    await expect(form).toBeVisible()

    return {
      form,
      nameInput: this.page.getByTestId('member-form-name-input'),
      emailInput: this.page.getByTestId('member-form-email-input'),
      submitBtn: this.page.getByTestId('member-form-submit-button'),
      cancelBtn: this.page.getByTestId('member-form-cancel-button'),
    }
  }

  async deleteMember(memberId: number) {
    const deleteBtn = this.page.getByTestId(`members-table-action-delete-${memberId}`)
    await deleteBtn.click()

    const confirmBtn = this.page.getByTestId('modal-confirm-button-ok')
    await confirmBtn.click()

    await this.page.waitForLoadState('networkidle')
  }
}

test.describe('Members Page', () => {
  let membersPage: MembersPage

  test.beforeEach(async ({ page }) => {
    membersPage = new MembersPage(page)
    await membersPage.goto()
  })

  test('should display members table', async ({ page }) => {
    const table = page.getByTestId('members-table')
    await expect(table).toBeVisible()
  })

  test('should search for members', async ({ page }) => {
    await membersPage.searchMember('Max')

    const row = await membersPage.getMemberRow(123)
    await expect(row).toBeVisible()
  })

  test('should edit member details', async ({ page }) => {
    await membersPage.searchMember('Max')

    const { nameInput, emailInput, submitBtn } = await membersPage.editMember(123)

    await nameInput.fill('Max Mustermann Updated')
    await emailInput.fill('max.updated@example.com')
    await submitBtn.click()

    await page.waitForURL('**/members')

    const successMsg = page.getByTestId('member-form-success')
    await expect(successMsg).toBeVisible()
  })

  test('should delete member', async ({ page }) => {
    const row = await membersPage.getMemberRow(456)
    await expect(row).toBeVisible()

    await membersPage.deleteMember(456)

    await expect(row).not.toBeVisible()
  })

  test('should cancel edit form', async ({ page }) => {
    await membersPage.searchMember('Max')

    const { cancelBtn } = await membersPage.editMember(123)
    await cancelBtn.click()

    const form = page.getByTestId('member-form')
    await expect(form).not.toBeVisible()
  })
})
```

---

## Integration with E2E Testing Patterns

This pattern complements the existing E2E testing patterns:

- **Pattern 001: Test Data Isolation** — Use test IDs to select and verify isolated test data
- **Pattern 002: Authentication Isolation** — Use test IDs for auth UI (login, logout buttons)
- **Pattern 003: Database-Agnostic Assertions** — Filter by test ID (not position) to find specific data
- **Pattern 004: Parallel Execution Safety** — Test IDs enable safe parallel tests (no selector conflicts)

**Example combining patterns**:
```typescript
test('should verify test data isolation with test IDs', async ({ page }) => {
  // Pattern 001: Use test IDs to find isolated test data
  const testMemberId = 'test-member-' + Date.now()

  // Create member
  const createBtn = page.getByTestId('members-create-button')
  await createBtn.click()

  const form = page.getByTestId('member-form')
  const input = form.getByTestId('member-form-name-input')
  await input.fill(testMemberId)

  // Pattern 003: Find by ID, not position
  const row = page.getByTestId(`members-table-row-${testMemberId}`)

  // Verify specific test data found
  await expect(row).toBeVisible()
})
```

---

## References

- **Admin Frontend Test IDs Pattern**: `admin-frontend/patterns/test-ids.md`
- **Playwright: getByTestId()**:https://playwright.dev/docs/locators#locate-by-test-id
- **Playwright Selectors**: https://playwright.dev/docs/locators
- **Playwright Best Practices**: https://playwright.dev/docs/best-practices

---

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0 | 2026-01-26 | Initial E2E test pattern for using test IDs |
