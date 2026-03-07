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

import type { Page, APIRequestContext, Playwright } from '@playwright/test'

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
 * Usage:
 *   const ctx = await loginAs(playwright, email, password)
 *   const resp = await ctx.patch('/api/auth/change-password', { data: { ... } })
 *   await ctx.dispose()
 */
export async function loginAs(
  playwright: Playwright,
  email: string,
  password: string,
): Promise<CsrfAwareContext> {
  const ctx = await playwright.request.newContext()
  const loginResponse = await ctx.post(`${API_BASE}/auth/login`, {
    data: { email, password },
  })

  if (!loginResponse.ok()) {
    const body = await loginResponse.text()
    throw new Error(`Login failed (${loginResponse.status()}): ${body}`)
  }

  const loginData = await loginResponse.json()
  const csrfToken = loginData.csrf_token || ''

  return new CsrfAwareContext(ctx, csrfToken)
}
