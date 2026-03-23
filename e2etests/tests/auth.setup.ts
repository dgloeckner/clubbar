/**
 * Authentication Setup
 *
 * This setup runs once before tests to authenticate and save storage state.
 * Implements Playwright's recommended multi-role authentication pattern:
 * https://playwright.dev/docs/auth#multiple-signed-in-roles
 *
 * Setup Project Configuration:
 * - Runs before all test projects
 * - Logs in test users and saves auth state to files
 * - Tests then use saved state instead of logging in repeatedly
 *
 * Benefits:
 * - Tests run faster (no repeated login)
 * - More reliable (auth happens once in controlled setup)
 * - Cleaner test code (no auth logic in fixtures)
 * - Easy to add more user roles (admin, member, etc.)
 *
 * Usage in tests:
 *   test.use({ storageState: 'playwright/.auth/admin.json' });
 *   test('my test', async ({ page }) => {
 *     // Already authenticated as admin
 *     await page.goto('/members');
 *   });
 *
 * TOTP Note:
 * The seeded admin (admin@example.com) has TOTP pre-enrolled. We use page.request
 * (which shares the browser's cookie jar) to call the backend API directly:
 *   1. POST /api/auth/login  → may return requiresMfa / requiresTotpSetup
 *   2. Complete MFA or enrollment if needed
 *   3. Set localStorage manually (admin_id, csrf_token, is_authenticated)
 *   4. Navigate to /dashboard and save storage state
 *
 * page.request shares cookies with the page's browser context, so cookies set
 * by API calls made via page.request are available in the browser and will be
 * captured by context.storageState().
 */

import { test as setup } from '@playwright/test'
import { TEST_CREDENTIALS } from '../config/test-credentials'
import { generateTotp } from '../utils/totp'
import path from 'path'

// Define auth state paths
const authDir = 'playwright/.auth'
const adminAuthFile = path.join(authDir, 'admin.json')

const API_BASE = 'http://localhost:8080/api'
const FRONTEND_BASE = 'http://localhost:5173'

/**
 * Setup: Admin User Authentication
 *
 * Logs in as admin (handling TOTP if required) and saves storage state for
 * reuse in tests. This runs once before tests using the admin auth state.
 */
setup('authenticate as admin', async ({ page, context }) => {
  // Step 1: Call login endpoint via page.request so session cookie ends up
  // in the browser's cookie jar (page.request shares cookies with the page).
  const loginResp = await page.request.post(`${API_BASE}/auth/login`, {
    data: {
      email: TEST_CREDENTIALS.admin.email,
      password: TEST_CREDENTIALS.admin.password,
    },
  })

  if (!loginResp.ok()) {
    const body = await loginResp.text()
    throw new Error(`Admin login failed with status ${loginResp.status()}: ${body}`)
  }

  const loginData = await loginResp.json()

  let adminId: string
  let csrfToken: string

  if (loginData.requiresMfa) {
    // Step 2a: Admin is enrolled — provide TOTP verification code
    const code = generateTotp(TEST_CREDENTIALS.totp.adminSecret)
    const mfaResp = await page.request.post(`${API_BASE}/auth/mfa`, {
      data: { code },
    })

    if (!mfaResp.ok()) {
      const body = await mfaResp.text()
      throw new Error(`MFA verification failed with status ${mfaResp.status()}: ${body}`)
    }

    const mfaData = await mfaResp.json()
    adminId = mfaData.admin?.id ?? ''
    csrfToken = mfaData.csrf_token ?? ''

  } else if (loginData.requiresTotpSetup) {
    // Step 2b: Admin exists but has not yet enrolled — complete enrollment
    const loginCsrf = loginData.csrf_token ?? ''

    const setupResp = await page.request.post(`${API_BASE}/auth/2fa/setup`, {
      headers: { 'X-CSRF-Token': loginCsrf },
    })

    if (!setupResp.ok()) {
      const body = await setupResp.text()
      throw new Error(`TOTP setup failed with status ${setupResp.status()}: ${body}`)
    }

    const setupData = await setupResp.json()
    const code = generateTotp(setupData.secret)

    const confirmResp = await page.request.post(`${API_BASE}/auth/2fa/confirm`, {
      data: { code },
      headers: { 'X-CSRF-Token': loginCsrf },
    })

    if (!confirmResp.ok()) {
      const body = await confirmResp.text()
      throw new Error(`TOTP confirm failed with status ${confirmResp.status()}: ${body}`)
    }

    adminId = loginData.admin?.id ?? ''
    csrfToken = loginCsrf

  } else {
    // Step 2c: Plain login (no TOTP) — session is ready
    adminId = loginData.admin?.id ?? ''
    csrfToken = loginData.csrf_token ?? ''
  }

  if (!adminId || !csrfToken) {
    throw new Error(
      `Auth setup incomplete: adminId=${adminId}, csrfToken present=${Boolean(csrfToken)}`
    )
  }

  // Step 3: Navigate to the frontend and set the localStorage values that the
  // React app uses to consider the session authenticated.
  await page.goto(`${FRONTEND_BASE}/login`)
  await page.evaluate(
    ({ id, csrf }) => {
      localStorage.setItem('admin_id', id)
      localStorage.setItem('csrf_token', csrf)
      localStorage.setItem('is_authenticated', 'true')
      localStorage.removeItem('totp_setup_required')
    },
    { id: adminId, csrf: csrfToken }
  )

  // Step 4: Navigate to dashboard and verify the app accepts the session
  await page.goto(`${FRONTEND_BASE}/dashboard`)
  await page.waitForURL('**/dashboard', { timeout: 10000 })

  // Step 5: Save both cookies (session) and localStorage (auth tokens)
  await context.storageState({ path: adminAuthFile })
})
