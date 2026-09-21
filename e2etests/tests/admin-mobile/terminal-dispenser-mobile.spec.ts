/**
 * A terminal's dispenser on the mobile card layout (#954, ADR-0057).
 *
 * The Terminals tab has two layouts, not one: a table on the desktop and a
 * card below 768px. The cell is rendered twice, so it can be forgotten once —
 * and an operator who learns about a jam at all learns about it on the phone
 * they have with them at the bar.
 *
 * Raw locators rather than `SettingsPage`'s row helpers, which wait for the
 * desktop `settings-terminals-table` the card layout never renders (same
 * convention as `admin-users-roles-mobile.spec.ts`).
 *
 * Project config uses `devices['iPhone 14']` (390x844), WebKit.
 */

import { test, expect, type Page } from '@playwright/test'

import { loginAs } from '../../utils/csrf'
import { stepUp } from '../../fixtures/stepUp'
import { TEST_CREDENTIALS } from '../../config/test-credentials'

const API_BASE = 'http://localhost:8080/api'

/** A terminal of its own (Pattern 001), through the same API the panel uses. */
async function createTerminal(playwright: typeof import('playwright-core')) {
  const ctx = await loginAs(playwright, TEST_CREDENTIALS.admin.email, TEST_CREDENTIALS.admin.password)
  try {
    const stamp = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
    const response = await ctx.post(`${API_BASE}/admin/terminals`, {
      data: { ...stepUp(), name: `Disp mob ${stamp}`, device_id: `dev-dispmob-${stamp}` },
    })
    expect(response.status()).toBe(201)
    const body = await response.json()
    return { id: body.terminal.id as string, token: body.api_token as string }
  } finally {
    await ctx.dispose()
  }
}

async function openTerminalsTab(page: Page) {
  await page.goto('/settings')
  await page.getByTestId('settings-tab-terminals').click()
}

test.describe('Dispenser status on the mobile terminals card', () => {
  test('the card names the fault, dates it, and offers nothing to press', async ({ page, playwright }) => {
    const terminal = await createTerminal(playwright)

    const report = await page.request.put(`${API_BASE}/sync/terminal-status`, {
      headers: { Authorization: `Bearer ${terminal.token}` },
      data: {
        dispenser: {
          configured: true,
          contact: 'reported',
          state: 'fault',
          fault: 'jam',
          fault_code: 0,
          firmware: '1.2.0',
          protocol: 2,
          lifetime: { requested_tokens: 10, dispensed_tokens: 9, jams: 1 },
          pending_reconciliations: 0,
          manual_reconciliations: 0,
        },
      },
    })
    expect(report.status()).toBe(204)

    await openTerminalsTab(page)

    const cell = page.getByTestId(`settings-terminal-dispenser-${terminal.id}`)
    await expect(cell).toBeVisible()
    await expect(cell).toHaveAttribute('data-dispenser-state', 'unavailable')
    await expect(cell).toHaveAttribute('data-dispenser-reason', 'jam')
    // Never the status alone: a dropped report freezes the cell silently.
    await expect(page.getByTestId(`settings-terminal-dispenser-${terminal.id}-age`)).toBeVisible()
    // One fault that started when it started, not when it was last re-reported.
    await expect(page.getByTestId(`settings-terminal-dispenser-${terminal.id}-since`)).toBeVisible()

    await page.getByTestId(`settings-terminal-dispenser-${terminal.id}-details`).click()
    const panel = page.getByTestId('terminal-dispenser-panel-content')
    await expect(panel).toBeVisible()
    await expect(page.getByTestId('terminal-dispenser-detail-dispensed')).toHaveText('9')

    // Owner decision 3: no reset, no clear — only a way out of the dialog.
    const buttons = await panel.locator('button').evaluateAll((nodes) =>
      nodes.map((n) => n.getAttribute('data-testid')),
    )
    expect(buttons).toEqual(['terminal-dispenser-panel-close'])

    await page.getByTestId('terminal-dispenser-panel-close').click()
    await expect(panel).toHaveCount(0)
  })
})
