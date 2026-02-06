/**
 * Profile Page E2E Tests
 *
 * Tests for admin user profile management:
 * - View profile information
 * - Update profile (email, display name, language)
 * - Change password
 */

import { test, expect } from '../../fixtures/pageObjects'

test.describe('Profile Page', () => {
  test.beforeEach(async ({ page }) => {
    await page.goto('/profile')
    await page.getByTestId('profile-page').waitFor({ state: 'visible', timeout: 10000 })
  })

  test.describe('Page Structure', () => {
    test('should display profile page', async ({ page }) => {
      await expect(page.getByTestId('profile-page')).toBeVisible()
    })

    test('should display profile section', async ({ page }) => {
      await expect(page.getByTestId('profile-section')).toBeVisible()
    })

    test('should display password section', async ({ page }) => {
      await expect(page.getByTestId('password-section')).toBeVisible()
    })

    test('should display page title', async ({ page }) => {
      await expect(page.getByRole('heading', { name: 'Mein Profil' })).toBeVisible()
    })
  })

  test.describe('Profile Form', () => {
    test('should display email field', async ({ page }) => {
      await expect(page.getByTestId('profile-email')).toBeVisible()
    })

    test('should display display name field', async ({ page }) => {
      await expect(page.getByTestId('profile-display-name')).toBeVisible()
    })

    test('should display language selector', async ({ page }) => {
      await expect(page.getByTestId('profile-locale-trigger')).toBeVisible()
    })

    test('should display save button', async ({ page }) => {
      await expect(page.getByTestId('profile-save-button')).toBeVisible()
    })

    test('should load current profile data', async ({ page }) => {
      // Email should be populated
      const emailInput = page.getByTestId('profile-email')
      const emailValue = await emailInput.inputValue()
      expect(emailValue).toBeTruthy()
      expect(emailValue).toContain('@')

      // Display name should be populated
      const nameInput = page.getByTestId('profile-display-name')
      const nameValue = await nameInput.inputValue()
      expect(nameValue).toBeTruthy()
    })
  })

  test.describe('Profile Update', () => {
    test('should update display name', async ({ page }) => {
      const nameInput = page.getByTestId('profile-display-name')
      const originalName = await nameInput.inputValue()

      // Change display name
      const newName = `Test Admin ${Date.now()}`
      await nameInput.clear()
      await nameInput.fill(newName)

      // Save
      const responsePromise = page.waitForResponse((resp) => resp.url().includes('/auth/profile') && resp.request().method() === 'PATCH')
      await page.getByTestId('profile-save-button').click()
      await responsePromise

      // Should show success
      await expect(page.getByTestId('profile-success')).toBeVisible()

      // Revert to original name
      await nameInput.clear()
      await nameInput.fill(originalName)
      const revertPromise = page.waitForResponse((resp) => resp.url().includes('/auth/profile') && resp.request().method() === 'PATCH')
      await page.getByTestId('profile-save-button').click()
      await revertPromise
    })

    test('should change language via dropdown', async ({ page }) => {
      // Click language dropdown
      await page.getByTestId('profile-locale-trigger').click()

      // Should show dropdown options
      await expect(page.getByTestId('profile-locale-dropdown')).toBeVisible()

      // Select English
      const responsePromise = page.waitForResponse((resp) => resp.url().includes('/auth/profile') && resp.request().method() === 'PATCH')
      await page.getByTestId('profile-locale-option-en').click()
      await page.getByTestId('profile-save-button').click()
      await responsePromise

      // Should show success
      await expect(page.getByTestId('profile-success')).toBeVisible()

      // Revert to German
      await page.getByTestId('profile-locale-trigger').click()
      await page.getByTestId('profile-locale-option-de').click()
      const revertPromise = page.waitForResponse((resp) => resp.url().includes('/auth/profile') && resp.request().method() === 'PATCH')
      await page.getByTestId('profile-save-button').click()
      await revertPromise
    })
  })

  test.describe('Password Form', () => {
    test('should display new password field', async ({ page }) => {
      await expect(page.getByTestId('password-new')).toBeVisible()
    })

    test('should display confirm password field', async ({ page }) => {
      await expect(page.getByTestId('password-confirm')).toBeVisible()
    })

    test('should display change password button', async ({ page }) => {
      await expect(page.getByTestId('password-change-button')).toBeVisible()
    })

    test('should show error for mismatched passwords', async ({ page }) => {
      await page.getByTestId('password-new').fill('NewPassword123')
      await page.getByTestId('password-confirm').fill('DifferentPassword123')
      await page.getByTestId('password-change-button').click()

      await expect(page.getByTestId('password-error')).toBeVisible()
      await expect(page.getByTestId('password-error')).toContainText('stimmen nicht überein')
    })

    test('should show error for weak password', async ({ page }) => {
      await page.getByTestId('password-new').fill('weak')
      await page.getByTestId('password-confirm').fill('weak')
      await page.getByTestId('password-change-button').click()

      await expect(page.getByTestId('password-error')).toBeVisible()
    })
  })

  test.describe('Navigation', () => {
    test('should be accessible from header user badge', async ({ page }) => {
      // Go to another page first
      await page.goto('/members')
      await page.waitForLoadState('networkidle')

      // Click user badge
      const userBadge = page.getByTestId('header-user-badge')
      if (await userBadge.isVisible()) {
        await userBadge.click()
        await expect(page).toHaveURL(/\/profile/)
      }
    })
  })
})
