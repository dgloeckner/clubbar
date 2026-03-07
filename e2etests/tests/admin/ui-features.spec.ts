/**
 * Admin Frontend - Navigation & Auth E2E Tests
 *
 * Icon rendering is not an E2E concern.
 * Patterns: 006 (POM), 007 (Fixtures), 008 (Assertions)
 */

import { test, expect } from '../../fixtures/pageObjects'
import { MainLayoutPage } from '../../pages/MainLayoutPage'
import { MembersPage } from '../../pages/MembersPage'

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
    const count = parseInt(await membersPage.getMemberCount(), 10)
    expect(count).toBeGreaterThanOrEqual(1)
  })

  test('should display open balance with currency format', async ({ authenticatedMembersPage }) => {
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
