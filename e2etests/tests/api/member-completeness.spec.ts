import { randomUUID } from 'crypto'
import type { APIRequestContext } from '@playwright/test'
import { test, expect } from '../../fixtures/auth.fixture'

/**
 * Finding the members with missing data (#629).
 *
 * The roster could always filter for one gap at a time — `has_card_uid`,
 * `has_email`, `sepa_status` — but two things were missing, and they are what
 * turn four capable filter pills into a story:
 *
 * 1. **The birth-date gap was invisible.** `MemberListItem` deliberately
 *    carries no `date_of_birth` (ADR-0045: a roster an admin scrolls has no
 *    business holding one), which also meant the members a terminal refuses
 *    every age-restricted product to could not be found from the panel at all.
 *    The list now carries the *flag*, and a filter to go with it.
 * 2. **Nothing counted.** `GET /members/completeness` is the headline the
 *    Datenqualität panel prints, and every count it returns has a filter
 *    behind it that lists exactly the members counted.
 *
 * E2E Pattern 001: each test mints its own members, so the counts are only
 * ever asserted as deltas — another worker's fixtures are in the same table.
 */

const API_BASE = 'http://localhost:8080/api'

const uniqueMemberBody = (overrides: Record<string, unknown> = {}) => {
  const id = randomUUID().replace(/-/g, '').slice(0, 10)

  return {
    first_name: `Cmp${id}`,
    last_name: `Member${id}`,
    email: `cmp-${id}@example.com`,
    date_of_birth: '1985-06-15',
    preferred_language: 'de',
    ...overrides,
  }
}

async function createMember(request: APIRequestContext, overrides: Record<string, unknown> = {}) {
  const response = await request.post(`${API_BASE}/admin/members`, { data: uniqueMemberBody(overrides) })
  expect(response.status(), await response.text()).toBe(201)
  return response.json()
}

test.describe('Member data completeness', () => {
  test('the roster carries whether a birth date exists, never the date itself', async ({
    authenticatedRequest,
  }) => {
    const member = await createMember(authenticatedRequest)

    const list = await authenticatedRequest.get(
      `${API_BASE}/admin/members?search=${member.first_name}&per_page=5`,
    )
    expect(list.status()).toBe(200)
    const row = (await list.json()).data.find((m: { id: string }) => m.id === member.id)

    expect(row).toBeDefined()
    expect(row.has_date_of_birth).toBe(true)
    // ADR-0045 holds: the flag replaces the date, it does not accompany it.
    expect(row.date_of_birth).toBeUndefined()
  })

  test('has_date_of_birth=with finds the member; without does not', async ({
    authenticatedRequest,
  }) => {
    const member = await createMember(authenticatedRequest)

    const withDob = await authenticatedRequest.get(
      `${API_BASE}/admin/members?search=${member.first_name}&has_date_of_birth=with`,
    )
    expect(withDob.status()).toBe(200)
    expect((await withDob.json()).data.map((m: { id: string }) => m.id)).toContain(member.id)

    const withoutDob = await authenticatedRequest.get(
      `${API_BASE}/admin/members?search=${member.first_name}&has_date_of_birth=without`,
    )
    expect(withoutDob.status()).toBe(200)
    expect((await withoutDob.json()).data.map((m: { id: string }) => m.id)).not.toContain(member.id)
  })

  test('data_status=incomplete is an OR across the gaps, not an AND', async ({
    authenticatedRequest,
  }) => {
    // Missing a card UID and a mandate, but has an email and a birth date —
    // so it qualifies as incomplete on two counts and would be excluded by
    // any filter that intersected them.
    const gappy = await createMember(authenticatedRequest)

    const incomplete = await authenticatedRequest.get(
      `${API_BASE}/admin/members?search=${gappy.first_name}&data_status=incomplete`,
    )
    expect(incomplete.status()).toBe(200)
    expect((await incomplete.json()).data.map((m: { id: string }) => m.id)).toContain(gappy.id)

    const complete = await authenticatedRequest.get(
      `${API_BASE}/admin/members?search=${gappy.first_name}&data_status=complete`,
    )
    expect(complete.status()).toBe(200)
    expect((await complete.json()).data.map((m: { id: string }) => m.id)).not.toContain(gappy.id)
  })

  test('the completeness summary answers with a count per gap', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get(`${API_BASE}/admin/members/completeness`)
    expect(response.status(), await response.text()).toBe(200)
    const body = await response.json()

    for (const key of [
      'total',
      'without_card_uid',
      'without_email',
      'without_date_of_birth',
      'without_mandate',
      'incomplete',
    ]) {
      expect(typeof body[key], `${key} should be a number`).toBe('number')
      expect(body[key]).toBeGreaterThanOrEqual(0)
    }

    // One member can be missing several things, so the headline is at most
    // the sum — and never more, which is the arithmetic a reader checks.
    expect(body.incomplete).toBeLessThanOrEqual(
      body.without_card_uid + body.without_email + body.without_date_of_birth + body.without_mandate,
    )
    expect(body.incomplete).toBeLessThanOrEqual(body.total)
  })

  test('a new member with no mandate raises the mandate count and the headline', async ({
    authenticatedRequest,
  }) => {
    const before = await (await authenticatedRequest.get(`${API_BASE}/admin/members/completeness`)).json()

    await createMember(authenticatedRequest)

    const after = await (await authenticatedRequest.get(`${API_BASE}/admin/members/completeness`)).json()

    // Deltas, not absolutes: parallel workers are creating members too, so the
    // assertion is "at least one more", which is what this test caused.
    expect(after.total).toBeGreaterThan(before.total)
    expect(after.without_mandate).toBeGreaterThan(before.without_mandate)
    expect(after.without_card_uid).toBeGreaterThan(before.without_card_uid)
    expect(after.incomplete).toBeGreaterThan(before.incomplete)
    // The birth date is mandatory on create, so nothing this test did can
    // have raised that count.
    expect(after.without_date_of_birth).toBe(before.without_date_of_birth)
  })

  test('an inactive member drops out of the list the panel counts', async ({
    authenticatedRequest,
  }) => {
    const member = await createMember(authenticatedRequest)

    // The panel counts active members only, and every one of its buttons pins
    // `status=active` for exactly that reason — so this is the list behind the
    // headline, asserted through the filter rather than through the totals.
    // Totals move under parallel workers; a search for one member does not.
    const listed = async () => {
      const response = await authenticatedRequest.get(
        `${API_BASE}/admin/members?search=${member.first_name}&data_status=incomplete&status=active`,
      )
      expect(response.status()).toBe(200)
      return (await response.json()).data.map((m: { id: string }) => m.id)
    }

    expect(await listed()).toContain(member.id)

    const deactivated = await authenticatedRequest.patch(`${API_BASE}/admin/members/${member.id}`, {
      data: { is_active: false },
    })
    expect(deactivated.status(), await deactivated.text()).toBe(200)

    expect(await listed()).not.toContain(member.id)
  })
})
