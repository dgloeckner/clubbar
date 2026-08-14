/**
 * Telling settlement states apart on a phone (#377).
 *
 * The mobile card was worse than the table it replaces: the table knew three
 * states and the card knew two — `is_cancelled ? cancelled : active` — so on a
 * phone an exported run was indistinguishable from a draft, and a run already
 * with the bank looked like both. The treasurer doing this on the phone that
 * took the payments is exactly who is at risk of submitting twice.
 *
 * Both layouts now render the same badge component, so this file guards that
 * the phone shows the whole ladder rather than a two-value summary of it.
 *
 * E2E Patterns applied:
 *  - Pattern 001: each test builds its own settlement via `settlementFactory`
 *  - Pattern 006/007: all interaction goes through the page object
 *  - Pattern 008: `expect()` assertions, no try-catch visibility checks
 */

import { test, expect } from '../../fixtures/auth.fixture'
import { SettlementsPage } from '../../pages/SettlementsPage'
import { exportSepaXml } from '../../fixtures/encryption'

test.describe('Settlement status on a phone', () => {
  test('the card distinguishes draft, exported and submitted — not just cancelled vs active', async ({
    page,
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create()

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForMobileCardsLoad()
    await settlementsPage.expectStatusBadge(settlement.id, 'Entwurf')

    expect((await exportSepaXml(authenticatedRequest, settlement.id)).status()).toBe(200)
    await settlementsPage.navigate()
    await settlementsPage.waitForMobileCardsLoad()
    await settlementsPage.expectStatusBadge(settlement.id, 'Exportiert')
    // The marker matters most here: a phone cannot hover a tooltip to find out
    // whether the file was ever sent (ruling #142 §2).
    await settlementsPage.expectAwaitingConfirmation(settlement.id)

    await settlementsPage.markSubmitted(settlement.id)
    await settlementsPage.waitForMobileCardsLoad()
    await settlementsPage.expectStatusBadge(settlement.id, 'Übermittelt')
    await settlementsPage.expectNoAwaitingConfirmation(settlement.id)
    await settlementsPage.expectExportSepaDisabled(settlement.id)
  })
})
