/**
 * Page Object Fixtures
 *
 * Provides ready-to-use page objects with authentication.
 * Implements E2E Testing Pattern 007: Page Object Fixtures
 *
 * Usage in tests:
 *   import { test, expect } from '../fixtures/pageObjects.fixture'
 *
 *   test('my test', async ({ loginPage, authenticatedMembersPage }) => {
 *     // authenticatedMembersPage fixture automatically:
 *     // - Clears localStorage
 *     // - Logs in
 *     // - Navigates to members page
 *     await authenticatedMembersPage.createMember('test@ex.com', 'First', 'Last')
 *   })
 */

import { test as base, Page } from '@playwright/test'
import { LoginPage, MembersPage } from '../pages'

interface PageObjectFixtures {
  loginPage: LoginPage
  membersPage: MembersPage
  authenticatedMembersPage: MembersPage
}

/**
 * Fixture: loginPage
 * Provides LoginPage instance
 */
const loginPageFixture = async ({ page }: { page: Page }, use: (value: LoginPage) => Promise<void>) => {
  const loginPage = new LoginPage(page)
  await use(loginPage)
}

/**
 * Fixture: membersPage
 * Provides MembersPage instance (unauthenticated)
 */
const membersPageFixture = async ({ page }: { page: Page }, use: (value: MembersPage) => Promise<void>) => {
  const membersPage = new MembersPage(page)
  await use(membersPage)
}

/**
 * Fixture: authenticatedMembersPage
 * Provides MembersPage with admin already logged in
 *
 * Setup:
 * 1. Clears localStorage to ensure clean state
 * 2. Logs in with test credentials
 * 3. Navigates to members page
 *
 * Test can immediately use page object methods
 */
const authenticatedMembersPageFixture = async (
  { page }: { page: Page },
  use: (value: MembersPage) => Promise<void>
) => {
  // Clear auth state
  await page.goto('http://localhost:5173/')
  await page.evaluate(() => {
    localStorage.removeItem('admin_id')
    localStorage.removeItem('email')
    localStorage.removeItem('display_name')
    localStorage.removeItem('locale')
  })

  // Login
  const loginPage = new LoginPage(page)
  await loginPage.navigate()
  await page.waitForTimeout(500)

  // Fill credentials
  await loginPage.fillEmail('admin@example.com')
  await loginPage.fillPassword('password123')
  await loginPage.clickLogin()

  // Wait a bit for login to process
  await page.waitForTimeout(2000)

  // Navigate to members page (handles both successful login and already-logged-in cases)
  const membersPage = new MembersPage(page)
  await membersPage.navigate()

  // Wait for stats to load
  try {
    await page.waitForSelector('[data-testid="stat-card-mitglieder"]', { timeout: 10000 })
  } catch (error) {
    // If stats don't load, page might not have members yet - that's ok
    await page.waitForLoadState('domcontentloaded')
  }

  // Provide authenticated page object to test
  await use(membersPage)
}

/**
 * Extend base test with custom fixtures
 */
export const test = base.extend<PageObjectFixtures>({
  loginPage: loginPageFixture,
  membersPage: membersPageFixture,
  authenticatedMembersPage: authenticatedMembersPageFixture,
})

// Re-export expect for convenience
export { expect } from '@playwright/test'
