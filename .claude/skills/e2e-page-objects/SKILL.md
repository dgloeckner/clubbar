# E2E Page Objects, Test IDs & Fixtures

**Context**: Playwright API and E2E tests for the Ruderbar backend (Slim 4, PDO, PHP 8.3).

Use this when creating page objects, writing UI tests with `data-testid` selectors, or setting up Playwright fixtures for E2E tests.

Source: `e2etests/patterns/` (Patterns 005-test-ids, 005-POM, 006-fixtures).

---

## Test IDs (Pattern 005)

Use `data-testid` attributes with `page.getByTestId()` for stable, semantic selectors.

### Naming Convention

Format: `{page}-{element}-{qualifier}`

```typescript
page.getByTestId('members-page')
page.getByTestId('members-search-input')
page.getByTestId('members-table')
page.getByTestId('members-table-row-123')          // Dynamic: entity ID
page.getByTestId('members-table-action-edit-123')   // Dynamic: entity + action
page.getByTestId('member-form-name-input')
page.getByTestId('member-form-submit-button')
page.getByTestId('modal-confirm-button-ok')
```

### Selector Priority

```typescript
// ✅ Best: Test ID (stable, semantic)
const btn = page.getByTestId('members-create-button')

// ✅ Good: Role-based (accessible, stable)
const btn = page.getByRole('button', { name: /create/i })

// ⚠️ Acceptable: Placeholder/aria-label
const input = page.locator('input[placeholder*="Search"]')

// ❌ Avoid: Positional CSS selectors (brittle)
const btn = page.locator('.action-bar > button:nth-child(2)')
```

### Chaining Test IDs

```typescript
// Scope within container
const form = page.getByTestId('member-form')
const nameInput = form.getByTestId('member-form-name-input')
await nameInput.fill('Max')

// Dynamic rows with prefix match
const rows = page.locator('[data-testid^="members-table-row-"]')
expect(await rows.count()).toBeGreaterThan(0)
```

---

## Page Object Model (Pattern 005-POM)

Page objects encapsulate locators (private) and expose user actions (public methods).

### Structure

```typescript
import { Page } from '@playwright/test'

export class MembersPage {
  private page: Page

  // Private locators — implementation details
  private readonly heading = () => this.page.locator('h1:has-text("Members")')
  private readonly searchInput = () => this.page.getByTestId('members-search-input')
  private readonly createBtn = () => this.page.getByTestId('members-create-button')
  private readonly tableRows = () => this.page.locator('tbody tr')
  private readonly formModal = () => this.page.getByTestId('member-form')

  constructor(page: Page) {
    this.page = page
  }

  // Public methods — user actions
  async navigate() {
    await this.page.goto('http://localhost:5173/members')
    await this.page.waitForLoadState('domcontentloaded')
  }

  async search(term: string) {
    await this.searchInput().clear()
    await this.searchInput().fill(term)
    // Wait for API response, not networkidle
    await this.page.waitForResponse(resp =>
      resp.url().includes('/api/admin/members') && resp.status() === 200
    )
  }

  async openCreateModal() {
    await this.createBtn().click()
    await this.formModal().waitFor({ state: 'visible', timeout: 5000 })
  }

  async getTableRowCount(): Promise<number> {
    return await this.tableRows().count()
  }

  async expectMemberVisibleInTable(firstName: string) {
    const row = this.page.locator(`tbody tr:has-text("${firstName}")`)
    await expect(row).toBeVisible()
  }
}
```

### Key Principles

1. **Locators are private** — tests never access raw locators
2. **Methods represent user actions** — `openCreateModal()` not `clickButton()`
3. **Return testable data** — strings, counts, booleans (not Locator objects)
4. **Include implicit waits** — page object handles timing, tests don't

```typescript
// ❌ Bad: Expose locator
export const searchInput = () => page.locator('input')

// ✅ Good: Expose action
async search(term: string) {
  await this.searchInput().clear()
  await this.searchInput().fill(term)
}

// ❌ Bad: Return Locator
async getMemberRow(id: string) { return this.page.locator(`...`) }

// ✅ Good: Return data
async getMemberCount(): Promise<number> { return await this.tableRows().count() }
```

### What NOT to put in page objects

```typescript
// ❌ Don't expose isVisible() helpers — use expect() in tests instead
async isLoaded(): Promise<boolean> {
  try { return await this.heading().isVisible() } catch { return false }
}

// ✅ Tests should use Playwright expect() directly
await expect(page.getByTestId('members-page')).toBeVisible()
```

---

## Page Object Fixtures (Pattern 006)

Inject page objects via Playwright fixtures to eliminate `beforeEach` boilerplate.

### Fixture Definition

```typescript
// fixtures/pageObjects.ts
import { test as baseTest, Page } from '@playwright/test'
import { LoginPage } from '../pages/LoginPage'
import { MembersPage } from '../pages/MembersPage'

export const test = baseTest.extend<{
  loginPage: LoginPage
  membersPage: MembersPage
  authenticatedMembersPage: MembersPage
}>({
  loginPage: async ({ page }, use) => {
    await use(new LoginPage(page))
  },

  membersPage: async ({ page }, use) => {
    await use(new MembersPage(page))
  },

  // Composite fixture: login + navigate
  authenticatedMembersPage: async ({ loginPage, membersPage }, use) => {
    await loginPage.navigate()
    await loginPage.login(
      process.env.ADMIN_EMAIL || 'admin@example.com',
      process.env.ADMIN_PASSWORD || 'password123'
    )
    await membersPage.navigate()
    await use(membersPage)
  },
})

export { expect } from '@playwright/test'
```

### Usage in Tests

```typescript
// Import from fixtures, NOT from @playwright/test
import { test, expect } from '../fixtures/pageObjects'

test.describe('Members Page', () => {
  // ✅ No beforeEach, no manual setup
  test('should create member', async ({ authenticatedMembersPage }) => {
    await authenticatedMembersPage.openCreateModal()
    // ... already logged in and on members page
  })

  // Multiple fixtures in one test
  test('should navigate between pages', async ({ loginPage, membersPage }) => {
    await loginPage.navigate()
    await loginPage.login('admin@example.com', 'password123')
    await membersPage.navigate()
  })
})
```

### Before vs After

**Before (anti-pattern):**
```typescript
import { test, expect } from '@playwright/test'
import { LoginPage, MembersPage } from '../pages'

test.describe('Members', () => {
  let loginPage: LoginPage
  let membersPage: MembersPage

  test.beforeEach(async ({ page }) => {
    loginPage = new LoginPage(page)
    membersPage = new MembersPage(page)
    await loginPage.navigate()
    await loginPage.login('admin@example.com', 'password123')
    await membersPage.navigate()
  })

  test('create member', async () => { /* ... */ })
})
```

**After (with fixtures):**
```typescript
import { test, expect } from '../fixtures/pageObjects'

test.describe('Members', () => {
  test('create member', async ({ authenticatedMembersPage }) => { /* ... */ })
})
```

---

## File Organization

```
e2etests/
├── fixtures/
│   ├── auth.fixture.ts          # authenticatedRequest for API tests
│   └── pageObjects.ts           # Page object fixtures for UI tests
├── pages/
│   ├── BasePage.ts
│   ├── LoginPage.ts
│   ├── MembersPage.ts
│   ├── ProductsPage.ts
│   ├── JournalPage.ts
│   ├── SettlementsPage.ts
│   └── index.ts
├── tests/
│   ├── auth.setup.ts
│   ├── api/                     # API tests (use auth.fixture)
│   └── admin/                   # UI tests (use pageObjects fixture)
└── utils/
    ├── members.ts               # Helper functions
    └── transactions.ts
```

---

## Quick Reference

```typescript
// Test ID selector
page.getByTestId('members-create-button')

// Dynamic test ID
page.getByTestId(`members-table-row-${memberId}`)

// Prefix match for multiple elements
page.locator('[data-testid^="members-table-row-"]')

// Page object: private locators, public actions
private readonly btn = () => this.page.getByTestId('create-btn')
async clickCreate() { await this.btn().click() }

// Fixture: inject ready-to-use page object
test('my test', async ({ authenticatedMembersPage }) => { ... })
```
