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

import { Playwright } from '@playwright/test'
import { test, expect } from '../../fixtures/pageObjects'
import { TEST_CREDENTIALS } from '../../config/test-credentials'
import { generateTotp } from '../../utils/totp'
import { loginAs } from '../../utils/csrf'

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
  playwright: Playwright,
  emailPrefix: string
): Promise<{ email: string; password: string }> {
  const ctx = await loginAs(playwright, TEST_CREDENTIALS.admin.email, TEST_CREDENTIALS.admin.password)
  try {
    const email = uniqueEmail(emailPrefix)
    const createResp = await ctx.post(`${API_BASE}/admin/admin-users`, {
      data: { email, display_name: `Login Test ${emailPrefix}`, locale: 'en' },
    })
    if (createResp.status() !== 201) {
      throw new Error(`Failed to create admin user (${createResp.status()}): ${await createResp.text()}`)
    }
    const data = await createResp.json()
    return { email, password: data.password }
  } finally {
    await ctx.dispose()
  }
}

test.describe('Login (UI)', () => {
  // Override the project's pre-authenticated storageState — these tests start logged out.
  test.use({ storageState: { cookies: [], origins: [] } })

  test('logs in a TOTP-enrolled admin via the MFA step', async ({ loginPage, page }) => {
    await loginPage.navigate()
    await loginPage.login(TEST_CREDENTIALS.admin.email, TEST_CREDENTIALS.admin.password)

    // Backend's login() branch 1: TOTP-enrolled → requiresMfa, no session yet
    await expect(loginPage.mfaCodeInput()).toBeVisible()

    const code = generateTotp(TEST_CREDENTIALS.totp.adminSecret)
    await loginPage.submitMfaCode(code)

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
    const { email, password } = await createUnenrolledAdmin(playwright, 'login-setup')

    await loginPage.navigate()
    await loginPage.login(email, password)

    // Backend's login() branch 2: not enrolled → requiresTotpSetup, provisional session
    await expect(loginPage.totpQrCode()).toBeVisible()
    await expect(loginPage.totpSetupCodeInput()).toBeVisible()

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
})
