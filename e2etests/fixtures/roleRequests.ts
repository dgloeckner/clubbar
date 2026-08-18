/**
 * Per-role API fixtures (ADR-0044, #517).
 *
 * The epic's regression net needs to make requests *as* a Kassenwart and *as*
 * a Getränkewart, and the seeded admin cannot stand in for either: demoting it
 * would be observable, mid-run, by every other spec authenticated as the same
 * account (Pattern 002, the same reasoning `utils/isolatedAdmin.ts` records).
 *
 * So each worker mints its own pair of accounts. Worker-scoped rather than
 * per-test for the reason #338 records: minting an account costs a step-up
 * with a TOTP code, and a login as the fresh account costs an enrolment — one
 * pair per worker keeps that off the per-test path while still giving parallel
 * workers accounts they cannot contend over.
 *
 * These accounts are single-use by construction, so a test may mutate them.
 * What a test must **not** do is log one out or reset its 2FA: the context is
 * shared by every test in the worker, and the next one would get a 401 it
 * cannot explain.
 */

import { test as base } from './auth.fixture'
import { createIsolatedAdmin } from '../utils/isolatedAdmin'
import { loginAs, CsrfAwareContext } from '../utils/csrf'
import type { AdminRoleName } from '../utils/isolatedAdmin'

export interface RoleWorkerFixtures {
  /** A session holding `kassenwart` alone — the treasurer's office. */
  kassenwartRequest: CsrfAwareContext
  /** A session holding `getraenkewart` alone — the bar stock office. */
  getraenkewartRequest: CsrfAwareContext
  /** The email of the account behind `kassenwartRequest`, for assertions about it. */
  kassenwartEmail: string
}

async function mint(
  playwright: typeof import('playwright-core'),
  prefix: string,
  roles: AdminRoleName[],
): Promise<{ ctx: CsrfAwareContext; email: string }> {
  const { email, password } = await createIsolatedAdmin(playwright, prefix, roles)
  // `loginAs` completes the mandatory enrolment gate on the way in, so the
  // account arrives fully authenticated with a session of its own.
  return { ctx: await loginAs(playwright, email, password), email }
}

/** This file adds worker fixtures only; nothing per-test. */
type NoTestFixtures = Record<never, never>

export const test = base.extend<NoTestFixtures, RoleWorkerFixtures>({
  kassenwartRequest: [
    async ({ playwright }, use) => {
      const { ctx } = await mint(playwright, 'role-kassenwart', ['kassenwart'])
      await use(ctx)
      await ctx.dispose()
    },
    { scope: 'worker' },
  ],

  getraenkewartRequest: [
    async ({ playwright }, use) => {
      const { ctx } = await mint(playwright, 'role-getraenkewart', ['getraenkewart'])
      await use(ctx)
      await ctx.dispose()
    },
    { scope: 'worker' },
  ],

  kassenwartEmail: [
    async ({ playwright }, use) => {
      const { email } = await createIsolatedAdmin(playwright, 'role-kassenwart-target', ['kassenwart'])
      await use(email)
    },
    { scope: 'worker' },
  ],
})

export { expect } from '@playwright/test'
