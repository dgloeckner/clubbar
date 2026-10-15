import { randomUUID } from 'node:crypto'

import type { APIRequestContext } from '@playwright/test'

import { test, expect } from '../../fixtures/roleRequests'
import { stepUp } from '../../fixtures/stepUp'

/**
 * The hopper's fill estimate, end to end (#955, ADR-0058).
 *
 * The dispenser cannot warn us — its *empty* switch is a factory option this
 * unit does not have — so the warning is arithmetic: a counted refill, minus
 * the token purchases that terminal booked since. These tests drive that whole
 * path with real requests: an admin records a count, a terminal syncs sales
 * with its own bearer token, and `GET /api/admin/terminals` serves the estimate
 * back.
 *
 * Four properties matter more than the round trip, and each has a test:
 *
 * - **Only this terminal's token sales count.** Another till's hopper is
 *   another hopper, and a beer is not a token.
 * - **A sale is counted by when the bar sold it**, not by when it synced. An
 *   offline terminal uploading yesterday's sales after today's refill must not
 *   subtract them from today's load.
 * - **A refill replaces the total**, and the audit row carries the estimate it
 *   replaced — the only place the drift is ever written down.
 * - **The `admin` office alone**, asserted by name in both directions. A bare
 *   403 also matches a CSRF rejection, and a test that accepted either would
 *   pass against a completely broken session (Pattern 011).
 */

const API_BASE = 'http://localhost:8080/api'

async function createTerminal(authenticatedRequest: APIRequestContext, label: string) {
  const stamp = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
  const response = await authenticatedRequest.post('/api/admin/terminals', {
    data: { ...stepUp(), name: `Fill ${label} ${stamp}`, device_id: `device-fill-${label}-${stamp}` },
  })
  expect(response.status(), await response.text()).toBe(201)
  return response.json()
}

/** A product that comes out of the hopper, and one that does not. */
async function createProduct(authenticatedRequest: APIRequestContext, requiresDispenser: boolean) {
  const id = randomUUID().replace(/-/g, '').slice(0, 10)
  const category = await authenticatedRequest.post('/api/admin/categories', {
    data: { names: { de: `Kat${id}`, en: `Cat${id}` } },
  })
  expect(category.status()).toBe(201)

  const response = await authenticatedRequest.post('/api/admin/products', {
    data: {
      names: { de: `Token ${id}`, en: `Token ${id}` },
      category_id: (await category.json()).id,
      price_cents: 100,
      requires_dispenser: requiresDispenser,
    },
  })
  expect(response.status(), await response.text()).toBe(201)
  return response.json()
}

async function createMember(authenticatedRequest: APIRequestContext) {
  const id = randomUUID().replace(/-/g, '').slice(0, 8)
  const response = await authenticatedRequest.post('/api/admin/members', {
    data: {
      first_name: 'Fill',
      last_name: `Tester${id}`,
      email: `fill-${id}@example.com`,
      preferred_language: 'de',
      date_of_birth: '1980-05-17',
      iban: 'DE89370400440532013000',
      mandate_signed_at: '2024-01-01',
    },
  })
  expect(response.status(), await response.text()).toBe(201)
  return response.json()
}

/**
 * One row per token — the shape the terminal really writes: a dispense of five
 * tokens is five transactions, not one carrying a quantity.
 */
async function sellTokens(
  request: APIRequestContext,
  token: string,
  memberId: string,
  productId: string,
  count: number,
  occurredAt: Date = new Date(),
) {
  const transactions = Array.from({ length: count }, () => ({
    id: randomUUID(),
    member_id: memberId,
    product_id: productId,
    amount_cents: 100,
    created_at: occurredAt.toISOString().replace(/\.\d{3}Z$/, 'Z'),
  }))

  const response = await request.post(`${API_BASE}/sync/transactions`, {
    headers: { Authorization: `Bearer ${token}` },
    data: { transactions },
  })
  expect(response.ok(), await response.text()).toBeTruthy()
  return transactions
}

async function refill(authenticatedRequest: APIRequestContext, terminalId: string, tokens: number) {
  const response = await authenticatedRequest.post(`/api/admin/terminals/${terminalId}/dispenser-refill`, {
    data: { tokens },
  })
  expect(response.status(), await response.text()).toBe(200)
  return (await response.json()).dispenser_fill
}

async function readFill(authenticatedRequest: APIRequestContext, terminalId: string) {
  const response = await authenticatedRequest.get(`/api/admin/terminals/${terminalId}`)
  expect(response.status()).toBe(200)
  return (await response.json()).terminal.dispenser_fill
}

test.describe('Terminal dispenser fill estimate', () => {
  test('a refill anchors the estimate and this terminal\'s token sales draw it down', async ({
    authenticatedRequest,
    request,
  }) => {
    const mine = await createTerminal(authenticatedRequest, 'mine')
    const other = await createTerminal(authenticatedRequest, 'other')
    const tokenProduct = await createProduct(authenticatedRequest, true)
    const beer = await createProduct(authenticatedRequest, false)
    const member = await createMember(authenticatedRequest)

    // Nothing recorded yet: *no estimate*, which is not "0 tokens left".
    const before = await readFill(authenticatedRequest, mine.terminal.id)
    expect(before.refilled_at).toBeNull()
    expect(before.estimated_left).toBeNull()
    expect(before.sold_since).toBeNull()
    // The threshold is a stored setting and exists from the start.
    expect(before.low_threshold).toBe(20)

    const recorded = await refill(authenticatedRequest, mine.terminal.id, 400)
    expect(recorded.refill_tokens).toBe(400)
    expect(recorded.estimated_left).toBe(400)

    await sellTokens(request, mine.api_token, member.id, tokenProduct.id, 7)
    // A beer at the same terminal is not a token…
    await sellTokens(request, mine.api_token, member.id, beer.id, 3)
    // …and another terminal's hopper is another hopper.
    await sellTokens(request, other.api_token, member.id, tokenProduct.id, 5)

    const after = await readFill(authenticatedRequest, mine.terminal.id)
    expect(after.sold_since).toBe(7)
    expect(after.estimated_left).toBe(393)

    // The list carries it too — the Terminals page reads the list, and a DTO
    // that answers on one route and not the other renders an empty column.
    const list = await authenticatedRequest.get('/api/admin/terminals', { params: { per_page: 100 } })
    const row = (await list.json()).data.find((t: { id: string }) => t.id === mine.terminal.id)
    expect(row.dispenser_fill.estimated_left).toBe(393)
  })

  test('a sale made before the refill and synced after it is not subtracted', async ({
    authenticatedRequest,
    request,
  }) => {
    // The offline case, which is the ordinary one: the terminal sells all
    // evening without a network and uploads in the morning, after somebody has
    // already filled the hopper. Those tokens came out of the old load.
    const created = await createTerminal(authenticatedRequest, 'offline')
    const tokenProduct = await createProduct(authenticatedRequest, true)
    const member = await createMember(authenticatedRequest)

    const yesterday = new Date(Date.now() - 24 * 60 * 60 * 1000)

    await refill(authenticatedRequest, created.terminal.id, 200)
    await sellTokens(request, created.api_token, member.id, tokenProduct.id, 4, yesterday)

    const fill = await readFill(authenticatedRequest, created.terminal.id)
    expect(fill.sold_since).toBe(0)
    expect(fill.estimated_left).toBe(200)
  })

  test('a storno does not put a token back in the hopper', async ({ authenticatedRequest, request }) => {
    const created = await createTerminal(authenticatedRequest, 'storno')
    const tokenProduct = await createProduct(authenticatedRequest, true)
    const member = await createMember(authenticatedRequest)

    await refill(authenticatedRequest, created.terminal.id, 50)
    const sold = await sellTokens(request, created.api_token, member.id, tokenProduct.id, 2)

    const reversed = await authenticatedRequest.post(`/api/admin/transactions/${sold[0].id}/storno`, {
      data: { reason: 'Falsches Mitglied' },
    })
    expect(reversed.ok(), await reversed.text()).toBeTruthy()

    // The member got their euro back; the token is in their pocket.
    const fill = await readFill(authenticatedRequest, created.terminal.id)
    expect(fill.sold_since).toBe(2)
    expect(fill.estimated_left).toBe(48)
  })

  test('a second refill replaces the total and the audit row keeps the drift', async ({
    authenticatedRequest,
    request,
  }) => {
    const created = await createTerminal(authenticatedRequest, 'audit')
    const tokenProduct = await createProduct(authenticatedRequest, true)
    const member = await createMember(authenticatedRequest)

    await refill(authenticatedRequest, created.terminal.id, 10)
    await sellTokens(request, created.api_token, member.id, tokenProduct.id, 6)
    // The anchor has second precision, so a refill recorded inside the same
    // second as the sales would count them against the new load. A second's
    // wait is the whole of that ambiguity, and it is the real shape of the act:
    // somebody walks to the machine before they type the number.
    await new Promise((resolve) => setTimeout(resolve, 1100))
    await refill(authenticatedRequest, created.terminal.id, 500)

    const fill = await readFill(authenticatedRequest, created.terminal.id)
    expect(fill.refill_tokens).toBe(500)
    // Replaced, not added to: 500 in the hopper, not 500 plus the 4 the
    // arithmetic still believed in. And the count restarts from the new anchor.
    expect(fill.sold_since).toBe(0)
    expect(fill.estimated_left).toBe(500)

    const audit = await authenticatedRequest.get(
      `/api/admin/audit-log?filters[entity_id]=${created.terminal.id}` +
        '&filters[action]=terminal_dispenser_refilled',
    )
    expect(audit.status()).toBe(200)
    const entry = (await audit.json()).data.find(
      (e: { new_values?: { refill_tokens?: number } }) => e.new_values?.refill_tokens === 500,
    )
    expect(entry).toBeDefined()
    // The pair nothing else writes down: what the arithmetic believed, beside
    // what somebody counted. 4 against 500 is a hopper that was nowhere near
    // empty, or an estimate that had drifted — only this row can say which.
    expect(entry.old_values.estimated_left).toBe(4)
    expect(entry.new_values.refill_tokens).toBe(500)
  })

  test('the threshold rides the ordinary terminal update', async ({ authenticatedRequest }) => {
    const created = await createTerminal(authenticatedRequest, 'threshold')

    const updated = await authenticatedRequest.patch(`/api/admin/terminals/${created.terminal.id}`, {
      data: { dispenser_low_threshold: 35 },
    })
    expect(updated.status(), await updated.text()).toBe(200)

    expect((await readFill(authenticatedRequest, created.terminal.id)).low_threshold).toBe(35)
  })

  test('a count that is not a whole number of tokens is refused', async ({ authenticatedRequest }) => {
    const created = await createTerminal(authenticatedRequest, 'validation')

    for (const body of [{ tokens: -1 }, { tokens: 'viele' }, { tokens: 12.5 }, {}]) {
      const response = await authenticatedRequest.post(
        `/api/admin/terminals/${created.terminal.id}/dispenser-refill`,
        { data: body },
      )
      expect(response.status()).toBe(422)
    }

    // Nothing was recorded by any of them.
    expect((await readFill(authenticatedRequest, created.terminal.id)).refilled_at).toBeNull()
  })

  /**
   * Owner decision, 2026-09-20: the dispenser is the admin office's, and the
   * estimate with it. Asserted **by name** in both directions — a bare 403 also
   * matches a CSRF rejection.
   */
  test.describe('the admin office alone', () => {
    test('a Kassenwart can neither read the estimate nor record a refill', async ({
      authenticatedRequest,
      kassenwartRequest,
    }) => {
      const created = await createTerminal(authenticatedRequest, 'kassenwart')
      await refill(authenticatedRequest, created.terminal.id, 400)

      for (const path of ['/admin/terminals', `/admin/terminals/${created.terminal.id}`]) {
        const read = await kassenwartRequest.get(`${API_BASE}${path}`)
        expect(read.status()).toBe(403)
        expect((await read.json()).error).toBe('insufficient_role')
      }

      const write = await kassenwartRequest.post(
        `${API_BASE}/admin/terminals/${created.terminal.id}/dispenser-refill`,
        { data: { tokens: 999 } },
      )
      expect(write.status()).toBe(403)
      expect((await write.json()).error).toBe('insufficient_role')

      const threshold = await kassenwartRequest.patch(`${API_BASE}/admin/terminals/${created.terminal.id}`, {
        data: { dispenser_low_threshold: 99 },
      })
      expect(threshold.status()).toBe(403)
      expect((await threshold.json()).error).toBe('insufficient_role')

      // And the refusal really refused: nothing moved.
      const fill = await readFill(authenticatedRequest, created.terminal.id)
      expect(fill.refill_tokens).toBe(400)
      expect(fill.low_threshold).toBe(20)
    })

    test('a Getränkewart can neither read the estimate nor record a refill', async ({
      authenticatedRequest,
      getraenkewartRequest,
    }) => {
      // The bar stock office is the one a hopper full of tokens looks closest
      // to, and it is still outside this on every surface.
      const created = await createTerminal(authenticatedRequest, 'getraenkewart')
      await refill(authenticatedRequest, created.terminal.id, 400)

      for (const path of ['/admin/terminals', `/admin/terminals/${created.terminal.id}`]) {
        const read = await getraenkewartRequest.get(`${API_BASE}${path}`)
        expect(read.status()).toBe(403)
        expect((await read.json()).error).toBe('insufficient_role')
      }

      const write = await getraenkewartRequest.post(
        `${API_BASE}/admin/terminals/${created.terminal.id}/dispenser-refill`,
        { data: { tokens: 999 } },
      )
      expect(write.status()).toBe(403)
      expect((await write.json()).error).toBe('insufficient_role')

      const threshold = await getraenkewartRequest.patch(
        `${API_BASE}/admin/terminals/${created.terminal.id}`,
        { data: { dispenser_low_threshold: 99 } },
      )
      expect(threshold.status()).toBe(403)
      expect((await threshold.json()).error).toBe('insufficient_role')

      const fill = await readFill(authenticatedRequest, created.terminal.id)
      expect(fill.refill_tokens).toBe(400)
      expect(fill.low_threshold).toBe(20)
    })

    test('a terminal token cannot record a refill for itself', async ({
      authenticatedRequest,
      request,
    }) => {
      // The refill is a *counted* number. A device that could write it would be
      // inventing the one figure in this feature that only a human can supply.
      const created = await createTerminal(authenticatedRequest, 'terminal-token')

      const response = await request.post(
        `${API_BASE}/admin/terminals/${created.terminal.id}/dispenser-refill`,
        { headers: { Authorization: `Bearer ${created.api_token}` }, data: { tokens: 400 } },
      )
      expect(response.status()).toBe(401)
    })
  })
})
