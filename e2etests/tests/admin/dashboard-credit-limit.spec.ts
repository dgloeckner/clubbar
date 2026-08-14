/**
 * Members close to their credit limit, on the dashboard (#385).
 *
 * The terminal already warns the member and, past the limit, refuses their next
 * checkout. Nobody told the club, so the first anyone heard of it was a blocked
 * card at the bar. These tests pin the other half: the admin sees who is nearly
 * there while there is still time to say something.
 *
 * The line is the terminal's own — 80 % of €100.00 to warn, past €100.00 to
 * block — so the fixtures below are stated in cents relative to it rather than
 * in round numbers that would stop meaning anything if the limit moved.
 *
 * The panel names only the five biggest tabs, and that cap is what the two
 * pieces of machinery below are for. `topOfTheList()` seeds above whatever is
 * currently listed, so a fixture is never hidden behind older ones; the
 * `afterEach` deactivates the fixtures again, so this file does not fill those
 * five rows a little more on every run. Both matter only in a database that
 * outlives one run — CI gets a fresh one, a developer's stack does not.
 *
 * Patterns: 001 (a member seeded per test, never shared), 003 (rows found by
 * their own id), 004 (parallel-safe — nothing asserted about members this file
 * did not seed), 005 (test IDs), 006 (page object), 008 (expect, never
 * try/catch).
 */

import { type APIRequestContext } from '@playwright/test'
import { expect, test } from '../../fixtures/auth.fixture'
import { DashboardPage } from '../../pages/DashboardPage'
import { seedMember } from '../../utils/exclusions'

const LIMIT_CENTS = 10_000
const WARN_AT_CENTS = 8_000

/** What the dashboard says about the tab a member is carrying. */
const percentOfLimit = (balanceCents: number) => Math.round((balanceCents * 100) / LIMIT_CENTS)

/**
 * A tab of at least `atLeastCents` that also outranks every tab the panel is
 * currently showing, so the member seeded with it is named rather than capped
 * away. `ceilingCents` holds the result inside the warning band for the tests
 * that are about being warned rather than blocked.
 */
async function topOfTheList(
  request: APIRequestContext,
  atLeastCents: number,
  ceilingCents = Number.MAX_SAFE_INTEGER
): Promise<number> {
  const response = await request.get('/api/admin/dashboard')
  expect(response.status(), await response.text()).toBe(200)
  const listed: Array<{ balance_cents: number }> =
    (await response.json()).members_near_limit?.members ?? []

  const biggest = listed.reduce((max, member) => Math.max(max, member.balance_cents), 0)

  return Math.min(ceilingCents, Math.max(atLeastCents, biggest + 100))
}

test.describe('Dashboard: members close to their limit (#385)', () => {
  /** Members this test seeded, taken off the panel again when it ends. */
  let seeded: string[] = []

  test.beforeEach(() => {
    seeded = []
  })

  test.afterEach(async ({ authenticatedRequest }) => {
    // Deactivating the fixtures hands the panel's five rows back. Nothing
    // deletes a member, and a tab is only cleared by a settlement run, so
    // without this every run of this file would leave two more members parked
    // at the top of the list — and after a few runs no later fixture could get
    // into it. Deactivation is the product's own way off the list (the terminal
    // does not serve them), which is why it is safe to use as cleanup.
    for (const memberId of seeded) {
      await authenticatedRequest.patch(`/api/admin/members/${memberId}`, {
        data: { is_active: false },
      })
    }
  })
  test('names a member whose tab has entered the warning band', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    page,
  }) => {
    const tabCents = await topOfTheList(authenticatedRequest, WARN_AT_CENTS + 500, LIMIT_CENTS)
    const nearly = await seedMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Near',
      prefix: 'Limit',
      amounts: [tabCents],
    })
    seeded.push(nearly.memberId)

    const dashboard = new DashboardPage(page)
    await dashboard.goto()

    await dashboard.expectMembersNearLimitVisible()
    await dashboard.expectMemberNearLimit(nearly.memberId)
    // The tab itself, however the active locale punctuates it.
    expect(await dashboard.getMemberNearLimitBalance(nearly.memberId)).toMatch(
      new RegExp(`${Math.floor(tabCents / 100)}[.,]00`)
    )
    expect(await dashboard.getMemberNearLimitStatus(nearly.memberId)).toContain(
      String(percentOfLimit(tabCents))
    )
    expect(await dashboard.getMemberNearLimitState(nearly.memberId)).toBe('approaching')
  })

  test('a member past the limit is marked as blocked, not merely warned', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    page,
  }) => {
    const tabCents = await topOfTheList(authenticatedRequest, LIMIT_CENTS + 1_000)
    const over = await seedMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Over',
      prefix: 'Limit',
      amounts: [tabCents],
    })
    seeded.push(over.memberId)

    const dashboard = new DashboardPage(page)
    await dashboard.goto()

    await dashboard.expectMemberNearLimit(over.memberId)
    // The verdict, not just a bigger number: the terminal has stopped serving
    // them, and the row has to carry that rather than reading like a warning.
    expect(await dashboard.getMemberNearLimitState(over.memberId)).toBe('exceeded')
    expect(await dashboard.getMemberNearLimitStatus(over.memberId)).toContain(
      String(percentOfLimit(tabCents))
    )
  })

  test('a member comfortably inside their limit is left off the list', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    page,
  }) => {
    const modest = await seedMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Modest',
      prefix: 'Limit',
      amounts: [WARN_AT_CENTS - 1],
    })
    seeded.push(modest.memberId)

    const dashboard = new DashboardPage(page)
    await dashboard.goto()

    await dashboard.expectMembersNearLimitVisible()
    await dashboard.expectMemberNotNearLimit(modest.memberId)
  })

  test('the API reports the tab, its share of the limit and the verdict', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const tabCents = await topOfTheList(authenticatedRequest, WARN_AT_CENTS + 1_000, LIMIT_CENTS)
    const nearly = await seedMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Api',
      prefix: 'Limit',
      amounts: [tabCents],
    })
    seeded.push(nearly.memberId)

    const response = await authenticatedRequest.get('/api/admin/dashboard')
    expect(response.status(), await response.text()).toBe(200)
    const body = await response.json()

    expect(body.members_near_limit.limit_cents).toBe(LIMIT_CENTS)
    expect(body.members_near_limit.warn_at_cents).toBe(WARN_AT_CENTS)
    expect(body.members_near_limit.total).toBeGreaterThanOrEqual(1)

    const row = body.members_near_limit.members.find(
      (member: { id: string }) => member.id === nearly.memberId
    )
    expect(row, 'the seeded member is missing from the list').toBeDefined()
    expect(row.balance_cents).toBe(tabCents)
    expect(row.percent_of_limit).toBe(percentOfLimit(tabCents))
    expect(row.status).toBe('approaching')
    expect(row.name).toContain(nearly.lastName)
  })
})
