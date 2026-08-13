/**
 * TOTP Two-Factor Authentication Tests — Milestone 4
 *
 * Covers:
 *  4.1 Login with pre-enrolled admin → requiresMfa → MFA verification → dashboard access
 *  4.2 Login with unenrolled user → requiresTotpSetup → panel inaccessible (403)
 *  4.3 Complete enrollment flow → panel accessible after confirm
 *  4.4 Invalid TOTP code rejected (401)
 *  4.5 MFA endpoint without pending session returns 401
 *  4.6 Admin resets another user's 2FA
 *  4.7 Protected endpoint blocked when totp_setup_required session
 *
 * E2E Patterns applied:
 *  - Pattern 001: Unique test data per test (timestamps in emails)
 *  - Pattern 002: Fresh APIRequestContext per test for session isolation
 *  - Pattern 003: Assert specific data by ID rather than by position
 */

import { test, expect } from "../../fixtures/auth.fixture";
import { TEST_CREDENTIALS } from "../../config/test-credentials";
import { generateTotp, submitTotpWithRetry } from "../../utils/totp";

/**
 * Serial within this file: these tests perform full password+MFA logins as the
 * shared seeded admin, and `totp_last_timestep` (#338) accepts one TOTP code
 * per 30-second step per account. Run 4-wide, the second login in a step is
 * correctly rejected as a replay and the test fails for a reason that has
 * nothing to do with what it is testing. E2E Pattern 004: a test that cannot
 * be made independent of a shared resource is run in order instead.
 */
test.describe.configure({ mode: 'default' })

const API_BASE = "http://localhost:8080/api";

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Create a unique email address for the test.
 * Uses a timestamp suffix to prevent collisions when tests run in parallel.
 */
function uniqueEmail(prefix: string): string {
  return `${prefix}.${Date.now()}@test.example`
}

/**
 * Create a new (unenrolled) admin user via the admin API.
 * Returns the created admin object and its auto-generated password.
 */
async function createAdminUser(
  authenticatedRequest: any,
  emailPrefix: string,
): Promise<{ admin: any; password: string }> {
  const email = uniqueEmail(emailPrefix)
  const response = await authenticatedRequest.post(`${API_BASE}/admin/admin-users`, {
    data: {
      email,
      display_name: `Test ${emailPrefix}`,
      locale: "en",
    },
  })

  if (response.status() !== 201) {
    const body = await response.text()
    throw new Error(`Failed to create admin user (${response.status()}): ${body}`)
  }

  const data = await response.json()
  return { admin: data.admin, password: data.password }
}

/**
 * Perform a raw (unauthenticated) login and return the full response JSON
 * plus the raw Set-Cookie header value.
 */
async function rawLogin(
  request: any,
  email: string,
  password: string,
): Promise<{ data: any; cookieString: string; status: number }> {
  const resp = await request.post(`${API_BASE}/auth/login`, {
    data: { email, password },
  })

  const setCookieHeader = resp.headers()["set-cookie"]
  const cookieString = Array.isArray(setCookieHeader)
    ? setCookieHeader[0]
    : setCookieHeader || ""

  return { data: await resp.json(), cookieString, status: resp.status() }
}

// ---------------------------------------------------------------------------
// Test suite
// ---------------------------------------------------------------------------

test.describe("TOTP 2FA", () => {

  /**
   * 4.1 — Login with enrolled user follows requiresMfa → MFA step → full access
   */
  test("4.1 login with enrolled admin requires MFA verification before granting access", async ({
    playwright,
  }) => {
    // Retries against a same-window replay collision on the shared admin secret (#338).
    test.setTimeout(360_000)
    const ctx = await playwright.request.newContext()

    try {
      // Step 1: Initial login returns requiresMfa, not a full session
      const loginResp = await ctx.post(`${API_BASE}/auth/login`, {
        data: {
          email: TEST_CREDENTIALS.admin.email,
          password: TEST_CREDENTIALS.admin.password,
        },
      })
      expect(loginResp.status()).toBe(200)

      const loginData = await loginResp.json()
      expect(loginData).toHaveProperty("requiresMfa", true)
      expect(loginData).not.toHaveProperty("csrf_token") // no full session yet

      // Step 2: Complete MFA with correct TOTP code
      const mfaResp = await submitTotpWithRetry(TEST_CREDENTIALS.totp.adminSecret, (code) =>
        ctx.post(`${API_BASE}/auth/mfa`, { data: { code } })
      )
      expect(mfaResp.status()).toBe(200)

      const mfaData = await mfaResp.json()
      expect(mfaData).toHaveProperty("message", "Login successful")
      expect(mfaData.admin).toHaveProperty("id")
      expect(mfaData.admin).toHaveProperty("email", TEST_CREDENTIALS.admin.email)
      expect(mfaData).toHaveProperty("csrf_token")

      // Step 3: Session is now fully authenticated — protected endpoints accessible
      const dashResp = await ctx.get(`${API_BASE}/admin/dashboard`)
      expect(dashResp.status()).toBe(200)
    } finally {
      await ctx.dispose()
    }
  })

  /**
   * 4.2 — Unenrolled user cannot access protected endpoints
   */
  test("4.2 unenrolled admin gets requiresTotpSetup and cannot access admin panel", async ({
    authenticatedRequest,
    playwright,
  }) => {
    // Create a fresh admin user (no TOTP enrolled)
    const { admin, password } = await createAdminUser(authenticatedRequest, "unenrolled42")

    const ctx = await playwright.request.newContext()
    try {
      // Login returns requiresTotpSetup
      const loginResp = await ctx.post(`${API_BASE}/auth/login`, {
        data: { email: admin.email, password },
      })
      expect(loginResp.status()).toBe(200)

      const loginData = await loginResp.json()
      expect(loginData).toHaveProperty("requiresTotpSetup", true)
      expect(loginData).not.toHaveProperty("requiresMfa")

      // Session cookie is set but the session carries totp_setup_required flag
      const setCookieHeader = loginResp.headers()["set-cookie"]
      expect(setCookieHeader).toBeTruthy()

      // Any protected endpoint must return 403 totp_setup_required
      const dashResp = await ctx.get(`${API_BASE}/admin/dashboard`)
      expect(dashResp.status()).toBe(403)

      const dashData = await dashResp.json()
      expect(dashData).toHaveProperty("error", "totp_setup_required")
    } finally {
      await ctx.dispose()
    }
  })

  /**
   * 4.3 — Full enrollment flow: after confirm the session is fully authenticated
   */
  test("4.3 completing TOTP enrollment grants full admin panel access", async ({
    authenticatedRequest,
    playwright,
  }) => {
    // Create a fresh unenrolled admin
    const { admin, password } = await createAdminUser(authenticatedRequest, "enroll43")

    const ctx = await playwright.request.newContext()
    try {
      // Login
      const loginResp = await ctx.post(`${API_BASE}/auth/login`, {
        data: { email: admin.email, password },
      })
      expect(loginResp.status()).toBe(200)

      const loginData = await loginResp.json()
      expect(loginData).toHaveProperty("requiresTotpSetup", true)
      const loginCsrf = loginData.csrf_token ?? ""

      // Request TOTP setup (returns QR code and secret)
      const setupResp = await ctx.post(`${API_BASE}/auth/2fa/setup`, {
        headers: { "X-CSRF-Token": loginCsrf },
      })
      expect(setupResp.status()).toBe(200)

      const setupData = await setupResp.json()
      expect(setupData).toHaveProperty("qrCode")
      expect(setupData).toHaveProperty("secret")
      expect(typeof setupData.secret).toBe("string")
      expect(setupData.secret.length).toBeGreaterThan(0)

      // Generate TOTP code from the secret returned by the server
      const code = generateTotp(setupData.secret)

      // Confirm enrollment
      const confirmResp = await ctx.post(`${API_BASE}/auth/2fa/confirm`, {
        data: { code },
        headers: { "X-CSRF-Token": loginCsrf },
      })
      expect(confirmResp.status()).toBe(200)

      const confirmData = await confirmResp.json()
      expect(confirmData).toHaveProperty("message", "Two-factor authentication enabled")

      // Session is now fully authenticated — dashboard must be accessible
      const dashResp = await ctx.get(`${API_BASE}/admin/dashboard`)
      expect(dashResp.status()).toBe(200)

      // Verify totp_enabled flag in the admin users list
      const listResp = await authenticatedRequest.get(`${API_BASE}/admin/admin-users`)
      expect(listResp.status()).toBe(200)

      const listData = await listResp.json()
      const enrolled = listData.data?.find((u: any) => u.id === admin.id)
      expect(enrolled).toBeDefined()
      expect(enrolled.totp_enabled).toBe(true)
    } finally {
      await ctx.dispose()
    }
  })

  /**
   * 4.4 — Wrong TOTP code is rejected
   */
  test("4.4 invalid TOTP code is rejected with 401", async ({ playwright }) => {
    const ctx = await playwright.request.newContext()
    try {
      // Initiate login
      const loginResp = await ctx.post(`${API_BASE}/auth/login`, {
        data: {
          email: TEST_CREDENTIALS.admin.email,
          password: TEST_CREDENTIALS.admin.password,
        },
      })
      expect(loginResp.status()).toBe(200)
      const loginData = await loginResp.json()
      expect(loginData.requiresMfa).toBe(true)

      // Submit a deliberately wrong code
      const mfaResp = await ctx.post(`${API_BASE}/auth/mfa`, {
        data: { code: "000000" },
      })
      expect(mfaResp.status()).toBe(401)
    } finally {
      await ctx.dispose()
    }
  })

  /**
   * 4.5 — MFA endpoint without a pending login session returns 401
   */
  test("4.5 MFA endpoint without a pending session returns 401", async ({ playwright }) => {
    const ctx = await playwright.request.newContext()
    try {
      // No prior login — there is no pending MFA session
      const mfaResp = await ctx.post(`${API_BASE}/auth/mfa`, {
        data: { code: "123456" },
      })
      expect(mfaResp.status()).toBe(401)
    } finally {
      await ctx.dispose()
    }
  })

  /**
   * 4.6 — Superadmin can reset another user's 2FA; user must re-enroll on next login
   */
  test("4.6 admin can reset another user's TOTP and user must re-enroll", async ({
    authenticatedRequest,
    playwright,
  }) => {
    // Step 1: Create a fresh admin user
    const { admin: newAdmin, password } = await createAdminUser(
      authenticatedRequest,
      "reset46"
    )

    // Step 2: Enroll the new admin via a fresh session
    const enrollCtx = await playwright.request.newContext()
    try {
      const loginResp = await enrollCtx.post(`${API_BASE}/auth/login`, {
        data: { email: newAdmin.email, password },
      })
      expect(loginResp.status()).toBe(200)
      const loginData = await loginResp.json()
      expect(loginData.requiresTotpSetup).toBe(true)
      const loginCsrf = loginData.csrf_token ?? ""

      const setupResp = await enrollCtx.post(`${API_BASE}/auth/2fa/setup`, {
        headers: { "X-CSRF-Token": loginCsrf },
      })
      const setupData = await setupResp.json()
      const code = generateTotp(setupData.secret)

      const confirmResp = await enrollCtx.post(`${API_BASE}/auth/2fa/confirm`, {
        data: { code },
        headers: { "X-CSRF-Token": loginCsrf },
      })
      expect(confirmResp.status()).toBe(200)
    } finally {
      await enrollCtx.dispose()
    }

    // Verify the user now shows totp_enabled = true before reset
    const listBefore = await authenticatedRequest.get(`${API_BASE}/admin/admin-users`)
    expect(listBefore.status()).toBe(200)
    const listBeforeData = await listBefore.json()
    const beforeReset = listBeforeData.data?.find((u: any) => u.id === newAdmin.id)
    expect(beforeReset).toBeDefined()
    expect(beforeReset.totp_enabled).toBe(true)

    // Step 3: Superadmin resets the new admin's 2FA — the caller must first
    // re-prove their own identity with a step-up credential (#337): their
    // own password, plus their own fresh TOTP code since they have 2FA
    // enabled.
    const resetResp = await authenticatedRequest.post(`${API_BASE}/auth/2fa/reset`, {
      data: {
        userId: newAdmin.id,
        current_password: TEST_CREDENTIALS.admin.password,
        totp_code: generateTotp(TEST_CREDENTIALS.totp.adminSecret),
      },
    })
    expect(resetResp.status()).toBe(200)

    const resetData = await resetResp.json()
    expect(resetData).toHaveProperty("message")

    // Step 4: Verify totp_enabled is now false in the admin users list
    const listAfter = await authenticatedRequest.get(`${API_BASE}/admin/admin-users`)
    expect(listAfter.status()).toBe(200)
    const listAfterData = await listAfter.json()
    const afterReset = listAfterData.data?.find((u: any) => u.id === newAdmin.id)
    expect(afterReset).toBeDefined()
    expect(afterReset.totp_enabled).toBe(false)

    // Audit trail must carry enough context to tell entries apart without a
    // lookup (#381): a totp_enrolled entry for the new admin's own action,
    // and a totp_reset entry naming *which* admin's 2FA the caller reset —
    // entity_id alone is just the target's UUID.
    const enrolledAudit = await authenticatedRequest.get(
      `${API_BASE}/admin/audit-log?filters[entity_type]=admin_user&filters[action]=totp_enrolled&limit=100`
    )
    expect(enrolledAudit.ok()).toBeTruthy()
    const enrolledAuditData = await enrolledAudit.json()
    const enrolledEntry = enrolledAuditData.data.find((entry: any) => entry.entity_id === newAdmin.id)
    expect(enrolledEntry).toBeDefined()

    const resetAudit = await authenticatedRequest.get(
      `${API_BASE}/admin/audit-log?filters[entity_type]=admin_user&filters[action]=totp_reset&limit=100`
    )
    expect(resetAudit.ok()).toBeTruthy()
    const resetAuditData = await resetAudit.json()
    const resetEntry = resetAuditData.data.find((entry: any) => entry.entity_id === newAdmin.id)
    expect(resetEntry).toBeDefined()
    expect(resetEntry.new_values.target_email).toBe(newAdmin.email)

    // Step 5: User must now re-enroll — login returns requiresTotpSetup, not requiresMfa
    const reloginCtx = await playwright.request.newContext()
    try {
      const reloginResp = await reloginCtx.post(`${API_BASE}/auth/login`, {
        data: { email: newAdmin.email, password },
      })
      expect(reloginResp.status()).toBe(200)

      const reloginData = await reloginResp.json()
      expect(reloginData).toHaveProperty("requiresTotpSetup", true)
      expect(reloginData).not.toHaveProperty("requiresMfa")
    } finally {
      await reloginCtx.dispose()
    }
  })

  /**
   * 4.7 — Session with totp_setup_required blocks all protected endpoints with 403
   */
  test("4.7 session with totp_setup_required is blocked from protected endpoints", async ({
    authenticatedRequest,
    playwright,
  }) => {
    // Create a fresh unenrolled admin
    const { admin, password } = await createAdminUser(authenticatedRequest, "blocked47")

    const ctx = await playwright.request.newContext()
    try {
      // Login to obtain a totp_setup_required session
      const loginResp = await ctx.post(`${API_BASE}/auth/login`, {
        data: { email: admin.email, password },
      })
      expect(loginResp.status()).toBe(200)

      const loginData = await loginResp.json()
      expect(loginData).toHaveProperty("requiresTotpSetup", true)

      // GET /api/admin/members must be blocked
      const membersResp = await ctx.get(`${API_BASE}/admin/members`)
      expect(membersResp.status()).toBe(403)

      const membersData = await membersResp.json()
      expect(membersData).toHaveProperty("error", "totp_setup_required")
    } finally {
      await ctx.dispose()
    }
  })
})
