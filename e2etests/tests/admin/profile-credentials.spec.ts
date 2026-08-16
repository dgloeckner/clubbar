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

import { Playwright } from '@playwright/test'
import { test, expect } from '../../fixtures/pageObjects'
import { TEST_CREDENTIALS } from '../../config/test-credentials'
import { generateTotp } from '../../utils/totp'
import { loginAs } from '../../utils/csrf'
import { ProfilePage } from '../../pages/ProfilePage'

const API_BASE = 'http://localhost:8080/api'

function uniqueEmail(prefix: string): string {
  return `${prefix}-${Date.now()}-${Math.round(Math.random() * 1e6)}@test.example`
}

/**
 * Mint an unenrolled admin through an isolated API context, so the browser
 * context under test stays logged out and must drive the real login form.
 */
async function createAdmin(
  playwright: Playwright,
  prefix: string
): Promise<{ email: string; password: string }> {
  const ctx = await loginAs(playwright, TEST_CREDENTIALS.admin.email, TEST_CREDENTIALS.admin.password)
  try {
    const email = uniqueEmail(prefix)
    const response = await ctx.post(`${API_BASE}/admin/admin-users`, {
      data: { email, display_name: `Credential Test ${prefix}`, locale: 'de' },
    })
    if (response.status() !== 201) {
      throw new Error(`Failed to create admin (${response.status()}): ${await response.text()}`)
    }
    return { email, password: (await response.json()).password }
  } finally {
    await ctx.dispose()
  }
}

test.describe('Profile credential changes (UI)', () => {
  // Start logged out: these tests sign in as their own admin, not the shared one.
  test.use({ storageState: { cookies: [], origins: [] } })

  /**
   * Log in as a fresh admin and complete the mandatory enrollment gate,
   * returning the secret the setup screen displayed — the only place a test
   * can obtain it, since the server never reveals it again.
   */
  async function signInAndEnroll(
    loginPage: { navigate: () => Promise<void>; login: (e: string, p: string) => Promise<void>; totpQrCode: () => { waitFor: (o?: unknown) => Promise<void> }; getTotpSetupSecret: () => Promise<string>; submitTotpSetupCode: (c: string) => Promise<void> },
    page: { waitForURL: (u: string, o?: unknown) => Promise<void> },
    email: string,
    password: string
  ): Promise<string> {
    await loginPage.navigate()
    await loginPage.login(email, password)
    const secret = await loginPage.getTotpSetupSecret()
    await loginPage.submitTotpSetupCode(generateTotp(secret))
    await page.waitForURL('**/dashboard', { timeout: 10000 })
    return secret
  }

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
    await profilePage.fillTotpCode(generateTotp(secret))

    // The request itself, not just the form's own validation — that distinction
    // is what this file exists for.
    expect(await profilePage.submitPasswordChange()).toBe(200)
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
    await profilePage.fillTotpCode('000000')

    expect(await profilePage.submitPasswordChange()).toBe(401)
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
})
