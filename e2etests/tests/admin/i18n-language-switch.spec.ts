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
 * - Pattern 006: Page Object Model (ProfilePage)
 * - Pattern 008: Playwright Assertions (expect API)
 */

import { test, expect } from '../../fixtures/auth.fixture'
import { ProfilePage } from '../../pages/ProfilePage'

const API_BASE = 'http://localhost:8080/api'

// Navigation labels by language
const NAV_LABELS = {
  de: {
    members: 'Mitglieder',
    products: 'Produkte',
    categories: 'Kategorien',
    journal: 'Buchungsjournal',
    settlements: 'Abrechnungen',
    statistics: 'Statistik',
    settings: 'Einstellungen',
    auditLog: 'Audit-Log',
  },
  en: {
    members: 'Members',
    products: 'Products',
    categories: 'Categories',
    journal: 'Journal',
    settlements: 'Settlements',
    statistics: 'Statistics',
    settings: 'Settings',
    auditLog: 'Audit Log',
  },
}

/**
 * Helper to verify navigation labels match expected language
 */
async function expectNavigationInLanguage(page: any, lang: 'de' | 'en') {
  const labels = NAV_LABELS[lang]
  await expect(page.locator('[data-testid="nav-members"]')).toContainText(labels.members)
  await expect(page.locator('[data-testid="nav-products"]')).toContainText(labels.products)
  await expect(page.locator('[data-testid="nav-categories"]')).toContainText(labels.categories)
  await expect(page.locator('[data-testid="nav-journal"]')).toContainText(labels.journal)
  await expect(page.locator('[data-testid="nav-settlements"]')).toContainText(labels.settlements)
  await expect(page.locator('[data-testid="nav-statistics"]')).toContainText(labels.statistics)
  await expect(page.locator('[data-testid="nav-settings"]')).toContainText(labels.settings)
  await expect(page.locator('[data-testid="nav-audit-log"]')).toContainText(labels.auditLog)
}

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
    // Reload to pick up the localStorage change
    await page.reload()
    await page.waitForSelector('[data-testid="nav-members"]', { timeout: 10000 })

    // Verify German labels
    await expectNavigationInLanguage(page, 'de')
  })

  test('should switch to English when language changed in profile', async ({ page }) => {
    // Reload to ensure German is active
    await page.reload()
    await page.waitForSelector('[data-testid="nav-members"]', { timeout: 10000 })

    // Navigate to profile and change language
    const profilePage = new ProfilePage(page)
    await profilePage.navigate()
    await profilePage.changeLanguage('en')

    // Verify navigation changed to English
    await expectNavigationInLanguage(page, 'en')
  })

  test('should persist language after page refresh', async ({ page }) => {
    // Reload to ensure German is active
    await page.reload()

    // Navigate to profile and change to English
    const profilePage = new ProfilePage(page)
    await profilePage.navigate()
    await profilePage.changeLanguage('en')

    // Verify English is active
    await expectNavigationInLanguage(page, 'en')

    // Refresh page
    await page.reload()
    await page.waitForSelector('[data-testid="nav-members"]', { timeout: 10000 })

    // Verify language persisted (still English after refresh)
    await expectNavigationInLanguage(page, 'en')
  })

  test('should switch back to German', async ({ page }) => {
    // Reload to ensure German is active
    await page.reload()

    const profilePage = new ProfilePage(page)
    await profilePage.navigate()

    // Switch to English first
    await profilePage.changeLanguage('en')
    await expectNavigationInLanguage(page, 'en')

    // Switch back to German
    await profilePage.changeLanguage('de')
    await expectNavigationInLanguage(page, 'de')
  })
})
