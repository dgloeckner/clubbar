/**
 * CSRF Token Utilities for E2E Tests
 *
 * The backend requires an X-CSRF-Token header for state-changing requests
 * (POST/PATCH/PUT/DELETE). These helpers abstract CSRF handling so tests
 * don't need to deal with token extraction or header construction.
 *
 * Two usage patterns:
 * 1. Admin-chromium tests (page.request): use csrfHeaders(page) to read token from localStorage
 * 2. API tests (fresh request contexts): use loginAs() to create a CSRF-aware context
 */

import { test } from '@playwright/test'
import type { Page, APIRequestContext, Playwright } from '@playwright/test'
import { TEST_CREDENTIALS } from '../config/test-credentials'
import { generateTotp, submitTotpWithRetry } from './totp'

const API_BASE = 'http://localhost:8080/api'

/**
 * Get headers object with the CSRF token for use with page.request mutations.
 * Reads the CSRF token from the page's localStorage (set by the frontend during login).
 */
export async function csrfHeaders(
  page: Page,
  extraHeaders?: Record<string, string>
): Promise<Record<string, string>> {
  const token = await page.evaluate(() => localStorage.getItem('csrf_token') ?? '')
  return {
    ...extraHeaders,
    ...(token ? { 'X-CSRF-Token': token } : {}),
  }
}

/**
 * A request context wrapper that automatically includes CSRF tokens
 * on mutation requests (POST, PATCH, PUT, DELETE).
 */
class CsrfAwareContext {
  constructor(
    private ctx: APIRequestContext,
    private csrfToken: string,
  ) {}

  get = (url: string, options?: any) => this.ctx.get(url, options)

  post = (url: string, options?: any) =>
    this.ctx.post(url, {
      ...options,
      headers: { ...options?.headers, 'X-CSRF-Token': this.csrfToken },
    })

  patch = (url: string, options?: any) =>
    this.ctx.patch(url, {
      ...options,
      headers: { ...options?.headers, 'X-CSRF-Token': this.csrfToken },
    })

  put = (url: string, options?: any) =>
    this.ctx.put(url, {
      ...options,
      headers: { ...options?.headers, 'X-CSRF-Token': this.csrfToken },
    })

  delete = (url: string, options?: any) =>
    this.ctx.delete(url, {
      ...options,
      headers: { ...options?.headers, 'X-CSRF-Token': this.csrfToken },
    })

  dispose = () => this.ctx.dispose()
}

/**
 * Login as a specific admin user and return a CSRF-aware request context.
 * The returned context automatically includes session cookies and CSRF token
 * on all mutation requests.
 *
 * Handles all three login outcomes transparently:
 *  - `{ message: "Login successful" }` — plain login, no TOTP involved
 *  - `{ requiresMfa: true }`           — TOTP verification required
 *  - `{ requiresTotpSetup: true }`     — first-time TOTP enrollment required
 *
 * For the `requiresMfa` case, the TOTP code is generated from
 * TEST_CREDENTIALS.totp.adminSecret (the seeded admin's secret). Tests that
 * login as other users must enroll them first or use this helper after
 * confirming TOTP setup on their behalf.
 *
 * Usage:
 *   const ctx = await loginAs(playwright, email, password)
 *   const resp = await ctx.patch('/api/auth/change-password', { data: { ... } })
 *   await ctx.dispose()
 */
export async function loginAs(
  playwright: Playwright,
  email: string,
  password: string,
  totpSecret?: string,
): Promise<CsrfAwareContext> {
  // baseURL so helpers that address the API with a relative path (the settlement
  // factory, for one) work against this context exactly as they do against the
  // authenticatedRequest fixture. Absolute URLs are unaffected.
  const ctx = await playwright.request.newContext({ baseURL: API_BASE })

  // Step 1: Initial login
  const loginResponse = await ctx.post(`${API_BASE}/auth/login`, {
    data: { email, password },
  })

  if (!loginResponse.ok()) {
    const body = await loginResponse.text()
    throw new Error(`Login failed (${loginResponse.status()}): ${body}`)
  }

  const loginData = await loginResponse.json()

  // Step 2a: TOTP verification required — user is already enrolled
  if (loginData.requiresMfa) {
    const secret = totpSecret ?? TEST_CREDENTIALS.totp.adminSecret

    // Retries on a rejected code (submitTotpWithRetry) — mainly relevant when
    // `secret` is the shared seeded admin's, where a concurrent login can land
    // in the same 30-second window and collide with replay protection (#338).
    test.setTimeout(240_000)
    const mfaResponse = await submitTotpWithRetry(secret, (code) =>
      ctx.post(`${API_BASE}/auth/mfa`, { data: { code } })
    )

    if (!mfaResponse.ok()) {
      const body = await mfaResponse.text()
      throw new Error(`MFA verification failed (${mfaResponse.status()}): ${body}`)
    }

    const mfaData = await mfaResponse.json()
    const csrfToken = mfaData.csrf_token ?? ''
    return new CsrfAwareContext(ctx, csrfToken)
  }

  // Step 2b: TOTP setup required — user is not yet enrolled
  if (loginData.requiresTotpSetup) {
    // The session cookie is stored in ctx; subsequent calls are automatically authenticated
    const setupResponse = await ctx.post(`${API_BASE}/auth/2fa/setup`, {
      headers: { 'X-CSRF-Token': loginData.csrf_token ?? '' },
    })

    if (!setupResponse.ok()) {
      const body = await setupResponse.text()
      throw new Error(`TOTP setup failed (${setupResponse.status()}): ${body}`)
    }

    const setupData = await setupResponse.json()
    const enrollSecret = totpSecret ?? setupData.secret
    const code = generateTotp(enrollSecret)

    const confirmResponse = await ctx.post(`${API_BASE}/auth/2fa/confirm`, {
      data: { code },
      headers: { 'X-CSRF-Token': loginData.csrf_token ?? '' },
    })

    if (!confirmResponse.ok()) {
      const body = await confirmResponse.text()
      throw new Error(`TOTP confirm failed (${confirmResponse.status()}): ${body}`)
    }

    // After enrollment the session is fully authenticated; csrf_token came from the login response
    const csrfToken = loginData.csrf_token ?? ''
    return new CsrfAwareContext(ctx, csrfToken)
  }

  // Step 2c: Plain login (no TOTP) — legacy or test-only path
  const csrfToken = loginData.csrf_token ?? ''
  return new CsrfAwareContext(ctx, csrfToken)
}
