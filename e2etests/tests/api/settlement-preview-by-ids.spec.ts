/**
 * Settlement preview by transaction ids (#128).
 *
 * The Journal's settlement selection spans pages, and a settlement sweeps each
 * selected member's whole unsettled position rather than the rows that were
 * ticked (#161 §2). Both facts mean no count the client derives from its own
 * selection can state what the run will contain — so the preview has to accept
 * the very ids the create call will post and answer for them.
 *
 * Patterns: 001 (per-test data), 003 (find your own rows by id), 004 (nothing
 * shared between tests, so these are parallel-safe).
 */

import { test, expect } from '../../fixtures/auth.fixture'
import { FACTORY_IBAN } from '../../utils/settlements'
import { generateUUID } from '../../utils/transactions'
import { minimumExecutionDate, serverToday } from '../../utils/dates'
import type { APIRequestContext } from '@playwright/test'

interface SeededMember {
  memberId: string
  transactionIds: string[]
  totalCents: number
}

/**
 * A member holding `amounts.length` unsettled purchases.
 *
 * Created per test so the sweep totals are the test's own and cannot be moved
 * by a concurrent run (Pattern 001).
 */
async function seedMemberWithPurchases(
  adminRequest: APIRequestContext,
  terminalRequest: APIRequestContext,
  amounts: number[],
  options: { withMandate?: boolean } = {}
): Promise<SeededMember> {
  const withMandate = options.withMandate ?? true
  const suffix = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`

  const memberResponse = await adminRequest.post('/api/admin/members', {
    data: {
      first_name: 'Preview',
      last_name: `Fixture${suffix}`,
      email: `preview-${suffix}@test.example`,
      preferred_language: 'de',
      ...(withMandate ? { iban: FACTORY_IBAN, mandate_signed_at: '2024-01-01' } : {}),
    },
  })
  expect(memberResponse.status(), await memberResponse.text()).toBe(201)
  const member = await memberResponse.json()

  const transactionIds = amounts.map(() => generateUUID())
  const syncResponse = await terminalRequest.post('/api/sync/transactions', {
    data: {
      transactions: amounts.map((amountCents, index) => ({
        id: transactionIds[index],
        member_id: member.id,
        type: 'product',
        product_id: generateUUID(),
        quantity: 1,
        unit_price_cents: amountCents,
        amount_cents: amountCents,
        notes: `Preview fixture ${suffix} #${index}`,
        created_at: new Date().toISOString(),
      })),
    },
  })
  expect(syncResponse.status(), await syncResponse.text()).toBe(201)

  return {
    memberId: member.id,
    transactionIds,
    totalCents: amounts.reduce((sum, cents) => sum + cents, 0),
  }
}

interface PreviewMember {
  member_id?: string
  balance_cents?: number
}

/** Pluck this test's own member out of a preview bucket (Pattern 003). */
function findMember(bucket: PreviewMember[], memberId: string): PreviewMember | undefined {
  return bucket.find((entry) => entry.member_id === memberId)
}

test.describe('Settlement preview by transaction ids (#128)', () => {
  test('names the member behind a posted id and reports their whole position', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    // Four purchases; the client will name exactly one of them.
    const seeded = await seedMemberWithPurchases(
      authenticatedRequest,
      authenticatedTerminalRequest,
      [500, 700, 900, 300]
    )

    const response = await authenticatedRequest.post('/api/admin/settlements/preview', {
      data: { transaction_ids: [seeded.transactionIds[0]] },
    })

    expect(response.status()).toBe(200)
    const body = await response.json()

    const entry = findMember(body.eligible_members, seeded.memberId)
    expect(entry, 'the member named by the posted id must appear').toBeDefined()

    // 2400, not the 500 that was actually selected — the run sweeps the lot.
    expect(entry?.balance_cents).toBe(seeded.totalCents)
  })

  test('reports every member of a cross-page selection, not just the last page', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    // Stands in for a selection made across three pages of the journal.
    const pageOneA = await seedMemberWithPurchases(authenticatedRequest, authenticatedTerminalRequest, [100])
    const pageOneB = await seedMemberWithPurchases(authenticatedRequest, authenticatedTerminalRequest, [200])
    const pageTwoC = await seedMemberWithPurchases(authenticatedRequest, authenticatedTerminalRequest, [300])

    const response = await authenticatedRequest.post('/api/admin/settlements/preview', {
      data: {
        transaction_ids: [
          pageOneA.transactionIds[0],
          pageOneB.transactionIds[0],
          pageTwoC.transactionIds[0],
        ],
      },
    })

    expect(response.status()).toBe(200)
    const body = await response.json()

    // The bug: only the current page's member survived to the settlement.
    for (const seeded of [pageOneA, pageOneB, pageTwoC]) {
      expect(findMember(body.eligible_members, seeded.memberId), `member ${seeded.memberId} must be in the run`).toBeDefined()
    }
  })

  test('two ids of the same member name that member once', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const seeded = await seedMemberWithPurchases(authenticatedRequest, authenticatedTerminalRequest, [400, 600])

    const response = await authenticatedRequest.post('/api/admin/settlements/preview', {
      data: { transaction_ids: seeded.transactionIds },
    })

    expect(response.status()).toBe(200)
    const body = await response.json()

    const matches = (body.eligible_members as PreviewMember[]).filter(
      (entry) => entry.member_id === seeded.memberId
    )
    expect(matches).toHaveLength(1)
    expect(matches[0].balance_cents).toBe(1000)
  })

  test('counts the transactions the run would contain, not the ids posted', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const seeded = await seedMemberWithPurchases(
      authenticatedRequest,
      authenticatedTerminalRequest,
      [100, 200, 300]
    )

    const oneId = await authenticatedRequest.post('/api/admin/settlements/preview', {
      data: { transaction_ids: [seeded.transactionIds[0]] },
    })
    const allIds = await authenticatedRequest.post('/api/admin/settlements/preview', {
      data: { transaction_ids: seeded.transactionIds },
    })

    expect(oneId.status()).toBe(200)
    expect(allIds.status()).toBe(200)

    const oneBody = await oneId.json()
    const allBody = await allIds.json()

    expect(typeof oneBody.transaction_count).toBe('number')
    // Naming one row and naming all three describe the same run, because both
    // name the same member. A count taken from the selection would say 1 vs 3.
    expect(oneBody.transaction_count).toBe(allBody.transaction_count)
    expect(oneBody.transaction_count).toBeGreaterThanOrEqual(3)
  })

  test('surfaces a member the creation would refuse, before the post', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    // No mandate → the settlement service rejects the whole run with a 422.
    const noMandate = await seedMemberWithPurchases(
      authenticatedRequest,
      authenticatedTerminalRequest,
      [750],
      { withMandate: false }
    )

    const response = await authenticatedRequest.post('/api/admin/settlements/preview', {
      data: { transaction_ids: [noMandate.transactionIds[0]] },
    })

    expect(response.status()).toBe(200)
    const body = await response.json()

    expect(findMember(body.ineligible_members, noMandate.memberId)).toBeDefined()
    expect(findMember(body.eligible_members, noMandate.memberId)).toBeUndefined()
  })

  test('an already-settled id names nobody', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const seeded = await seedMemberWithPurchases(authenticatedRequest, authenticatedTerminalRequest, [1200])

    const created = await authenticatedRequest.post('/api/admin/settlements', {
      data: {
        method: 'direct_debit',
        transaction_ids: seeded.transactionIds,
        settlement_date: await serverToday(authenticatedRequest),
        execution_date: await minimumExecutionDate(authenticatedRequest),
      },
    })
    expect(created.status(), await created.text()).toBe(201)

    const response = await authenticatedRequest.post('/api/admin/settlements/preview', {
      data: { transaction_ids: seeded.transactionIds },
    })

    expect(response.status()).toBe(200)
    const body = await response.json()
    expect(findMember(body.eligible_members, seeded.memberId)).toBeUndefined()
  })

  test('rejects a transaction_ids that is not an array', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post('/api/admin/settlements/preview', {
      data: { transaction_ids: 'not-an-array' },
    })

    expect(response.status()).toBe(422)
    const body = await response.json()
    expect(body.error).toBe('validation_failed')
  })

  test('without transaction_ids the window still selects the participants', async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.post('/api/admin/settlements/preview', {
      data: { from_date: '2026-01-01', to_date: '2026-12-31' },
    })

    expect(response.status()).toBe(200)
    const body = await response.json()
    expect(body).toHaveProperty('eligible_members')
    expect(body).toHaveProperty('transaction_count')
  })
})
