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
import { LoginPage, MembersPage, ProductsPage, SettlementsPage, CategoriesPage, JournalPage, SettingsPage, AuditLogPage, DashboardPage } from '../pages'
import { ProfilePage } from '../pages/ProfilePage'
import { ExcludedFromCollectionPage } from '../pages/ExcludedFromCollectionPage'

interface PageObjectFixtures {
  loginPage: LoginPage
  membersPage: MembersPage
  authenticatedMembersPage: MembersPage
  productsPage: ProductsPage
  authenticatedProductsPage: ProductsPage
  settlementsPage: SettlementsPage
  authenticatedSettlementsPage: SettlementsPage
  categoriesPage: CategoriesPage
  authenticatedCategoriesPage: CategoriesPage
  journalPage: JournalPage
  authenticatedJournalPage: JournalPage
  settingsPage: SettingsPage
  authenticatedSettingsPage: SettingsPage
  auditLogPage: AuditLogPage
  authenticatedAuditLogPage: AuditLogPage
  authenticatedProfilePage: ProfilePage
  authenticatedDashboardPage: DashboardPage
  authenticatedExcludedPage: ExcludedFromCollectionPage
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
 * Fixture: journalPage
 * Provides JournalPage instance (unauthenticated)
 */
const journalPageFixture = async ({ page }: { page: Page }, use: (value: JournalPage) => Promise<void>) => {
  const journalPage = new JournalPage(page)
  await use(journalPage)
}

/**
 * Fixture: authenticatedJournalPage
 *
 * Provides JournalPage with test already authenticated (via storage state).
 * Simply navigates to the page and returns the page object.
 */
const authenticatedJournalPageFixture = async (
  { page }: { page: Page },
  use: (value: JournalPage) => Promise<void>
) => {
  // Navigate to journal page
  await page.goto('/journal', { waitUntil: 'domcontentloaded' })

  // Wait for page to load - journal-page test ID indicates page is ready
  await page.waitForSelector('[data-testid="journal-page"]', { timeout: 5000 })

  // Wait for data to load - wait for the loading indicator to disappear
  // This matches the pattern used in other pages (MembersPage, ProductsPage)
  await page.waitForSelector('[data-testid="journal-loading"]', { state: 'hidden', timeout: 10000 })

  // Create and provide JournalPage
  const journalPage = new JournalPage(page)
  await use(journalPage)
}

/**
 * Fixture: settingsPage
 * Provides SettingsPage instance (unauthenticated)
 */
const settingsPageFixture = async ({ page }: { page: Page }, use: (value: SettingsPage) => Promise<void>) => {
  const settingsPage = new SettingsPage(page)
  await use(settingsPage)
}

/**
 * Fixture: authenticatedSettingsPage
 *
 * Provides SettingsPage with test already authenticated (via storage state).
 * Simply navigates to the page and returns the page object.
 */
const authenticatedSettingsPageFixture = async (
  { page }: { page: Page },
  use: (value: SettingsPage) => Promise<void>
) => {
  // Navigate to settings page
  await page.goto('/settings', { waitUntil: 'domcontentloaded' })

  // Wait for page to load - settings-page test ID indicates page is ready
  await page.waitForSelector('[data-testid="settings-page"]', { timeout: 5000 })

  // Create and provide SettingsPage
  const settingsPage = new SettingsPage(page)
  await use(settingsPage)
}

/**
 * Fixture: auditLogPage
 * Provides AuditLogPage instance (unauthenticated)
 */
const auditLogPageFixture = async ({ page }: { page: Page }, use: (value: AuditLogPage) => Promise<void>) => {
  const auditLogPage = new AuditLogPage(page)
  await use(auditLogPage)
}

/**
 * Fixture: authenticatedAuditLogPage
 *
 * Provides AuditLogPage with test already authenticated (via storage state).
 * Simply navigates to the page and returns the page object.
 */
const authenticatedAuditLogPageFixture = async (
  { page }: { page: Page },
  use: (value: AuditLogPage) => Promise<void>
) => {
  // Navigate to audit log page
  await page.goto('/audit-log', { waitUntil: 'domcontentloaded' })

  // Wait for page to load - audit-log-page test ID indicates page is ready
  await page.waitForSelector('[data-testid="audit-log-page"]', { timeout: 5000 })

  // Create and provide AuditLogPage
  const auditLogPage = new AuditLogPage(page)
  await use(auditLogPage)
}

/**
 * Fixture: authenticatedProfilePage
 *
 * Provides ProfilePage with test already authenticated (via storage state).
 * Navigates to /profile and waits for the language trigger to be visible.
 */
const authenticatedProfilePageFixture = async (
  { page }: { page: Page },
  use: (value: ProfilePage) => Promise<void>
) => {
  await page.goto('/profile', { waitUntil: 'domcontentloaded' })
  await page.waitForSelector('[data-testid="profile-locale-trigger"]', { timeout: 10000 })
  const profilePage = new ProfilePage(page)
  await use(profilePage)
}

/**
 * Fixture: authenticatedDashboardPage
 *
 * Provides DashboardPage with test already authenticated (via storage state).
 * Simply navigates to the page and returns the page object.
 */
const authenticatedDashboardPageFixture = async (
  { page }: { page: Page },
  use: (value: DashboardPage) => Promise<void>
) => {
  // Navigate to dashboard page
  await page.goto('/dashboard', { waitUntil: 'domcontentloaded' })

  // Wait for page to load - dashboard-page test ID indicates page is ready
  await page.waitForSelector('[data-testid="dashboard-page"]', { timeout: 5000 })

  // Create and provide DashboardPage
  const dashboardPage = new DashboardPage(page)
  await use(dashboardPage)
}

/**
 * Fixture: authenticatedExcludedPage
 * Provides the Excluded from Collection surface (#188), already authenticated.
 */
const authenticatedExcludedPageFixture = async (
  { page }: { page: Page },
  use: (value: ExcludedFromCollectionPage) => Promise<void>
) => {
  await use(new ExcludedFromCollectionPage(page))
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
  categoriesPage: categoriesPageFixture,
  authenticatedCategoriesPage: authenticatedCategoriesPageFixture,
  journalPage: journalPageFixture,
  authenticatedJournalPage: authenticatedJournalPageFixture,
  settingsPage: settingsPageFixture,
  authenticatedSettingsPage: authenticatedSettingsPageFixture,
  auditLogPage: auditLogPageFixture,
  authenticatedAuditLogPage: authenticatedAuditLogPageFixture,
  authenticatedProfilePage: authenticatedProfilePageFixture,
  authenticatedDashboardPage: authenticatedDashboardPageFixture,
  authenticatedExcludedPage: authenticatedExcludedPageFixture,
})

// Re-export expect for convenience
export { expect } from '@playwright/test'
