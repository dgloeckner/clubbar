/**
 * Mint-your-own-admin helper, for UI tests that must mutate account-level
 * state (password, email, locale, ...).
 *
 * `playwright/.auth/admin.json` is one shared storageState file used by
 * every worker (Pattern 002) — the seeded admin behind it is read by every
 * other test's session bootstrap (`auth/session.ts` calls
 * `changeLanguage(admin.locale)` on every verify). A test that changes that
 * admin's locale, password or email is observable, mid-run, by every
 * unrelated test authenticated as the same account — which is exactly how a
 * transient English locale turned into failing German-text assertions in
 * completely unrelated specs (see profile.spec.ts and
 * i18n-language-switch.spec.ts's history).
 *
 * `createAdmin` mints a fresh, single-use admin via an isolated API context;
 * `signInAndEnroll` drives it through the real login form and the mandatory
 * TOTP enrollment gate — the only place a test can read the freshly
 * generated secret — leaving the caller with an authenticated `page` for a
 * throwaway account nobody else touches.
 */

import type { Page } from '@playwright/test'
import { TEST_CREDENTIALS } from '../config/test-credentials'
import { generateTotp } from './totp'
import { loginAs } from './csrf'
import { stepUp } from '../fixtures/stepUp'
import type { LoginPage } from '../pages/LoginPage'

// The `playwright` fixture's own type (PlaywrightWorkerArgs['playwright']) —
// `@playwright/test` re-exports everything from `playwright/test` but does
// not export a standalone `Playwright` type name.
type Playwright = typeof import('playwright-core')

const API_BASE = 'http://localhost:8080/api'

/** The roles an account may hold (ADR-0044). */
export type AdminRoleName = 'admin' | 'kassenwart' | 'getraenkewart'

export function uniqueTestEmail(prefix: string): string {
  return `${prefix}-${Date.now()}-${Math.round(Math.random() * 1e6)}@test.example`
}

/**
 * Mint an unenrolled admin through an isolated API context.
 *
 * `roles` (ADR-0044) defaults to what the endpoint has always created — an
 * `admin` — so every existing caller is unaffected. Pass a lesser set to mint
 * the office a test needs: a Getränkewart account is the only honest way to
 * test that the panel hides what that office may not open.
 */
export async function createIsolatedAdmin(
  playwright: Playwright,
  prefix: string,
  roles?: AdminRoleName[]
): Promise<{ email: string; password: string }> {
  const ctx = await loginAs(playwright, TEST_CREDENTIALS.admin.email, TEST_CREDENTIALS.admin.password)
  try {
    const email = uniqueTestEmail(prefix)
    const response = await ctx.post(`${API_BASE}/admin/admin-users`, {
      data: {
        ...stepUp(),
        email,
        display_name: `Isolated Test ${prefix}`,
        locale: 'de',
        ...(roles ? { roles } : {}),
      },
    })
    if (response.status() !== 201) {
      throw new Error(`Failed to create admin (${response.status()}): ${await response.text()}`)
    }
    return { email, password: (await response.json()).password }
  } finally {
    await ctx.dispose()
  }
}

/**
 * Log in as a fresh admin and complete the mandatory enrollment gate,
 * returning the secret the setup screen displayed.
 *
 * `landing` is where the panel is expected to arrive afterwards. It is a
 * parameter rather than a constant because the landing page is per role
 * (ADR-0044, #516): a Getränkewart's dashboard would be a 403, so they land on
 * the drinks list instead.
 */
export async function signInAndEnroll(
  loginPage: LoginPage,
  page: Page,
  email: string,
  password: string,
  landing: string = '/dashboard'
): Promise<string> {
  await loginPage.navigate()
  await loginPage.login(email, password)
  const secret = await loginPage.getTotpSetupSecret()
  await loginPage.submitTotpSetupCode(generateTotp(secret))
  await page.waitForURL(`**${landing}`, { timeout: 10000 })
  return secret
}
