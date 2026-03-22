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
 * - Pattern 007: Page Object Fixtures (injected POMs)
 * - Pattern 008: Playwright Assertions (expect API)
 */

import { test } from '../../fixtures/auth.fixture'
import { csrfHeaders } from '../../utils/csrf'

const API_BASE = 'http://localhost:8080/api'

test.describe('i18n Language Switching', () => {
  test.beforeEach(async ({ page }) => {
    // Navigate to a page first so the page context has loaded cookies+localStorage
    await page.goto('/members', { waitUntil: 'domcontentloaded' })

    // Reset admin's locale to German via the existing browser session.
    // Using page.request avoids a fresh login (which risks hitting the rate limiter
    // when multiple tests run in parallel).
    const headers = await csrfHeaders(page)
    await page.request.patch(`${API_BASE}/auth/profile`, {
      data: { locale: 'de' },
      headers,
    })

    // Sync localStorage so the frontend picks up the reset locale
    await page.evaluate(() => {
      localStorage.setItem('adminLocale', 'de')
    })
  })

  test('should display navigation in German by default', async ({ page, mainLayoutPage }) => {
    await page.reload()
    await mainLayoutPage.waitForNavigation()
    await mainLayoutPage.expectNavigationInLanguage('de')
  })

  test('should switch to English when language changed in profile', async ({ page, mainLayoutPage, profilePage }) => {
    await page.reload()
    await mainLayoutPage.waitForNavigation()

    await profilePage.navigate()
    await profilePage.changeLanguage('en')

    await mainLayoutPage.expectNavigationInLanguage('en')
  })

  test('should persist language after page refresh', async ({ page, mainLayoutPage, profilePage }) => {
    await page.reload()

    await profilePage.navigate()
    await profilePage.changeLanguage('en')
    await mainLayoutPage.expectNavigationInLanguage('en')

    await page.reload()
    await mainLayoutPage.waitForNavigation()
    await mainLayoutPage.expectNavigationInLanguage('en')
  })

  test('should switch back to German', async ({ page, mainLayoutPage, profilePage }) => {
    await page.reload()

    await profilePage.navigate()
    await profilePage.changeLanguage('en')
    await mainLayoutPage.expectNavigationInLanguage('en')

    await profilePage.changeLanguage('de')
    await mainLayoutPage.expectNavigationInLanguage('de')
  })
})
