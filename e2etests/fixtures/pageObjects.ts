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

  // Clear localStorage to ensure clean login state
  await page.evaluate(() => {
    localStorage.removeItem('admin_id')
    localStorage.removeItem('email')
    localStorage.removeItem('display_name')
    localStorage.removeItem('locale')
  })

  // Navigate and login
  const loginPage = new LoginPage(page)
  await loginPage.navigate()
  await page.waitForLoadState('domcontentloaded')

  // Fill and submit login form
  await loginPage.fillEmail('admin@example.com')
  await loginPage.fillPassword('password123')
  await loginPage.clickLogin()

  // Wait for login to complete (increased timeout)
  await page.waitForTimeout(3000)

  // Navigate to members page (handles redirect or manual navigation)
  await page.goto('http://localhost:5173/members', { waitUntil: 'domcontentloaded' })
  await page.waitForTimeout(1000)

  // Create MembersPage instance
  const membersPage = new MembersPage(page)

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
