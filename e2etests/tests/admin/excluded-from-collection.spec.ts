/**
 * Excluded from Collection — the standing view under Members (#188).
 *
 * The settlement preview already partitions the members a run leaves out, but
 * only while somebody has the preview open. These tests pin the thing that
 * fixes: between runs, the treasurer can see who the club owes money to and
 * who the next run will skip, and can act on the second without leaving the
 * page.
 *
 * The two listings need opposite remedies — pay them back versus investigate
 * the bank return — so several tests assert not just that a member is in the
 * right section but that they are absent from the wrong one.
 *
 * Patterns: 001 (data seeded per test), 003 (rows found by their own ids, never
 * by position), 004 (parallel-safe — no shared state, no assertions about
 * members this test did not seed), 005 (test IDs), 006 (page object),
 * 008 (expect, never try/catch).
 */

import { test, expect } from '../../fixtures/auth.fixture'
import { ExcludedFromCollectionPage } from '../../pages/ExcludedFromCollectionPage'
import { NewSettlementPage } from '../../pages/NewSettlementPage'
import { seedCreditMember, seedHeldMember, seedMember } from '../../utils/exclusions'

test.describe('Excluded from Collection', () => {
  test('a member in credit is listed here and cannot be collected from', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    page,
  }) => {
    // The test #188 names by hand. Credit is seeded the way production makes
    // it: a purchase that was collected and is only then reversed.
    const inCredit = await seedCreditMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Shown',
      amountCents: 1500,
    })

    const excluded = new ExcludedFromCollectionPage(page)
    await excluded.goto()

    await excluded.expectMemberInCreditSection(inCredit.memberId)
    expect(await excluded.getCreditRowText(inCredit.memberId)).toContain(inCredit.lastName)
    // €15.00 owed back, however the active locale punctuates it.
    expect(await excluded.getCreditRowText(inCredit.memberId)).toMatch(/15[.,]00/)

    // A member in credit is not on hold — the two remedies contradict.
    await excluded.expectMemberNotInHoldSection(inCredit.memberId)

    // And the run itself still refuses to collect from them.
    const newSettlement = new NewSettlementPage(page)
    await newSettlement.goto()
    await newSettlement.expectMemberInCreditSection(inCredit.memberId)
    await newSettlement.expectMemberNotSelectable(inCredit.memberId)
  })

  test('a held member is listed with the reason the bank return wrote', async ({
    authenticatedRequest,
    settlementFactory,
    page,
  }) => {
    const held = await seedHeldMember(authenticatedRequest, settlementFactory, {
      tag: 'Reason',
      amountCents: 1700,
    })

    const excluded = new ExcludedFromCollectionPage(page)
    await excluded.goto()

    await excluded.expectMemberInHoldSection(held.memberId)

    const row = await excluded.getHoldRowText(held.memberId)
    expect(row).toMatch(/17[.,]00/)
    // The reason is server-composed prose, shown verbatim so "why was this
    // member skipped in March?" stays answerable.
    expect(row).toContain(held.bankReference)

    await excluded.expectMemberNotInCreditSection(held.memberId)
  })

  test('the confirmation names the hold it is about to clear', async ({
    authenticatedRequest,
    settlementFactory,
    page,
  }) => {
    const held = await seedHeldMember(authenticatedRequest, settlementFactory, {
      tag: 'Dialog',
      amountCents: 1300,
    })

    const excluded = new ExcludedFromCollectionPage(page)
    await excluded.goto()
    await excluded.openClearDialog(held.memberId)

    const dialog = await excluded.getClearDialogText()
    expect(dialog, 'the amount going back into the next run').toMatch(/13[.,]00/)
    expect(dialog, 'which hold this is').toContain(held.bankReference)

    // Cancelling changes nothing — the row is still held.
    await excluded.cancelClear()
    await excluded.expectMemberInHoldSection(held.memberId)
  })

  test('clearing a hold puts the member back into the next run', async ({
    authenticatedRequest,
    settlementFactory,
    page,
  }) => {
    const held = await seedHeldMember(authenticatedRequest, settlementFactory, {
      tag: 'Clear',
      amountCents: 1100,
    })

    const excluded = new ExcludedFromCollectionPage(page)
    await excluded.goto()
    await excluded.expectMemberInHoldSection(held.memberId)

    await excluded.clearHold(held.memberId)

    // The row leaves the listing without a reload — the mutation refetches.
    await excluded.expectMemberNotInHoldSection(held.memberId)

    // And the consequence the dialog promised actually happened: the member is
    // selectable on the next run again.
    const newSettlement = new NewSettlementPage(page)
    await newSettlement.goto()
    await newSettlement.search(held.memberName)
    await newSettlement.expectMemberSelectable(held.memberId)
  })

  test('the totals add up the rows the page is showing', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    settlementFactory,
    page,
  }) => {
    const inCredit = await seedCreditMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Total',
      amountCents: 2200,
    })
    const held = await seedHeldMember(authenticatedRequest, settlementFactory, {
      tag: 'Total',
      amountCents: 1900,
    })

    const excluded = new ExcludedFromCollectionPage(page)
    await excluded.goto()

    // Self-consistency, deliberately: both totals are club-wide aggregates that
    // other workers are moving while this test runs, so comparing the page
    // against a *separately fetched* server total races them (Pattern 004) —
    // a member seeded or deleted between the two reads makes them disagree for
    // no reason the page is at fault for. What must hold regardless is that one
    // render's total equals the rows in that same render.
    const creditAmounts = await excluded.getCreditAmountsCents()
    const holdAmounts = await excluded.getHoldAmountsCents()

    expect(creditAmounts).toContain(-2200)
    expect(holdAmounts).toContain(1900)

    const sum = (values: number[]) => values.reduce((total, value) => total + value, 0)
    expect(await excluded.getCreditTotalCents()).toBe(sum(creditAmounts))
    expect(await excluded.getHoldTotalCents()).toBe(sum(holdAmounts))

    // And the seeded members really are the ones behind those figures.
    await excluded.expectMemberInCreditSection(inCredit.memberId)
    await excluded.expectMemberInHoldSection(held.memberId)
  })

  test('the Members tab reaches the surface and its badge counts both listings', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    settlementFactory,
    page,
  }) => {
    await seedCreditMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Badge',
      amountCents: 600,
    })
    await seedHeldMember(authenticatedRequest, settlementFactory, {
      tag: 'Badge',
      amountCents: 800,
    })

    const excluded = new ExcludedFromCollectionPage(page)
    await excluded.gotoViaTab()
    await excluded.expectTabsVisible()

    const credit = await (
      await authenticatedRequest.get('/api/admin/members/credit-balances')
    ).json()
    const holds = await (
      await authenticatedRequest.get('/api/admin/members/collection-holds')
    ).json()

    // The one figure that makes the tab worth clicking: how many members the
    // next run will leave out, across both reasons.
    expect(await excluded.getTabBadgeCount()).toBe(credit.items.length + holds.items.length)
  })

  test('the badge is readable from the roster, before the tab is clicked', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    settlementFactory,
    page,
  }) => {
    // The badge is the figure that makes the tab worth clicking, so it has to
    // be on the roster. It was not: MembersPage rendered the tab strip without
    // a count, and the badge appeared only once you were already on the page it
    // advertises. The earlier badge test missed it by navigating through first.
    await seedCreditMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Roster',
      amountCents: 500,
    })
    await seedHeldMember(authenticatedRequest, settlementFactory, {
      tag: 'Roster',
      amountCents: 700,
    })

    const excluded = new ExcludedFromCollectionPage(page)
    await excluded.gotoRoster()

    const credit = await (
      await authenticatedRequest.get('/api/admin/members/credit-balances')
    ).json()
    const holds = await (
      await authenticatedRequest.get('/api/admin/members/collection-holds')
    ).json()

    expect(await excluded.getTabBadgeCount()).toBe(credit.items.length + holds.items.length)
  })

  test('a member with an ordinary balance is in neither listing', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    page,
  }) => {
    // The surface must not become "all members with a balance" — a collectable
    // member appearing here would send the treasurer chasing a non-problem.
    const collectable = await seedMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'Plain',
      amounts: [900],
      prefix: 'Excl',
    })

    const excluded = new ExcludedFromCollectionPage(page)
    await excluded.goto()

    await excluded.expectMemberNotInCreditSection(collectable.memberId)
    await excluded.expectMemberNotInHoldSection(collectable.memberId)
  })
})
