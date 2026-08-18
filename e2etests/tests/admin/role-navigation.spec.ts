/**
 * Admin Frontend — per-role navigation, landing and refusal (ADR-0044, #516)
 *
 * The panel's half of tiered admin roles. Nothing here is a security control —
 * the server refuses every request on its own (#519) and these tests do not
 * prove otherwise. What they prove is that a lesser office is not shown doors
 * that answer 403, lands somewhere it can actually work, and is told *why*
 * when it reaches a page it may not open.
 *
 * Each test mints its own admin in the office it needs (Pattern 001, Pattern
 * 002): the shared seeded admin holds `admin` and demoting it would be visible
 * to every other spec running beside this one.
 *
 * Implements E2E Testing Patterns:
 * - Pattern 001: Test Data Isolation (a fresh admin per test)
 * - Pattern 002: Authentication Isolation (never touches the seeded admin)
 * - Pattern 005: Using Test IDs (data-testid)
 * - Pattern 006: Page Object Model (MainLayoutPage)
 * - Pattern 008: Playwright Assertions (expect API, no try-catch visibility)
 */

import { test, expect } from '../../fixtures/pageObjects'
import { createIsolatedAdmin, signInAndEnroll } from '../../utils/isolatedAdmin'
import { MainLayoutPage } from '../../pages/MainLayoutPage'
import { loginAs } from '../../utils/csrf'
import { stepUp } from '../../fixtures/stepUp'
import { TEST_CREDENTIALS } from '../../config/test-credentials'

type Playwright = typeof import('playwright-core')

const API_BASE = 'http://localhost:8080/api'

/** Demote an account to `getraenkewart`, as the seeded admin would. */
async function setRoles(
  playwright: Playwright,
  email: string,
  roles: Array<'admin' | 'kassenwart' | 'getraenkewart'>,
): Promise<void> {
  const ctx = await loginAs(playwright, TEST_CREDENTIALS.admin.email, TEST_CREDENTIALS.admin.password)
  try {
    const list = await ctx.get(`${API_BASE}/admin/admin-users`, { params: { search: email, per_page: 100 } })
    const account = (await list.json()).data.find((a: { email: string }) => a.email === email)
    if (!account) throw new Error(`No admin account found for ${email}`)

    const response = await ctx.patch(`${API_BASE}/admin/admin-users/${account.id}`, {
      data: { ...stepUp(), roles },
    })
    if (!response.ok()) {
      throw new Error(`Role change failed (${response.status()}): ${await response.text()}`)
    }
  } finally {
    await ctx.dispose()
  }
}

test.describe('Role-aware navigation', () => {
  // Start logged out: each test signs in as the office it is about.
  test.use({ storageState: { cookies: [], origins: [] } })

  /**
   * The whole point of the epic: the drinks list can be handed to somebody
   * without also handing them every member's bank details. Products and
   * Reports are what a Getränkewart may open; everything else is absent from
   * the navigation rather than present-and-disabled.
   */
  test('a Getränkewart lands on the drinks list and sees only what it may open', async ({
    loginPage,
    page,
    playwright,
  }) => {
    const { email, password } = await createIsolatedAdmin(playwright, 'role-bar', ['getraenkewart'])
    await signInAndEnroll(loginPage, page, email, password, '/products')

    const layout = new MainLayoutPage(page)
    const nav = await layout.getVisibleNavTestIds()

    expect(nav).toEqual(['nav-products', 'nav-reports'])
    await layout.expectNoInsufficientRoleScreen()
  })

  /**
   * Hidden navigation is not enforcement (ADR-0044 rule 5), so the bookmark
   * case has to be handled rather than assumed away: a Getränkewart typing the
   * members URL gets a named refusal and a way back, not a blank page or a
   * silent redirect that reads like the section moved.
   */
  test('a Getränkewart reaching a members URL is refused by name and offered the way back', async ({
    loginPage,
    page,
    playwright,
  }) => {
    const { email, password } = await createIsolatedAdmin(playwright, 'role-bar-deep', ['getraenkewart'])
    await signInAndEnroll(loginPage, page, email, password, '/products')

    const layout = new MainLayoutPage(page)

    await page.goto('/members')
    await layout.expectInsufficientRoleScreen()

    // The members table itself must not be on screen behind the refusal.
    await expect(page.locator('[data-testid="members-page"]')).toBeHidden()

    await layout.clickInsufficientRoleHomeLink()
    await page.waitForURL('**/products', { timeout: 5000 })
    await layout.expectNoInsufficientRoleScreen()
  })

  /**
   * The treasurer's office is the mirror image: members and settlements yes,
   * the operator surfaces (accounts, keys, mail configuration, the audit log)
   * no — and the audit log especially, because a Kassenwart who can read it
   * can see whether anyone is watching.
   */
  test('a Kassenwart keeps the settlement work and loses the operator surfaces', async ({
    loginPage,
    page,
    playwright,
  }) => {
    const { email, password } = await createIsolatedAdmin(playwright, 'role-treasury', ['kassenwart'])
    await signInAndEnroll(loginPage, page, email, password)

    const layout = new MainLayoutPage(page)
    const nav = await layout.getVisibleNavTestIds()

    expect(nav).toEqual([
      'nav-dashboard',
      'nav-members',
      'nav-journal',
      'nav-settlements',
      'nav-reports',
      'nav-notifications',
    ])

    await page.goto('/audit-log')
    await layout.expectInsufficientRoleScreen()
  })

  /**
   * Grants are additive (ADR-0044 rule 2). Somebody covering both lesser
   * offices sees the union of them and is still not an admin — the settings
   * and audit-log entries stay gone.
   */
  test('holding both lesser offices shows the union and still not the admin surfaces', async ({
    loginPage,
    page,
    playwright,
  }) => {
    const { email, password } = await createIsolatedAdmin(playwright, 'role-both', [
      'kassenwart',
      'getraenkewart',
    ])
    await signInAndEnroll(loginPage, page, email, password)

    const layout = new MainLayoutPage(page)
    const nav = await layout.getVisibleNavTestIds()

    expect(nav).toContain('nav-products')
    expect(nav).toContain('nav-members')
    expect(nav).not.toContain('nav-settings')
    expect(nav).not.toContain('nav-audit-log')
  })

  /**
   * The case hidden navigation cannot cover, and the reason the refusal screen
   * exists at all: the roles changed while the tab stayed open. The panel is
   * still showing the old navigation — it has no way to know — so the first
   * refused request is what tells it, and what it does with that is re-read its
   * own roles rather than log anybody out or show a raw error.
   */
  test('a role revoked mid-session refuses the page and collapses the navigation', async ({
    loginPage,
    page,
    playwright,
  }) => {
    const { email, password } = await createIsolatedAdmin(playwright, 'role-revoked')
    await signInAndEnroll(loginPage, page, email, password)

    const layout = new MainLayoutPage(page)
    expect(await layout.getVisibleNavTestIds()).toContain('nav-members')

    await setRoles(playwright, email, ['getraenkewart'])

    // The stale tab still offers Members. Following it is the 403 that the
    // panel learns from.
    await page.locator('[data-testid="nav-members"]').click()

    await layout.expectInsufficientRoleScreen()
    await expect(page.locator('[data-testid="nav-members"]')).toBeHidden()
    expect(await layout.getVisibleNavTestIds()).toEqual(['nav-products', 'nav-reports'])
  })

  /**
   * An `admin` sees everything, which is the behaviour-preservation check: the
   * whole epic must be invisible to the account every existing installation
   * has.
   */
  test('an admin still sees every section', async ({ loginPage, page, playwright }) => {
    const { email, password } = await createIsolatedAdmin(playwright, 'role-admin')
    await signInAndEnroll(loginPage, page, email, password)

    const layout = new MainLayoutPage(page)
    const nav = await layout.getVisibleNavTestIds()

    expect(nav).toEqual([
      'nav-dashboard',
      'nav-members',
      'nav-products',
      'nav-journal',
      'nav-settlements',
      'nav-reports',
      'nav-settings',
      'nav-notifications',
      'nav-audit-log',
    ])
  })
})
