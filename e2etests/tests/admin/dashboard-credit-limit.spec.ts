/**
 * Members close to their credit limit, on the dashboard (#385).
 *
 * The terminal already warns the member and, past the limit, refuses their next
 * checkout. Nobody told the club, so the first anyone heard of it was a blocked
 * card at the bar. These tests pin the other half: the admin sees who is nearly
 * there while there is still time to say something.
 *
 * The line is the terminal's own — a share of the ceiling to warn, past the
 * ceiling to block — and since ADR-0047 that ceiling is per member, so each
 * fixture below is given one of its own and stated relative to it.
 *
 * The panel names only the five members closest to their ceiling, measured as
 * a **share** of it rather than as a raw amount (#559). Each fixture therefore
 * states the share it needs — as high as the case allows — and the `afterEach`
 * deactivates them again, so this file does not fill those five rows a little
 * more on every run. That cleanup matters only in a database that outlives one
 * run: CI gets a fresh one, a developer's stack does not.
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

/**
 * **Every fixture here carries a ceiling of its own** (ADR-0047), and that is
 * deliberate rather than incidental. The club default is a singleton another
 * spec in this project edits — the epic's acceptance flow sets it, asserts
 * against it and puts it back — so a file that measured its members against it
 * would fail whenever the two overlapped, and would fail for a reason that has
 * nothing to do with the dashboard. A member with an override is measured
 * against their own number no matter what the club does (Pattern 001: own the
 * data you assert on, singletons included).
 *
 * The warning band is club-wide by design and has no per-member form, so it is
 * the one value still read from the API — once, in `beforeAll`.
 */
const OWN_LIMIT_CENTS = 20_000

/** The club-wide share of a ceiling at which a member starts being warned. */
let WARN_PCT = 0

const warnAtCents = (limitCents: number) => Math.floor((limitCents * WARN_PCT) / 100)

/** What the dashboard says about a tab measured against its own ceiling. */
const percentOfLimit = (balanceCents: number, limitCents: number) =>
  Math.round((balanceCents * 100) / limitCents)

/**
 * Seed a member with a tab **and** a ceiling of their own.
 *
 * The panel ranks by share of ceiling rather than by raw amount (#559), so the
 * share is what decides whether a fixture is named or capped away by the
 * five-row limit — which is why each test states the share it wants rather
 * than an amount that used to imply one.
 */
async function seedWithOwnCeiling(
  adminRequest: APIRequestContext,
  terminalRequest: APIRequestContext,
  options: { tag: string; balanceCents: number; limitCents: number }
) {
  const member = await seedMember(adminRequest, terminalRequest, {
    tag: options.tag,
    prefix: 'Limit',
    amounts: [options.balanceCents],
  })
  const response = await adminRequest.patch(`/api/admin/members/${member.memberId}`, {
    data: { credit_limit_cents: options.limitCents },
  })
  expect(response.status(), await response.text()).toBe(200)
  return member
}

test.describe('Dashboard: members close to their limit (#385)', () => {
  /** Members this test seeded, taken off the panel again when it ends. */
  let seeded: string[] = []

  test.beforeAll(async ({ browser }) => {
    // A context of its own: `beforeAll` has no per-test fixtures, and this only
    // reads the singleton config row.
    const context = await browser.newContext()
    try {
      const config = await (
        await context.request.get('http://localhost:8080/api/admin/credit-limit-config')
      ).json()
      WARN_PCT = config.warn_threshold_percent
    } finally {
      await context.close()
    }
  })

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
    // 99 % of their own ceiling: inside the band by any club-wide percentage,
    // and a share high enough that only a member already past their limit can
    // push this one out of the five rows the panel shows.
    const tabCents = OWN_LIMIT_CENTS - 200
    expect(tabCents, 'the fixture must sit inside the warning band').toBeGreaterThanOrEqual(
      warnAtCents(OWN_LIMIT_CENTS)
    )
    const nearly = await seedWithOwnCeiling(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Near',
      balanceCents: tabCents,
      limitCents: OWN_LIMIT_CENTS,
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
      String(percentOfLimit(tabCents, OWN_LIMIT_CENTS))
    )
    expect(await dashboard.getMemberNearLimitState(nearly.memberId)).toBe('approaching')
  })

  test('a member past the limit is marked as blocked, not merely warned', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    page,
  }) => {
    // Well past it: a member at five times their ceiling outranks anything the
    // panel could already be showing, so this test never loses its row to the
    // five-row cap.
    const overLimitCents = 2_000
    const tabCents = 10_000
    const over = await seedWithOwnCeiling(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Over',
      balanceCents: tabCents,
      limitCents: overLimitCents,
    })
    seeded.push(over.memberId)

    const dashboard = new DashboardPage(page)
    await dashboard.goto()

    await dashboard.expectMemberNearLimit(over.memberId)
    // The verdict, not just a bigger number: the terminal has stopped serving
    // them, and the row has to carry that rather than reading like a warning.
    expect(await dashboard.getMemberNearLimitState(over.memberId)).toBe('exceeded')
    expect(await dashboard.getMemberNearLimitStatus(over.memberId)).toContain(
      String(percentOfLimit(tabCents, overLimitCents))
    )
  })

  test('a member comfortably inside their limit is left off the list', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    page,
  }) => {
    const modest = await seedWithOwnCeiling(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Modest',
      balanceCents: warnAtCents(OWN_LIMIT_CENTS) - 1,
      limitCents: OWN_LIMIT_CENTS,
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
    const tabCents = OWN_LIMIT_CENTS - 200
    const nearly = await seedWithOwnCeiling(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Api',
      balanceCents: tabCents,
      limitCents: OWN_LIMIT_CENTS,
    })
    seeded.push(nearly.memberId)

    const response = await authenticatedRequest.get('/api/admin/dashboard')
    expect(response.status(), await response.text()).toBe(200)
    const body = await response.json()

    // The envelope names the *club default* — whatever it is at this moment,
    // which is why it is asserted for consistency rather than against a value
    // read minutes ago — and each row names the ceiling that member was
    // actually measured against (ADR-0047).
    expect(body.members_near_limit.limit_cents).toBeGreaterThan(0)
    expect(body.members_near_limit.warn_at_cents).toBe(
      warnAtCents(body.members_near_limit.limit_cents)
    )
    expect(body.members_near_limit.total).toBeGreaterThanOrEqual(1)

    const row = body.members_near_limit.members.find(
      (member: { id: string }) => member.id === nearly.memberId
    )
    expect(row, 'the seeded member is missing from the list').toBeDefined()
    expect(row.balance_cents).toBe(tabCents)
    expect(row.limit_cents).toBe(OWN_LIMIT_CENTS)
    expect(row.percent_of_limit).toBe(percentOfLimit(tabCents, OWN_LIMIT_CENTS))
    expect(row.status).toBe('approaching')
    expect(row.name).toContain(nearly.lastName)
  })
})
