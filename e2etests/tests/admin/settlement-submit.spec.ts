/**
 * Recording that a settlement's SEPA file reached the bank (#377).
 *
 * This is the loop the screenshot in #377 stops halfway through: the treasurer
 * exports the pain.008 file, uploads it in online banking — and the admin
 * panel offers nothing to say so. The backend has had the state all along
 * (`submitted_at`, `POST /settlements/{id}/submit`, six derived statuses); the
 * page threw the server's answer away and re-derived three of its own, so
 * `submitted` was literally unrenderable.
 *
 * What these tests pin, in the treasurer's terms:
 *  1. after sending the file, the run can be recorded as submitted, it says so
 *     after a reload, and the row's undo slot switches to reversal (#433 §2)
 *  2. the dialog states what marking submitted costs before it is done
 *  3. a file already at the bank cannot be exported again
 *  4. a run nobody exported offers no way to claim it is at the bank
 *  5. a forgotten click is visible — exported and unsent carries a marker
 *  6. the pills find the run under "Übermittelt" and no longer under "Exportiert"
 *
 * E2E Patterns applied:
 *  - Pattern 001: each test builds its own settlement via `settlementFactory`
 *  - Pattern 006/007: all interaction goes through the page object
 *  - Pattern 008: `expect()` assertions, no try-catch visibility checks
 */

import { test, expect } from '../../fixtures/auth.fixture'
import { SettlementsPage } from '../../pages/SettlementsPage'
import { exportSepaXml } from '../../fixtures/encryption'

test.describe('Marking a settlement as submitted to the bank', () => {
  test('after sending the SEPA file, the run is recorded as submitted and can no longer be cancelled', async ({
    page,
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create()

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    // Before the file exists there is nothing to confirm.
    await settlementsPage.expectStatusBadge(settlement.id, 'Entwurf')
    await settlementsPage.expectMarkSubmittedNotOffered(settlement.id)

    // Export through the UI: the whole point of the flow is that the treasurer
    // never leaves this page.
    const exportResponse = await settlementsPage.clickExportSepa(settlement.id)
    expect(exportResponse.status()).toBe(200)
    await settlementsPage.waitForPageLoad()
    await settlementsPage.expectStatusBadge(settlement.id, 'Exportiert')

    await settlementsPage.markSubmitted(settlement.id)
    await settlementsPage.expectSubmitSuccessVisible()
    await settlementsPage.expectStatusBadge(settlement.id, 'Übermittelt')

    // It persisted, rather than merely repainting the badge.
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()
    await settlementsPage.expectStatusBadge(settlement.id, 'Übermittelt')

    // End to end: the backend recorded the submission, not just the UI.
    const stored = await authenticatedRequest.get(`/api/admin/settlements/${settlement.id}`)
    expect(stored.status()).toBe(200)
    const body = await stored.json()
    expect(body.submitted_at).not.toBeNull()
    expect(body.submitted_by_admin_id).not.toBeNull()
    expect(body.status).toBe('submitted')

    // And the consequence the dialog warned about: cancellation is foreclosed
    // and the row's single slot has switched to the remaining remedy (#433 §2).
    // It used to keep offering "Rückgängig" and refuse the click with the
    // backend's reason — the disabled control #81 objected to.
    await settlementsPage.expectReversalOffered(settlement.id)
  })

  test('the treasurer is told what marking submitted costs before doing it', async ({
    page,
    authenticatedRequest,
    settlementFactory,
  }) => {
    // Two members at €25 each: figures the dialog cannot produce by accident.
    const settlement = await settlementFactory.create({ amountCents: 2500, memberCount: 2 })
    expect((await exportSepaXml(authenticatedRequest, settlement.id)).status()).toBe(200)

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    const rowAmount = await settlementsPage.getSettlementTotalAmount(settlement.id)

    await settlementsPage.openMarkSubmittedDialog(settlement.id)
    const details = await settlementsPage.getMarkSubmittedDialogDetails()

    // Compared against the row's own rendering rather than a literal, so the
    // assertion holds in either UI language.
    expect(details.amount).toBe(rowAmount?.trim())
    expect(details.amount).toContain('50')
    expect(details.members).toBe('2')
    expect(details.date).toMatch(/\d{1,2}\.\d{1,2}\.\d{4}/)

    // The fact the button cannot carry: this closes the undo path for good.
    await settlementsPage.expectMarkSubmittedWarning(/storniert werden.*Rückbuchung/s)

    // Dismissing asks nothing of the backend: the run is still cancellable.
    await settlementsPage.dismissMarkSubmittedDialog()
    const after = await authenticatedRequest.get(`/api/admin/settlements/${settlement.id}`)
    expect((await after.json()).submitted_at).toBeNull()
  })

  test('a file already at the bank cannot be exported again', async ({
    page,
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create()
    expect((await exportSepaXml(authenticatedRequest, settlement.id)).status()).toBe(200)

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    // Regenerating a file that has not been sent is legitimate — the treasurer
    // may have lost the download.
    await settlementsPage.expectExportSepaEnabled(settlement.id)

    await settlementsPage.markSubmitted(settlement.id)

    // Once it is with the bank it is not: a second file is a second debit.
    await settlementsPage.expectExportSepaDisabled(settlement.id)
    await settlementsPage.expectMarkSubmittedNotOffered(settlement.id)
  })

  test('a run nobody has exported offers no way to claim it is at the bank', async ({
    page,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create()

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    // The backend 409s on this, so the UI mirrors that rather than offering a
    // button whose only outcome is an error banner.
    await settlementsPage.expectStatusBadge(settlement.id, 'Entwurf')
    await settlementsPage.expectMarkSubmittedNotOffered(settlement.id)
    await settlementsPage.expectNoAwaitingConfirmation(settlement.id)
  })

  test('a forgotten click is visible: exported and unsent says so', async ({
    page,
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create()
    expect((await exportSepaXml(authenticatedRequest, settlement.id)).status()).toBe(200)

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    // Ruling #142 §2: the one state the system cannot tell apart from a
    // forgotten click has to say so, or it drifts silently.
    await settlementsPage.expectAwaitingConfirmation(settlement.id)

    await settlementsPage.markSubmitted(settlement.id)
    await settlementsPage.expectNoAwaitingConfirmation(settlement.id)
  })

  test('filtering to "Übermittelt" finds the run just submitted, and "Exportiert" no longer does', async ({
    page,
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create()
    expect((await exportSepaXml(authenticatedRequest, settlement.id)).status()).toBe(200)

    const settlementsPage = new SettlementsPage(page)
    await settlementsPage.navigate()
    await settlementsPage.waitForPageLoad()

    await settlementsPage.filterByStatus('exported')
    await settlementsPage.expectSettlementRowVisible(settlement.id)

    await settlementsPage.filterByStatus('all')
    await settlementsPage.markSubmitted(settlement.id)

    await settlementsPage.filterByStatus('submitted')
    await settlementsPage.expectSettlementRowVisible(settlement.id)

    // The pill and the badge agree: a submitted run is no longer "exported".
    await settlementsPage.filterByStatus('exported')
    await settlementsPage.expectSettlementRowAbsent(settlement.id)
  })
})
