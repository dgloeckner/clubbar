/**
 * Issue #372 — a settlement that collects 0.00 EUR is forbidden.
 *
 * The case is ordinary: a member's purchases are all stornoed, so their whole
 * unsettled position nets out. Settling *only* such members used to produce a
 * settlement of 0.00 EUR whose pain.008 file carried an empty payment block —
 * not a valid file, and one no bank accepts.
 *
 * What stays true is ruling #141 §5: a member who owes nothing still settles
 * alongside somebody who owes money, closing their rows out and getting no line
 * in the file. It is the *run's* total that may not be zero, so both halves are
 * asserted here, through the XML, the settlement CSV and the transaction CSV
 * the way the treasurer reads them.
 *
 * E2E Pattern 001: every test builds its own members and transactions.
 */

import type { APIRequestContext } from '@playwright/test'
import { test, expect, type TestTransactionsFixture } from '../../fixtures/auth.fixture'
import { exportSepaXml } from '../../fixtures/encryption'
import { minimumExecutionDate } from '../../utils/dates'
import { FACTORY_IBAN, ensureSepaConfigured } from '../../utils/settlements'

interface TestMember {
  id: string
  /** "First Last", exactly as the CSV and the SEPA file render it. */
  name: string
}

/**
 * A member with a live mandate, named so the SEPA file can be asserted against
 * the name: `SepaSanitizer` strips characters outside the SEPA-allowed set, so
 * a suffix with an underscore in it would never appear in the XML.
 */
async function createMember(request: APIRequestContext, lastName: string): Promise<TestMember> {
  const suffix = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 7)}`
  const firstName = `Zero${suffix}`

  const response = await request.post('/api/admin/members', {
    data: {
      first_name: firstName,
      last_name: lastName,
      email: `zero-total-${suffix}@test.example`,
      date_of_birth: '1985-06-15',
      preferred_language: 'de',
      iban: FACTORY_IBAN,
      mandate_signed_at: '2024-01-01',
    },
  })
  if (response.status() !== 201) {
    throw new Error(`Could not create member (${response.status()}): ${await response.text()}`)
  }

  return { id: (await response.json()).id, name: `${firstName} ${lastName}` }
}

/** A member whose single purchase has been stornoed: position 0, two open rows. */
async function memberWhoOwesNothing(
  request: APIRequestContext,
  testTransactions: TestTransactionsFixture,
): Promise<TestMember & { purchaseId: string; stornoId: string }> {
  const member = await createMember(request, 'Netsout')
  const purchaseId = await testTransactions.createSyncTransaction(member.id, 1750, 'Purchase to be stornoed')
  const stornoId = await testTransactions.createStorno(member.id, 0, 'Cancelled by mistake', purchaseId)

  return { ...member, purchaseId, stornoId }
}

test.describe('Settlement with a total of 0.00 EUR (#372)', () => {
  test('the preview still lists a net-zero member, at a balance of zero', async ({
    authenticatedRequest,
    testTransactions,
  }) => {
    const zero = await memberWhoOwesNothing(authenticatedRequest, testTransactions)

    const response = await authenticatedRequest.post('/api/admin/settlements/preview', {
      data: { member_ids: [zero.id] },
    })

    expect(response.status(), await response.text()).toBe(200)
    const preview = await response.json()

    // Not an exclusion: nothing is owed in either direction, so there is
    // nothing for a treasurer to chase. The member is shown, with both rows.
    const entry = preview.eligible_members.find((m: any) => m.member_id === zero.id)
    expect(entry).toBeDefined()
    expect(entry.balance_cents).toBe(0)
    expect(entry.transaction_count).toBe(2)
    expect(preview.credit_members).toEqual([])
  })

  test('a run naming only net-zero members is refused', async ({
    authenticatedRequest,
    testTransactions,
  }) => {
    const zero = await memberWhoOwesNothing(authenticatedRequest, testTransactions)

    const response = await authenticatedRequest.post('/api/admin/settlements', {
      data: {
        method: 'direct_debit',
        member_ids: [zero.id],
        execution_date: await minimumExecutionDate(authenticatedRequest),
      },
    })

    expect(response.status(), await response.text()).toBe(409)
    const body = await response.json()
    expect(body.message).toContain('0.00 EUR')
  })

  test('the transaction_ids path is refused for the same reason', async ({
    authenticatedRequest,
    testTransactions,
  }) => {
    // The compatibility path resolves ids to members and sweeps their whole
    // position, so it reaches the same zero and must give the same answer
    // rather than posting a run the member path would have rejected.
    const zero = await memberWhoOwesNothing(authenticatedRequest, testTransactions)

    const response = await authenticatedRequest.post('/api/admin/settlements', {
      data: {
        method: 'direct_debit',
        transaction_ids: [zero.purchaseId, zero.stornoId],
        execution_date: await minimumExecutionDate(authenticatedRequest),
      },
    })

    expect(response.status(), await response.text()).toBe(409)
    expect((await response.json()).message).toContain('0.00 EUR')
  })

  test('a write-off of nothing is refused too', async ({ authenticatedRequest, testTransactions }) => {
    const zero = await memberWhoOwesNothing(authenticatedRequest, testTransactions)

    const response = await authenticatedRequest.post('/api/admin/settlements', {
      data: {
        method: 'write_off',
        member_ids: [zero.id],
        execution_date: await minimumExecutionDate(authenticatedRequest),
      },
    })

    expect(response.status(), await response.text()).toBe(409)
    expect((await response.json()).message).toContain('0.00 EUR')
  })

  test('a net-zero member rides along in a run that collects, and appears in every export', async ({
    authenticatedRequest,
    testTransactions,
  }) => {
    await ensureSepaConfigured(authenticatedRequest)

    const zero = await memberWhoOwesNothing(authenticatedRequest, testTransactions)
    const payer = await createMember(authenticatedRequest, 'Owes')
    await testTransactions.createSyncTransaction(payer.id, 3400, 'Purchase that is collected')

    const created = await authenticatedRequest.post('/api/admin/settlements', {
      data: {
        method: 'direct_debit',
        member_ids: [zero.id, payer.id],
        execution_date: await minimumExecutionDate(authenticatedRequest),
      },
    })

    expect(created.status(), await created.text()).toBe(201)
    const settlement = await created.json()

    // The run's total is the payer's balance; the zero member contributes
    // nothing but is covered, and their two rows are claimed.
    expect(settlement.total_amount_cents).toBe(3400)
    expect(settlement.member_count).toBe(2)
    expect(settlement.items).toHaveLength(3)

    // ── The bank file: exactly one collection, and it is the payer's. ──
    const sepa = await exportSepaXml(authenticatedRequest, settlement.id)
    expect(sepa.status(), await sepa.text()).toBe(200)
    const xml = await sepa.text()

    expect(xml).toContain('<NbOfTxs>1</NbOfTxs>')
    expect(xml).toContain('<InstdAmt Ccy="EUR">34.00</InstdAmt>')
    expect(xml).toContain(payer.name)
    // The member who owes nothing must never reach the bank: an instruction
    // for 0.00 is what issue #372 asks about, and there is none.
    expect(xml).not.toContain(zero.name)
    expect(xml).not.toContain('Ccy="EUR">0.00<')

    // ── The settlement CSV: a reconciliation aid, so it shows both. ──
    const csv = await authenticatedRequest.get(`/api/admin/settlements/${settlement.id}/export/csv`)
    expect(csv.status()).toBe(200)
    const rows = (await csv.text()).trim().split('\n').slice(1)

    expect(rows).toHaveLength(2)
    expect(rows.find((r) => r.includes(payer.name))).toContain('34.00')
    expect(rows.find((r) => r.includes(zero.name))).toContain('0.00')

    // ── The transaction CSV: every claimed row, storno included. ──
    const txCsv = await authenticatedRequest.get(
      `/api/admin/settlements/${settlement.id}/export-transactions`,
    )
    expect(txCsv.status()).toBe(200)
    const txRows = (await txCsv.text()).trim().split('\n').slice(1)

    expect(txRows).toHaveLength(3)
    expect(txRows.filter((r) => r.includes(zero.name))).toHaveLength(2)
  })
})
