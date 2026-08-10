/**
 * Members Page — deactivating from the mobile card list, #130
 *
 * On a phone the status toggle sits directly beside the member's name in the
 * card list, which is exactly where a thumb lands while scrolling. Until #130
 * that tap wrote straight through: the member was cut off from the terminal
 * with nothing asked and nothing said. The card only re-rendered with the
 * toggle off, which on a scrolling list is easy to miss entirely.
 *
 * These tests pin the mobile half of the fix. The desktop half lives in
 * tests/admin/members.spec.ts.
 *
 * Implements E2E Testing Patterns:
 * - Pattern 001: Test Data Isolation (each test creates its own member)
 * - Pattern 004: Parallel Execution Safety (per-page routes, own test data)
 * - Pattern 005: Test IDs for element selection
 * - Pattern 008: Playwright Assertions & Auto-Waiting
 *
 * Project config uses `devices['iPhone 14']` (390x844, mobile user agent).
 * The admin frontend renders mobile layout at <768px via `useBreakpoint()`.
 */

import { test, expect } from '@playwright/test'
import { createMemberViaPage } from '../../utils/members'
import { csrfHeaders } from '../../utils/csrf'

test.describe('Members Page deactivation - Mobile', () => {
  test('tapping the card toggle asks before deactivating, and cancelling changes nothing', async ({
    page,
  }) => {
    // The page is loaded first: createMemberViaPage reads the CSRF token out of
    // the app's localStorage, which only exists on the app origin.
    await page.goto('/members')
    await expect(page.getByTestId('members-page')).toBeVisible({ timeout: 15000 })

    const prefix = `MDeact${Date.now()}`
    const member = await createMemberViaPage(page, {
      firstName: prefix,
      lastName: 'Card',
      email: `${prefix.toLowerCase()}@test.com`,
    })

    // Count the PATCHes this test causes: a mistap must cost nothing.
    let patchCalls = 0
    await page.route(`**/api/admin/members/${member.id}`, (route) => {
      if (route.request().method() === 'PATCH') patchCalls += 1
      return route.continue()
    })

    // Narrow to our own member so the card is on screen regardless of how many
    // members this database holds (Pattern 003).
    await page.getByTestId('members-search-input').fill(prefix)
    await page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/members?') && resp.status() === 200,
      { timeout: 15000 },
    )

    const card = page.getByTestId(`member-card-${member.id}`)
    await expect(card).toBeVisible({ timeout: 15000 })

    const toggle = page.getByTestId(`members-status-toggle-${member.id}`)
    await expect(toggle).toHaveAttribute('aria-checked', 'true')

    // ── The tap asks first ──────────────────────────────────────────────
    await toggle.tap()
    await expect(page.getByTestId('confirm-dialog')).toBeVisible()

    // The dialog names the member, so a mistap is recognisable as one.
    await expect(page.getByTestId('confirm-dialog-message')).toContainText(prefix)

    // ── Cancelling leaves the member alone ──────────────────────────────
    await page.getByTestId('confirm-dialog-cancel').tap()
    await expect(page.getByTestId('confirm-dialog')).toBeHidden()
    expect(patchCalls).toBe(0)
    await expect(toggle).toHaveAttribute('aria-checked', 'true')

    // ── Confirming deactivates, end to end ──────────────────────────────
    const reload = page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/members?') && resp.status() === 200,
      { timeout: 15000 },
    )
    await toggle.tap()
    await expect(page.getByTestId('confirm-dialog')).toBeVisible()
    await page.getByTestId('confirm-dialog-ok').tap()
    await expect(page.getByTestId('confirm-dialog')).toBeHidden()
    await reload

    expect(patchCalls).toBe(1)
    await expect(toggle).toHaveAttribute('aria-checked', 'false')

    // The backend agrees, not just the card.
    const after = await page.request.get(`http://localhost:8080/api/admin/members/${member.id}`)
    expect(after.status()).toBe(200)
    expect((await after.json()).is_active).toBe(false)
  })

  test('reactivating from the card list needs no confirmation', async ({ page }) => {
    await page.goto('/members')
    await expect(page.getByTestId('members-page')).toBeVisible({ timeout: 15000 })

    const prefix = `MReact${Date.now()}`
    const member = await createMemberViaPage(page, {
      firstName: prefix,
      lastName: 'Card',
      email: `${prefix.toLowerCase()}@test.com`,
    })

    // Create always yields an active member, so the starting state is set here.
    const deactivated = await page.request.patch(
      `http://localhost:8080/api/admin/members/${member.id}`,
      { data: { is_active: false }, headers: await csrfHeaders(page) },
    )
    expect(deactivated.ok()).toBe(true)

    await page.getByTestId('members-search-input').fill(prefix)
    await page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/members?') && resp.status() === 200,
      { timeout: 15000 },
    )

    const toggle = page.getByTestId(`members-status-toggle-${member.id}`)
    await expect(toggle).toBeVisible({ timeout: 15000 })
    await expect(toggle).toHaveAttribute('aria-checked', 'false')

    // Restoring access loses nothing, so it stays a single tap.
    const reload = page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/members?') && resp.status() === 200,
      { timeout: 15000 },
    )
    await toggle.tap()
    await expect(page.getByTestId('confirm-dialog')).toBeHidden()
    await reload

    await expect(toggle).toHaveAttribute('aria-checked', 'true')
  })
})
