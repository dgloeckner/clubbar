/**
 * New Settlement screen — selection is a member picker (UC-A30, ADR-0030).
 *
 * The screen this replaced asked the admin to tick transactions while the run
 * settled whole member positions. Nothing on screen said so, so the model was
 * only ever revealed by a number jumping at the confirmation (#128).
 *
 * These tests pin the thing that fixes: what the admin selects, what the screen
 * says the run contains, and what the run then actually settles are the same
 * three facts.
 *
 * Patterns: 001 (data seeded per test), 003 (rows found by their own ids),
 * 004 (parallel-safe — no shared state), 005 (test IDs), 006 (page object),
 * 008 (expect, never try/catch).
 */

import { test, expect } from '../../fixtures/auth.fixture'
import { NewSettlementPage } from '../../pages/NewSettlementPage'
import { JournalPage } from '../../pages/JournalPage'
import { seedMember } from '../../utils/exclusions'
import type { APIRequestContext } from '@playwright/test'

async function openTransactionCount(
  adminRequest: APIRequestContext,
  memberId: string
): Promise<number> {
  const response = await adminRequest.get(
    `/api/admin/transactions?member_id=${memberId}&settlement_status=open&per_page=100`
  )
  expect(response.status()).toBe(200)
  return ((await response.json()).data ?? []).length
}

test.describe('New Settlement — the selection is members', () => {
  test('a member row states the whole position the run will sweep', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    page,
  }) => {
    const member = await seedMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Sweep',
      amounts: [500, 700, 900, 300],
    })

    const newSettlement = new NewSettlementPage(page)
    await newSettlement.goto()
    await newSettlement.search(member.lastName)

    // Four rows, €24.00 — the member's whole position, stated on their row
    // rather than inferred from a confirmation.
    expect(await newSettlement.getMemberTransactionCount(member.memberId)).toBe(4)
    expect(await newSettlement.getMemberBalance(member.memberId)).toContain('24')
  })

  test('expanding a member shows the transactions, and none of them are selectable', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    page,
  }) => {
    const member = await seedMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Detail',
      amounts: [100, 200, 300],
    })

    const newSettlement = new NewSettlementPage(page)
    await newSettlement.goto()
    await newSettlement.search(member.lastName)
    await newSettlement.expandMember(member.memberId)

    expect(await newSettlement.getExpandedTransactionCount(member.memberId)).toBe(3)
    // Under the sweep a per-transaction choice has no meaning, so offering one
    // would be the old lie in a new place.
    await newSettlement.expectDetailHasNoCheckboxes(member.memberId)
  })

  test('deselecting a member drops exactly their figures from the run', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    page,
  }) => {
    const member = await seedMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Summary',
      amounts: [400, 600],
    })

    const newSettlement = new NewSettlementPage(page)
    await newSettlement.goto()

    // Everything eligible starts selected (UC-A30), so this member is in.
    await newSettlement.expectMemberSelected(member.memberId)
    const before = await newSettlement.getRunSummary()

    await newSettlement.deselectMember(member.memberId)
    const after = await newSettlement.getRunSummary()

    expect(after.members).toBe(before.members - 1)
    expect(after.transactions).toBe(before.transactions - 2)
  })

  test('a run settles every open row of the members chosen, and nobody else', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    page,
  }) => {
    const included = await seedMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'In',
      amounts: [250, 350, 450],
    })
    const excluded = await seedMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Out',
      amounts: [800],
    })

    const newSettlement = new NewSettlementPage(page)
    await newSettlement.goto()

    // Start from nothing, then take exactly one member.
    await newSettlement.toggleSelectAll()
    await newSettlement.expectCreateDisabled()

    await newSettlement.selectMember(included.memberId)
    const summary = await newSettlement.getRunSummary()
    expect(summary.members).toBe(1)
    expect(summary.transactions).toBe(3)

    const settlementId = await newSettlement.create()

    const settlement = await authenticatedRequest.get(`/api/admin/settlements/${settlementId}`)
    expect(settlement.status()).toBe(200)
    expect((await settlement.json()).member_count).toBe(1)

    // The chosen member is swept clean; the other is untouched.
    expect(await openTransactionCount(authenticatedRequest, included.memberId)).toBe(0)
    expect(await openTransactionCount(authenticatedRequest, excluded.memberId)).toBe(1)
  })

  test('a member in credit is shown apart and cannot be collected from', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    page,
  }) => {
    // A credit position can only arise the way it does in production, and the
    // sequence is the point: sync refuses a negative amount outright (#79
    // forces every uploaded row to a purchase), and a storno of an *unsettled*
    // purchase just nets it to zero. The member goes negative only once the
    // purchase has been collected and is *then* reversed — the January
    // overcharge discovered after January was billed (ruling #141).
    const inCredit = await seedMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Credit',
      amounts: [1500],
    })

    const dates = await authenticatedRequest.get('/api/admin/settlements/execution-date-info')
    const { minimum_date: executionDate } = await dates.json()

    const settled = await authenticatedRequest.post('/api/admin/settlements', {
      data: {
        method: 'direct_debit',
        member_ids: [inCredit.memberId],
        execution_date: executionDate,
      },
    })
    expect(settled.status(), await settled.text()).toBe(201)

    const storno = await authenticatedRequest.post(
      `/api/admin/transactions/${inCredit.transactionIds[0]}/storno`,
      { data: { reason: 'Overcharged, collected in error' } },
    )
    expect(storno.status(), await storno.text()).toBe(201)

    const newSettlement = new NewSettlementPage(page)
    await newSettlement.goto()

    await newSettlement.expectMemberInCreditSection(inCredit.memberId)
    await newSettlement.expectMemberNotSelectable(inCredit.memberId)
  })

  test('a member without a mandate is shown apart from a member in credit', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    page,
  }) => {
    const noMandate = await seedMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'NoMandate',
      amounts: [600],
      withMandate: false,
    })

    const newSettlement = new NewSettlementPage(page)
    await newSettlement.goto()

    // Two sections, not one merged warning list: the remedies are opposites —
    // chase the bank details versus pay the member back (ruling #141).
    await newSettlement.expectMemberInIneligibleSection(noMandate.memberId)
    await newSettlement.expectMemberNotSelectable(noMandate.memberId)
  })

  test('a member whose collection bounced is shown on hold, with the reason', async ({
    authenticatedRequest,
    settlementFactory,
    page,
  }) => {
    // A hold outranks a missing mandate and outranks credit (ruling #148 §4):
    // the member's last collection came back, and until somebody looks at that
    // their bank details are not the question. It is the exclusion that must
    // never be silent — a held member is skipped run after run otherwise.
    const settlement = await settlementFactory.create({ amountCents: 1700 })

    expect((await authenticatedRequest.get(`/api/admin/settlements/${settlement.id}/export/sepa-xml`)).status()).toBe(200)
    expect((await authenticatedRequest.post(`/api/admin/settlements/${settlement.id}/submit`)).status()).toBe(200)

    const reversed = await authenticatedRequest.post(`/api/admin/settlements/${settlement.id}/reverse`, {
      data: { reason: 'bank_return', bank_reference: 'RET-UI1', notes: 'Returned unpaid' },
    })
    expect(reversed.status(), await reversed.text()).toBe(201)

    const newSettlement = new NewSettlementPage(page)
    await newSettlement.goto()

    await newSettlement.expectMemberInHeldSection(settlement.memberId)
    await newSettlement.expectMemberNotSelectable(settlement.memberId)
    expect(await newSettlement.getHoldReason(settlement.memberId)).toContain('RET-UI1')
  })

  test('search narrows the list without changing the run', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    page,
  }) => {
    const shown = await seedMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Shown',
      amounts: [100],
    })
    const hidden = await seedMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Hidden',
      amounts: [200],
    })

    const newSettlement = new NewSettlementPage(page)
    await newSettlement.goto()
    const before = await newSettlement.getRunSummary()

    await newSettlement.search(shown.lastName)
    await newSettlement.expectMemberVisible(shown.memberId)
    await newSettlement.expectMemberHidden(hidden.memberId)

    // Filtering the view is not editing the run.
    expect(await newSettlement.getRunSummary()).toEqual(before)

    await newSettlement.search('')
    await newSettlement.expectMemberSelected(hidden.memberId)
  })

  test('reached from the Settlements page, which is where UC-A30 says it lives', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    page,
  }) => {
    await seedMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Entry',
      amounts: [100],
    })

    const newSettlement = new NewSettlementPage(page)
    await newSettlement.gotoViaSettlements()

    expect(page.url()).toContain('/settlements/new')
    expect(await newSettlement.getErrorMessage()).toBeNull()
  })
})

test.describe('The Journal no longer creates settlements (ADR-0030)', () => {
  test('offers no selection UI, only a way through to the run screen', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    page,
  }) => {
    await seedMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Journal',
      amounts: [100],
    })

    const journalPage = new JournalPage(page)
    await journalPage.navigate()
    await journalPage.waitForPageLoad()

    // The transaction picker, its checkboxes and the confirm modal are gone.
    await journalPage.expectNoSettlementSelectionUi()

    await journalPage.goToNewSettlement()
    await new NewSettlementPage(page).waitForLoaded()
  })
})
