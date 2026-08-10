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

  /**
   * The Save button PATCHes the whole form (email + display_name + locale)
   * from whatever is currently in React state (ProfilePage.tsx
   * handleSaveProfile), not just the field that changed. Both tests below
   * exercise that same shared admin-chromium session (playwright/.auth/admin.json
   * is one file for every worker — Pattern 002). Running them truly in
   * parallel lets one test's full-form save stomp the other's field with a
   * stale snapshot it loaded before the other test's change landed. Serial
   * mode keeps them from interleaving; nothing else in the suite calls the
   * Save button on this shared admin (other specs PATCH single fields
   * directly via the API).
   */
  test.describe.configure({ mode: 'serial' })

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

  test('should change language to English and back via API', async ({ authenticatedProfilePage }) => {
    // Change to English and verify success (API persisted the change)
    await authenticatedProfilePage.changeLanguage('en')
    await authenticatedProfilePage.expectSuccessVisible()

    // #104: confirm the locale was actually written server-side by reloading
    // from scratch, not just trusting the optimistically-updated trigger label.
    await authenticatedProfilePage.navigate()
    await authenticatedProfilePage.expectLanguageSelected('en')

    // Revert to German
    await authenticatedProfilePage.changeLanguage('de')
    await authenticatedProfilePage.expectSuccessVisible()
    await authenticatedProfilePage.navigate()
    await authenticatedProfilePage.expectLanguageSelected('de')
  })

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

  test('should be accessible from header user badge', async ({ page, authenticatedProfilePage }) => {
    await page.goto('/members')
    await authenticatedProfilePage.expectUserBadgeVisible()
    await authenticatedProfilePage.navigateViaUserBadge()
  })
})
