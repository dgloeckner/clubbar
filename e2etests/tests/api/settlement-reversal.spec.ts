import { test, expect } from '../../fixtures/auth.fixture'
import type { APIRequestContext } from '@playwright/test'
import type { CreatedSettlement } from '../../utils/settlements'
import { exportSepaXml } from '../../fixtures/encryption'

/**
 * Settlement reversal, end to end (issue #196, ruling #148).
 *
 * #81 closed the double-debit path: a submitted settlement refuses
 * cancellation and tells the treasurer to reverse it instead. Until now there
 * was nothing to reverse it *with* — a bounced direct debit left the member's
 * items claimed, their Deckel reading €0, and the club out the money with no
 * record of it.
 *
 * Every test drives the real HTTP stack: settle through the API, submit,
 * reverse, and then read the consequences back out of the journal, the
 * settlement preview and the hold listing.
 */

/** Export the file and tell the API it reached the bank — the point of no return. */
async function submitToBank(request: APIRequestContext, settlementId: string): Promise<void> {
  expect((await exportSepaXml(request, settlementId)).status()).toBe(200)
  expect((await request.post(`/api/admin/settlements/${settlementId}/submit`)).status()).toBe(200)
}

/** The ids of this member's transactions that no live settlement claims. */
async function unsettledTransactionIds(request: APIRequestContext, memberId: string): Promise<string[]> {
  const response = await request.get(`/api/admin/transactions?member_id=${memberId}&settlement_status=unsettled`)
  expect(response.status()).toBe(200)

  return ((await response.json()).data as Array<{ id: string }>).map((t) => t.id)
}

/** This member's row in the run's preview, and which bucket it landed in. */
async function previewBucketFor(
  request: APIRequestContext,
  memberId: string
): Promise<{ bucket: string; entry: Record<string, unknown> } | null> {
  const response = await request.post('/api/admin/settlements/preview', { data: { member_id: memberId } })
  expect(response.status()).toBe(200)
  const preview = await response.json()

  for (const bucket of ['eligible_members', 'ineligible_members', 'credit_members', 'held_members']) {
    const entry = (preview[bucket] as Array<Record<string, unknown>>).find((m) => m.member_id === memberId)
    if (entry) {
      return { bucket, entry }
    }
  }

  return null
}

/** A submitted two-member direct debit — the shape every per-member assertion needs. */
async function submittedTwoMemberSettlement(
  request: APIRequestContext,
  factory: { create(options?: { amountCents?: number; memberCount?: number }): Promise<CreatedSettlement> },
  amountCents: number
): Promise<CreatedSettlement> {
  const settlement = await factory.create({ amountCents, memberCount: 2 })
  await submitToBank(request, settlement.id)

  return settlement
}

test.describe('Settlement reversal (#196)', () => {
  test('R1: a bank return frees only that member’s items — the rest stay settled', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await submittedTwoMemberSettlement(authenticatedRequest, settlementFactory, 1500)
    const [alice, bob] = settlement.members

    const response = await authenticatedRequest.post(`/api/admin/settlements/${settlement.id}/reverse`, {
      data: { reason: 'bank_return', member_ids: [alice.id], bank_reference: 'RET-R1' },
    })

    expect(response.status()).toBe(201)
    const body = await response.json()
    expect(body.status).toBe('partly_reversed')
    expect(body.reversed_member_count).toBe(1)

    // Alice's drink is collectable again; Bob's is still with the bank.
    expect(await unsettledTransactionIds(authenticatedRequest, alice.id)).toContain(alice.transactionId)
    expect(await unsettledTransactionIds(authenticatedRequest, bob.id)).not.toContain(bob.transactionId)
  })

  test('R2: the returned member goes on hold and the next run skips them, with a reason', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    // Without the hold the next run sweeps the member's whole unsettled
    // position — including what just bounced — and earns a second return fee.
    const settlement = await settlementFactory.create({ amountCents: 1700 })
    await submitToBank(authenticatedRequest, settlement.id)

    const reversed = await authenticatedRequest.post(`/api/admin/settlements/${settlement.id}/reverse`, {
      data: { reason: 'bank_return', bank_reference: 'RET-R2', notes: 'Returned unpaid' },
    })
    expect(reversed.status()).toBe(201)

    const placed = await previewBucketFor(authenticatedRequest, settlement.memberId)
    expect(placed?.bucket).toBe('held_members')
    expect(String(placed?.entry.collection_hold_reason)).toContain('RET-R2')

    // And the same member is named in the standing hold listing.
    const holds = await authenticatedRequest.get('/api/admin/members/collection-holds')
    expect(holds.status()).toBe(200)
    const listed = ((await holds.json()).items as Array<Record<string, unknown>>).find(
      (h) => h.member_id === settlement.memberId
    )
    expect(listed).toBeTruthy()
    expect(listed?.balance_cents).toBe(1700)
  })

  test('R3: a held member cannot be collected from until the hold is cleared', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create({ amountCents: 1300 })
    await submitToBank(authenticatedRequest, settlement.id)
    expect(
      (
        await authenticatedRequest.post(`/api/admin/settlements/${settlement.id}/reverse`, {
          data: { reason: 'bank_return', bank_reference: 'RET-R3' },
        })
      ).status()
    ).toBe(201)

    const freed = await unsettledTransactionIds(authenticatedRequest, settlement.memberId)

    // A new direct debit over the freed transaction is refused while held...
    const refused = await authenticatedRequest.post('/api/admin/settlements', {
      data: {
        method: 'direct_debit',
        transaction_ids: freed,
        execution_date: settlement.executionDate,
      },
    })
    expect(refused.status()).toBe(422)
    expect(JSON.stringify(await refused.json())).toMatch(/collection hold/i)

    // ...and accepted once an admin has cleared it.
    const cleared = await authenticatedRequest.post(
      `/api/admin/members/${settlement.memberId}/collection-hold/clear`
    )
    expect(cleared.status()).toBe(200)

    const placed = await previewBucketFor(authenticatedRequest, settlement.memberId)
    expect(placed?.bucket).toBe('eligible_members')

    const accepted = await authenticatedRequest.post('/api/admin/settlements', {
      data: {
        method: 'direct_debit',
        transaction_ids: freed,
        execution_date: settlement.executionDate,
      },
    })
    expect(accepted.status()).toBe(201)
  })

  test('R4: a club error frees the items and applies no hold — re-collection is the point', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create({ amountCents: 2200 })
    await submitToBank(authenticatedRequest, settlement.id)

    const response = await authenticatedRequest.post(`/api/admin/settlements/${settlement.id}/reverse`, {
      data: { reason: 'club_error', notes: 'Collected the wrong amount' },
    })
    expect(response.status()).toBe(201)
    expect((await response.json()).reversals[0].reason).toBe('club_error')

    const placed = await previewBucketFor(authenticatedRequest, settlement.memberId)
    expect(placed?.bucket).toBe('eligible_members')
    expect(placed?.entry.balance_cents).toBe(2200)
  })

  test('R5: reversing every member reads as fully reversed, one as partly', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await submittedTwoMemberSettlement(authenticatedRequest, settlementFactory, 900)
    const [alice, bob] = settlement.members

    const first = await authenticatedRequest.post(`/api/admin/settlements/${settlement.id}/reverse`, {
      data: { reason: 'club_error', member_ids: [alice.id] },
    })
    expect((await first.json()).status).toBe('partly_reversed')

    const second = await authenticatedRequest.post(`/api/admin/settlements/${settlement.id}/reverse`, {
      data: { reason: 'club_error', member_ids: [bob.id] },
    })
    expect((await second.json()).status).toBe('fully_reversed')

    // The status is derived on every read, not stamped once at write time.
    const reread = await authenticatedRequest.get(`/api/admin/settlements/${settlement.id}`)
    const body = await reread.json()
    expect(body.status).toBe('fully_reversed')
    expect(body.reversed_member_count).toBe(2)
    expect(body.reversals).toHaveLength(2)
  })

  test('R6: a reversed settlement still exports what it originally contained', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    // The claim is released; the history is not. A settlement whose CSV came
    // back empty could not answer "what did we collect in March?".
    const settlement = await submittedTwoMemberSettlement(authenticatedRequest, settlementFactory, 1250)

    expect(
      (
        await authenticatedRequest.post(`/api/admin/settlements/${settlement.id}/reverse`, {
          data: { reason: 'bank_return', bank_reference: 'RET-R6' },
        })
      ).status()
    ).toBe(201)

    const csv = await authenticatedRequest.get(`/api/admin/settlements/${settlement.id}/export/csv`)
    expect(csv.status()).toBe(200)
    const rows = (await csv.text()).trim().split('\n')

    // One row per member, each still carrying what was collected from them.
    expect(rows).toHaveLength(settlement.members.length + 1)
    for (const member of settlement.members) {
      const row = rows.find((r) => r.includes(member.name))
      expect(row).toBeDefined()
      expect(row).toContain('12.50')
    }
  })

  test('R7: the same member cannot be reversed twice on one settlement', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create({ amountCents: 640 })
    await submitToBank(authenticatedRequest, settlement.id)

    expect(
      (
        await authenticatedRequest.post(`/api/admin/settlements/${settlement.id}/reverse`, {
          data: { reason: 'bank_return', bank_reference: 'RET-R7' },
        })
      ).status()
    ).toBe(201)

    const second = await authenticatedRequest.post(`/api/admin/settlements/${settlement.id}/reverse`, {
      data: { reason: 'bank_return', bank_reference: 'RET-R7b' },
    })

    expect(second.status()).toBe(409)
    expect((await second.json()).message).toMatch(/already been reversed/i)
  })

  test('R8: a settlement that never reached the bank is cancelled, not reversed', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    // The mirror of #81's gate: nothing moved, so there is nothing to return.
    const settlement = await settlementFactory.create({ amountCents: 480 })

    const detail = await authenticatedRequest.get(`/api/admin/settlements/${settlement.id}`)
    const body = await detail.json()
    expect(body.status).toBe('draft')
    expect(body.is_reversible).toBe(false)
    expect(body.is_cancellable).toBe(true)
    expect(body.reversal_blocked_reason).toMatch(/cancel it instead/i)

    const refused = await authenticatedRequest.post(`/api/admin/settlements/${settlement.id}/reverse`, {
      data: { reason: 'bank_return' },
    })
    expect(refused.status()).toBe(409)
    expect((await refused.json()).message).toMatch(/cancel it instead/i)
  })

  test('R9: a submitted settlement offers reversal exactly where it refuses cancellation', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create({ amountCents: 1050 })
    await submitToBank(authenticatedRequest, settlement.id)

    const detail = await authenticatedRequest.get(`/api/admin/settlements/${settlement.id}`)
    const body = await detail.json()

    expect(body.status).toBe('submitted')
    expect(body.is_cancellable).toBe(false)
    expect(body.is_reversible).toBe(true)
    expect(body.reversal_blocked_reason).toBeNull()
  })

  test('R10: a member outside the settlement cannot be reversed against it', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create({ amountCents: 700 })
    const stranger = await settlementFactory.create({ amountCents: 700 })
    await submitToBank(authenticatedRequest, settlement.id)

    const response = await authenticatedRequest.post(`/api/admin/settlements/${settlement.id}/reverse`, {
      data: { reason: 'bank_return', member_ids: [stranger.memberId] },
    })

    expect(response.status()).toBe(422)
    expect(JSON.stringify(await response.json())).toContain(stranger.memberId)
  })

  test('R11: an unknown reason is refused before anything is written', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    // German return codes are suppressed and collapse into "sonstige Gründe",
    // so the schema carries two reasons and no finer classification.
    const settlement = await settlementFactory.create({ amountCents: 330 })
    await submitToBank(authenticatedRequest, settlement.id)

    const response = await authenticatedRequest.post(`/api/admin/settlements/${settlement.id}/reverse`, {
      data: { reason: 'insufficient_funds' },
    })
    expect(response.status()).toBe(422)

    const detail = await authenticatedRequest.get(`/api/admin/settlements/${settlement.id}`)
    expect((await detail.json()).status).toBe('submitted')
  })

  test('R12: reversing an unknown settlement is a 404', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post(
      `/api/admin/settlements/${crypto.randomUUID()}/reverse`,
      { data: { reason: 'bank_return' } }
    )

    expect(response.status()).toBe(404)
  })

  test('R13: clearing a hold that is not there is refused', async ({ authenticatedRequest, settlementFactory }) => {
    // Succeeding silently would write a second "cleared" audit entry for a
    // hold somebody else already released.
    const settlement = await settlementFactory.create({ amountCents: 260 })

    const response = await authenticatedRequest.post(
      `/api/admin/members/${settlement.memberId}/collection-hold/clear`
    )

    expect(response.status()).toBe(409)
    expect((await response.json()).message).toMatch(/not on collection hold/i)
  })

  test('R14: the reversal is recorded against the settlement with its amount and reference', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create({ amountCents: 3125 })
    await submitToBank(authenticatedRequest, settlement.id)

    const response = await authenticatedRequest.post(`/api/admin/settlements/${settlement.id}/reverse`, {
      data: { reason: 'bank_return', bank_reference: 'RET-R14', notes: 'Returned unpaid' },
    })
    expect(response.status()).toBe(201)

    const [reversal] = (await response.json()).reversals
    expect(reversal.member_id).toBe(settlement.memberId)
    // The amount is derived from the settled items, never supplied by the caller.
    expect(reversal.amount_cents).toBe(3125)
    expect(reversal.amount_eur).toBe(31.25)
    expect(reversal.bank_reference).toBe('RET-R14')
    expect(reversal.notes).toBe('Returned unpaid')
    expect(reversal.reason_label).toBe('Returned by the bank')
    expect(reversal.created_at).toBeTruthy()
  })
})
