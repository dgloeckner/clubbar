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
import { LoginPage, MembersPage, ProductsPage, SettlementsPage, StatisticsPage, CategoriesPage } from '../pages'

interface PageObjectFixtures {
  loginPage: LoginPage
  membersPage: MembersPage
  authenticatedMembersPage: MembersPage
  productsPage: ProductsPage
  authenticatedProductsPage: ProductsPage
  settlementsPage: SettlementsPage
  authenticatedSettlementsPage: SettlementsPage
  statisticsPage: StatisticsPage
  authenticatedStatisticsPage: StatisticsPage
  categoriesPage: CategoriesPage
  authenticatedCategoriesPage: CategoriesPage
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
 * Fixture: settlementsPage
 * Provides SettlementsPage instance (unauthenticated)
 */
const settlementsPageFixture = async ({ page }: { page: Page }, use: (value: SettlementsPage) => Promise<void>) => {
  const settlementsPage = new SettlementsPage(page)
  await use(settlementsPage)
}

/**
 * Fixture: authenticatedSettlementsPage
 *
 * Provides SettlementsPage with test already authenticated (via storage state).
 * Simply navigates to the page and returns the page object.
 */
const authenticatedSettlementsPageFixture = async (
  { page }: { page: Page },
  use: (value: SettlementsPage) => Promise<void>
) => {
  // Navigate to settlements page
  await page.goto('/settlements', { waitUntil: 'domcontentloaded' })

  // Wait for page to load - settlements-page test ID indicates page is ready
  await page.waitForSelector('[data-testid="settlements-page"]', { timeout: 5000 })

  // Create and provide SettlementsPage
  const settlementsPage = new SettlementsPage(page)
  await use(settlementsPage)
}

/**
 * Fixture: statisticsPage
 * Provides StatisticsPage instance (unauthenticated)
 */
const statisticsPageFixture = async ({ page }: { page: Page }, use: (value: StatisticsPage) => Promise<void>) => {
  const statisticsPage = new StatisticsPage(page)
  await use(statisticsPage)
}

/**
 * Fixture: authenticatedStatisticsPage
 *
 * Provides StatisticsPage with test already authenticated (via storage state).
 * Simply navigates to the page and returns the page object.
 */
const authenticatedStatisticsPageFixture = async (
  { page }: { page: Page },
  use: (value: StatisticsPage) => Promise<void>
) => {
  // Navigate to statistics page
  await page.goto('/statistics', { waitUntil: 'domcontentloaded' })

  // Wait for page to load - statistics-page test ID indicates page is ready
  await page.waitForSelector('[data-testid="statistics-page"]', { timeout: 5000 })

  // Create and provide StatisticsPage
  const statisticsPage = new StatisticsPage(page)
  await use(statisticsPage)
}

/**
 * Fixture: categoriesPage
 * Provides CategoriesPage instance (unauthenticated)
 */
const categoriesPageFixture = async ({ page }: { page: Page }, use: (value: CategoriesPage) => Promise<void>) => {
  const categoriesPage = new CategoriesPage(page)
  await use(categoriesPage)
}

/**
 * Fixture: authenticatedCategoriesPage
 *
 * Provides CategoriesPage with test already authenticated (via storage state).
 * Simply navigates to the page and returns the page object.
 */
const authenticatedCategoriesPageFixture = async (
  { page }: { page: Page },
  use: (value: CategoriesPage) => Promise<void>
) => {
  // Navigate to categories page
  await page.goto('/categories', { waitUntil: 'domcontentloaded' })

  // Wait for page to load - categories-page test ID indicates page is ready
  await page.waitForSelector('[data-testid="categories-page"]', { timeout: 5000 })

  // Create and provide CategoriesPage
  const categoriesPage = new CategoriesPage(page)
  await use(categoriesPage)
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
  settlementsPage: settlementsPageFixture,
  authenticatedSettlementsPage: authenticatedSettlementsPageFixture,
  statisticsPage: statisticsPageFixture,
  authenticatedStatisticsPage: authenticatedStatisticsPageFixture,
  categoriesPage: categoriesPageFixture,
  authenticatedCategoriesPage: authenticatedCategoriesPageFixture,
})

// Re-export expect for convenience
export { expect } from '@playwright/test'
