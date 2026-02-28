/**
 * Profile Page E2E Tests
 *
 * Patterns: 006 (Page Object Model), 007 (Fixtures), 008 (Assertions)
 */

import { test, expect } from '../../fixtures/pageObjects'

test.describe('Profile Page', () => {
  test('should display all profile sections', async ({ authenticatedProfilePage }) => {
    await authenticatedProfilePage.expectPageVisible()
    await authenticatedProfilePage.expectSectionsVisible()
  })

  test('should load current profile data with valid email and name', async ({ authenticatedProfilePage }) => {
    const email = await authenticatedProfilePage.getEmailValue()
    expect(email).toContain('@')
    expect(email.length).toBeGreaterThan(3)

    const name = await authenticatedProfilePage.getDisplayNameValue()
    expect(name.length).toBeGreaterThan(0)
  })

  test('should update display name and persist via API', async ({ authenticatedProfilePage }) => {
    const originalName = await authenticatedProfilePage.getDisplayNameValue()
    const newName = `TestAdmin_${Date.now()}`

    await authenticatedProfilePage.setDisplayName(newName)
    await authenticatedProfilePage.saveProfile()
    // Verify API persisted the change (PATCH succeeded, success shown)
    await authenticatedProfilePage.expectSuccessVisible()

    // Revert to original name
    await authenticatedProfilePage.setDisplayName(originalName)
    await authenticatedProfilePage.saveProfile()
  })

  test('should change language to English and back via API', async ({ authenticatedProfilePage }) => {
    // Change to English and verify success (API persisted the change)
    await authenticatedProfilePage.changeLanguage('en')
    await authenticatedProfilePage.expectSuccessVisible()

    // Revert to German
    await authenticatedProfilePage.changeLanguage('de')
    await authenticatedProfilePage.expectSuccessVisible()
  })

  test('should reject mismatched passwords with error', async ({ authenticatedProfilePage }) => {
    await authenticatedProfilePage.fillNewPassword('NewPassword123')
    await authenticatedProfilePage.fillConfirmPassword('DifferentPassword456')
    await authenticatedProfilePage.clickChangePassword()
    await authenticatedProfilePage.expectPasswordError('stimmen nicht überein')
  })

  test('should reject weak password', async ({ authenticatedProfilePage }) => {
    await authenticatedProfilePage.fillNewPassword('weak')
    await authenticatedProfilePage.fillConfirmPassword('weak')
    await authenticatedProfilePage.clickChangePassword()
    await authenticatedProfilePage.expectPasswordError()
  })

  test('should be accessible from header user badge', async ({ page, authenticatedProfilePage }) => {
    await page.goto('/members')
    await authenticatedProfilePage.expectUserBadgeVisible()
    await authenticatedProfilePage.navigateViaUserBadge()
  })
})
