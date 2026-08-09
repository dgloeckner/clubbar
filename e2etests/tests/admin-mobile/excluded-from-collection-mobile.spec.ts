/**
 * Excluded from Collection on a phone (#188).
 *
 * The hold listing carries a server-composed reason sentence — a settlement
 * id and a bank reference in prose — which no phone-width table column can
 * hold. Below the tablet breakpoint both listings become one card per member
 * instead: name and amount on one line so the page stays skimmable for the
 * figure, everything else stacked underneath.
 *
 * These tests exist because a table that merely *overflows* looks fine in a
 * screenshot and is unusable in the hand. They assert the card layout is what
 * rendered, that the page does not scroll sideways, and that the one write on
 * the surface still works when driven through it.
 *
 * Project `admin-mobile` — iPhone 14, which Playwright drives with **WebKit**,
 * not Chromium.
 *
 * Patterns: 001 (data seeded per test), 003 (rows by id), 004 (parallel-safe),
 * 005 (test IDs), 006 (page object), 008 (expect, never try/catch).
 */

import { test, expect } from '../../fixtures/auth.fixture'
import { ExcludedFromCollectionPage } from '../../pages/ExcludedFromCollectionPage'
import { seedCreditMember, seedHeldMember } from '../../utils/exclusions'

test.describe('Excluded from Collection — mobile', () => {
  test('both listings render as cards, not as an overflowing table', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    settlementFactory,
    page,
  }) => {
    const inCredit = await seedCreditMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'MobCredit',
      amountCents: 2450,
    })
    const held = await seedHeldMember(authenticatedRequest, settlementFactory, {
      tag: 'MobHold',
      amountCents: 3820,
    })

    const excluded = new ExcludedFromCollectionPage(page)
    await excluded.goto()

    // Both members are on screen with their figures...
    await excluded.expectMemberInCreditSection(inCredit.memberId)
    expect(await excluded.getCreditRowText(inCredit.memberId)).toMatch(/24[.,]50/)

    await excluded.expectMemberInHoldSection(held.memberId)
    const holdCard = await excluded.getHoldRowText(held.memberId)
    expect(holdCard).toMatch(/38[.,]20/)
    // ...including the reason, which is the whole argument for cards here.
    expect(holdCard).toContain(held.bankReference)

    // ...and neither is inside a table at this width.
    await expect(page.getByTestId('excluded-credit-table')).toHaveCount(0)
    await expect(page.getByTestId('excluded-hold-table')).toHaveCount(0)
  })

  test('the page does not scroll sideways', async ({
    authenticatedRequest,
    settlementFactory,
    page,
  }) => {
    // The hold reason is the longest string the surface renders; if anything
    // pushes the body wider than the viewport, it is that.
    await seedHeldMember(authenticatedRequest, settlementFactory, {
      tag: 'MobWide',
      amountCents: 1500,
    })

    const excluded = new ExcludedFromCollectionPage(page)
    await excluded.goto()

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth
    )
    expect(overflow, 'the body must not scroll horizontally at phone width').toBeLessThanOrEqual(1)
  })

  test('a hold can be cleared from its card', async ({
    authenticatedRequest,
    settlementFactory,
    page,
  }) => {
    const held = await seedHeldMember(authenticatedRequest, settlementFactory, {
      tag: 'MobClear',
      amountCents: 1100,
    })

    const excluded = new ExcludedFromCollectionPage(page)
    await excluded.goto()
    await excluded.expectMemberInHoldSection(held.memberId)

    // The confirmation still names the hold — the phone gets the same question
    // the desktop asks, not a shortened one.
    await excluded.openClearDialog(held.memberId)
    const dialog = await excluded.getClearDialogText()
    expect(dialog).toMatch(/11[.,]00/)
    expect(dialog).toContain(held.bankReference)

    await excluded.confirmClear()
    await excluded.expectMemberNotInHoldSection(held.memberId)
  })

  test('the Members tabs stay reachable at phone width', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    page,
  }) => {
    await seedCreditMember(authenticatedRequest, authenticatedTerminalRequest, {
      tag: 'MobTab',
      amountCents: 700,
    })

    const excluded = new ExcludedFromCollectionPage(page)
    await excluded.gotoViaTab()
    await excluded.expectTabsVisible()
    await excluded.expectPageVisible()
  })
})
