/**
 * Admin Frontend — warning before the hopper runs out (#955, ADR-0058).
 *
 * The machine cannot tell us it is running low: its *empty* switch is a
 * factory option this unit does not have. So the warning is arithmetic — a
 * counted refill, minus the tokens sold since — and this spec drives that
 * whole loop through the UI and the API the terminal really uses: an admin
 * records a count, the kiosk sells tokens with its own bearer token, and the
 * row an operator already has open turns amber before anybody walks to the
 * bar with a bag of coins.
 *
 * What is asserted, and why each one is not decoration:
 *
 * - **A refill is recorded through the UI and survives a reload.** The
 *   estimate is computed on read, so "it showed the right number once" is not
 *   the same claim.
 * - **Selling tokens moves it, and crossing the threshold turns the cell to
 *   the warning state.** That transition is the whole feature.
 * - **No refill recorded is no estimate**, never "0 tokens left" — opposite
 *   errands.
 * - **Recording a refill clears nothing.** A jammed terminal is still jammed
 *   afterwards: the device has no reset route (owner decision 3), and the
 *   dialog says so.
 * - **The `admin` office alone.** The lesser offices cannot even open the
 *   page, and the API refuses them by name — asserted in the API spec.
 *
 * Implements E2E Testing Patterns:
 * - Pattern 001: Test Data Isolation (a terminal, product and member per test)
 * - Pattern 002: Authentication Isolation
 * - Pattern 005: Using Test IDs
 * - Pattern 006: Page Object Model (SettingsPage)
 * - Pattern 008: Playwright Assertions
 * - Pattern 009: User-Flow-Based Tests
 */

import { randomUUID } from 'node:crypto'

import type { APIRequestContext } from '@playwright/test'

import { test, expect } from '../../fixtures/pageObjects'
import { loginAs, type CsrfAwareContext } from '../../utils/csrf'
import { stepUp } from '../../fixtures/stepUp'
import { TEST_CREDENTIALS } from '../../config/test-credentials'
import { SettingsPage } from '../../pages/SettingsPage'

type Playwright = typeof import('playwright-core')

const API_BASE = 'http://localhost:8080/api'

/** An admin context of its own, so nothing here disturbs the page's session. */
async function asAdmin<T>(playwright: Playwright, run: (ctx: CsrfAwareContext) => Promise<T>): Promise<T> {
  const ctx = await loginAs(playwright, TEST_CREDENTIALS.admin.email, TEST_CREDENTIALS.admin.password)
  try {
    return await run(ctx)
  } finally {
    await ctx.dispose()
  }
}

/** A terminal, a token product and a member — all of them this test's own. */
async function fixtures(playwright: Playwright, label: string) {
  return asAdmin(playwright, async (ctx) => {
    const stamp = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`

    const terminal = await ctx.post(`${API_BASE}/admin/terminals`, {
      data: { ...stepUp(), name: `Fill UI ${label} ${stamp}`, device_id: `dev-fillui-${label}-${stamp}` },
    })
    expect(terminal.status(), await terminal.text()).toBe(201)
    const created = await terminal.json()

    const category = await ctx.post(`${API_BASE}/admin/categories`, {
      data: { names: { de: `Kat ${stamp}`, en: `Cat ${stamp}` } },
    })
    expect(category.status()).toBe(201)

    const product = await ctx.post(`${API_BASE}/admin/products`, {
      data: {
        names: { de: `Token ${stamp}`, en: `Token ${stamp}` },
        category_id: (await category.json()).id,
        price_cents: 100,
        requires_dispenser: true,
      },
    })
    expect(product.status(), await product.text()).toBe(201)

    const member = await ctx.post(`${API_BASE}/admin/members`, {
      data: {
        first_name: 'Fill',
        last_name: `UI${stamp.slice(-6)}`,
        email: `fill-ui-${stamp}@example.com`,
        preferred_language: 'de',
        date_of_birth: '1980-05-17',
        iban: 'DE89370400440532013000',
        mandate_signed_at: '2024-01-01',
      },
    })
    expect(member.status(), await member.text()).toBe(201)

    return {
      name: created.terminal.name as string,
      id: created.terminal.id as string,
      token: created.api_token as string,
      productId: (await product.json()).id as string,
      memberId: (await member.json()).id as string,
    }
  })
}

/** One row per token, the way the kiosk really writes a dispense. */
async function sellTokens(
  request: APIRequestContext,
  fixture: { token: string; memberId: string; productId: string },
  count: number,
) {
  const response = await request.post(`${API_BASE}/sync/transactions`, {
    headers: { Authorization: `Bearer ${fixture.token}` },
    data: {
      transactions: Array.from({ length: count }, () => ({
        id: randomUUID(),
        member_id: fixture.memberId,
        product_id: fixture.productId,
        amount_cents: 100,
        created_at: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'),
      })),
    },
  })
  expect(response.ok(), await response.text()).toBeTruthy()
}

/** The document a healthy controller sends — the cell needs one to show a state. */
function healthy(overrides: Record<string, unknown> = {}) {
  return {
    dispenser: {
      configured: true,
      contact: 'reported',
      state: 'idle',
      fault: 'none',
      fault_code: 0,
      firmware: '1.2.0',
      protocol: 2,
      lifetime: { requested_tokens: 1412, dispensed_tokens: 1409, jams: 3, crashes: 0, overrun_tokens: 1 },
      pending_reconciliations: 0,
      manual_reconciliations: 0,
      observed_at: new Date().toISOString(),
      ...overrides,
    },
  }
}

async function report(request: APIRequestContext, token: string, data: unknown) {
  const response = await request.put(`${API_BASE}/sync/terminal-status`, {
    headers: { Authorization: `Bearer ${token}` },
    data,
  })
  expect(response.status()).toBe(204)
}

async function openTerminals(settings: SettingsPage) {
  await settings.goto()
  await settings.waitForLoad()
  await settings.clickTerminalsTab()
}

test.describe('The hopper estimate in the admin panel', () => {
  test('an admin records a refill, sells tokens, and the row warns before it jams', async ({
    authenticatedSettingsPage,
    playwright,
    request,
  }) => {
    const fixture = await fixtures(playwright, 'flow')
    await report(request, fixture.token, healthy())

    // 1. A working dispenser with no refill recorded reads as ready. It does
    //    not read as empty — nobody has ever counted this hopper.
    await openTerminals(authenticatedSettingsPage)
    await authenticatedSettingsPage.expectTerminalDispenserState(fixture.name, 'available')

    await authenticatedSettingsPage.openTerminalDispenserDetails(fixture.name)
    expect(await authenticatedSettingsPage.getDispenserDetail('estimated-left')).toBe('—')
    await authenticatedSettingsPage.closeTerminalDispenserDetails()

    // 2. Somebody fills the hopper and counts what went in — an exact number,
    //    with the warning tier set for this bar in the same breath.
    await authenticatedSettingsPage.openTerminalRefill(fixture.name)
    expect(await authenticatedSettingsPage.getRefillThresholdValue()).toBe('20')
    await authenticatedSettingsPage.recordTerminalRefill(30, 10)

    // 3. It survives a reload, because the estimate is computed on read rather
    //    than rendered once from the response.
    await openTerminals(authenticatedSettingsPage)
    await authenticatedSettingsPage.expectTerminalDispenserState(fixture.name, 'available')
    await authenticatedSettingsPage.openTerminalDispenserDetails(fixture.name)
    expect(await authenticatedSettingsPage.getDispenserDetail('refill-tokens')).toBe('30')
    expect(await authenticatedSettingsPage.getDispenserDetail('estimated-left')).toBe('30')
    expect(await authenticatedSettingsPage.getDispenserDetail('low-threshold')).toBe('10')
    await authenticatedSettingsPage.closeTerminalDispenserDetails()

    // 4. The bar sells 15 tokens. Half the load is gone, and nothing warns yet.
    await sellTokens(request, fixture, 15)
    await openTerminals(authenticatedSettingsPage)
    await authenticatedSettingsPage.expectTerminalDispenserState(fixture.name, 'available')

    // 5. Five more cross the tier. The machine is still working — which is
    //    exactly why the warning is worth anything.
    await sellTokens(request, fixture, 5)
    await openTerminals(authenticatedSettingsPage)
    await authenticatedSettingsPage.expectTerminalDispenserState(fixture.name, 'low')
    // Amber, not red: nothing is broken, and no reason is named because there
    // is no fault to name.
    await authenticatedSettingsPage.expectTerminalDispenserReason(fixture.name, '')

    await authenticatedSettingsPage.openTerminalDispenserDetails(fixture.name)
    expect(await authenticatedSettingsPage.getDispenserDetail('sold-since')).toBe('20')
    expect(await authenticatedSettingsPage.getDispenserDetail('estimated-left')).toBe('10')
    await authenticatedSettingsPage.closeTerminalDispenserDetails()

    // 6. A second refill puts the estimate back on a counted number, and the
    //    count starts again from there.
    await authenticatedSettingsPage.openTerminalRefill(fixture.name)
    await authenticatedSettingsPage.recordTerminalRefill(300)

    await openTerminals(authenticatedSettingsPage)
    await authenticatedSettingsPage.expectTerminalDispenserState(fixture.name, 'available')
    await authenticatedSettingsPage.openTerminalDispenserDetails(fixture.name)
    // 300, not 310: the count replaces the estimate rather than topping it up.
    expect(await authenticatedSettingsPage.getDispenserDetail('estimated-left')).toBe('300')
    await authenticatedSettingsPage.closeTerminalDispenserDetails()
  })

  test('recording a refill clears no fault, and offers nothing that would', async ({
    authenticatedSettingsPage,
    playwright,
    request,
  }) => {
    // The distinction this whole feature has to keep visible: a refill is a
    // fact about the hopper, not an acknowledgement of an alert. Nothing on
    // any surface clears a dispenser fault — a jam is cleared by a power cycle.
    const fixture = await fixtures(playwright, 'jam')
    await report(request, fixture.token, healthy({ state: 'fault', fault: 'jam' }))

    await openTerminals(authenticatedSettingsPage)
    await authenticatedSettingsPage.expectTerminalDispenserReason(fixture.name, 'jam')

    await authenticatedSettingsPage.openTerminalRefill(fixture.name)
    await authenticatedSettingsPage.recordTerminalRefill(400)

    await openTerminals(authenticatedSettingsPage)
    // Still jammed. The count went in; the machine did not change.
    await authenticatedSettingsPage.expectTerminalDispenserState(fixture.name, 'unavailable')
    await authenticatedSettingsPage.expectTerminalDispenserReason(fixture.name, 'jam')

    // And the detail panel still has exactly one button, which closes it.
    await authenticatedSettingsPage.openTerminalDispenserDetails(fixture.name)
    const buttons = await authenticatedSettingsPage.page
      .getByTestId('terminal-dispenser-panel-content')
      .locator('button')
      .evaluateAll((nodes) => nodes.map((n) => n.getAttribute('data-testid')))
    expect(buttons).toEqual(['terminal-dispenser-panel-close'])
  })
})
