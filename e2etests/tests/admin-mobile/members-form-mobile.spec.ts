/**
 * Member dialog on a phone — pinned header, pinned footer, status strip (#830)
 *
 * On a phone the form is unavoidably longer than the screen, so the two things
 * that must never scroll away are the action the admin came for and the state
 * that decides whether pressing it will work. Before #830 both did: *Speichern*
 * sat at the end of one ~1750px scrolling column, and the status was a panel at
 * the top that was gone by the second field.
 *
 * So this pins:
 *
 *   - the submit button is on screen when the dialog opens *and* after the form
 *     has been scrolled to its last field;
 *   - the strip's conclusion survives the strip — once it scrolls out of view,
 *     the header carries the three dots and the field that still blocks the
 *     save.
 *
 * Implements E2E Testing Patterns:
 * - Pattern 001: Test Data Isolation (each test creates its own member)
 * - Pattern 004: Parallel Execution Safety (own test data, no shared state)
 * - Pattern 005: Test IDs for element selection
 * - Pattern 008: Playwright Assertions & Auto-Waiting
 *
 * Project config uses `devices['iPhone 14']` (390x844, mobile user agent).
 * The admin frontend renders the mobile layout below 768px via `useBreakpoint()`.
 */

import { test, expect } from '@playwright/test'
import { createMemberViaPage } from '../../utils/members'

/**
 * Below 768px the roster is a card list, not a table, so the edit affordance is
 * the card's own `member-edit-*` button rather than the table row's
 * `members-table-action-edit-*` (see mobile-responsive.spec.ts for the same
 * convention on the other list pages).
 */
async function openMemberDialog(page: import('@playwright/test').Page, memberId: string) {
  await page.getByTestId(`member-edit-${memberId}`).click()
  await expect(page.getByTestId('members-form-modal')).toBeVisible()
}

test.describe('Member dialog - Mobile (#830)', () => {

  test('Speichern is on screen when the dialog opens and after the form is scrolled', async ({
    page,
  }) => {
    // The page is loaded first: createMemberViaPage reads the CSRF token out of
    // the app's localStorage, which only exists on the app origin.
    await page.goto('/members')
    await expect(page.getByTestId('members-page')).toBeVisible({ timeout: 15000 })

    const prefix = `MStrip${Date.now()}`
    const member = await createMemberViaPage(page, { firstName: prefix, lastName: 'Phone' })

    await page.reload()
    await page.getByTestId('members-search-input').fill(prefix)
    await openMemberDialog(page, member.id)

    const submit = page.getByTestId('members-form-submit-button')
    await expect(submit).toBeInViewport()

    // 44px is the smallest target a finger hits reliably.
    const box = await submit.boundingBox()
    expect(box?.height ?? 0).toBeGreaterThanOrEqual(44)

    // ── The body scrolls; the footer stays ──────────────────────────────
    await page.getByTestId('members-form-body').evaluate((element) => {
      element.scrollTop = element.scrollHeight
    })
    await expect(page.getByTestId('members-form-footer')).toBeVisible()
    await expect(submit).toBeInViewport()

    // The export moved out of the footer to the end of the form, where a
    // rarely used action is not competing with the primary one.
    await expect(page.getByTestId('members-form-export-button-mobile')).toBeVisible()
  })

  test('once the strip scrolls away the header carries its conclusion', async ({ page }) => {
    await page.goto('/members')
    await expect(page.getByTestId('members-page')).toBeVisible({ timeout: 15000 })

    const prefix = `MGap${Date.now()}`
    // No card and no bank details, so the strip opens with two tiles orange and
    // something worth carrying into the header.
    const member = await createMemberViaPage(page, {
      firstName: prefix,
      lastName: 'Phone',
      withSepa: false,
    })

    await page.reload()
    await page.getByTestId('members-search-input').fill(prefix)
    await openMemberDialog(page, member.id)

    // While the strip is on screen the header does not repeat it.
    await expect(page.getByTestId('members-form-status')).toBeVisible()
    await expect(page.getByTestId('members-form-status-summary')).toHaveCount(0)
    await expect(page.getByTestId('members-form-status-tile-terminal')).toHaveAttribute(
      'data-tone',
      'gap',
    )

    // ── Scroll it away, and the conclusion moves into the header ────────
    await page.getByTestId('members-form-body').evaluate((element) => {
      element.scrollTop = element.scrollHeight
    })

    const summary = page.getByTestId('members-form-status-summary')
    await expect(summary).toBeVisible()
    await expect(summary).toBeInViewport()
    // The dots read the same tiles, so they cannot offer a second opinion.
    await expect(page.getByTestId('members-form-status-dot-terminal')).toHaveAttribute(
      'data-tone',
      'gap',
    )
  })
})
