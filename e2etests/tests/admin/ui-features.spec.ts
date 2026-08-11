/**
 * Admin Frontend - Navigation & Auth E2E Tests
 *
 * Icon rendering is not an E2E concern.
 * Patterns: 006 (POM), 007 (Fixtures), 008 (Assertions)
 */

import { test, expect } from '../../fixtures/pageObjects'
import { MainLayoutPage } from '../../pages/MainLayoutPage'
import { MembersPage } from '../../pages/MembersPage'
import { generateTotp } from '../../utils/totp'
import { TEST_CREDENTIALS } from '../../config/test-credentials'

test.describe('Navigation', () => {
  test('should navigate to products page via nav tab', async ({ authenticatedMembersPage, page }) => {
    await authenticatedMembersPage.expectPageVisible()
    const layout = new MainLayoutPage(page)
    await layout.clickProducts()
    await expect(page).toHaveURL(/\/products/)
  })

  test('should display all navigation tabs', async ({ authenticatedMembersPage, page }) => {
    const layout = new MainLayoutPage(page)
    await layout.expectHeaderVisible()
  })
})

test.describe('User Badge & Logout', () => {
  test('should display user badge with admin name', async ({ authenticatedMembersPage, page }) => {
    const layout = new MainLayoutPage(page)
    await layout.expectUserBadgeContainsText('Admin')
  })

  test('should perform logout and redirect to login', async ({ page }) => {
    // Retries against a same-window replay collision on the shared admin secret (#338).
    test.setTimeout(240_000)

    // Navigate first so localStorage is accessible, then clear auth state
    await page.goto('/dashboard')
    await page.waitForURL('**/dashboard', { timeout: 10000 })
    await page.evaluate(() => localStorage.clear())
    await page.context().clearCookies()

    await page.goto('/login')
    await page.waitForURL('**/login', { timeout: 5000 })
    await page.locator('[data-testid="login-email-input"]').fill('admin@example.com')
    await page.locator('[data-testid="login-password-input"]').fill('password123')
    await page.locator('[data-testid="login-submit-button"]').click()

    // MFA step: login now requires TOTP verification. A code that collides with
    // another login's same 30-second window is correctly refused as a replay
    // (#338) even though it isn't a real attack — retry with a fresh one. Waits
    // past the next window boundary plus random jitter so that several callers
    // who collided in the same window don't retry into the next one together.
    const MAX_MFA_ATTEMPTS = 4
    await expect(page.locator('[data-testid="mfa-code-input"]')).toBeVisible({ timeout: 5000 })
    for (let attempt = 1; attempt <= MAX_MFA_ATTEMPTS; attempt++) {
      await page.locator('[data-testid="mfa-code-input"]').fill(generateTotp(TEST_CREDENTIALS.totp.adminSecret))
      await page.locator('[data-testid="mfa-submit-button"]').click()

      const outcome = await Promise.race([
        page.waitForURL('**/dashboard', { timeout: 5000 }).then(() => 'success' as const),
        page.locator('[data-testid="mfa-error"]').waitFor({ state: 'visible', timeout: 5000 }).then(() => 'rejected' as const),
      ]).catch(() => 'timeout' as const)

      if (outcome === 'success' || attempt === MAX_MFA_ATTEMPTS) break
      const msUntilNextStep = 30_000 - (Date.now() % 30_000)
      const jitter = Math.floor(Math.random() * 30_000)
      await page.waitForTimeout(msUntilNextStep + jitter + 250)
    }

    await page.waitForURL('**/dashboard', { timeout: 10000 })

    const layout = new MainLayoutPage(page)
    await layout.clickLogout()

    await page.waitForURL('**/login', { timeout: 5000 })
    await expect(page).toHaveURL(/\/login/)
  })
})

test.describe('Dashboard Statistics', () => {
  test('should display member count >= 1 after dashboard loads', async ({ page }) => {
    const dashboardResp = page.waitForResponse(
      (r) => r.url().includes('/api/admin/dashboard') && r.status() === 200,
      { timeout: 10000 }
    )
    await page.goto('/members')
    await dashboardResp

    const membersPage = new MembersPage(page)
    // The response arriving is not the card being rendered; until it is, the
    // card reads "—" and getMemberCount() reports 0 (see #132).
    await membersPage.waitForStatsToLoad()

    const count = parseInt(await membersPage.getMemberCount(), 10)
    expect(count).toBeGreaterThanOrEqual(1)
  })

  test('should display open balance with currency format', async ({ authenticatedMembersPage }) => {
    // Read it only once the metrics response has landed. Before #132 the cards
    // started at a formatted 0,00 €, so this assertion passed against a card
    // that had never been given a number — the very confusion #132 is about.
    await authenticatedMembersPage.waitForStatsToLoad()

    const balance = await authenticatedMembersPage.getOpenBalance()
    expect(balance).toMatch(/[\d.,€]/)
  })
})

test.describe('Responsive Layout', () => {
  test('should show bottom tab bar on mobile viewport', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 667 })
    await page.goto('/members')
    // Desktop nav should be hidden on mobile
    await expect(page.getByTestId('desktop-nav')).toBeHidden()
    // Bottom tab bar should be visible instead
    await expect(page.getByTestId('bottom-tab-bar')).toBeVisible()
  })
})
