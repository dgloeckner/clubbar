import { test } from '../../fixtures/auth.fixture'
import { expect } from '@playwright/test'
import { randomUUID } from 'node:crypto'

/**
 * E2E Test: a member's own credit ceiling, on the member API (ADR-0047, #558)
 *
 * One field, three meanings, and this file exists to keep them apart through
 * the whole stack:
 *
 * | Request carries | Meaning |
 * |---|---|
 * | field omitted | leave the override exactly as it is |
 * | `null` or `""` | clear it — back to the club default |
 * | `0` | a deliberate "no ceiling for this member" |
 *
 * `0` is not a way to clear anything. A UI that serialises an emptied input to
 * `0` grants that member unlimited credit, silently — which is why every case
 * below reads the stored value back rather than trusting the response.
 *
 * Pattern 001: a member per test, never a shared one.
 */
test.describe('Member credit limit override API', () => {
  const API_BASE = 'http://localhost:8080/api'

  /** A member body nothing else in the suite can collide with (Pattern 001). */
  const uniqueMemberBody = (overrides: Record<string, unknown> = {}) => {
    const id = randomUUID().replace(/-/g, '').slice(0, 10)

    return {
      first_name: `Limit${id}`,
      last_name: `Member${id}`,
      email: `limit-member-${id}@example.com`,
      date_of_birth: '1985-06-15',
      preferred_language: 'de',
      ...overrides,
    }
  }

  const stored = async (request: { get: (url: string) => Promise<{ json: () => Promise<Record<string, unknown>> }> }, id: string) =>
    (await (await request.get(`${API_BASE}/admin/members/${id}`)).json()).credit_limit_cents

  test('a member created without one follows the club default', async ({ authenticatedRequest }) => {
    const created = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: uniqueMemberBody(),
    })
    expect(created.status(), await created.text()).toBe(201)
    const member = await created.json()

    expect(member.credit_limit_cents).toBeNull()
    expect(await stored(authenticatedRequest, member.id)).toBeNull()
  })

  test('a ceiling given at creation is stored and read back', async ({ authenticatedRequest }) => {
    const created = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: uniqueMemberBody({ credit_limit_cents: 20_000 }),
    })
    const member = await created.json()

    expect(await stored(authenticatedRequest, member.id)).toBe(20_000)
  })

  test('the three request shapes mean three different things', async ({ authenticatedRequest }) => {
    const member = await (
      await authenticatedRequest.post(`${API_BASE}/admin/members`, {
        data: uniqueMemberBody({ credit_limit_cents: 5_000 }),
      })
    ).json()
    const patch = (data: Record<string, unknown>) =>
      authenticatedRequest.patch(`${API_BASE}/admin/members/${member.id}`, { data })

    // Omitted: untouched.
    await patch({ last_name: 'Corrected' })
    expect(await stored(authenticatedRequest, member.id)).toBe(5_000)

    // Explicit null: back to the club default.
    await patch({ credit_limit_cents: null })
    expect(await stored(authenticatedRequest, member.id)).toBeNull()

    // Blank string, which is what a cleared form field sends.
    await patch({ credit_limit_cents: 7_500 })
    await patch({ credit_limit_cents: '' })
    expect(await stored(authenticatedRequest, member.id)).toBeNull()

    // Zero: a deliberate absence of a ceiling, not a clear.
    await patch({ credit_limit_cents: 0 })
    expect(await stored(authenticatedRequest, member.id)).toBe(0)
  })

  test('an out-of-range or non-integer ceiling is refused on both paths', async ({
    authenticatedRequest,
  }) => {
    for (const value of [-1, 10_000_001, '100.50']) {
      const created = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
        data: uniqueMemberBody({ credit_limit_cents: value }),
      })
      expect(created.status(), `create with ${value}`).toBe(422)
    }

    const member = await (
      await authenticatedRequest.post(`${API_BASE}/admin/members`, { data: uniqueMemberBody() })
    ).json()

    for (const value of [-1, 10_000_001, '100.50']) {
      const updated = await authenticatedRequest.patch(`${API_BASE}/admin/members/${member.id}`, {
        data: { credit_limit_cents: value },
      })
      expect(updated.status(), `update with ${value}`).toBe(422)
    }
  })

  /**
   * A member singled out is a decision worth a line in the audit log; a member
   * following the club default is the ordinary case and worth none.
   */
  test('an override is audited when it is set, and absent when it is not', async ({
    authenticatedRequest,
  }) => {
    const plain = (
      await (await authenticatedRequest.post(`${API_BASE}/admin/members`, { data: uniqueMemberBody() })).json()
    ).id
    const singledOut = (
      await (
        await authenticatedRequest.post(`${API_BASE}/admin/members`, {
          data: uniqueMemberBody({ credit_limit_cents: 7_500 }),
        })
      ).json()
    ).id

    // Filtered by `entity_id` rather than paged through: this suite writes to
    // the audit log constantly, so a page of the newest hundred entries is not
    // a place a specific member's row can be relied on to appear.
    const entriesFor = async (id: string) => {
      const log = await (
        await authenticatedRequest.get(`${API_BASE}/admin/audit-log`, {
          params: { entity_id: id, per_page: 50 },
        })
      ).json()
      return log.data as Array<{ new_values?: Record<string, unknown> }>
    }

    expect((await entriesFor(singledOut)).length, 'the create is logged at all').toBeGreaterThan(0)
    expect(
      (await entriesFor(plain)).some((e) => 'credit_limit_cents' in (e.new_values ?? {})),
      'a member on the club default needs no line about a limit',
    ).toBe(false)
    expect(
      (await entriesFor(singledOut)).some((e) => 'credit_limit_cents' in (e.new_values ?? {})),
      'a member given their own ceiling is a decision the log records',
    ).toBe(true)
  })
})
