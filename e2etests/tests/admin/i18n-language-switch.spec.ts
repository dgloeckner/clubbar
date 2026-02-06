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
 * - Pattern 008: Playwright Assertions (expect API)
 */

import { test, expect } from '../../fixtures/auth.fixture'

const API_BASE = 'http://localhost:8080/api'

test.describe('i18n Language Switching', () => {
  test.beforeEach(async ({ authenticatedRequest }) => {
    // Reset admin's locale to German before each test
    await authenticatedRequest.patch(`${API_BASE}/admin/profile`, {
      data: { locale: 'de' },
    })
  })

  test('should display navigation in German by default', async ({ page }) => {
    // Navigate to members page (page has auth state from fixture)
    await page.goto('/members', { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('[data-testid="nav-members"]', { timeout: 10000 })

    // Check German navigation labels
    await expect(page.locator('[data-testid="nav-members"]')).toContainText('Mitglieder')
    await expect(page.locator('[data-testid="nav-products"]')).toContainText('Produkte')
    await expect(page.locator('[data-testid="nav-categories"]')).toContainText('Kategorien')
    await expect(page.locator('[data-testid="nav-journal"]')).toContainText('Buchungsjournal')
    await expect(page.locator('[data-testid="nav-settlements"]')).toContainText('Abrechnungen')
    await expect(page.locator('[data-testid="nav-statistics"]')).toContainText('Statistik')
    await expect(page.locator('[data-testid="nav-settings"]')).toContainText('Einstellungen')
    await expect(page.locator('[data-testid="nav-audit-log"]')).toContainText('Audit-Log')
  })

  test('should switch to English when language changed in profile', async ({ page }) => {
    // Go to profile page
    await page.goto('/profile', { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('[data-testid="profile-locale-trigger"]', { timeout: 10000 })

    // Open language selector dropdown
    await page.click('[data-testid="profile-locale-trigger"]')

    // Wait for dropdown to appear
    await page.waitForSelector('[data-testid="profile-locale-dropdown"]', { timeout: 5000 })

    // Wait for API response after language change
    const responsePromise = page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/profile') && resp.request().method() === 'PATCH'
    )

    // Select English
    await page.click('[data-testid="profile-locale-option-en"]')
    await responsePromise

    // Verify navigation changed to English
    await expect(page.locator('[data-testid="nav-members"]')).toContainText('Members')
    await expect(page.locator('[data-testid="nav-products"]')).toContainText('Products')
    await expect(page.locator('[data-testid="nav-categories"]')).toContainText('Categories')
    await expect(page.locator('[data-testid="nav-journal"]')).toContainText('Journal')
    await expect(page.locator('[data-testid="nav-settlements"]')).toContainText('Settlements')
    await expect(page.locator('[data-testid="nav-statistics"]')).toContainText('Statistics')
    await expect(page.locator('[data-testid="nav-settings"]')).toContainText('Settings')
    await expect(page.locator('[data-testid="nav-audit-log"]')).toContainText('Audit Log')
  })

  test('should persist language after page refresh', async ({ page }) => {
    // Go to profile and switch to English
    await page.goto('/profile', { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('[data-testid="profile-locale-trigger"]', { timeout: 10000 })

    // Open dropdown and select English
    await page.click('[data-testid="profile-locale-trigger"]')
    await page.waitForSelector('[data-testid="profile-locale-dropdown"]', { timeout: 5000 })

    const responsePromise = page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/profile') && resp.request().method() === 'PATCH'
    )
    await page.click('[data-testid="profile-locale-option-en"]')
    await responsePromise

    // Verify English is active
    await expect(page.locator('[data-testid="nav-members"]')).toContainText('Members')

    // Refresh page
    await page.reload()

    // Wait for page to load
    await page.waitForSelector('[data-testid="nav-members"]', { timeout: 10000 })

    // Verify language persisted (still English after refresh)
    await expect(page.locator('[data-testid="nav-members"]')).toContainText('Members')
    await expect(page.locator('[data-testid="nav-products"]')).toContainText('Products')
  })

  test('should switch back to German', async ({ page }) => {
    // Go to profile
    await page.goto('/profile', { waitUntil: 'domcontentloaded' })
    await page.waitForSelector('[data-testid="profile-locale-trigger"]', { timeout: 10000 })

    // Switch to English first
    await page.click('[data-testid="profile-locale-trigger"]')
    await page.waitForSelector('[data-testid="profile-locale-dropdown"]', { timeout: 5000 })

    let responsePromise = page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/profile') && resp.request().method() === 'PATCH'
    )
    await page.click('[data-testid="profile-locale-option-en"]')
    await responsePromise

    // Verify English
    await expect(page.locator('[data-testid="nav-members"]')).toContainText('Members')

    // Now switch back to German
    await page.click('[data-testid="profile-locale-trigger"]')
    await page.waitForSelector('[data-testid="profile-locale-dropdown"]', { timeout: 5000 })

    responsePromise = page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/profile') && resp.request().method() === 'PATCH'
    )
    await page.click('[data-testid="profile-locale-option-de"]')
    await responsePromise

    // Verify German
    await expect(page.locator('[data-testid="nav-members"]')).toContainText('Mitglieder')
    await expect(page.locator('[data-testid="nav-products"]')).toContainText('Produkte')
  })
})
