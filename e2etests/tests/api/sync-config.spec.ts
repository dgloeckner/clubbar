import { test } from '../../fixtures/auth.fixture'
import { expect, request as playwrightRequest } from '@playwright/test'

/**
 * E2E Test: `GET /api/sync/config` — the club policy a terminal caches
 * (ADR-0047, UC-T12 E2)
 *
 * UC-T12 has said "maximum balance configured in backend (synced to terminal)"
 * since it was written. This endpoint is where that stops being aspirational.
 *
 * Two channels carry a limit to a terminal on purpose: the club default here,
 * a member's own ceiling on their row in `/sync/members`. A pre-resolved
 * per-member figure was rejected because `/sync/members` is a delta on
 * `updated_at` — a club setting touches no member row, so the new default
 * would reach each terminal one member at a time, whenever those members next
 * changed for some unrelated reason.
 *
 * Serial, because it moves the singleton `credit_limit_config` row and puts it
 * back (same reasoning as `mail-config.spec.ts`).
 */
test.describe.serial('Terminal config sync', () => {
  const API_BASE = 'http://localhost:8080/api'
  const CONFIG_URL = `${API_BASE}/admin/credit-limit-config`
  const SYNC_URL = `${API_BASE}/sync/config`

  let original: { default_limit_cents: number; warn_threshold_percent: number } | null = null

  test.beforeAll(async ({ authenticatedRequest }) => {
    original = await (await authenticatedRequest.get(CONFIG_URL)).json()
  })

  test.afterAll(async ({ authenticatedRequest }) => {
    if (!original) return
    await authenticatedRequest.patch(CONFIG_URL, { data: original })
  })

  test('a terminal reads the club policy with its bearer token', async ({
    authenticatedTerminalRequest,
    authenticatedRequest,
  }) => {
    await authenticatedRequest.patch(CONFIG_URL, {
      data: { default_limit_cents: 15_000, warn_threshold_percent: 70 },
    })

    const response = await authenticatedTerminalRequest.get(SYNC_URL)
    expect(response.ok(), await response.text()).toBeTruthy()

    const { credit_limit } = await response.json()
    expect(credit_limit.default_limit_cents).toBe(15_000)
    expect(credit_limit.warn_threshold_percent).toBe(70)
    // Derived and sent, so the terminal never reproduces the rounding rule.
    expect(credit_limit.warn_at_cents).toBe(10_500)
  })

  /**
   * The boundary cent, by integer division: 70 % of 3,333 is 2,333.1, and both
   * sides have to land on 2,333. A terminal that rounded the other way would
   * warn a member the dashboard has not listed, or vice versa.
   */
  test('the warning threshold is the same whole cent the backend uses', async ({
    authenticatedTerminalRequest,
    authenticatedRequest,
  }) => {
    await authenticatedRequest.patch(CONFIG_URL, {
      data: { default_limit_cents: 3_333, warn_threshold_percent: 70 },
    })

    const { credit_limit } = await (await authenticatedTerminalRequest.get(SYNC_URL)).json()
    expect(credit_limit.warn_at_cents).toBe(2_333)
  })

  test('a club that caps nobody says so rather than going quiet', async ({
    authenticatedTerminalRequest,
    authenticatedRequest,
  }) => {
    await authenticatedRequest.patch(CONFIG_URL, {
      data: { default_limit_cents: 0, warn_threshold_percent: 80 },
    })

    const { credit_limit } = await (await authenticatedTerminalRequest.get(SYNC_URL)).json()
    expect(credit_limit.default_limit_cents).toBe(0)
    expect(credit_limit.warn_at_cents).toBe(0)
  })

  test('without a token it answers 401', async () => {
    const anonymous = await playwrightRequest.newContext()
    try {
      expect((await anonymous.get(SYNC_URL)).status()).toBe(401)
      expect((await anonymous.get(SYNC_URL, { headers: { Authorization: 'Bearer not-a-token' } })).status()).toBe(401)
    } finally {
      await anonymous.dispose()
    }
  })

  /**
   * The rule this endpoint exists to keep: `/health` carries only what a
   * terminal needs *before* it can authenticate — reachability and the
   * ADR-0035 pairing identity. Club policy is not that.
   */
  test('the health check gains no club policy', async () => {
    const anonymous = await playwrightRequest.newContext()
    try {
      const health = await (await anonymous.get(`${API_BASE}/health`)).json()

      expect(health).not.toHaveProperty('credit_limit')
      expect(Object.keys(health).filter((key) => key.includes('limit'))).toEqual([])
    } finally {
      await anonymous.dispose()
    }
  })
})
