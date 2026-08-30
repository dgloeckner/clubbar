/**
 * Login Page (UI) — drives the real login form through the browser.
 *
 * Unlike tests/api/admin-auth.spec.ts and tests/api/totp-2fa.spec.ts (which verify the
 * backend contract via raw HTTP calls), these tests exercise the actual frontend code
 * path: admin-frontend/src/auth/session.ts's loginWithSession/submitMfaWithSession/
 * setupTotpWithSession/confirmTotpWithSession, invoked through AuthContext and the
 * LoginPage/LoginForm components. All other admin-chromium tests run pre-authenticated
 * via storageState, so this is the only place the login form itself gets exercised.
 *
 * E2E Patterns applied:
 *  - Pattern 001: Unique test data per test (timestamped email for the enrollment test)
 *  - Pattern 002: Authentication isolation — a separate API request context creates the
 *    unenrolled admin so the page under test stays logged out
 *  - Pattern 008: Playwright assertions (expect().toBeVisible()), no visibility helpers
 */

import type { PlaywrightWorkerArgs } from '@playwright/test'
import { test, expect } from '../../fixtures/pageObjects'
import { TEST_CREDENTIALS } from '../../config/test-credentials'
import { generateTotp } from '../../utils/totp'
import { loginAs } from '../../utils/csrf'
import { stepUp } from '../../fixtures/stepUp'
import { tokenFromInvitationUrl, INVITED_ADMIN_PASSWORD } from '../../utils/adminInvitation'

const API_BASE = 'http://localhost:8080/api'

function uniqueEmail(prefix: string): string {
  return `${prefix}.${Date.now()}@test.example`
}

/**
 * Create a fresh, unenrolled admin user for the TOTP-enrollment test.
 * Logs in as the seeded admin through an isolated API request context (via
 * loginAs, which handles its pre-enrolled TOTP) so the browser context under
 * test never gains a session — it must still show the real login form.
 */
async function createUnenrolledAdmin(
  playwright: PlaywrightWorkerArgs['playwright'],
  emailPrefix: string
): Promise<{ email: string; password: string }> {
  const ctx = await loginAs(playwright, TEST_CREDENTIALS.admin.email, TEST_CREDENTIALS.admin.password)
  try {
    const email = uniqueEmail(emailPrefix)
    const createResp = await ctx.post(`${API_BASE}/admin/admin-users`, {
      data: { ...stepUp(), email, display_name: `Login Test ${emailPrefix}`, locale: 'en' },
    })
    if (createResp.status() !== 201) {
      throw new Error(`Failed to create admin user (${createResp.status()}): ${await createResp.text()}`)
    }
    // Walking the invitation is what gives the account a password
    // (migration 058); the account is created with none.
    const { invitation } = await createResp.json()
    const accepted = await ctx.post(
      `${API_BASE}/invitations/${tokenFromInvitationUrl(invitation.url)}/accept`,
      { data: { password: INVITED_ADMIN_PASSWORD, password_confirmation: INVITED_ADMIN_PASSWORD } },
    )
    if (accepted.status() !== 200) {
      throw new Error(
        `Failed to accept the invitation (${accepted.status()}): ${await accepted.text()}`,
      )
    }

    return { email, password: INVITED_ADMIN_PASSWORD }
  } finally {
    await ctx.dispose()
  }
}

test.describe('Login (UI)', () => {
  // Override the project's pre-authenticated storageState — these tests start logged out.
  test.use({ storageState: { cookies: [], origins: [] } })

  test('logs in a TOTP-enrolled admin via the MFA step', async ({ loginPage, page }) => {
    // Retries against a same-window replay collision on the shared admin secret (#338).
    test.setTimeout(360_000)
    await loginPage.navigate()
    await loginPage.login(TEST_CREDENTIALS.admin.email, TEST_CREDENTIALS.admin.password)

    // Backend's login() branch 1: TOTP-enrolled → requiresMfa, no session yet
    await expect(loginPage.mfaCodeInput()).toBeVisible()

    await loginPage.submitMfaCodeWithRetry(TEST_CREDENTIALS.totp.adminSecret)

    await page.waitForURL('**/dashboard', { timeout: 10000 })
    await expect(page.getByTestId('dashboard-page')).toBeVisible()

    const adminId = await page.evaluate(() => localStorage.getItem('admin_id'))
    expect(adminId).toBeTruthy()
  })

  test('rejects an invalid MFA code without granting access', async ({ loginPage }) => {
    await loginPage.navigate()
    await loginPage.login(TEST_CREDENTIALS.admin.email, TEST_CREDENTIALS.admin.password)
    await expect(loginPage.mfaCodeInput()).toBeVisible()

    await loginPage.submitMfaCode('000000')

    await expect(loginPage.mfaError()).toBeVisible()
    // Still on the MFA step, not redirected — a correct code can still be retried
    await expect(loginPage.mfaCodeInput()).toBeVisible()
  })

  test('completes first-time TOTP enrollment and reaches the dashboard', async ({ loginPage, page, playwright }) => {
    // createUnenrolledAdmin logs in as the shared seeded admin, which can retry
    // against a same-window replay collision (#338).
    test.setTimeout(360_000)
    const { email, password } = await createUnenrolledAdmin(playwright, 'login-setup')

    await loginPage.navigate()
    await loginPage.login(email, password)

    // Backend's login() branch 2: not enrolled → requiresTotpSetup, provisional session
    await expect(loginPage.totpQrCode()).toBeVisible()
    await expect(loginPage.totpSetupCodeInput()).toBeVisible()

    // The backup key is the account's only recovery path — there are no
    // recovery codes. Assert it is actually *visible* and copyable: reading it
    // with textContent() alone (as this test used to) also passes on an
    // element hidden by CSS, which is how #386 went unnoticed.
    await expect(loginPage.totpSetupBackupKey()).toBeVisible()
    await expect(loginPage.totpSetupSecret()).toBeVisible()
    await expect(loginPage.totpSetupBackupKeyCopyButton()).toBeVisible()

    const secret = await loginPage.getTotpSetupSecret()
    expect(secret.length).toBeGreaterThan(0)

    const code = generateTotp(secret)
    await loginPage.submitTotpSetupCode(code)

    await page.waitForURL('**/dashboard', { timeout: 10000 })
    await expect(page.getByTestId('dashboard-page')).toBeVisible()

    const adminId = await page.evaluate(() => localStorage.getItem('admin_id'))
    expect(adminId).toBeTruthy()
  })

  test('shows an error for invalid credentials', async ({ loginPage, page }) => {
    await loginPage.navigate()
    await loginPage.login('nobody@example.com', 'wrong-password')

    await expect(loginPage.errorMessage()).toBeVisible()
    expect(page.url()).toContain('/login')
  })

  // Pins #133: the error banner used to read `error` off the AuthContext render
  // that existed *before* `await login(...)` resolved, so the very first failed
  // attempt always fell back to the client's generic "Login failed" — the
  // backend's actual "Invalid credentials" only surfaced one attempt later,
  // describing the *previous* submission. A single attempt is enough to prove
  // the fix: the backend's message must appear immediately, not delayed.
  test('shows the backend error message on the very first failed attempt, not a generic fallback', async ({
    loginPage,
  }) => {
    await loginPage.navigate()
    await loginPage.login('nobody@example.com', 'wrong-password')

    await expect(loginPage.errorMessage()).toBeVisible()
    await expect(loginPage.errorMessage()).toHaveText('Invalid credentials')
    await expect(loginPage.errorMessage()).not.toHaveText('Login failed')
  })
})
