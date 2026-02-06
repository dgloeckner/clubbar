/**
 * Admin Frontend - i18n Language Switching E2E Tests
 *
 * Tests that the language switcher in the Profile page correctly:
 * 1. Displays navigation in German by default
 * 2. Switches to English when language changed
 * 3. Persists language preference after page refresh
 * 4. Switches back to German
 *
 * Implements E2E Testing Patterns:
 * - Pattern 001: Test Data Isolation (reset locale before each test)
 * - Pattern 002: Authentication Isolation (authenticatedRequest fixture)
 * - Pattern 005: Using Test IDs (data-testid)
 * - Pattern 006: Page Object Model (ProfilePage, MainLayoutPage)
 * - Pattern 008: Playwright Assertions (expect API)
 */

import { test } from '../../fixtures/auth.fixture'
import { ProfilePage } from '../../pages/ProfilePage'
import { MainLayoutPage } from '../../pages/MainLayoutPage'

const API_BASE = 'http://localhost:8080/api'

test.describe('i18n Language Switching', () => {
  test.beforeEach(async ({ page, authenticatedRequest }) => {
    // Reset admin's locale to German via API before each test
    await authenticatedRequest.patch(`${API_BASE}/auth/profile`, {
      data: { locale: 'de' },
    })

    // Clear localStorage to ensure clean state (i18n reads from localStorage)
    await page.goto('/members', { waitUntil: 'domcontentloaded' })
    await page.evaluate(() => {
      localStorage.setItem('adminLocale', 'de')
    })
  })

  test('should display navigation in German by default', async ({ page }) => {
    const layout = new MainLayoutPage(page)

    await page.reload()
    await layout.waitForNavigation()
    await layout.expectNavigationInLanguage('de')
  })

  test('should switch to English when language changed in profile', async ({ page }) => {
    const layout = new MainLayoutPage(page)
    const profilePage = new ProfilePage(page)

    await page.reload()
    await layout.waitForNavigation()

    await profilePage.navigate()
    await profilePage.changeLanguage('en')

    await layout.expectNavigationInLanguage('en')
  })

  test('should persist language after page refresh', async ({ page }) => {
    const layout = new MainLayoutPage(page)
    const profilePage = new ProfilePage(page)

    await page.reload()

    await profilePage.navigate()
    await profilePage.changeLanguage('en')
    await layout.expectNavigationInLanguage('en')

    await page.reload()
    await layout.waitForNavigation()
    await layout.expectNavigationInLanguage('en')
  })

  test('should switch back to German', async ({ page }) => {
    const layout = new MainLayoutPage(page)
    const profilePage = new ProfilePage(page)

    await page.reload()

    await profilePage.navigate()
    await profilePage.changeLanguage('en')
    await layout.expectNavigationInLanguage('en')

    await profilePage.changeLanguage('de')
    await layout.expectNavigationInLanguage('de')
  })
})
