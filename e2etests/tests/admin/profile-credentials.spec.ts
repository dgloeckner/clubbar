/**
 * Self-service credential changes, driven through the real UI.
 *
 * These live apart from profile.spec.ts because they need their own admin.
 * profile.spec.ts runs pre-authenticated as the shared seeded admin
 * (playwright/.auth/admin.json, one file for every worker — Pattern 002), and
 * rotating that account's password or email would break every spec running
 * beside it. Each test here mints an admin, enrolls it through the real
 * enrollment screen — which is also the only way to learn its TOTP secret —
 * and changes only that account's credentials.
 *
 * They also close the gap that let the change-password form ship broken: every
 * password test in profile.spec.ts asserts a *client-side* rejection, and all
 * of those return before any HTTP call is made. Nothing exercised the request
 * itself, so a frontend calling POST with `confirm_password` against a backend
 * serving PATCH with `new_password_confirmation` looked fine.
 *
 * E2E Patterns: 001 (isolation), 002 (auth isolation), 005 (test IDs),
 * 006 (page objects), 008 (assertions).
 */

import { test, expect } from '../../fixtures/pageObjects'
import { generateTotp } from '../../utils/totp'
import { loginAs } from '../../utils/csrf'
import { createIsolatedAdmin as createAdmin, signInAndEnroll, uniqueTestEmail as uniqueEmail } from '../../utils/isolatedAdmin'
import { ProfilePage } from '../../pages/ProfilePage'

const API_BASE = 'http://localhost:8080/api'

test.describe('Profile credential changes (UI)', () => {
  // Start logged out: these tests sign in as their own admin, not the shared one.
  test.use({ storageState: { cookies: [], origins: [] } })

  test('changes the password through the form and the new password authenticates', async ({
    loginPage,
    page,
    playwright,
  }) => {
    // Enrollment plus a login with the new password can each wait out a TOTP
    // window on a replay collision (#338).
    test.setTimeout(360_000)
    const { email, password } = await createAdmin(playwright, 'ui-pw')
    const secret = await signInAndEnroll(loginPage, page, email, password)
    const newPassword = 'UiRotated1234'

    const profilePage = new ProfilePage(page)
    await profilePage.navigate()
    await profilePage.expectTotpCodeFieldVisible()

    await profilePage.fillCurrentPassword(password)
    await profilePage.fillNewPassword(newPassword)
    await profilePage.fillConfirmPassword(newPassword)
    // The request itself, not just the form's own validation — that distinction
    // is what this file exists for. The code goes in through submitPasswordChange
    // because typing its sixth digit is what sends the request.
    expect(await profilePage.submitPasswordChange(generateTotp(secret))).toBe(200)
    await profilePage.expectPasswordSuccess()

    // The change is only proven by the credential it produced.
    const reauthed = await loginAs(playwright, email, newPassword, secret)
    expect((await reauthed.get(`${API_BASE}/auth/profile`)).status()).toBe(200)
    await reauthed.dispose()
  })

  test('rejects a password change carrying a wrong 2FA code', async ({
    loginPage,
    page,
    playwright,
  }) => {
    test.setTimeout(360_000)
    const { email, password } = await createAdmin(playwright, 'ui-pw-bad')
    await signInAndEnroll(loginPage, page, email, password)

    const profilePage = new ProfilePage(page)
    await profilePage.navigate()
    await profilePage.fillCurrentPassword(password)
    await profilePage.fillNewPassword('UiRotated1234')
    await profilePage.fillConfirmPassword('UiRotated1234')

    expect(await profilePage.submitPasswordChange('000000')).toBe(401)
    await profilePage.expectPasswordError()
  })

  test('changes the email only after a step-up, and the new address becomes the login', async ({
    loginPage,
    page,
    playwright,
  }) => {
    test.setTimeout(360_000)
    const { email, password } = await createAdmin(playwright, 'ui-email')
    const secret = await signInAndEnroll(loginPage, page, email, password)
    const newEmail = uniqueEmail('ui-email-moved')

    const profilePage = new ProfilePage(page)
    await profilePage.navigate()
    await profilePage.setEmail(newEmail)
    await profilePage.saveProfileExpectingStepUp()

    // Saving must not have written anything yet — the dialog is the gate.
    await profilePage.expectStepUpDialogVisible()

    expect(await profilePage.confirmEmailStepUp(password, generateTotp(secret))).toBe(200)
    await profilePage.expectSuccessVisible()

    // The identifier actually moved: the new address authenticates.
    const reauthed = await loginAs(playwright, newEmail, password, secret)
    expect((await reauthed.get(`${API_BASE}/auth/profile`)).status()).toBe(200)
    await reauthed.dispose()
  })

  test('keeps the email dialog open when the step-up credential is wrong', async ({
    loginPage,
    page,
    playwright,
  }) => {
    test.setTimeout(360_000)
    const { email, password } = await createAdmin(playwright, 'ui-email-bad')
    await signInAndEnroll(loginPage, page, email, password)

    const profilePage = new ProfilePage(page)
    await profilePage.navigate()
    await profilePage.setEmail(uniqueEmail('ui-email-nope'))
    await profilePage.saveProfileExpectingStepUp()

    expect(await profilePage.confirmEmailStepUp('WrongPassword123', '000000')).toBe(401)
    await profilePage.expectStepUpError()
    await profilePage.expectStepUpDialogVisible()
  })

  /**
   * The step-up dialog asks for two things, and the code is only one of them.
   * Completing it must not send the form on its own — nor may filling in the
   * password afterwards set it off, which would submit against a half-typed
   * password and spend the code on a 401.
   */
  test('sends nothing when the step-up code completes with no password', async ({
    loginPage,
    page,
    playwright,
  }) => {
    test.setTimeout(360_000)
    const { email, password } = await createAdmin(playwright, 'ui-email-nopw')
    const secret = await signInAndEnroll(loginPage, page, email, password)

    const profilePage = new ProfilePage(page)
    await profilePage.navigate()
    await profilePage.setEmail(uniqueEmail('ui-email-nopw-new'))
    await profilePage.saveProfileExpectingStepUp()

    let patches = 0
    page.on('request', (req) => {
      if (req.url().includes('/api/auth/profile') && req.method() === 'PATCH') patches++
    })

    await profilePage.fillStepUpTotpCode(generateTotp(secret))
    // Nothing to await — the assertion is that no request happens, so give the
    // page a beat in which one could have.
    await page.waitForTimeout(1000)

    expect(patches).toBe(0)
    await profilePage.expectStepUpDialogVisible()

    // The code was never spent, so the dialog still confirms with it — and now
    // that the password is there, the sixth digit is enough on its own. No
    // click: this is the step-up half of the auto-submit behaviour.
    expect(await profilePage.confirmEmailStepUpByTyping(password, generateTotp(secret))).toBe(200)
    await profilePage.expectSuccessVisible()
  })

  /**
   * The regression guard, at the UI level: `handleLanguageChange` PATCHes the
   * profile on every toggle. If the step-up ever stops being conditional on the
   * email moving, switching language starts demanding a password.
   */
  test('switches language with no credential prompt', async ({
    loginPage,
    page,
    playwright,
  }) => {
    test.setTimeout(360_000)
    const { email, password } = await createAdmin(playwright, 'ui-locale')
    await signInAndEnroll(loginPage, page, email, password)

    const profilePage = new ProfilePage(page)
    await profilePage.navigate()
    await profilePage.changeLanguage('en')

    await profilePage.expectSuccessVisible()
    await profilePage.expectStepUpDialogHidden()
  })

  /**
   * #104: a success toast only proves the PATCH resolved, not that the value
   * survived server-side rather than being rendered optimistically. Reloads
   * from a fresh navigation to confirm the server actually has it — on this
   * test's own isolated admin, never the shared one (see file comment).
   */
  test('language change persists across a reload', async ({
    loginPage,
    page,
    playwright,
  }) => {
    test.setTimeout(360_000)
    const { email, password } = await createAdmin(playwright, 'ui-locale-persist')
    await signInAndEnroll(loginPage, page, email, password)

    const profilePage = new ProfilePage(page)
    await profilePage.navigate()
    await profilePage.changeLanguage('en')
    await profilePage.expectSuccessVisible()

    await profilePage.navigate()
    await profilePage.expectLanguageSelected('en')
  })

  /**
   * #134: a saved `display_name` used to reach only localStorage. AuthContext's
   * state — what the header actually renders from — was populated at
   * login/mount and never touched again, so a rename stayed invisible in the
   * header until a full reload. This asserts the header updates immediately,
   * with no navigation in between.
   */
  test('renaming the display name updates the header immediately, without a reload', async ({
    loginPage,
    page,
    playwright,
  }) => {
    test.setTimeout(360_000)
    const { email, password } = await createAdmin(playwright, 'ui-rename')
    await signInAndEnroll(loginPage, page, email, password)

    const profilePage = new ProfilePage(page)
    await profilePage.navigate()

    const newName = `Renamed_${Date.now()}`
    await profilePage.setDisplayName(newName)
    await profilePage.saveProfile()
    await profilePage.expectSuccessVisible()

    await expect(page.locator('[data-testid="header-user-badge"]')).toContainText(newName)
  })

  /**
   * #134: i18n persisted the language under its own `adminLocale` localStorage
   * key, separate from the `locale` key session.ts uses for the rest of the
   * admin's identity — and only the latter was cleared on logout. The two
   * could diverge permanently. Fixed by consolidating on a single key; this
   * asserts no second key reappears and the shared one holds the new value.
   */
  test('changing language leaves a single, consistent locale key in storage', async ({
    loginPage,
    page,
    playwright,
  }) => {
    test.setTimeout(360_000)
    const { email, password } = await createAdmin(playwright, 'ui-locale-key')
    await signInAndEnroll(loginPage, page, email, password)

    const profilePage = new ProfilePage(page)
    await profilePage.navigate()
    await profilePage.changeLanguage('en')
    await profilePage.expectSuccessVisible()

    const keys = await page.evaluate(() => Object.keys(localStorage))
    expect(keys).not.toContain('adminLocale')
    expect(await page.evaluate(() => localStorage.getItem('locale'))).toBe('en')
  })
})
