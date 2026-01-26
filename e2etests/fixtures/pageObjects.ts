/**
 * Page Object Fixtures
 *
 * Provides ready-to-use page objects.
 * Implements E2E Testing Pattern 007: Page Object Fixtures
 *
 * Authentication:
 * - Uses Playwright's storage state (saved by auth.setup.ts)
 * - Tests are already authenticated when they run
 * - No login logic in fixtures (cleaner, faster, more reliable)
 *
 * Usage in tests:
 *   import { test, expect } from '../fixtures/pageObjects'
 *
 *   test('my test', async ({ authenticatedMembersPage }) => {
 *     // Already authenticated, navigate to page
 *     await authenticatedMembersPage.expectPageVisible()
 *   })
 */

import { test as base, Page } from '@playwright/test'
import { LoginPage, MembersPage, ProductsPage } from '../pages'

interface PageObjectFixtures {
  loginPage: LoginPage
  membersPage: MembersPage
  authenticatedMembersPage: MembersPage
  productsPage: ProductsPage
  authenticatedProductsPage: ProductsPage
}

/**
 * Fixture: loginPage
 * Provides LoginPage instance (for auth tests)
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
 *
 * Provides MembersPage with test already authenticated (via storage state).
 * Simply navigates to the page and returns the page object.
 * No login needed - auth was setup by auth.setup.ts
 */
const authenticatedMembersPageFixture = async (
  { page }: { page: Page },
  use: (value: MembersPage) => Promise<void>
) => {
  // Navigate to members page
  await page.goto('/members', { waitUntil: 'domcontentloaded' })

  // Wait for page to load - members-page test ID indicates page is ready
  await page.waitForSelector('[data-testid="members-page"]', { timeout: 5000 })

  // Create and provide MembersPage
  const membersPage = new MembersPage(page)
  await use(membersPage)
}

/**
 * Fixture: productsPage
 * Provides ProductsPage instance (unauthenticated)
 */
const productsPageFixture = async ({ page }: { page: Page }, use: (value: ProductsPage) => Promise<void>) => {
  const productsPage = new ProductsPage(page)
  await use(productsPage)
}

/**
 * Fixture: authenticatedProductsPage
 *
 * Provides ProductsPage with test already authenticated (via storage state).
 * Simply navigates to the page and returns the page object.
 */
const authenticatedProductsPageFixture = async (
  { page }: { page: Page },
  use: (value: ProductsPage) => Promise<void>
) => {
  // Navigate to products page
  await page.goto('/products', { waitUntil: 'domcontentloaded' })

  // Wait for page to load - products-page test ID indicates page is ready
  await page.waitForSelector('[data-testid="products-page"]', { timeout: 5000 })

  // Create and provide ProductsPage
  const productsPage = new ProductsPage(page)
  await use(productsPage)
}

/**
 * Extend base test with custom fixtures
 */
export const test = base.extend<PageObjectFixtures>({
  loginPage: loginPageFixture,
  membersPage: membersPageFixture,
  authenticatedMembersPage: authenticatedMembersPageFixture,
  productsPage: productsPageFixture,
  authenticatedProductsPage: authenticatedProductsPageFixture,
})

// Re-export expect for convenience
export { expect } from '@playwright/test'
