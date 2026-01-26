# E2E Testing Pattern 008: Playwright Assertions & Auto-Waiting

## Overview

**Pattern 008** establishes the idiomatic way to assert on UI state in Playwright E2E tests. It eliminates brittle manual visibility checks wrapped in try-catch and replaces them with Playwright's robust built-in `expect()` API that includes automatic waiting and clear error messages.

**Problem Solved:**
- Manual try-catch wrapped visibility checks swallow errors → Hard to debug failures
- Silent fallbacks hide real problems → Tests pass when they shouldn't
- Playwright's auto-waiting and error messages lost → Weak test feedback
- Page objects exposing helper methods for visibility checks → Anti-pattern duplication

**Solution:**
- Use `expect(locator).toBeVisible()` for synchronous assertions
- Use `await expect(locator).toBeVisible()` in async contexts
- Playwright auto-waits up to 30 seconds with intelligent polling
- Descriptive error messages show exactly what's wrong
- Faster test feedback and easier debugging

---

## The Anti-Pattern: Try-Catch Visibility Checks

```typescript
// ❌ ANTI-PATTERN: Manual visibility check with try-catch
class BasePage {
  async isElementVisible(locator: Locator): Promise<boolean> {
    try {
      return await locator.isVisible({ timeout: 1000 })
    } catch {
      return false  // ← Swallows all errors, hides real problems
    }
  }
}

// Usage in tests
const isVisible = await page.isElementVisible(loginBtn)
if (isVisible) {
  // ← Silent failure: We don't know WHY it's not visible
  await loginBtn.click()
}

// Usage in page objects
async isLoaded(): Promise<boolean> {
  return await this.isElementVisible(this.heading())
}
```

**Why This Fails:**

1. **Swallows Errors**: Network timeout? Error getting ignored. Page crashed? No feedback.
2. **Silent Failures**: Test says "element not visible" but doesn't say why (wrong selector, page crashed, wrong URL, etc.)
3. **Hard to Debug**: No stack trace, no error message, no helpful output
4. **Performance**: Creating multiple async tasks just to get boolean values
5. **Unmaintainable**: Every page object duplicates the same try-catch pattern

---

## The Solution: Playwright Expect API

### Synchronous Assertions (in sync contexts)

```typescript
// ✅ GOOD: Use expect() for direct assertions
import { expect } from '@playwright/test'

test('button is visible', async ({ page }) => {
  const loginBtn = page.getByRole('button', { name: /login/i })

  // Auto-waits up to 30s, then gives detailed error
  await expect(loginBtn).toBeVisible()

  // If fails, Playwright shows:
  // "Timeout 30000ms exceeded. Locator: button with text 'Login' not found"
  // With full stack trace and helpful diagnostics
})
```

### With Custom Timeout

```typescript
// ✅ GOOD: Override timeout when needed
await expect(modal).toBeVisible({ timeout: 10000 })

// ✅ GOOD: Assert element is hidden
await expect(modal).toBeHidden({ timeout: 5000 })
```

### Chaining Multiple Assertions

```typescript
// ✅ GOOD: Combine assertions without duplication
test('form submission flow', async ({ page }) => {
  const form = page.locator('form')
  const submitBtn = form.locator('button[type="submit"]')
  const successMsg = page.locator('[role="alert"]')

  // All auto-wait with clear error messages
  await expect(form).toBeVisible()
  await expect(submitBtn).toBeEnabled()
  await submitBtn.click()
  await expect(successMsg).toContainText('Success')
})
```

---

## Pattern: Page Objects Without Try-Catch Helpers

Instead of exposing visibility helpers, page objects should:

1. **Provide semantic actions** (what a user does)
2. **Let Playwright handle waits** (built-in auto-waiting)
3. **Return values tests need** (not booleans)

### Anti-Pattern: Page Object with Try-Catch

```typescript
// ❌ ANTI-PATTERN: Exposing visibility helpers
export class LoginPage extends BasePage {
  private readonly heading = () => this.page.locator('h1')

  // ❌ This shouldn't exist - encourages try-catch pattern
  async isLoaded(): Promise<boolean> {
    return await this.isElementVisible(this.heading())
  }

  async login(email: string, password: string) {
    // ← Tests forced to use: if (await page.isLoaded()) { ... }
    // ← Anti-pattern creeps into tests
  }
}
```

### Proper: Page Object with Semantic Methods

```typescript
// ✅ GOOD: Page object provides semantic actions
export class LoginPage extends BasePage {
  private readonly heading = () => this.page.locator('h1:has-text("Login")')
  private readonly emailInput = () => this.page.getByLabel('Email')
  private readonly passwordInput = () => this.page.getByLabel('Password')
  private readonly submitBtn = () => this.page.getByRole('button', { name: /login/i })

  async navigate() {
    await this.page.goto('http://localhost:5173/login')
    // ← Page object handles implicit wait via Playwright
  }

  async fillForm(email: string, password: string) {
    // ← Playwright auto-waits for each interaction
    await this.emailInput().fill(email)
    await this.passwordInput().fill(password)
  }

  async submitForm() {
    // ← Let Playwright handle waiting
    await this.submitBtn().click()
    // ← Returns immediately; Playwright handles waits internally
  }

  async getErrorMessage(): Promise<string | null> {
    // ← Return values tests actually use
    const errorLocator = this.page.locator('[role="alert"]')
    try {
      return await errorLocator.textContent()
    } catch {
      return null  // ← Only catch for "element doesn't exist" case
    }
  }
}
```

### Proper Test Using This Pattern

```typescript
// ✅ GOOD: Tests are simple and focused
test('successful login', async ({ page }) => {
  const loginPage = new LoginPage(page)

  await loginPage.navigate()
  await loginPage.fillForm('user@example.com', 'password123')
  await loginPage.submitForm()

  // Assert on page state using expect()
  await expect(page).toHaveURL('**/dashboard')
  await expect(page.locator('h1')).toContainText('Dashboard')
})

// ✅ GOOD: Error handling test
test('shows error on invalid login', async ({ page }) => {
  const loginPage = new LoginPage(page)

  await loginPage.navigate()
  await loginPage.fillForm('user@example.com', 'wrongpassword')
  await loginPage.submitForm()

  // Use expect() to assert on error state
  const errorMsg = await loginPage.getErrorMessage()
  expect(errorMsg).toContain('Invalid credentials')

  // Or directly with expect:
  await expect(page.locator('[role="alert"]')).toContainText('Invalid credentials')
})
```

---

## Playwright Expect Matchers

### Visibility & Presence

```typescript
// Wait for element to be visible
await expect(locator).toBeVisible()

// Wait for element to be hidden
await expect(locator).toBeHidden()

// Wait for element to be enabled
await expect(locator).toBeEnabled()

// Wait for element to be disabled
await expect(locator).toBeDisabled()

// Wait for element to be checked (checkbox/radio)
await expect(locator).toBeChecked()

// Wait for element to exist in DOM (even if hidden)
await expect(locator).toHaveCount(1)
```

### Content Assertions

```typescript
// Wait for element to have text
await expect(locator).toContainText('Expected text')

// Wait for element to have exact text
await expect(locator).toHaveText('Exact text')

// Wait for element to have attribute
await expect(locator).toHaveAttribute('data-testid', 'my-element')

// Wait for element to have value
await expect(locator).toHaveValue('input value')
```

### Navigation & URL

```typescript
// Wait for URL to match
await expect(page).toHaveURL('**/dashboard')

// Wait for specific URL
await expect(page).toHaveURL(/dashboard\d+/)
```

### Counts

```typescript
// Wait for exactly N matching elements
await expect(page.locator('[role="listitem"]')).toHaveCount(5)

// Wait for at least 1 matching element
await expect(page.locator('[role="row"]')).toHaveCount(count => count > 0)
```

---

## Anti-Patterns to Avoid

### ❌ Anti-Pattern 1: Try-Catch for Every Check

```typescript
// ❌ BAD: Excessive try-catch
async isLoaded() {
  try {
    return await this.heading().isVisible({ timeout: 1000 })
  } catch {
    return false
  }
}

// Usage
if (await page.isLoaded()) {
  // ...
}
```

**Fix**: Use `expect()` directly

```typescript
// ✅ GOOD
test('page loads', async ({ page }) => {
  await expect(page.locator('h1')).toBeVisible()
})
```

### ❌ Anti-Pattern 2: Polling with Waits

```typescript
// ❌ BAD: Manual polling
async waitForData() {
  let data = null
  for (let i = 0; i < 30; i++) {
    data = await this.getData()
    if (data) break
    await this.page.waitForTimeout(1000)  // ← Polling is slow
  }
  return data
}
```

**Fix**: Let Playwright handle waiting

```typescript
// ✅ GOOD: Playwright auto-waits
async getData(): Promise<string | null> {
  const dataElement = this.page.locator('[data-testid="data"]')
  await expect(dataElement).toBeVisible()
  return await dataElement.textContent()
}
```

### ❌ Anti-Pattern 3: Silent Failures

```typescript
// ❌ BAD: Silently continue if element not found
async clickIfExists(selector: string) {
  try {
    await this.page.locator(selector).click()
  } catch {
    // ← What if click failed? We don't know
  }
}

test('my test', async ({ page }) => {
  await page.clickIfExists('.button')
  // ← Did it click or not? Test doesn't know
})
```

**Fix**: Make intent explicit

```typescript
// ✅ GOOD: Explicit waiting
test('my test', async ({ page }) => {
  const btn = page.locator('[data-testid="submit-button"]')

  // If button doesn't appear, test fails with clear error
  await expect(btn).toBeVisible()
  await btn.click()
})

// Or if button is optional:
test('my test', async ({ page }) => {
  const btn = page.locator('[data-testid="optional-button"]')

  // Explicitly handle optional case
  if (await btn.isVisible({ timeout: 1000 }).catch(() => false)) {
    await btn.click()
  }
  // ← Still clear: if isVisible returns false, we skip
})
```

### ❌ Anti-Pattern 4: Returning Booleans Instead of Values

```typescript
// ❌ BAD: Test has to interpret boolean
async isErrorVisible(): Promise<boolean> {
  return await this.page.locator('[role="alert"]').isVisible()
}

test('login error', async ({ page }) => {
  const isError = await page.isErrorVisible()
  if (isError) {
    // ← But what's the error text?
    console.log('Error shown')
  }
})
```

**Fix**: Return the value tests need

```typescript
// ✅ GOOD: Return actual data
async getErrorMessage(): Promise<string | null> {
  const error = this.page.locator('[role="alert"]')
  try {
    await expect(error).toBeVisible()
    return await error.textContent()
  } catch {
    return null
  }
}

test('login error', async ({ page }) => {
  const error = await page.getErrorMessage()
  expect(error).toContain('Invalid')  // ← Clear assertion
})
```

---

## Verification Checklist

Before committing E2E tests, verify:

- [ ] **🚨 NO ARBITRARY `waitForTimeout()` CALLS** - Every `.waitForTimeout()` must have an explicit expectation immediately after
- [ ] No page objects expose `isVisible()` or `isElementVisible()` helper methods
- [ ] All visibility/state checks use `await expect(locator).toBeVisible()`
- [ ] No try-catch wrapped `isVisible()` calls in test code
- [ ] Page objects return semantic values (strings, counts, data), not booleans
- [ ] Page object methods represent user actions (click, fill, submit)
- [ ] All await-able Playwright methods are awaited
- [ ] Custom waits only used when Playwright's auto-waiting insufficient
- [ ] Tests use `expect()` for assertions, not custom helper methods
- [ ] Error messages are descriptive (use Playwright's built-in messages)
- [ ] Every navigation has URL expectation: `await page.waitForURL()` or `await expect(page).toHaveURL()`
- [ ] Every state change has element expectation: `await expect(locator).toBeVisible()`

---

## ⚠️ CRITICAL RULE: NO `waitForTimeout()` Without Expectations

**This is the most common anti-pattern and MUST NOT appear in tests:**

```typescript
// ❌ ABSOLUTELY FORBIDDEN: Arbitrary timeout without expectations
await page.waitForTimeout(2000)  // ← What are we waiting for? Does nothing!
await page.waitForTimeout(1500)  // ← No verification that anything happened
await loginBtn.click()
await page.waitForTimeout(1000)  // ← Is the page loading? Did something break?
```

**Why This is Critical:**

1. **Flaky Tests**: If the page loads in 500ms, test waits 1500ms unnecessarily (slow)
2. **Hidden Bugs**: If navigation fails, arbitrary timeout hides the problem
3. **Test Fragility**: Times out in CI, passes locally - unreliable
4. **No Feedback**: Timeout ends silently; tests can't tell if something actually happened
5. **Unmaintainable**: Next developer doesn't know WHY the wait is there

**EVERY `waitForTimeout()` must be replaced with an explicit expectation:**

```typescript
// ❌ WRONG
await loginPage.login('user@example.com', 'password123')
await page.waitForTimeout(2000)  // ← What?

// ✅ CORRECT: Wait for actual navigation
await loginPage.login('user@example.com', 'password123')
await page.waitForURL('**/members', { timeout: 5000 })  // ← Verify it happened

// ✅ CORRECT: Wait for element to appear
await loginBtn.click()
await expect(page.locator('[data-testid="success-message"]')).toBeVisible()

// ✅ CORRECT: Wait for response from server
await expect(page).toHaveURL('**/dashboard')  // ← Confirm URL changed
```

**Real Example from auth.setup.ts:**

Before (Anti-Pattern):
```typescript
await loginPage.login('admin@example.com', 'password123')
await page.waitForTimeout(2000)  // ← Arbitrary wait, might hide login failure
```

After (Correct):
```typescript
await loginPage.login('admin@example.com', 'password123')
await page.waitForURL('**/members', { timeout: 5000 })  // ← Verify redirect happened
```

---

## When to Use Manual Waits (Rare Cases)

**Manual waits ONLY appear in these specific cases:**

1. **Waiting for a specific millisecond delay** (e.g., debounce, animation)
   ```typescript
   // ✅ OK: Explicit delay for debounce
   await loginPage.search('query')
   await page.waitForTimeout(500)  // ← Documented debounce time, with comment explaining why
   await expect(page.locator('[data-testid="results"]')).toBeVisible()  // ← Then verify
   ```

2. **Waiting for external system** (API, WebSocket, etc.)
   ```typescript
   // ✅ OK: Waiting for external response
   await page.waitForResponse(
     response => response.url().includes('/api/sync') && response.status() === 200
   )
   ```

3. **Waiting for browser events** (load, unload, etc.)
   ```typescript
   // ✅ OK: Waiting for browser event
   await Promise.all([
     page.waitForNavigation(),
     page.click('[data-testid="submit"]')
   ])
   ```

---

## Real-World Example: Fixing Members Page Tests

### Before (Anti-Pattern)

```typescript
// ❌ ANTI-PATTERN
export class MembersPage {
  private statCard = () => this.page.getByTestId('stat-card-mitglieder')

  async isLoaded(): Promise<boolean> {
    try {
      return await this.statCard().isVisible({ timeout: 1000 })
    } catch {
      return false  // ← Silent failure!
    }
  }
}

test('members page loads', async ({ membersPage }) => {
  const isLoaded = await membersPage.isLoaded()
  expect(isLoaded).toBe(true)  // ← Weak assertion, hard to debug
})
```

### After (Pattern 008)

```typescript
// ✅ GOOD
export class MembersPage {
  private statCard = () => this.page.getByTestId('stat-card-mitglieder')

  // No isLoaded() method - tests use expect() directly
  async getMemberCount(): Promise<number> {
    // ← Return actual value tests need
    const text = await this.statCard().textContent()
    return parseInt(text || '0', 10)
  }
}

test('members page loads', async ({ page, membersPage }) => {
  // ← Playwright auto-waits, fails with clear error if stat card doesn't appear
  await expect(page.getByTestId('stat-card-mitglieder')).toBeVisible()

  const count = await membersPage.getMemberCount()
  expect(count).toBeGreaterThanOrEqual(0)
})
```

---

## Benefits of Pattern 008

✅ **Clearer Failures**: Playwright's error messages show exactly what's wrong
✅ **Faster Debugging**: Stack traces pinpoint the issue
✅ **Auto-Waiting**: Playwright intelligently waits with optimal polling
✅ **Maintainable**: No duplication of try-catch in every page object
✅ **Idiomatic**: Follows Playwright best practices and conventions
✅ **Testable**: Assertions are verifiable; return values are checkable

---

## See Also

- [Playwright: Assertions](https://playwright.dev/docs/test-assertions)
- [Playwright: Locators](https://playwright.dev/docs/locators)
- [Pattern 005: Test IDs](005-test-ids.md) - Reliable selectors for assertions
- [Pattern 006: Page Object Model](005-page-object-model.md) - Structure page objects properly
- [Pattern 007: Page Object Fixtures](006-page-object-fixtures.md) - Inject page objects cleanly
