/**
 * Undoing a settlement — the confirmation (#127, ruling #142)
 *
 * Undoing a run returns its transactions to the unsettled pool, so the next
 * run collects them again. The old dialog asked "Undo this settlement? All
 * transactions will return to Open state." for every settlement alike: no
 * date, no amount, no member count, and no hint that a pain.008 file for this
 * run already existed. It was the last line of defence against debiting every
 * member twice, and it conveyed none of that.
 *
 * What the dialog now has to do, and what this file pins:
 *  1. state which run is about to be undone — date, total, members, transactions
 *  2. for a settlement whose SEPA file exists, warn and refuse to confirm until
 *     the admin states the file never reached the bank
 *  3. for a settlement the backend refuses to cancel, say why instead of
 *     offering a confirm button — the reason used to live in a `title`
 *     tooltip on a disabled button, which a touch device never shows
 *
 * E2E Patterns applied:
 *  - Pattern 001: each test builds its own settlement via `settlementFactory`
 *  - Pattern 006/007: all interaction goes through the page object
 *  - Pattern 008: `expect()` assertions, no try-catch visibility checks
 */

import { test, expect } from '../../fixtures/auth.fixture'
import { SettlementsPage } from '../../pages/SettlementsPage'
import { exportSepaXml } from '../../fixtures/encryption'

test.describe('Settlement undo confirmation', () => {
  test('the confirmation states the date, total, members and transactions of the run', async ({
    page,
    settlementFactory,
  }) => {
    // Two members, one €25 purchase each: figures the dialog cannot produce by
    // accident from a hardcoded default.
    const settlement = await settlementFactory.create({ amountCents: 2500, memberCount: 2 })

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()
    await settlementsPage.expectSettlementRowVisible(settlement.id)

    const rowAmount = await settlementsPage.getSettlementTotalAmount(settlement.id)

    await settlementsPage.openUndoDialog(settlement.id)
    const details = await settlementsPage.getUndoDialogDetails()

    // The amount is compared against the row's own rendering rather than a
    // literal, so the assertion holds in either UI language.
    expect(details.amount).toBe(rowAmount?.trim())
    expect(details.amount).toContain('50')
    expect(details.members).toBe('2')
    expect(details.transactions).toBe('2')
    expect(details.date).toMatch(/\d{1,2}\.\d{1,2}\.\d{4}/)

    // A run that was never exported has nothing extra to acknowledge.
    await settlementsPage.expectNoUndoExportWarning()
    await settlementsPage.expectUndoConfirmEnabled()

    // Dismissing asks nothing of the backend: the settlement stays live.
    await settlementsPage.dismissUndoDialog()
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()
    // "Entwurf", not the old catch-all "Aktiv": since #377 the badge names the
    // rung the server derived, and a run with no file is a draft.
    expect((await settlementsPage.getSettlementStatusText(settlement.id))?.trim()).toBe('Entwurf')
  })

  test('an exported settlement warns about the SEPA file and waits for an acknowledgement', async ({
    page,
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create()

    // Generate the pain.008 file, exactly as the SEPA button does. Exporting
    // is deliberately not a cancellation blocker (ruling #142 §1 — generating
    // a file is not sending it), which is why the dialog has to ask.
    const exportResponse = await exportSepaXml(authenticatedRequest, settlement.id)
    expect(exportResponse.status()).toBe(200)

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()
    expect((await settlementsPage.getSettlementStatusText(settlement.id))?.trim()).toBe('Exportiert')

    await settlementsPage.openUndoDialog(settlement.id)
    await settlementsPage.expectUndoExportWarning()
    await settlementsPage.expectUndoConfirmDisabled()

    await settlementsPage.acknowledgeUndoExport()
    await settlementsPage.expectUndoConfirmEnabled()

    await settlementsPage.confirmUndoDialog(settlement.id)
    expect((await settlementsPage.getSettlementStatusText(settlement.id))?.trim()).toBe('Storniert')

    // The undo says so outright, rather than leaving the treasurer to spot a
    // changed badge in one row of a long list (#130).
    await settlementsPage.expectUndoSuccessVisible()
    expect((await settlementsPage.getUndoSuccessMessage()).length).toBeGreaterThan(5)

    // End to end: the backend really cancelled the run, not just the UI.
    const after = await authenticatedRequest.get(`/api/admin/settlements/${settlement.id}`)
    expect(after.status()).toBe(200)
    expect((await after.json()).is_cancelled).toBe(true)
  })

  test('the acknowledgement is asked again the next time the dialog opens', async ({
    page,
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create()
    const exportResponse = await exportSepaXml(authenticatedRequest, settlement.id)
    expect(exportResponse.status()).toBe(200)

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    await settlementsPage.openUndoDialog(settlement.id)
    await settlementsPage.acknowledgeUndoExport()
    await settlementsPage.expectUndoConfirmEnabled()
    await settlementsPage.dismissUndoDialog()

    // A tick that survives the dialog would let the second undo through
    // unread — the warning is only a warning if it is answered every time.
    await settlementsPage.openUndoDialog(settlement.id)
    await settlementsPage.expectUndoConfirmDisabled()
    await settlementsPage.dismissUndoDialog()
  })

  test('a settlement submitted to the bank cannot be undone, and the dialog says why', async ({
    page,
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create()

    // Once the file is with the bank the money has moved: CancellationGate
    // shuts and reversal is the only remaining remedy (ruling #142 §1).
    // Submitting requires an exported file — there is nothing at the bank
    // otherwise — so the export comes first.
    const exportResponse = await exportSepaXml(authenticatedRequest, settlement.id)
    expect(exportResponse.status()).toBe(200)

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    // Through the UI, not a raw POST. This test used to reach around the admin
    // panel with `authenticatedRequest.post(.../submit)` because the panel
    // offered no way into the submitted state at all — that side door was #377
    // in test form, and it is closed now.
    await settlementsPage.markSubmitted(settlement.id)

    // The button still opens — a disabled button hides its reason behind a
    // hover the phone that took the payment cannot perform.
    await settlementsPage.expectUndoButtonEnabled(settlement.id)
    await settlementsPage.openUndoDialog(settlement.id)
    expect(await settlementsPage.getUndoDialogTitle()).toMatch(/nicht storniert werden/)
    await settlementsPage.expectUndoBlocked(/submitted to the bank.*Reverse it instead/s)

    // The figures are stated here too, so the reason names a specific run.
    const details = await settlementsPage.getUndoDialogDetails()
    expect(details.members).toBe('1')
    expect(details.amount).toContain('25')

    await settlementsPage.dismissUndoDialog()

    // Nothing was undone: the settlement is still live for the bank.
    const after = await authenticatedRequest.get(`/api/admin/settlements/${settlement.id}`)
    expect(after.status()).toBe(200)
    expect((await after.json()).is_cancelled).toBe(false)
  })

  test('an already-cancelled settlement offers no undo button at all', async ({
    page,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create()

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    await settlementsPage.undoSettlement(settlement.id)
    expect((await settlementsPage.getSettlementStatusText(settlement.id))?.trim()).toBe('Storniert')
    // There is nothing left to explain, so the button is simply dead.
    await settlementsPage.expectUndoButtonDisabled(settlement.id)
  })
})
