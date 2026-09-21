/**
 * Recording a hopper refill from the phone (#955, ADR-0058).
 *
 * This is the layout the act actually happens on: whoever refills the hopper
 * is standing at the machine with a bag of tokens, not at a desk. The
 * Terminals tab renders its actions twice — a table above 768px and a card
 * below it — so the refill control can be forgotten in exactly one of them.
 *
 * Raw locators rather than `SettingsPage`'s row helpers, which wait for the
 * desktop `settings-terminals-table` the card layout never renders (the
 * convention `terminal-dispenser-mobile.spec.ts` follows).
 *
 * Project config uses `devices['iPhone 14']` (390x844), WebKit.
 */

import { test, expect, type Page } from '@playwright/test'

import { loginAs } from '../../utils/csrf'
import { stepUp } from '../../fixtures/stepUp'
import { TEST_CREDENTIALS } from '../../config/test-credentials'

const API_BASE = 'http://localhost:8080/api'

async function createTerminal(playwright: typeof import('playwright-core')) {
  const ctx = await loginAs(playwright, TEST_CREDENTIALS.admin.email, TEST_CREDENTIALS.admin.password)
  try {
    const stamp = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
    const response = await ctx.post(`${API_BASE}/admin/terminals`, {
      data: { ...stepUp(), name: `Fill mob ${stamp}`, device_id: `dev-fillmob-${stamp}` },
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

test.describe('Recording a refill on the mobile terminals card', () => {
  test('the card carries the refill action, and the count survives a reload', async ({ page, playwright }) => {
    const terminal = await createTerminal(playwright)

    const report = await page.request.put(`${API_BASE}/sync/terminal-status`, {
      headers: { Authorization: `Bearer ${terminal.token}` },
      data: {
        dispenser: {
          configured: true,
          contact: 'reported',
          state: 'idle',
          fault: 'none',
          fault_code: 0,
          firmware: '1.2.0',
          protocol: 2,
          lifetime: { requested_tokens: 10, dispensed_tokens: 10 },
          pending_reconciliations: 0,
          manual_reconciliations: 0,
        },
      },
    })
    expect(report.status()).toBe(204)

    await openTerminalsTab(page)

    await page.getByTestId(`settings-terminal-refill-button-${terminal.id}`).click()
    const dialog = page.getByTestId('terminal-refill-dialog-content')
    await expect(dialog).toBeVisible()

    // The dialog says what it is and what it is not: a fact about the hopper,
    // not an acknowledgement. Nothing here reaches the machine.
    await expect(page.getByTestId('terminal-refill-dialog-note')).toContainText('Quittierung')

    // Two buttons, and neither clears anything (owner decision 3).
    const buttons = await dialog.locator('button').evaluateAll((nodes) =>
      nodes.map((n) => n.getAttribute('data-testid')),
    )
    expect(buttons).toEqual(['terminal-refill-dialog-cancel', 'terminal-refill-dialog-save'])

    await page.getByTestId('terminal-refill-tokens-input').fill('250')
    await page.getByTestId('terminal-refill-dialog-save').click()
    await expect(dialog).toHaveCount(0)

    // Computed on read, so a reload is the real assertion.
    await openTerminalsTab(page)
    await page.getByTestId(`settings-terminal-dispenser-${terminal.id}-details`).click()
    await expect(page.getByTestId('terminal-dispenser-detail-estimated-left')).toHaveText('250')
    await page.getByTestId('terminal-dispenser-panel-close').click()
  })
})
