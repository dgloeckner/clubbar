import type { APIRequestContext } from '@playwright/test'

import { test, expect } from '../../fixtures/roleRequests'
import { stepUp } from '../../fixtures/stepUp'

/**
 * Terminals report their dispenser's status (#952, ADR-0057).
 *
 * The terminal is the only thing that can reach the dispenser and the only
 * thing that reaches the backend, so every fact about the machine arrives
 * second-hand or not at all. These tests exercise that path whole: a terminal
 * files a report with its bearer token, the backend records it beside
 * `last_sync_at`, and `GET /api/admin/terminals` serves it back with the
 * availability verdict already made.
 *
 * Three properties are worth more than the round trip itself, and each has its
 * own test below:
 *
 * - **Fail-open.** A malformed, oversized or unknown-valued report is answered
 *   `204` and leaves the previous document untouched. A terminal must never
 *   fail a sync cycle over telemetry.
 * - **A protocol mismatch is not "offline".** Nothing is wrong at the machine;
 *   the errand is a deployment one. Folding the two together is epic finding 13.
 * - **The `admin` office alone.** Owner decision, 2026-09-20: the Kassenwart
 *   and the Getränkewart neither see dispenser state nor manage it. The
 *   negative direction is asserted by name, not as "some non-2xx".
 */

const API_BASE = 'http://localhost:8080/api'
const REPORT = `${API_BASE}/sync/terminal-status`

/** A terminal of its own per test, so nothing here depends on another's reports. */
async function createTerminal(authenticatedRequest: APIRequestContext, label: string) {
  const stamp = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
  const response = await authenticatedRequest.post('/api/admin/terminals', {
    data: { ...stepUp(), name: `Dispenser ${label} ${stamp}`, device_id: `device-disp-${label}-${stamp}` },
  })
  expect(response.status()).toBe(201)
  return response.json()
}

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

async function report(request: APIRequestContext, token: string, data: unknown) {
  return request.put(REPORT, { headers: { Authorization: `Bearer ${token}` }, data })
}

async function readTerminal(authenticatedRequest: APIRequestContext, id: string) {
  const response = await authenticatedRequest.get(`/api/admin/terminals/${id}`)
  expect(response.status()).toBe(200)
  return (await response.json()).terminal
}

test.describe('Terminal dispenser status', () => {
  test('a report is recorded and served back with the verdict already made', async ({
    authenticatedRequest,
    request,
  }) => {
    const created = await createTerminal(authenticatedRequest, 'record')

    // Nothing reported yet. That is *unknown*, and must not read as "no
    // dispenser" — which is a report of its own.
    const before = await readTerminal(authenticatedRequest, created.terminal.id)
    expect(before.dispenser_status).toBeNull()
    expect(before.dispenser_status_at).toBeNull()

    const response = await report(request, created.api_token, healthy())
    expect(response.status()).toBe(204)

    const after = await readTerminal(authenticatedRequest, created.terminal.id)
    expect(after.dispenser_status).not.toBeNull()
    expect(after.dispenser_status.configured).toBe(true)
    expect(after.dispenser_status.state).toBe('idle')
    expect(after.dispenser_status.fault).toBe('none')
    expect(after.dispenser_status.firmware).toBe('1.2.0')
    expect(after.dispenser_status.lifetime.dispensed_tokens).toBe(1409)
    // Derived by the backend, never taken from the body.
    expect(after.dispenser_status.available).toBe(true)
    expect(after.dispenser_status.unavailable_reason).toBeNull()
    expect(after.dispenser_status.state_since).toBeTruthy()
    // Its own stamp, beside `last_sync_at` rather than instead of it.
    expect(after.dispenser_status_at).toBeTruthy()
  })

  test('the terminals list carries the report, not only the detail view', async ({
    authenticatedRequest,
    request,
  }) => {
    // The Terminals page reads the list. A DTO that answers on one route and
    // not the other renders an empty column nobody can explain.
    const created = await createTerminal(authenticatedRequest, 'list')
    await report(request, created.api_token, healthy({ state: 'fault', fault: 'jam' }))

    const list = await authenticatedRequest.get('/api/admin/terminals', { params: { per_page: 100 } })
    expect(list.status()).toBe(200)
    const row = (await list.json()).data.find((t: { id: string }) => t.id === created.terminal.id)
    expect(row).toBeTruthy()
    expect(row.dispenser_status.unavailable_reason).toBe('jam')
  })

  test('a report lands on the terminal that sent it and on no other', async ({
    authenticatedRequest,
    request,
  }) => {
    // The terminal identity comes from the bearer token, never the body —
    // otherwise one kiosk could report a jam against another.
    const mine = await createTerminal(authenticatedRequest, 'mine')
    const yours = await createTerminal(authenticatedRequest, 'yours')

    const response = await report(
      request,
      mine.api_token,
      // A body naming the *other* terminal, which must change nothing.
      { terminal_id: yours.terminal.id, ...healthy({ state: 'fault', fault: 'jam' }) },
    )
    expect(response.status()).toBe(204)

    const mineAfter = await readTerminal(authenticatedRequest, mine.terminal.id)
    const yoursAfter = await readTerminal(authenticatedRequest, yours.terminal.id)
    expect(mineAfter.dispenser_status.unavailable_reason).toBe('jam')
    expect(yoursAfter.dispenser_status).toBeNull()
  })

  /**
   * Each of these must be answered `204` and must leave the previously stored
   * document exactly as it was. A bar that cannot sell beer because a
   * peripheral's JSON was malformed is a far worse outcome than a panel one
   * report out of date.
   */
  for (const [label, body] of [
    ['a body that is not an object', 'jam'],
    ['a body with no dispenser envelope', { configured: true, state: 'idle' }],
    ['a state outside the protocol', { dispenser: { configured: true, contact: 'reported', state: 'refilling' } }],
    ['a fault outside the protocol', { dispenser: { configured: true, contact: 'reported', state: 'idle', fault: 'stuck' } }],
    ['a device that answered without saying what it is doing', { dispenser: { configured: true, contact: 'reported' } }],
    ['a report that does not say whether a dispenser is attached', { dispenser: { contact: 'reported', state: 'idle' } }],
  ] as const) {
    test(`${label} is answered 204 and keeps the previous report`, async ({
      authenticatedRequest,
      request,
    }) => {
      const created = await createTerminal(authenticatedRequest, 'failopen')
      await report(request, created.api_token, healthy({ firmware: '1.2.0' }))

      const response = await report(request, created.api_token, body)
      expect(response.status()).toBe(204)

      const after = await readTerminal(authenticatedRequest, created.terminal.id)
      expect(after.dispenser_status.firmware).toBe('1.2.0')
      expect(after.dispenser_status.state).toBe('idle')
    })
  }

  test('an oversized body is dropped and the previous report kept', async ({
    authenticatedRequest,
    request,
  }) => {
    const created = await createTerminal(authenticatedRequest, 'oversize')
    await report(request, created.api_token, healthy())

    const response = await report(
      request,
      created.api_token,
      healthy({ reset_reason: 'x'.repeat(16384) }),
    )
    expect(response.status()).toBe(204)

    const after = await readTerminal(authenticatedRequest, created.terminal.id)
    expect(after.dispenser_status.reset_reason).toBe('Power On')
  })

  /**
   * Epic finding 13. Nothing is wrong at the machine; the errand is a
   * deployment one, and an admin sent looking for a power cable has been sent
   * to the wrong place.
   */
  test('a protocol mismatch names itself and is never reported as offline', async ({
    authenticatedRequest,
    request,
  }) => {
    const created = await createTerminal(authenticatedRequest, 'protocol')

    await report(
      request,
      created.api_token,
      healthy({ contact: 'protocol_mismatch', state: null, protocol: 1, fault: 'none' }),
    )

    const after = await readTerminal(authenticatedRequest, created.terminal.id)
    expect(after.dispenser_status.unavailable_reason).toBe('protocol_mismatch')
    expect(after.dispenser_status.available).toBe(false)
    // The device is not faulty — that is the whole distinction.
    expect(after.dispenser_status.fault).toBe('none')
  })

  test('nothing answering is offline, and says so', async ({ authenticatedRequest, request }) => {
    const created = await createTerminal(authenticatedRequest, 'offline')

    await report(request, created.api_token, healthy({ contact: 'unreachable', state: null }))

    const after = await readTerminal(authenticatedRequest, created.terminal.id)
    expect(after.dispenser_status.unavailable_reason).toBe('offline')
  })

  /**
   * A controller that crashed and came back is `idle` / `none` with an `error`
   * transaction behind it. Availability is `state != fault`; "needs a human" is
   * `fault != none`. Taking this machine out of service would close a bar that
   * is working.
   */
  test('a recovered crash leaves the dispenser in service', async ({
    authenticatedRequest,
    request,
  }) => {
    const created = await createTerminal(authenticatedRequest, 'crash')

    await report(
      request,
      created.api_token,
      healthy({
        state: 'idle',
        fault: 'none',
        reset_reason: 'Software Reset',
        lifetime: { crashes: 1, requested_tokens: 10, dispensed_tokens: 9 },
      }),
    )

    const after = await readTerminal(authenticatedRequest, created.terminal.id)
    expect(after.dispenser_status.available).toBe(true)
    expect(after.dispenser_status.unavailable_reason).toBeNull()
    expect(after.dispenser_status.lifetime.crashes).toBe(1)
  })

  test('a terminal with no dispenser reports that, and it is not "unknown"', async ({
    authenticatedRequest,
    request,
  }) => {
    const created = await createTerminal(authenticatedRequest, 'absent')

    await report(request, created.api_token, { dispenser: { configured: false } })

    const after = await readTerminal(authenticatedRequest, created.terminal.id)
    expect(after.dispenser_status).not.toBeNull()
    expect(after.dispenser_status.configured).toBe(false)
    expect(after.dispenser_status.available).toBe(false)
    expect(after.dispenser_status.unavailable_reason).toBeNull()
  })

  /**
   * A jam re-reported on every sync cycle is one fault that started once. The
   * panel shows it as *since …* and #956's deduplication key is built on it, so
   * a stamp that moved on every report would read "just now" forever.
   */
  test('re-reporting the same condition keeps the stamp it began with', async ({
    authenticatedRequest,
    request,
  }) => {
    const created = await createTerminal(authenticatedRequest, 'since')

    await report(request, created.api_token, healthy({ state: 'fault', fault: 'jam' }))
    const first = await readTerminal(authenticatedRequest, created.terminal.id)

    await report(
      request,
      created.api_token,
      healthy({
        state: 'fault',
        fault: 'jam',
        uptime_s: 90000,
        lifetime: { requested_tokens: 2000, dispensed_tokens: 1990, jams: 4 },
      }),
    )
    const second = await readTerminal(authenticatedRequest, created.terminal.id)

    expect(second.dispenser_status.state_since).toBe(first.dispenser_status.state_since)
    expect(second.dispenser_status.lifetime.jams).toBe(4)

    // A different condition does start a new episode.
    await report(request, created.api_token, healthy({ state: 'idle', fault: 'none' }))
    const third = await readTerminal(authenticatedRequest, created.terminal.id)
    expect(third.dispenser_status.state_since).not.toBe(first.dispenser_status.state_since)
  })

  test('a report without a terminal token is refused', async ({ request }) => {
    const response = await request.put(REPORT, { data: healthy() })
    expect(response.status()).toBe(401)
  })

  test('an admin session is not a terminal and cannot file a report', async ({
    authenticatedRequest,
  }) => {
    // The two auth mechanisms never mix (Pattern 012/013): a session cookie
    // carries no bearer token, and this route only knows bearer tokens.
    const response = await authenticatedRequest.put('/api/sync/terminal-status', { data: healthy() })
    expect(response.status()).toBe(401)
  })

  /**
   * Owner decision, 2026-09-20: dispenser state is the admin office's. The
   * negative direction is the one that matters, and it is asserted **by name** —
   * a bare 403 also matches a CSRF rejection, and a test that accepted either
   * would pass against a completely broken session (Pattern 011).
   */
  test.describe('the admin office alone', () => {
    test('a Kassenwart cannot read a dispenser status', async ({
      authenticatedRequest,
      request,
      kassenwartRequest,
    }) => {
      const created = await createTerminal(authenticatedRequest, 'kassenwart')
      await report(request, created.api_token, healthy({ state: 'fault', fault: 'jam' }))

      for (const path of ['/admin/terminals', `/admin/terminals/${created.terminal.id}`]) {
        const response = await kassenwartRequest.get(`${API_BASE}${path}`)
        expect(response.status()).toBe(403)
        expect((await response.json()).error).toBe('insufficient_role')
      }
    })

    test('a Getränkewart cannot read a dispenser status', async ({
      authenticatedRequest,
      request,
      getraenkewartRequest,
    }) => {
      // The Getränkewart manages the bar's stock, which is the office a token
      // dispenser looks closest to — and is still outside this on every
      // surface, this one included.
      const created = await createTerminal(authenticatedRequest, 'getraenkewart')
      await report(request, created.api_token, healthy({ state: 'fault', fault: 'hopper_error', fault_code: 4 }))

      for (const path of ['/admin/terminals', `/admin/terminals/${created.terminal.id}`]) {
        const response = await getraenkewartRequest.get(`${API_BASE}${path}`)
        expect(response.status()).toBe(403)
        expect((await response.json()).error).toBe('insufficient_role')
      }
    })

    test('an admin reads it', async ({ authenticatedRequest, request }) => {
      // The positive half of the same grant: the refusals above have to be a
      // boundary rather than a broken route.
      const created = await createTerminal(authenticatedRequest, 'admin')
      await report(request, created.api_token, healthy({ state: 'fault', fault: 'hopper_error', fault_code: 4 }))

      const after = await readTerminal(authenticatedRequest, created.terminal.id)
      expect(after.dispenser_status.unavailable_reason).toBe('hopper_error')
      expect(after.dispenser_status.fault_code).toBe(4)
    })
  })
})
