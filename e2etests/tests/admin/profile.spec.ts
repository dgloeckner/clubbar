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

    // #104: a success toast only proves the PATCH request resolved, not that
    // the value survived server-side rather than being rendered optimistically.
    // Reload from a fresh navigation and confirm the server actually has it.
    await authenticatedProfilePage.navigate()
    expect(await authenticatedProfilePage.getDisplayNameValue()).toBe(newName)

    // Revert to original name
    await authenticatedProfilePage.setDisplayName(originalName)
    await authenticatedProfilePage.saveProfile()
    await authenticatedProfilePage.navigate()
    expect(await authenticatedProfilePage.getDisplayNameValue()).toBe(originalName)
  })

  // Language-switch coverage lives in profile-credentials.spec.ts instead of
  // here: changing the shared seeded admin's locale (playwright/.auth/admin.json
  // — Pattern 002) is server-side and read back by every other test's session
  // bootstrap (auth/session.ts calls changeLanguage(admin.locale) on every
  // verify), so a transient 'en' here was observable — and asserted against as
  // a bug — by unrelated German-text assertions running in parallel. The
  // credentials spec already mints its own isolated admin for exactly this
  // reason (see its file comment).

  test('should reject mismatched passwords with error', async ({ authenticatedProfilePage }) => {
    await authenticatedProfilePage.fillCurrentPassword('AnyValue1!')
    await authenticatedProfilePage.fillNewPassword('NewPassword123')
    await authenticatedProfilePage.fillConfirmPassword('DifferentPassword456')
    await authenticatedProfilePage.clickChangePassword()
    await authenticatedProfilePage.expectPasswordError('stimmen nicht überein')
  })

  test('should reject weak password', async ({ authenticatedProfilePage }) => {
    await authenticatedProfilePage.fillCurrentPassword('AnyValue1!')
    await authenticatedProfilePage.fillNewPassword('weak')
    await authenticatedProfilePage.fillConfirmPassword('weak')
    await authenticatedProfilePage.clickChangePassword()
    await authenticatedProfilePage.expectPasswordError()
  })

  // #135: a password long enough but missing an uppercase/digit must report
  // the actual requirement, not "Minimum 8 characters required" again.
  test('should reject password missing complexity with a dedicated message', async ({ authenticatedProfilePage }) => {
    await authenticatedProfilePage.fillCurrentPassword('AnyValue1!')
    await authenticatedProfilePage.fillNewPassword('alllowercase')
    await authenticatedProfilePage.fillConfirmPassword('alllowercase')
    await authenticatedProfilePage.clickChangePassword()
    await authenticatedProfilePage.expectPasswordError('Großbuchstaben')
  })

  test('should be accessible from header user badge', async ({ page, authenticatedProfilePage }) => {
    await page.goto('/members')
    await authenticatedProfilePage.expectUserBadgeVisible()
    await authenticatedProfilePage.navigateViaUserBadge()
  })
})
