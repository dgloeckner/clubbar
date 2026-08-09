/**
 * The two standing exclusion listings, as endpoints in their own right (#188).
 *
 * Both back the "Excluded from collection" surface under Members, and until
 * now neither was tested as an endpoint. `credit-balances` had never been
 * called over HTTP at all — its unit tests mock the repository, so the route
 * wiring, the session guard and the JSON envelope were unverified, and the
 * admin frontend would have been the first thing to find out. The hold
 * listing was reached only as a step inside the reversal flow
 * (`settlement-reversal.spec.ts`), which asserts the member is named and
 * nothing about `total_held_cents`, `held_at` or `held_by_admin_name` — three
 * of the columns the new surface renders.
 *
 * Totals and ordering are asserted the parallel-safe way (Pattern 004): other
 * workers are seeding their own excluded members at the same time, so every
 * assertion is either about a member this test seeded and found by id
 * (Pattern 003), or about a property of the whole response that holds no
 * matter who else is in it.
 */

import { test, expect } from '../../fixtures/auth.fixture'
import { seedCreditMember, seedHeldMember } from '../../utils/exclusions'
import type { APIRequestContext } from '@playwright/test'

const API_BASE = '/api/admin'

interface Listing {
  items: Array<Record<string, unknown>>
  total: number
}

async function readListing(
  request: APIRequestContext,
  path: string,
  totalKey: string
): Promise<Listing> {
  const response = await request.get(`${API_BASE}${path}`)
  expect(response.status(), await response.text()).toBe(200)

  const body = await response.json()
  expect(Array.isArray(body.items), `${path} must answer with an items array`).toBe(true)
  expect(typeof body[totalKey], `${path} must answer with ${totalKey}`).toBe('number')

  return { items: body.items, total: body[totalKey] }
}

function rowFor(listing: Listing, memberId: string): Record<string, unknown> | undefined {
  return listing.items.find((item) => item.member_id === memberId)
}

test.describe('GET /admin/members/credit-balances', () => {
  test('names a member the club owes money to, with the amount owed', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const member = await seedCreditMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'One',
      amountCents: 1500,
    })

    const listing = await readListing(authenticatedRequest, '/members/credit-balances', 'total_credit_cents')
    const row = rowFor(listing, member.memberId)

    expect(row, 'the seeded credit member must be listed').toBeTruthy()
    expect(row?.balance_cents).toBe(-1500)
    expect(row?.last_name).toBe(member.lastName)
    expect(row?.first_name).toBe('Run')
  })

  test('lists the deepest credit first', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    // Two members, seeded shallow-then-deep, so a listing that merely preserved
    // insertion order would fail this.
    const shallow = await seedCreditMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Shallow',
      amountCents: 400,
    })
    const deep = await seedCreditMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Deep',
      amountCents: 9900,
    })

    const listing = await readListing(authenticatedRequest, '/members/credit-balances', 'total_credit_cents')

    const balances = listing.items.map((item) => Number(item.balance_cents))
    const sorted = [...balances].sort((a, b) => a - b)
    expect(balances, 'the whole listing is ordered most-negative first').toEqual(sorted)

    const deepIndex = listing.items.findIndex((i) => i.member_id === deep.memberId)
    const shallowIndex = listing.items.findIndex((i) => i.member_id === shallow.memberId)
    expect(deepIndex).toBeGreaterThanOrEqual(0)
    expect(shallowIndex).toBeGreaterThanOrEqual(0)
    expect(deepIndex).toBeLessThan(shallowIndex)
  })

  test('the total is the sum of the rows it lists', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    await seedCreditMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Total',
      amountCents: 2200,
    })

    const listing = await readListing(authenticatedRequest, '/members/credit-balances', 'total_credit_cents')
    const summed = listing.items.reduce((sum, item) => sum + Number(item.balance_cents), 0)

    expect(listing.total).toBe(summed)
    expect(listing.total, 'credit is money owed out, so the total is negative').toBeLessThan(0)
  })

  test('drops a member once they are deleted', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const member = await seedCreditMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Deleted',
      amountCents: 800,
    })

    const before = await readListing(authenticatedRequest, '/members/credit-balances', 'total_credit_cents')
    expect(rowFor(before, member.memberId)).toBeTruthy()

    const deleted = await authenticatedRequest.delete(`${API_BASE}/members/${member.memberId}`)
    expect(deleted.status(), await deleted.text()).toBe(200)

    const after = await readListing(authenticatedRequest, '/members/credit-balances', 'total_credit_cents')
    expect(rowFor(after, member.memberId), 'a soft-deleted member is not owed anything on screen').toBeFalsy()
  })

  test('refuses an unauthenticated caller', async ({ request }) => {
    const response = await request.get(`${API_BASE}/members/credit-balances`)
    expect(response.status()).toBe(401)
  })
})

test.describe('GET /admin/members/collection-holds', () => {
  test('carries the reason, the amount held and who held it', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const held = await seedHeldMember(authenticatedRequest, settlementFactory, {
      tag: 'Fields',
      amountCents: 1700,
    })

    const listing = await readListing(authenticatedRequest, '/members/collection-holds', 'total_held_cents')
    const row = rowFor(listing, held.memberId)

    expect(row, 'the reversed member must be listed as held').toBeTruthy()
    expect(row?.balance_cents).toBe(1700)

    // The reason is composed by the reversal and shown verbatim on the
    // surface, so "why was this member skipped in March?" stays answerable.
    expect(String(row?.collection_hold_reason)).toContain(held.bankReference)
    expect(String(row?.collection_hold_reason)).toMatch(/returned by the bank/i)

    // Provenance: both are columns the standing view renders.
    expect(row?.held_at, 'a hold records when it was placed').toBeTruthy()
    expect(Number.isNaN(Date.parse(String(row?.held_at)))).toBe(false)
    expect(row?.held_by_admin_name, 'a hold records who placed it').toBeTruthy()
  })

  test('the total is the sum of what it holds back', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    await seedHeldMember(authenticatedRequest, settlementFactory, {
      tag: 'Total',
      amountCents: 1300,
    })

    const listing = await readListing(authenticatedRequest, '/members/collection-holds', 'total_held_cents')
    const summed = listing.items.reduce((sum, item) => sum + Number(item.balance_cents), 0)

    expect(listing.total).toBe(summed)
    expect(listing.total, 'a hold keeps money the club is owed, so the total is positive').toBeGreaterThan(0)
  })

  test('a cleared hold leaves the listing', async ({ authenticatedRequest, settlementFactory }) => {
    const held = await seedHeldMember(authenticatedRequest, settlementFactory, {
      tag: 'Cleared',
      amountCents: 1100,
    })

    const before = await readListing(authenticatedRequest, '/members/collection-holds', 'total_held_cents')
    expect(rowFor(before, held.memberId)).toBeTruthy()

    const cleared = await authenticatedRequest.post(
      `${API_BASE}/members/${held.memberId}/collection-hold/clear`
    )
    expect(cleared.status(), await cleared.text()).toBe(200)

    const after = await readListing(authenticatedRequest, '/members/collection-holds', 'total_held_cents')
    expect(rowFor(after, held.memberId)).toBeFalsy()
  })

  test('refuses an unauthenticated caller', async ({ request }) => {
    const response = await request.get(`${API_BASE}/members/collection-holds`)
    expect(response.status()).toBe(401)
  })
})

test.describe('The two listings disagree about a member on purpose', () => {
  test('a member in credit is not on hold, and a held member is not in credit', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    settlementFactory,
  }) => {
    // The buckets are exclusive and need opposite remedies — pay them back
    // versus investigate the bank return — so a member landing in both would
    // put contradictory instructions in front of the treasurer.
    const inCredit = await seedCreditMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Split',
      amountCents: 700,
    })
    const onHold = await seedHeldMember(authenticatedRequest, settlementFactory, {
      tag: 'Split',
      amountCents: 900,
    })

    const credit = await readListing(authenticatedRequest, '/members/credit-balances', 'total_credit_cents')
    const holds = await readListing(authenticatedRequest, '/members/collection-holds', 'total_held_cents')

    expect(rowFor(credit, inCredit.memberId)).toBeTruthy()
    expect(rowFor(holds, inCredit.memberId)).toBeFalsy()

    expect(rowFor(holds, onHold.memberId)).toBeTruthy()
    expect(rowFor(credit, onHold.memberId)).toBeFalsy()
  })
})
