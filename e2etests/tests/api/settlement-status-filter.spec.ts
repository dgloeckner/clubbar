import { test, expect } from '../../fixtures/auth.fixture'
import type { APIRequestContext } from '@playwright/test'
import { exportSepaXml } from '../../fixtures/encryption'

/**
 * Filtering the settlement list by where each run stands (#377).
 *
 * Before this, `status` understood two values — `active` and `cancelled` —
 * described in the spec as "cancellation status". The four rungs between them
 * were unaskable, which is why the admin panel had no way to answer the
 * question in #377: *the SEPA file was exported, now what?*
 *
 * The trap the tests below are really about is not coverage but agreement. A
 * status is derived, not stored, so the filter cannot be a column test:
 * `WHERE submitted_at IS NOT NULL` would return a submitted run that the bank
 * has partly returned, whose own badge reads "partly reversed". Badge and pill
 * disagreeing about one row is the silent drift ADR-0032 exists to prevent, so
 * each test asks the question a treasurer would ask and checks the answer
 * excludes the rungs above it.
 *
 * E2E Patterns applied:
 *  - Pattern 001: every test builds its own settlements via `settlementFactory`
 *  - Pattern 003: results are searched by id, never by position
 *  - Pattern 004: assertions only ever concern this test's own settlements
 */

/** Export the file and record that it reached the bank. */
async function submitToBank(request: APIRequestContext, settlementId: string): Promise<void> {
  expect((await exportSepaXml(request, settlementId)).status()).toBe(200)
  expect((await request.post(`/api/admin/settlements/${settlementId}/submit`)).status()).toBe(200)
}

/**
 * The settlement ids one pill returns.
 *
 * A generous page, newest first: every settlement these tests ask about was
 * created seconds earlier, so it is at the head of whichever pill claims it —
 * the same assumption the rest of the settlement suite runs on. Membership is
 * then tested by id, never by position (Pattern 003).
 */
async function idsForStatus(request: APIRequestContext, status: string): Promise<string[]> {
  const query = status === 'all' ? '' : `&status=${status}`
  const response = await request.get(`/api/admin/settlements?per_page=100&sort=created_at&order=desc${query}`)
  expect(response.status(), await response.text()).toBe(200)

  return ((await response.json()).data as Array<{ id: string }>).map((s) => s.id)
}

/** What the settlement itself says it is — the badge's side of the agreement. */
async function statusOf(request: APIRequestContext, settlementId: string): Promise<string> {
  const response = await request.get(`/api/admin/settlements/${settlementId}`)
  expect(response.status()).toBe(200)

  return (await response.json()).status
}

test.describe('Settlement list — status filter', () => {
  test('"which runs have not been exported yet?" returns exactly the drafts', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create()

    expect(await statusOf(authenticatedRequest, settlement.id)).toBe('draft')
    expect(await idsForStatus(authenticatedRequest, 'draft')).toContain(settlement.id)
    expect(await idsForStatus(authenticatedRequest, 'exported')).not.toContain(settlement.id)
    expect(await idsForStatus(authenticatedRequest, 'submitted')).not.toContain(settlement.id)
  })

  test('"which files have I generated but never sent?" excludes the submitted runs', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const generated = await settlementFactory.create()
    const sent = await settlementFactory.create()

    expect((await exportSepaXml(authenticatedRequest, generated.id)).status()).toBe(200)
    await submitToBank(authenticatedRequest, sent.id)

    const exported = await idsForStatus(authenticatedRequest, 'exported')
    expect(exported).toContain(generated.id)
    // The distinction the whole issue turns on: generating a file is not
    // sending it, so these two runs must not answer the same question.
    expect(exported).not.toContain(sent.id)
    expect(await idsForStatus(authenticatedRequest, 'draft')).not.toContain(generated.id)
  })

  test('"what is with the bank right now?" excludes any run carrying a reversal', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const atBank = await settlementFactory.create()
    const partlyReturned = await settlementFactory.create({ memberCount: 2 })

    await submitToBank(authenticatedRequest, atBank.id)
    await submitToBank(authenticatedRequest, partlyReturned.id)

    const reversal = await authenticatedRequest.post(
      `/api/admin/settlements/${partlyReturned.id}/reverse`,
      { data: { reason: 'bank_return', member_ids: [partlyReturned.members[0].id] } }
    )
    expect(reversal.status(), await reversal.text()).toBe(201)

    // Its badge reads "partly reversed", so the "submitted" pill must not
    // return it — a naive `submitted_at IS NOT NULL` filter would.
    expect(await statusOf(authenticatedRequest, partlyReturned.id)).toBe('partly_reversed')

    const submitted = await idsForStatus(authenticatedRequest, 'submitted')
    expect(submitted).toContain(atBank.id)
    expect(submitted).not.toContain(partlyReturned.id)
  })

  test('"where did money come back?" returns both partly and fully reversed runs', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const partly = await settlementFactory.create({ memberCount: 2 })
    const fully = await settlementFactory.create({ memberCount: 2 })

    await submitToBank(authenticatedRequest, partly.id)
    await submitToBank(authenticatedRequest, fully.id)

    expect(
      (
        await authenticatedRequest.post(`/api/admin/settlements/${partly.id}/reverse`, {
          data: { reason: 'bank_return', member_ids: [partly.members[0].id] },
        })
      ).status()
    ).toBe(201)
    // No member_ids means every member the settlement covers.
    expect(
      (
        await authenticatedRequest.post(`/api/admin/settlements/${fully.id}/reverse`, {
          data: { reason: 'club_error' },
        })
      ).status()
    ).toBe(201)

    expect(await statusOf(authenticatedRequest, partly.id)).toBe('partly_reversed')
    expect(await statusOf(authenticatedRequest, fully.id)).toBe('fully_reversed')

    // One pill, both rungs: the treasurer asking where money came back does
    // not yet care how much of it did.
    const reversed = await idsForStatus(authenticatedRequest, 'reversed')
    expect(reversed).toContain(partly.id)
    expect(reversed).toContain(fully.id)
  })

  test('"what did we call off?" returns the cancelled runs and nothing else', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const cancelled = await settlementFactory.create()
    const live = await settlementFactory.create()

    expect((await authenticatedRequest.delete(`/api/admin/settlements/${cancelled.id}/cancel`)).status()).toBe(200)

    const ids = await idsForStatus(authenticatedRequest, 'cancelled')
    expect(ids).toContain(cancelled.id)
    expect(ids).not.toContain(live.id)
    expect(await idsForStatus(authenticatedRequest, 'draft')).not.toContain(cancelled.id)
  })

  test('bookmarked links keep working: status=active still returns every live run', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const live = await settlementFactory.create()
    const cancelled = await settlementFactory.create()
    expect((await authenticatedRequest.delete(`/api/admin/settlements/${cancelled.id}/cancel`)).status()).toBe(200)

    // `active` is gone from the pill row but still accepted: the vocabulary
    // moved on, the existing links did not.
    const ids = await idsForStatus(authenticatedRequest, 'active')
    expect(ids).toContain(live.id)
    expect(ids).not.toContain(cancelled.id)
  })

  test('filtering never loses or duplicates a run — the pills partition the list', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    // One settlement on every rung, built through the API the same way a
    // treasurer would reach it.
    const draft = await settlementFactory.create()

    const exported = await settlementFactory.create()
    expect((await exportSepaXml(authenticatedRequest, exported.id)).status()).toBe(200)

    const submitted = await settlementFactory.create()
    await submitToBank(authenticatedRequest, submitted.id)

    const returned = await settlementFactory.create({ memberCount: 2 })
    await submitToBank(authenticatedRequest, returned.id)
    expect(
      (
        await authenticatedRequest.post(`/api/admin/settlements/${returned.id}/reverse`, {
          data: { reason: 'bank_return', member_ids: [returned.members[0].id] },
        })
      ).status()
    ).toBe(201)

    const cancelled = await settlementFactory.create()
    expect((await authenticatedRequest.delete(`/api/admin/settlements/${cancelled.id}/cancel`)).status()).toBe(200)

    const expected: Record<string, string> = {
      draft: draft.id,
      exported: exported.id,
      submitted: submitted.id,
      reversed: returned.id,
      cancelled: cancelled.id,
    }
    const pills = Object.keys(expected)
    const byPill = new Map(
      await Promise.all(pills.map(async (pill) => [pill, await idsForStatus(authenticatedRequest, pill)] as const))
    )

    const unfiltered = await idsForStatus(authenticatedRequest, 'all')

    for (const [pill, settlementId] of Object.entries(expected)) {
      expect(unfiltered, `${pill} run missing from the unfiltered list`).toContain(settlementId)

      const matching = pills.filter((candidate) => byPill.get(candidate)!.includes(settlementId))
      // Exactly one, not at least one: a run returned by two pills is a run
      // whose badge contradicts one of them.
      expect(matching, `the ${pill} run is returned by ${matching.length} pills`).toEqual([pill])
    }
  })
})
