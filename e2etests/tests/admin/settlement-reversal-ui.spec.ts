/**
 * Reversal reaches the admin panel (epic #433).
 *
 * Once a settlement is submitted, reversal is the **only** remedy —
 * cancellation is closed off by design (ruling #142, ADR-0032). The backend for
 * it shipped with #196: endpoint, gate, per-member granularity, collection
 * holds, audit trail. None of it was reachable from the admin panel, so a
 * treasurer whose bank returned a direct debit had nowhere to record it, and one
 * who submitted a wrong file had no way to undo it. #81's complaint was that
 * "reverse it instead" must never point at a door that refuses to open; it
 * pointed at no door at all.
 *
 * What these tests pin, in the treasurer's terms:
 *  1. a run submitted in error can be undone, whole or per member
 *  2. the row offers the operation that is actually available — never both,
 *     never neither (the invariant #81 turns on)
 *  3. a return from the bank is recorded by pasting the reference off the
 *     statement, without knowing which run it came from
 *  4. a reference that genuinely exists never comes back as "no match"
 *  5. expanding a run says which member came back, and out of how many
 *
 * E2E Patterns applied:
 *  - Pattern 001: each test builds its own settlement via `settlementFactory`
 *  - Pattern 006/007: all interaction goes through the page object
 *  - Pattern 008: `expect()` assertions, no try-catch visibility checks
 */

import { test, expect } from '../../fixtures/auth.fixture'
import type { APIRequestContext } from '@playwright/test'
import { SettlementsPage } from '../../pages/SettlementsPage'
import { ExcludedFromCollectionPage } from '../../pages/ExcludedFromCollectionPage'
import { exportSepaXml } from '../../fixtures/encryption'

/** Export the file and tell the API it reached the bank — the point of no return. */
async function submitToBank(request: APIRequestContext, settlementId: string): Promise<void> {
  expect((await exportSepaXml(request, settlementId)).status()).toBe(200)
  expect((await request.post(`/api/admin/settlements/${settlementId}/submit`)).status()).toBe(200)
}

/**
 * `EndToEndId::forCollection` — what the bank quotes back as `EREF+` on a
 * return booking (#149, #150). Restated rather than read back out of our own
 * API: the treasurer types what the *bank* printed, so a test that fetched the
 * value first would never notice the two drifting apart.
 */
function referenceFor(settlementId: string, memberId: string): string {
  const half = (uuid: string) => uuid.replace(/-/g, '').slice(0, 12)

  return `E2E-${half(settlementId)}-${half(memberId)}`
}

/** This member's transactions that no live settlement claims. */
async function unsettledTransactionIds(request: APIRequestContext, memberId: string): Promise<string[]> {
  const response = await request.get(`/api/admin/transactions?member_id=${memberId}&settlement_status=unsettled`)
  expect(response.status()).toBe(200)

  return ((await response.json()).data as Array<{ id: string }>).map((t) => t.id)
}

test.describe('Undoing a collection the club got wrong', () => {
  test('a treasurer who submitted the wrong file can undo the whole run', async ({
    page,
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create({ amountCents: 2400, memberCount: 2 })
    await submitToBank(authenticatedRequest, settlement.id)

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    // Whole-settlement reversal is this button's default: the endpoint's own
    // default call omits `member_ids` entirely.
    await settlementsPage.reverseWholeSettlement(settlement.id)

    await settlementsPage.expectReverseSuccessVisible()
    await settlementsPage.expectStatusBadge(settlement.id, 'Vollständig zurückgebucht')

    // It persisted, rather than merely repainting the badge.
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()
    await settlementsPage.expectStatusBadge(settlement.id, 'Vollständig zurückgebucht')

    // End to end: every member's drink is collectable again.
    const stored = await authenticatedRequest.get(`/api/admin/settlements/${settlement.id}`)
    expect((await stored.json()).status).toBe('fully_reversed')

    for (const member of settlement.members) {
      expect(await unsettledTransactionIds(authenticatedRequest, member.id)).toContain(member.transactionId)
    }
  })

  test('a treasurer who included a member by mistake can undo just that member', async ({
    page,
    authenticatedRequest,
    settlementFactory,
  }) => {
    // Forcing this through a whole-settlement reversal would make the treasurer
    // over-reverse everyone else and re-collect them — manufacturing exactly
    // the churn holds exist to prevent.
    const settlement = await settlementFactory.create({ amountCents: 1800, memberCount: 2 })
    const [alice, bob] = settlement.members
    await submitToBank(authenticatedRequest, settlement.id)

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    await settlementsPage.openReverseDialog(settlement.id)
    await settlementsPage.chooseMembersToReverse([alice.id])
    await settlementsPage.confirmReversal(settlement.id)

    await settlementsPage.expectStatusBadge(settlement.id, 'Teilweise zurückgebucht')

    // Alice's collection came back; Bob's stands.
    expect(await unsettledTransactionIds(authenticatedRequest, alice.id)).toContain(alice.transactionId)
    expect(await unsettledTransactionIds(authenticatedRequest, bob.id)).not.toContain(bob.transactionId)
  })

  test('a run that has not moved money offers cancellation, not reversal — and vice versa', async ({
    page,
    authenticatedRequest,
    settlementFactory,
  }) => {
    // The invariant #81 turns on, seen from the row: `ReversalGate` is the exact
    // mirror of `CancellationGate`, so the slot always shows the operation that
    // actually exists. A treasurer reaching for "undo" on a submitted run must
    // not land on a disabled control explaining what they may not do.
    const settlement = await settlementFactory.create({ amountCents: 1500 })

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    await settlementsPage.expectCancellationOffered(settlement.id)

    await submitToBank(authenticatedRequest, settlement.id)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    await settlementsPage.expectReversalOffered(settlement.id)
  })

  test('a treasurer is told a reversal cannot be taken back before making one', async ({
    page,
    authenticatedRequest,
    settlementFactory,
  }) => {
    // `UNIQUE (settlement_id, member_id)`, foreign keys `ON DELETE RESTRICT`,
    // no update and no delete: correcting a mistaken reversal is not possible,
    // and nothing about the button suggests that.
    const settlement = await settlementFactory.create({ amountCents: 1250 })
    await submitToBank(authenticatedRequest, settlement.id)

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    await settlementsPage.openReverseDialog(settlement.id)
    await settlementsPage.expectReversalIrreversibleStated()
    // A club error re-collects on the next run, so it places no hold and the
    // dialog must not claim otherwise.
    await settlementsPage.expectNoHoldWarning()
    await settlementsPage.dismissReverseDialog()
  })
})

test.describe('Recording a return from the bank', () => {
  test('the return-entry button is there even before any run is reversible', async ({ page }) => {
    // A missing button tells a treasurer holding a real bank statement nothing
    // about why. The empty case belongs one step later, where the lookup can
    // say something actionable.
    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    await settlementsPage.expectRecordBankReturnOffered()
  })

  test('a treasurer holding a bank statement can record the return without knowing which run it came from', async ({
    page,
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create({ amountCents: 3300 })
    await submitToBank(authenticatedRequest, settlement.id)
    const reference = referenceFor(settlement.id, settlement.memberId)

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    await settlementsPage.openBankReturnLookup()
    await settlementsPage.lookupReference(reference)

    // The four facts that let a treasurer match the row against the line on
    // their statement.
    const candidate = await settlementsPage.getCandidateText(settlement.id, settlement.memberId)
    expect(candidate).toContain(settlement.memberName)
    expect(candidate).toContain('33,00')

    await settlementsPage.chooseCandidate(settlement.id, settlement.memberId)

    // §4: the bank quotes our own EndToEndId back, so it is already filled in.
    expect(await settlementsPage.getReverseBankReference()).toBe(reference)
    // §5: the hold is a consequence nobody requested, so it is stated.
    await settlementsPage.expectHoldWarningVisible()
    await settlementsPage.expectLockedMember(settlement.memberName)

    await settlementsPage.confirmReversal(settlement.id)
    await settlementsPage.expectReverseSuccessVisible()
    await settlementsPage.expectHoldLinkOffered()

    // The member is reversed and now frozen out of the next run, with the
    // return's own provenance on the exclusion page.
    const excludedPage = new ExcludedFromCollectionPage(page)
    await excludedPage.goto()
    await excludedPage.expectMemberInHoldSection(settlement.memberId)
    expect(await excludedPage.getHoldRowText(settlement.memberId)).toContain(settlement.memberName)

    await excludedPage.openClearDialog(settlement.memberId)
    expect(await excludedPage.getClearDialogText()).toContain(reference)

    // And the hold can be lifted again — the existing flow, guarded.
    await excludedPage.confirmClear()
    await excludedPage.expectMemberNotInHoldSection(settlement.memberId)
  })

  test('an imperfect reference still finds the collection', async ({
    page,
    authenticatedRequest,
    settlementFactory,
  }) => {
    // The treasurer is copying a value off a statement under time pressure and
    // should not have to reproduce it perfectly, nor classify it first.
    const settlement = await settlementFactory.create({ amountCents: 2050 })
    await submitToBank(authenticatedRequest, settlement.id)
    const reference = referenceFor(settlement.id, settlement.memberId)

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()
    await settlementsPage.openBankReturnLookup()

    for (const typed of [`  ${reference} `, reference.toLowerCase(), reference.replace(/^E2E-/, '')]) {
      await settlementsPage.lookupReference(typed)
      await settlementsPage.expectCandidateVisible(settlement.id, settlement.memberId)
    }
  })

  test('a mandate reference finds the member’s collections when the treasurer has no EREF', async ({
    page,
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create({ amountCents: 1950 })
    await submitToBank(authenticatedRequest, settlement.id)

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()
    await settlementsPage.openBankReturnLookup()
    await settlementsPage.lookupReference(settlement.mandateReference)

    await settlementsPage.expectCandidateVisible(settlement.id, settlement.memberId)
  })

  test('a return already recorded by a colleague says so instead of vanishing', async ({
    page,
    authenticatedRequest,
    settlementFactory,
  }) => {
    // Filtering it out produces the worst outcome available: the treasurer
    // pasted a reference that genuinely exists, the UI claims nothing matches,
    // and they eventually record the return against the wrong thing.
    const settlement = await settlementFactory.create({ amountCents: 1450 })
    await submitToBank(authenticatedRequest, settlement.id)
    expect(
      (
        await authenticatedRequest.post(`/api/admin/settlements/${settlement.id}/reverse`, {
          data: { reason: 'bank_return', bank_reference: 'RET-COLLEAGUE' },
        })
      ).status()
    ).toBe(201)

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()
    await settlementsPage.openBankReturnLookup()
    await settlementsPage.lookupReference(referenceFor(settlement.id, settlement.memberId))

    await settlementsPage.expectCandidateBlocked(settlement.id, settlement.memberId, /Bereits am .* von .* zurückgebucht/)
  })

  test('a reference for a run that never moved money explains itself', async ({
    page,
    authenticatedRequest,
    settlementFactory,
  }) => {
    // The gate's own words, pointing at cancellation. Re-deriving a second rule
    // set here is the #81 bug, so the reason is shown verbatim.
    const settlement = await settlementFactory.create({ amountCents: 1350 })
    expect((await exportSepaXml(authenticatedRequest, settlement.id)).status()).toBe(200)

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()
    await settlementsPage.openBankReturnLookup()
    await settlementsPage.lookupReference(referenceFor(settlement.id, settlement.memberId))

    await settlementsPage.expectCandidateBlocked(settlement.id, settlement.memberId, /Cancel it instead/)
  })

  test('an unrecognised reference explains the two reasons it might not match', async ({ page }) => {
    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()
    await settlementsPage.openBankReturnLookup()
    await settlementsPage.lookupReference('E2E-000000000000-000000000000')

    await settlementsPage.expectLookupNoMatch(/Einzugskennungen.*Rückweisung/s)
  })

  test('nothing lets a treasurer pick a member by name', async ({
    page,
    authenticatedRequest,
    settlementFactory,
  }) => {
    // The boundary ADR-0032 §8 draws, guarded from the UI side: a field that
    // resolves a reference and has the treasurer confirm it is a lookup; one
    // that matches names is the free-hand picker the ADR forbids.
    const settlement = await settlementFactory.create({ amountCents: 1550 })
    await submitToBank(authenticatedRequest, settlement.id)

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()
    await settlementsPage.openBankReturnLookup()
    await settlementsPage.lookupReference(settlement.memberName)

    await settlementsPage.expectNoCandidates()
    await settlementsPage.expectLookupNoMatch(/kein Einzug gefunden/)
  })

  test('the treasurer sees who they are about to reverse before it happens', async ({
    page,
    authenticatedRequest,
    settlementFactory,
  }) => {
    // A single match still renders as a candidate to confirm rather than
    // jumping into the dialog: with an irreversible operation at the end, one
    // glance at *who* earns its click.
    const settlement = await settlementFactory.create({ amountCents: 2750 })
    await submitToBank(authenticatedRequest, settlement.id)

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()
    await settlementsPage.openBankReturnLookup()
    await settlementsPage.lookupReference(referenceFor(settlement.id, settlement.memberId))

    await settlementsPage.expectCandidateVisible(settlement.id, settlement.memberId)
    // Nothing has been recorded yet — the settlement is still submitted.
    const stored = await authenticatedRequest.get(`/api/admin/settlements/${settlement.id}`)
    expect((await stored.json()).status).toBe('submitted')
  })
})

test.describe('Seeing what happened', () => {
  test('expanding a run shows which member was returned and why', async ({
    page,
    authenticatedRequest,
    settlementFactory,
  }) => {
    // Not the reversals alone: a treasurer expanding "1 von 2 zurückgebucht" is
    // asking *which one*, and a reversal shown without the other member loses
    // the denominator.
    const settlement = await settlementFactory.create({ amountCents: 2600, memberCount: 2 })
    const [alice, bob] = settlement.members
    await submitToBank(authenticatedRequest, settlement.id)
    expect(
      (
        await authenticatedRequest.post(`/api/admin/settlements/${settlement.id}/reverse`, {
          data: { reason: 'bank_return', member_ids: [alice.id], bank_reference: 'RET-EXPAND' },
        })
      ).status()
    ).toBe(201)

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()
    await settlementsPage.expandSettlement(settlement.id)

    expect(await settlementsPage.getBreakdownMemberCount()).toBe(2)
    await settlementsPage.expectMemberInBreakdown(alice.id, new RegExp(alice.name))
    await settlementsPage.expectMemberReversedInBreakdown(alice.id, /26,00.*Rücklastschrift.*RET-EXPAND/s)
    // Bob is still collected, and still shown.
    await settlementsPage.expectMemberInBreakdown(bob.id, new RegExp(bob.name))
    await settlementsPage.expectMemberNotReversedInBreakdown(bob.id)
  })

  test('a run with nothing reversed still shows what it collected', async ({
    page,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create({ amountCents: 900, memberCount: 2 })

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()
    await settlementsPage.expandSettlement(settlement.id)

    expect(await settlementsPage.getBreakdownMemberCount()).toBe(2)
    for (const member of settlement.members) {
      await settlementsPage.expectMemberInBreakdown(member.id, /9,00/)
      await settlementsPage.expectMemberNotReversedInBreakdown(member.id)
    }
  })

  test('the list says how much of a run came back before it is expanded', async ({
    page,
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create({ amountCents: 1150, memberCount: 2 })
    const [alice] = settlement.members
    await submitToBank(authenticatedRequest, settlement.id)

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    // Nothing has come back yet, so the row says nothing about reversals.
    await settlementsPage.expectNoReversedCount(settlement.id)

    expect(
      (
        await authenticatedRequest.post(`/api/admin/settlements/${settlement.id}/reverse`, {
          data: { reason: 'club_error', member_ids: [alice.id] },
        })
      ).status()
    ).toBe(201)

    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()
    await settlementsPage.expectReversedCount(settlement.id, /1 von 2 zurückgebucht/)
  })
})
