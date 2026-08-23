# Pattern 006: Page Object Model

## Overview

**Pattern 005** provides a clean, reusable abstraction for interacting with pages during E2E tests. Instead of scattering Playwright locators throughout test files, page objects encapsulate all page-specific interactions in classes that provide a semantic, high-level API.

**Benefits:**
- Locators are centralized and easy to update
- Tests focus on behavior, not implementation details
- Page interactions are reusable across multiple tests
- Reduces code duplication
- Makes tests more readable and maintainable

## Pattern Structure

### Page Object Class

```typescript
import { Page } from '@playwright/test'

export class ProductsPage {
  private page: Page

  // Locators as private properties (implementation detail)
  private readonly heading = () => this.page.locator('h1:has-text("Products")')
  private readonly searchInput = () => this.page.locator('input[placeholder*="Search"]')
  private readonly createBtn = () => this.page.locator('button:has-text("Create Product")')
  private readonly table = () => this.page.locator('table, [role="table"]')
  private readonly tableRows = () => this.page.locator('tbody tr, [role="row"]')

  // Modal elements
  private readonly modalHeading = () => this.page.locator('[role="dialog"] h2, .modal h2')
  private readonly productNameInput = () => this.page.locator('input[placeholder*="Product name"]')
  private readonly priceInput = () => this.page.locator('input[type="number"], input[placeholder*="Price"]')
  private readonly createSubmitBtn = () => this.page.locator('button:has-text("Create")').last()
  private readonly cancelBtn = () => this.page.locator('button:has-text("Cancel")')

  constructor(page: Page) {
    this.page = page
  }

  // High-level page interactions (public API)

  async navigate() {
    await this.page.goto('http://localhost:5173/products')
    await this.page.waitForLoadState('domcontentloaded')
  }

  async isHeadingVisible(): Promise<boolean> {
    return await this.heading().isVisible()
  }

  async getTableRowCount(): Promise<number> {
    return await this.tableRows().count()
  }

  async openCreateModal() {
    await this.createBtn().click()
    await this.modalHeading().waitFor({ state: 'visible', timeout: 5000 })
  }

  async fillProductForm(name: string, price: string) {
    await this.productNameInput().fill(name)
    await this.priceInput().fill(price)
  }

  async submitProductForm() {
    await this.createSubmitBtn().click()
  }

  async cancelCreateModal() {
    await this.cancelBtn().click()
  }

  async search(term: string) {
    await this.searchInput().fill(term)
    await this.page.waitForTimeout(500) // Wait for debounce
  }

  async isTableVisible(): Promise<boolean> {
    return await this.table().isVisible()
  }

  async getSearchInputValue(): Promise<string | null> {
    return await this.searchInput().inputValue()
  }
}
```

## Key Principles

### 1. **Locators Are Private**
Locators are implementation details. They should never be exposed to test code.

```typescript
// ❌ BAD: Locators exposed
export const productNameInput = () => page.locator('input[placeholder*="name"]')

// ✅ GOOD: Locators private, accessed via public methods
async fillProductName(name: string) {
  await this.productNameInput().fill(name)
}
```

### 2. **Methods Perform User Actions**
Page object methods should represent what a user does, not how the browser does it.

```typescript
// ❌ BAD: Exposes implementation
test('create product', async ({ page }) => {
  await page.locator('button:has-text("Create")').click()
  await page.locator('input[placeholder*="name"]').fill('Coffee')
})

// ✅ GOOD: User-focused API
test('create product', async ({ page }) => {
  const productsPage = new ProductsPage(page)
  await productsPage.openCreateModal()
  await productsPage.fillProductForm('Coffee', '3.50')
})
```

### 3. **Return Only What Tests Need**
Methods return data that tests actually use (booleans for visibility, strings for values, counts for assertions).

```typescript
// ✅ GOOD: Returns testable data
async isHeadingVisible(): Promise<boolean> {
  return await this.heading().isVisible()
}

async getTableRowCount(): Promise<number> {
  return await this.tableRows().count()
}
```

### 4. **Include Implicit Waits**
Page objects handle waiting for elements. Tests don't need to worry about timing.

```typescript
// ✅ GOOD: Waits for modal before returning
async openCreateModal() {
  await this.createBtn().click()
  await this.modalHeading().waitFor({ state: 'visible', timeout: 5000 })
}
```

## Usage in Tests

### Before (No Page Objects)

```typescript
test('should create product', async ({ page }) => {
  await page.goto('http://localhost:5173/products')
  await page.waitForLoadState('domcontentloaded')

  const createBtn = page.locator('button:has-text("Create Product")')
  await createBtn.click()

  const modal = page.locator('[role="dialog"]')
  await modal.waitFor({ state: 'visible' })

  const nameInput = page.locator('input[placeholder*="Product name"]')
  const priceInput = page.locator('input[type="number"]')

  await nameInput.fill('Coffee')
  await priceInput.fill('3.50')

  const submitBtn = page.locator('button:has-text("Create")')
  await submitBtn.click()

  const successMsg = page.locator('text=/success|created/i')
  await expect(successMsg).toBeVisible()
})
```

### After (With Page Objects)

```typescript
test('should create product', async ({ page }) => {
  const productsPage = new ProductsPage(page)

  await productsPage.navigate()
  await productsPage.openCreateModal()
  await productsPage.fillProductForm('Coffee', '3.50')
  await productsPage.submitProductForm()

  // Assert result
  expect(await productsPage.getTableRowCount()).toBeGreaterThan(0)
})
```

## Multiple Page Objects

For complex applications with multiple pages, create separate page object classes:

```typescript
// pages/LoginPage.ts
export class LoginPage {
  constructor(page: Page) { this.page = page }
  async login(email: string, password: string) { ... }
  async isLoaded(): Promise<boolean> { ... }
}

// pages/ProductsPage.ts
export class ProductsPage {
  constructor(page: Page) { this.page = page }
  async navigate() { ... }
  async openCreateModal() { ... }
}

// pages/index.ts (convenience export)
export { LoginPage } from './LoginPage'
export { ProductsPage } from './ProductsPage'
```

### Using Multiple Pages in Tests

```typescript
test('login and create product', async ({ page }) => {
  const loginPage = new LoginPage(page)
  const productsPage = new ProductsPage(page)

  // Login
  await loginPage.navigate()
  await loginPage.login('admin@example.com', 'password123')

  // Navigate to products and create
  await productsPage.navigate()
  await productsPage.openCreateModal()
  await productsPage.fillProductForm('Coffee', '3.50')
})
```

## Advanced Pattern: Base Page Class

For shared functionality across multiple pages:

```typescript
export abstract class BasePage {
  protected page: Page

  constructor(page: Page) {
    this.page = page
  }

  async navigate(url: string) {
    await this.page.goto(url)
    await this.page.waitForLoadState('domcontentloaded')
  }

  async isElementVisible(locator: Locator): Promise<boolean> {
    return await locator.isVisible().catch(() => false)
  }

  async waitForElement(locator: Locator, timeout = 5000) {
    await locator.waitFor({ state: 'visible', timeout })
  }
}

export class ProductsPage extends BasePage {
  private readonly heading = () => this.page.locator('h1:has-text("Products")')

  async navigate() {
    await super.navigate('http://localhost:5173/products')
  }

  async isHeadingVisible(): Promise<boolean> {
    return await this.isElementVisible(this.heading())
  }
}
```

## Locator Strategy

### Use Semantic Locators (Best)
```typescript
// ✅ BEST: Role-based (accessible, stable)
const button = this.page.getByRole('button', { name: 'Create' })

// ✅ GOOD: Placeholder, aria-label (semantic)
const input = this.page.locator('input[placeholder*="Search"]')

// ⚠️ OK: Class names (can be fragile if styling changes)
const container = this.page.locator('.products-list')

// ❌ AVOID: Generic selectors (brittle, hard to maintain)
const element = this.page.locator('div > div > button:nth-child(3)')
```

## Complete Example: Login Page Object

```typescript
import { Page } from '@playwright/test'

export class LoginPage {
  private readonly page: Page

  private readonly emailInput = () => this.page.getByRole('textbox', { name: /email/i })
  private readonly passwordInput = () => this.page.getByRole('textbox', { name: /password/i })
  private readonly loginBtn = () => this.page.getByRole('button', { name: /login/i })
  private readonly errorMessage = () => this.page.locator('[class*="error"]')

  constructor(page: Page) {
    this.page = page
  }

  async navigate() {
    await this.page.goto('http://localhost:5173/login')
    await this.page.waitForLoadState('domcontentloaded')
  }

  async login(email: string, password: string) {
    await this.emailInput().fill(email)
    await this.passwordInput().fill(password)
    await this.loginBtn().click()

    // Wait for navigation (client-side routing)
    await this.page.waitForTimeout(1000)
  }

  async getErrorMessage(): Promise<string | null> {
    const error = this.errorMessage()
    return await error.textContent().catch(() => null)
  }

  async isErrorVisible(): Promise<boolean> {
    return await this.errorMessage().isVisible().catch(() => false)
  }
}
```

## Testing Best Practices with Page Objects

### ✅ DO
- Create a page object for each distinct page/modal in your application
- Make page object methods represent user actions (click, fill, submit)
- Keep locators private and encapsulated
- Return only the data tests need to assert
- Include implicit waits in page object methods
- Use semantic locators (role-based, aria-label, placeholder)
- Extend BasePage for shared functionality

### ❌ DON'T
- Expose locators or low-level Playwright methods
- Mix page object calls with raw Playwright locators in tests
- Create page objects for small components (use for full pages/modals)
- Return Locator objects from page objects
- Make page objects do business logic (keep them focused on UI)
- Create deeply nested page object hierarchies

## File Organization

```
e2etests/
├── pages/
│   ├── BasePage.ts
│   ├── LoginPage.ts
│   ├── ProductsPage.ts
│   ├── MembersPage.ts
│   └── index.ts (exports all pages)
├── tests/
│   ├── login.spec.ts
│   ├── admin/
│   │   ├── products.spec.ts
│   │   └── members.spec.ts
│   └── api/
│       └── health.spec.ts
└── patterns/
    └── pattern-006-page-object-model.md (this file)
```

## Benefits Summary

| Benefit | Example |
|---------|---------|
| **Maintainability** | Update a selector in one place, not 10 tests |
| **Readability** | `await productsPage.openCreateModal()` vs `await page.locator('button').click()` |
| **Reusability** | Use same page methods in multiple tests without duplication |
| **Robustness** | Implicit waits prevent flaky tests |
| **Scalability** | Easy to add new pages and tests as app grows |
| **Encapsulation** | Tests only know about high-level user actions, not UI details |

## Migration Guide

### Step 1: Create Page Objects
Identify distinct pages/modals and create corresponding page object classes.

### Step 2: Extract Locators
Move all locators from tests into page object private properties.

### Step 3: Create User Action Methods
For each locator, create a public method that represents what a user does.

### Step 4: Update Tests
Replace raw Playwright calls with page object method calls.

### Step 5: Add Shared Functionality
Create BasePage for common functionality (navigate, wait, etc).

## See Also

- **Pattern 001**: Test Data Isolation
- **Pattern 002**: Authentication Isolation
- **Pattern 003**: Database-Agnostic Assertions
- **Pattern 004**: Parallel Execution Safety
