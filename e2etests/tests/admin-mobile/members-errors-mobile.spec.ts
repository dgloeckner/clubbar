/**
 * Members Page — error visibility on mobile (iPhone 14), #132
 *
 * The error banner used to be rendered only inside MembersPage's desktop
 * branch. On a phone, a failed list load, an anonymize 409 ("member has
 * unsettled transactions") and a failed status toggle all set the error state
 * and then displayed nothing at all — the page just looked empty or unchanged.
 *
 * The banner now sits above the mobile/desktop split, so these tests assert on
 * the same `members-error-message` element the desktop suite uses, from a
 * mobile viewport. The desktop half of #132 lives in
 * tests/admin/silent-failures.spec.ts.
 *
 * Implements E2E Testing Patterns:
 * - Pattern 001: Test Data Isolation (no data created or mutated)
 * - Pattern 004: Parallel Execution Safety (per-page routes, torn down with the page)
 * - Pattern 005: Test IDs for element selection
 * - Pattern 008: Playwright Assertions & Auto-Waiting
 *
 * Project config uses `devices['iPhone 14']` (390x844, mobile user agent).
 * The admin frontend renders mobile layout at <768px via `useBreakpoint()`.
 */

import { test, expect, Page } from '@playwright/test'

/** Fail every call to `urlGlob` with a 500 until `page.unroute` removes it. */
async function failRequests(page: Page, urlGlob: string) {
  await page.route(urlGlob, async (route) => {
    await route.fulfill({
      status: 500,
      contentType: 'application/json',
      body: JSON.stringify({ error: 'server_error', message: 'Injected failure (#132 e2e)' }),
    })
  })
}

test.describe('Members Page errors - Mobile', () => {
  test('shows the error banner when the member list fails to load', async ({ page }) => {
    await failRequests(page, '**/api/admin/members*')

    await page.goto('/members')
    await expect(page.getByTestId('members-page')).toBeVisible({ timeout: 15000 })

    await expect(page.getByTestId('members-error-message')).toBeVisible()
  })

  test('shows "—" and an explanation when the metrics request fails', async ({ page }) => {
    await failRequests(page, '**/api/admin/dashboard*')

    await page.goto('/members')
    await expect(page.getByTestId('members-page')).toBeVisible({ timeout: 15000 })

    await expect(page.getByTestId('stat-card-aktive-mitglieder-value')).toHaveText('—')
    await expect(page.getByTestId('stat-card-offene-posten-value')).toHaveText('—')
    await expect(page.getByTestId('members-metrics-error')).toBeVisible()
  })

  test('keeps the member list usable when only the metrics request fails', async ({ page }) => {
    // The two streams are independent: a broken metrics call must not take the
    // member list down with it. Pattern 003: cards or a genuine empty state,
    // whichever this database produces — but no error either way.
    await failRequests(page, '**/api/admin/dashboard*')

    await page.goto('/members')
    await expect(page.getByTestId('members-page')).toBeVisible({ timeout: 15000 })

    await expect(
      page
        .locator('[data-testid="members-mobile-cards"], [data-testid="members-empty-state"]')
        .first()
    ).toBeVisible({ timeout: 15000 })
    await expect(page.getByTestId('members-error-message')).toBeHidden()
  })
})
