/**
 * Admin Frontend - i18n Language Switching E2E Tests
 *
 * Tests that the language switcher in the Profile page correctly:
 * 1. Displays navigation in German by default
 * 2. Switches to English when language changed
 * 3. Persists language preference after page refresh
 * 4. Switches back to German
 *
 * Each test mints its own isolated admin (see utils/isolatedAdmin.ts) rather
 * than switching the shared seeded admin's locale
 * (playwright/.auth/admin.json, one file for every worker — Pattern 002):
 * `auth/session.ts` calls `changeLanguage(admin.locale)` on every session
 * verify, so flipping that account's locale to English — even briefly, even
 * with a reset in `beforeEach` — was observable by every other test
 * authenticated as the same admin while this spec's tests ran beside them in
 * the same shard, turning unrelated German-text assertions in other specs
 * into flaky failures.
 *
 * Implements E2E Testing Patterns:
 * - Pattern 001: Test Data Isolation (a fresh admin per test, not shared state)
 * - Pattern 002: Authentication Isolation (never mutates the shared admin)
 * - Pattern 005: Using Test IDs (data-testid)
 * - Pattern 006: Page Object Model (ProfilePage, MainLayoutPage)
 * - Pattern 008: Playwright Assertions (expect API)
 */

import { test } from '../../fixtures/pageObjects'
import { createIsolatedAdmin, signInAndEnroll } from '../../utils/isolatedAdmin'
import { ProfilePage } from '../../pages/ProfilePage'
import { MainLayoutPage } from '../../pages/MainLayoutPage'

test.describe('i18n Language Switching', () => {
  // Start logged out: each test signs in as its own isolated admin.
  test.use({ storageState: { cookies: [], origins: [] } })

  test('should display navigation in German by default', async ({ loginPage, page, playwright }) => {
    const { email, password } = await createIsolatedAdmin(playwright, 'i18n-default')
    await signInAndEnroll(loginPage, page, email, password)

    const mainLayoutPage = new MainLayoutPage(page)
    await mainLayoutPage.waitForNavigation()
    await mainLayoutPage.expectNavigationInLanguage('de')
  })

  test('should switch to English when language changed in profile', async ({ loginPage, page, playwright }) => {
    const { email, password } = await createIsolatedAdmin(playwright, 'i18n-switch')
    await signInAndEnroll(loginPage, page, email, password)

    const profilePage = new ProfilePage(page)
    await profilePage.navigate()
    await profilePage.changeLanguage('en')

    const mainLayoutPage = new MainLayoutPage(page)
    await mainLayoutPage.expectNavigationInLanguage('en')
  })

  test('should persist language after page refresh', async ({ loginPage, page, playwright }) => {
    const { email, password } = await createIsolatedAdmin(playwright, 'i18n-persist')
    await signInAndEnroll(loginPage, page, email, password)

    const profilePage = new ProfilePage(page)
    await profilePage.navigate()
    await profilePage.changeLanguage('en')

    const mainLayoutPage = new MainLayoutPage(page)
    await mainLayoutPage.expectNavigationInLanguage('en')

    await page.reload()
    await mainLayoutPage.waitForNavigation()
    await mainLayoutPage.expectNavigationInLanguage('en')
  })

  test('should switch back to German', async ({ loginPage, page, playwright }) => {
    const { email, password } = await createIsolatedAdmin(playwright, 'i18n-back')
    await signInAndEnroll(loginPage, page, email, password)

    const profilePage = new ProfilePage(page)
    await profilePage.navigate()
    await profilePage.changeLanguage('en')

    const mainLayoutPage = new MainLayoutPage(page)
    await mainLayoutPage.expectNavigationInLanguage('en')

    await profilePage.changeLanguage('de')
    await mainLayoutPage.expectNavigationInLanguage('de')
  })
})
