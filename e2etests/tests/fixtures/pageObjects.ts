/**
 * Page Object Fixtures
 *
 * Provides Playwright fixtures that inject ready-to-use page objects into tests.
 * Implements E2E Testing Pattern 006: Page Object Fixtures
 *
 * Usage:
 *   import { test, expect } from '../fixtures/pageObjects'
 *
 *   test('my test', async ({ loginPage, productsPage }) => {
 *     // Use fixtures instead of manual instantiation
 *   })
 */

import { test as baseTest, Page } from '@playwright/test'
import { BasePage, LoginPage, ProductsPage } from '../../pages'

/**
 * Fixture: loginPage
 * Provides a LoginPage instance
 */
const loginPageFixture = async ({ page }: { page: Page }, use: (value: LoginPage) => Promise<void>) => {
  const loginPage = new LoginPage(page)
  await use(loginPage)
}

/**
 * Fixture: productsPage
 * Provides a ProductsPage instance
 */
const productsPageFixture = async ({ page }: { page: Page }, use: (value: ProductsPage) => Promise<void>) => {
  const productsPage = new ProductsPage(page)
  await use(productsPage)
}

/**
 * Fixture: authenticatedProductsPage
 *
 * Provides a ProductsPage with admin user already logged in and navigated to products page.
 * Composite fixture that depends on loginPage and productsPage.
 *
 * Usage:
 *   test('create product', async ({ authenticatedProductsPage }) => {
 *     // Already logged in, no setup needed
 *     await authenticatedProductsPage.openCreateModal()
 *   })
 */
const authenticatedProductsPageFixture = async (
  {
    loginPage,
    productsPage,
  }: {
    loginPage: LoginPage
    productsPage: ProductsPage
  },
  use: (value: ProductsPage) => Promise<void>
) => {
  // Setup: Login and navigate to products page
  await loginPage.navigate()
  await loginPage.login('admin@example.com', 'password123')
  await productsPage.navigate()

  // Provide authenticated page object to test
  await use(productsPage)

  // Teardown (optional): Could add logout here if needed
  // await loginPage.logout()
}

/**
 * Custom test function with fixtures
 *
 * Extends baseTest with custom fixtures:
 * - loginPage: Basic LoginPage instance
 * - productsPage: Basic ProductsPage instance
 * - authenticatedProductsPage: ProductsPage with admin logged in
 */
export const test = baseTest.extend({
  loginPage: loginPageFixture,
  productsPage: productsPageFixture,
  authenticatedProductsPage: authenticatedProductsPageFixture,
})

// Re-export expect for convenience
export { expect } from '@playwright/test'
