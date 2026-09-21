/**
 * Admin Frontend — a terminal's dispenser, on the page an operator already has
 * open (#954, ADR-0057).
 *
 * Before this, a jam was discovered by a member at the kiosk and the only way
 * for an operator to learn anything was to walk to the bar. These tests drive
 * the whole path: a terminal files a report with its own bearer token, the
 * backend records it, the Terminals tab renders it, and a *second* report
 * changes what the cell says.
 *
 * What is asserted, and why each one is not decoration:
 *
 * - **The four display states are distinguishable.** `unknown` (never
 *   reported) must never read as "no dispenser", which is a report of its own.
 * - **The age is always beside the status.** A terminal that is off reports
 *   nothing and a dropped report freezes the cell silently, so a status with
 *   no age is a claim nobody can check — ADR-0057's explicit instruction.
 * - **A fault is dated to when it began.** A jam re-reported every thirty
 *   seconds for an hour is one fault that started an hour ago.
 * - **A protocol mismatch is not "offline".** Nothing is wrong at the machine;
 *   folding the two together sent somebody looking for a power cable (epic
 *   finding 13).
 * - **No clear, reset or acknowledge affordance exists.** The device has no
 *   reset route and a jam is cleared by a power cycle (owner decision 3): a
 *   button would change a screen and not a hopper.
 * - **The `admin` office alone**, with the refusal asserted *by name* — a bare
 *   403 also matches a CSRF rejection.
 *
 * Implements E2E Testing Patterns:
 * - Pattern 001: Test Data Isolation (a terminal of its own per test)
 * - Pattern 002: Authentication Isolation (never demotes the seeded admin)
 * - Pattern 005: Using Test IDs
 * - Pattern 006: Page Object Model (SettingsPage)
 * - Pattern 008: Playwright Assertions
 * - Pattern 011: Testing a Role You Are Not
 */

import type { APIRequestContext } from '@playwright/test'

import { test, expect } from '../../fixtures/pageObjects'
import { createIsolatedAdmin, signInAndEnroll } from '../../utils/isolatedAdmin'
import { loginAs } from '../../utils/csrf'
import { stepUp } from '../../fixtures/stepUp'
import { TEST_CREDENTIALS } from '../../config/test-credentials'
import { SettingsPage } from '../../pages/SettingsPage'
import { MainLayoutPage } from '../../pages/MainLayoutPage'

type Playwright = typeof import('playwright-core')

const API_BASE = 'http://localhost:8080/api'

/**
 * Any well-formed id will do for the detail route: the role check is
 * default-deny and runs before the lookup, so a lesser office must never get
 * as far as "not found" (ADR-0044 rule 1).
 */
const ANY_TERMINAL_ID = '123e4567-e89b-12d3-a456-426614174000'

/**
 * A terminal of its own per test (Pattern 001), created through the same API
 * the panel uses, so the row under test cannot be another worker's.
 */
async function createTerminal(playwright: Playwright, label: string) {
  const ctx = await loginAs(playwright, TEST_CREDENTIALS.admin.email, TEST_CREDENTIALS.admin.password)
  try {
    const stamp = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
    const response = await ctx.post(`${API_BASE}/admin/terminals`, {
      data: { ...stepUp(), name: `Disp UI ${label} ${stamp}`, device_id: `dev-dispui-${label}-${stamp}` },
    })
    expect(response.status()).toBe(201)
    const body = await response.json()
    return { name: body.terminal.name as string, id: body.terminal.id as string, token: body.api_token as string }
  } finally {
    await ctx.dispose()
  }
}

/** The document a healthy controller sends — `filtered_pulses` absent, as the mock leaves it. */
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
      rssi: -61,
      uptime_s: 86400,
      reset_reason: 'Power On',
      lifetime: {
        requested_tokens: 1412,
        dispensed_tokens: 1409,
        jams: 3,
        crashes: 0,
        overrun_tokens: 1,
      },
      pending_reconciliations: 0,
      manual_reconciliations: 0,
      observed_at: new Date().toISOString(),
      ...overrides,
    },
  }
}

/** File a report as the terminal itself — bearer token, the real route. */
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

test.describe('Terminal dispenser status in the admin panel', () => {
  test('a report reaches the row, and a second report changes it', async ({
    authenticatedSettingsPage,
    playwright,
    request,
  }) => {
    const terminal = await createTerminal(playwright, 'flow')

    // 1. Nothing reported yet — the cell makes no claim, and says so rather
    //    than showing a blank or, worse, "no dispenser".
    await openTerminals(authenticatedSettingsPage)
    await authenticatedSettingsPage.expectTerminalDispenserState(terminal.name, 'unknown')
    expect(await authenticatedSettingsPage.getTerminalDispenserAge(terminal.name)).toBeNull()

    // 2. A healthy report arrives from the terminal itself.
    await report(request, terminal.token, healthy())
    await openTerminals(authenticatedSettingsPage)
    await authenticatedSettingsPage.expectTerminalDispenserState(terminal.name, 'available')
    await authenticatedSettingsPage.expectTerminalDispenserReason(terminal.name, '')
    // The age is required beside the status, always.
    expect(await authenticatedSettingsPage.getTerminalDispenserAge(terminal.name)).toBeTruthy()

    // 3. The machine jams. Same terminal, same token, new document.
    await report(request, terminal.token, healthy({ state: 'fault', fault: 'jam' }))
    await openTerminals(authenticatedSettingsPage)
    await authenticatedSettingsPage.expectTerminalDispenserState(terminal.name, 'unavailable')
    await authenticatedSettingsPage.expectTerminalDispenserReason(terminal.name, 'jam')
    // Dated to when the episode began, not to the last report about it.
    expect(await authenticatedSettingsPage.getTerminalDispenserSince(terminal.name)).toBeTruthy()

    // 4. And it recovers. A cell that could only ever go red would be useless.
    await report(request, terminal.token, healthy())
    await openTerminals(authenticatedSettingsPage)
    await authenticatedSettingsPage.expectTerminalDispenserState(terminal.name, 'available')
  })

  test('a terminal with no dispenser says so, rather than reading as unknown', async ({
    authenticatedSettingsPage,
    playwright,
    request,
  }) => {
    // `configured: false` is a report. It is the whole reason the panel can
    // distinguish "there is nothing attached" from "nobody ever told us".
    const terminal = await createTerminal(playwright, 'none')
    await report(request, terminal.token, { dispenser: { configured: false } })

    await openTerminals(authenticatedSettingsPage)
    await authenticatedSettingsPage.expectTerminalDispenserState(terminal.name, 'none')
    expect(await authenticatedSettingsPage.getTerminalDispenserAge(terminal.name)).toBeTruthy()
  })

  test('a protocol mismatch is its own errand, not an unreachable machine', async ({
    authenticatedSettingsPage,
    playwright,
    request,
  }) => {
    const terminal = await createTerminal(playwright, 'proto')
    await report(request, terminal.token, healthy({ contact: 'protocol_mismatch', protocol: 1, state: null }))

    await openTerminals(authenticatedSettingsPage)
    await authenticatedSettingsPage.expectTerminalDispenserState(terminal.name, 'unavailable')
    // Emphatically not `offline` — that conflation is epic finding 13.
    await authenticatedSettingsPage.expectTerminalDispenserReason(terminal.name, 'protocol_mismatch')
  })

  test('an unreachable dispenser drops its counters rather than reporting zeroes', async ({
    authenticatedSettingsPage,
    playwright,
    request,
  }) => {
    const terminal = await createTerminal(playwright, 'dark')
    await report(request, terminal.token, {
      dispenser: {
        configured: true,
        contact: 'unreachable',
        fault: 'none',
        fault_code: 0,
        pending_reconciliations: 2,
        manual_reconciliations: 1,
      },
    })

    await openTerminals(authenticatedSettingsPage)
    await authenticatedSettingsPage.expectTerminalDispenserReason(terminal.name, 'offline')

    await authenticatedSettingsPage.openTerminalDispenserDetails(terminal.name)
    // Absent, not zero: "0 jams" from a machine nobody reached is a
    // measurement nobody made.
    expect(await authenticatedSettingsPage.getDispenserDetail('jams')).toBe('—')
    expect(await authenticatedSettingsPage.getDispenserDetail('firmware')).toBe('—')
    // The reconciliation counts survive — they are rows in the terminal's own
    // database, not readings from the device.
    expect(await authenticatedSettingsPage.getDispenserDetail('pending')).toBe('2')
    expect(await authenticatedSettingsPage.getDispenserDetail('manual')).toBe('1')
    await authenticatedSettingsPage.closeTerminalDispenserDetails()
  })

  test('the detail shows the counters and offers nothing to press at the machine', async ({
    authenticatedSettingsPage,
    playwright,
    request,
  }) => {
    const terminal = await createTerminal(playwright, 'detail')
    await report(request, terminal.token, healthy({ state: 'fault', fault: 'hopper_error', fault_code: 5 }))

    await openTerminals(authenticatedSettingsPage)
    await authenticatedSettingsPage.expectTerminalDispenserReason(terminal.name, 'hopper_error')
    await authenticatedSettingsPage.openTerminalDispenserDetails(terminal.name)

    expect(await authenticatedSettingsPage.getDispenserDetail('firmware')).toBe('1.2.0')
    expect(await authenticatedSettingsPage.getDispenserDetail('requested')).toBe('1412')
    expect(await authenticatedSettingsPage.getDispenserDetail('dispensed')).toBe('1409')
    // `filtered_pulses` is absent against the mock even on a healthy report.
    expect(await authenticatedSettingsPage.getDispenserDetail('filtered')).toBe('—')
    // The remedy is a power cycle, spelled out — and it is all there is.
    await expect(authenticatedSettingsPage.page.getByTestId('terminal-dispenser-panel-remedy')).toContainText(
      'Strom',
    )

    // Owner decision 3: no reset, no clear, no acknowledge — anywhere.
    const panel = authenticatedSettingsPage.page.getByTestId('terminal-dispenser-panel-content')
    const buttons = await panel.locator('button').evaluateAll((nodes) =>
      nodes.map((n) => n.getAttribute('data-testid')),
    )
    expect(buttons).toEqual(['terminal-dispenser-panel-close'])
    await authenticatedSettingsPage.closeTerminalDispenserDetails()
  })
})

test.describe('Dispenser state belongs to the admin office alone', () => {
  // Signed out: each test signs in as the office it is about. The seeded admin
  // is never demoted — that is observable, mid-run, by every other spec
  // authenticated as it (Pattern 002).
  test.use({ storageState: { cookies: [], origins: [] } })

  /**
   * Owner decision 8, on the panel's own surface and from the panel's own
   * session.
   *
   * Both halves matter and neither implies the other: the door is hidden
   * *and* the route behind it refuses. The refusal is asserted **by name** —
   * a bare 403 also matches a CSRF rejection, and a test accepting either
   * would pass against a completely broken session (Pattern 011).
   */
  // The Getränkewart is one level further out: `/settings` itself is
  // TREASURY, so that office meets the refusal screen rather than a page with
  // one tab missing. Both are "no dispenser here"; only the door differs.
  for (const [office, landing, reachesSettings] of [
    ['kassenwart', '/dashboard', true],
    ['getraenkewart', '/products', false],
  ] as const) {
    test(`a ${office} finds no dispenser anywhere, and is refused by name`, async ({
      loginPage,
      page,
      playwright,
    }) => {
      const { email, password } = await createIsolatedAdmin(playwright, `disp-${office}`, [office])
      await signInAndEnroll(loginPage, page, email, password, landing)

      await page.goto('/settings')

      const layout = new MainLayoutPage(page)
      if (reachesSettings) {
        await expect(page.locator('[data-testid="settings-page"]')).toBeVisible()
        // The Terminals tab is `admin`-only in `SETTINGS_TAB_ROLES`, so the
        // dispenser rides a door this office is never shown.
        const settings = new SettingsPage(page)
        expect(await settings.getVisibleTabTestIds()).not.toContain('settings-tab-terminals')
      } else {
        await layout.expectInsufficientRoleScreen()
      }

      // Whichever door it was, no cell rendered anywhere behind it.
      await expect(page.locator('[data-testid^="settings-terminal-dispenser-"]')).toHaveCount(0)

      // And the data behind it is refused to this very session, by name.
      for (const path of ['/api/admin/terminals', `/api/admin/terminals/${ANY_TERMINAL_ID}`]) {
        const response = await page.request.get(`http://localhost:8080${path}`)
        expect(response.status(), `${office} on ${path}`).toBe(403)
        expect((await response.json()).error, `${office} on ${path}`).toBe('insufficient_role')
      }
    })
  }
})
