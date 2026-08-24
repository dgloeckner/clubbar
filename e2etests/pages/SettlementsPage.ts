/**
 * Settlements Page Object
 *
 * Encapsulates all interactions with the settlements page.
 * Implements E2E Testing Pattern 006: Page Object Model
 *
 * **CRITICAL PATTERN PRINCIPLES:**
 * 1. Page object provides HIGH-LEVEL SEMANTIC METHODS (not raw locators)
 * 2. Tests use page object methods, NOT page.locator() or page.getByTestId()
 * 3. All locators are PRIVATE and hidden from tests
 * 4. Page object handles data-testid selection internally
 */

import { Page, expect } from '@playwright/test'
import { BasePage } from './BasePage'
import { DEV_PRIVATE_KEY } from '../fixtures/encryption'
import { TEST_CREDENTIALS } from '../config/test-credentials'
import { generateTotp } from '../utils/totp'

export class SettlementsPage extends BasePage {
  // Loading and state indicators (PRIVATE)
  private readonly loadingIndicator = () => this.page.getByTestId('settlements-loading')

  // Main page locators (PRIVATE)
  private readonly table = () => this.page.getByTestId('settlements-table')
  private readonly emptyState = () => this.page.getByTestId('settlements-empty-state')
  private readonly mobileCards = () => this.page.getByTestId('settlements-mobile-cards')

  // Settlement list row/cell locators
  private readonly settlementRow = (settlementId: string) =>
    this.page.getByTestId(`settlements-table-row-${settlementId}`)
  private readonly settlementMemberCount = (settlementId: string) =>
    this.page.getByTestId(`settlements-member-count-${settlementId}`)
  private readonly settlementPrice = (settlementId: string) =>
    this.page.getByTestId(`settlements-price-${settlementId}`)
  private readonly settlementStatusBadge = (settlementId: string) =>
    this.page.getByTestId(`settlements-badge-status-${settlementId}`)
  // "wartet auf Bestätigung" — a file was generated and nobody has said it
  // reached the bank (#377, ruling #142 §2).
  private readonly awaitingConfirmationMarker = (settlementId: string) =>
    this.page.getByTestId(`settlements-badge-status-${settlementId}-awaiting`)
  private readonly settlementRows = () => this.page.locator('[data-testid^="settlements-table-row-"]')
  // The second line of the date cell — the period the run swept, printed only
  // when it says something the settlement's own date does not (#378).
  private readonly settlementTransactionPeriod = (settlementId: string) =>
    this.page.getByTestId(`settlements-transaction-period-${settlementId}`)
  private readonly exportWarning = () => this.page.getByTestId('settlements-export-warning')

  // SEPA export dialog (#393). The bank file is the one legitimate bulk
  // decryption, so it asks for the club's archived private key on top of the
  // usual step-up credential.
  private readonly exportSepaButton = (settlementId: string) =>
    this.page.getByTestId(`settlements-export-sepa-btn-${settlementId}`)
  private readonly exportPrivateKeyInput = () => this.page.getByTestId('private-key-paste')
  private readonly exportPrivateKeyFile = () => this.page.getByTestId('private-key-file')
  private readonly exportPasswordInput = () => this.page.getByTestId('step-up-password')
  private readonly exportTotpInput = () => this.page.getByTestId('step-up-totp-code')
  private readonly exportConfirmButton = () => this.page.getByTestId('confirm-dialog-ok')
  private readonly exportDialogError = () => this.page.getByTestId('step-up-error')

  // Undo confirmation dialog (#127). It states what is about to be undone —
  // date, amount, members, transactions — and, once a SEPA file exists for the
  // settlement, refuses to confirm until the export is acknowledged.
  private readonly undoButton = (settlementId: string) =>
    this.page.getByTestId(`settlements-undo-btn-${settlementId}`)
  private readonly undoDialog = () => this.page.getByTestId('confirm-dialog')
  private readonly undoDialogTitle = () => this.page.getByTestId('confirm-dialog-content').getByRole('heading')
  private readonly undoDialogConfirm = () => this.page.getByTestId('confirm-dialog-ok')
  private readonly undoDialogCancel = () => this.page.getByTestId('confirm-dialog-cancel')
  private readonly undoDialogDetail = (field: 'date' | 'amount' | 'members' | 'transactions') =>
    this.page.getByTestId(`undo-settlement-detail-${field}`)
  private readonly undoDialogBlockedReason = () => this.page.getByTestId('undo-settlement-blocked-reason')
  private readonly undoDialogExportWarning = () => this.page.getByTestId('undo-settlement-export-warning')
  private readonly undoDialogExportAck = () => this.page.getByTestId('undo-settlement-export-ack')

  // Mark as submitted (#377). Generating the pain.008 file is not sending it,
  // so this is the click that records the treasurer uploaded it — and the
  // point after which the run can only be reversed, never cancelled.
  private readonly markSubmittedButton = (settlementId: string) =>
    this.page.getByTestId(`settlements-mark-submitted-btn-${settlementId}`)
  private readonly markSubmittedDialog = () => this.page.getByTestId('confirm-dialog')
  private readonly markSubmittedConfirm = () => this.page.getByTestId('confirm-dialog-ok')
  private readonly markSubmittedCancel = () => this.page.getByTestId('confirm-dialog-cancel')
  private readonly markSubmittedDetail = (field: 'date' | 'amount' | 'members') =>
    this.page.getByTestId(`mark-submitted-detail-${field}`)
  private readonly markSubmittedWarning = () => this.page.getByTestId('mark-submitted-warning')
  private readonly submitSuccessBanner = () => this.page.getByTestId('settlements-submit-success')

  // Reversal (#433). The row has ONE undo slot: it reads "Rückgängig" while
  // the run can still be cancelled and becomes "Zurückbuchen" the moment it
  // cannot, because exactly one of the two gates is open for any live
  // settlement. The two test IDs are therefore mutually exclusive by design —
  // asserting on both is how the "door that refuses to open" gets caught.
  private readonly reverseButton = (settlementId: string) =>
    this.page.getByTestId(`settlements-reverse-btn-${settlementId}`)
  private readonly reverseDialog = () => this.page.getByTestId('confirm-dialog')
  private readonly reverseConfirm = () => this.page.getByTestId('confirm-dialog-ok')
  private readonly reverseCancel = () => this.page.getByTestId('confirm-dialog-cancel')
  private readonly reverseIrreversibleNotice = () => this.page.getByTestId('reverse-settlement-irreversible')
  private readonly reverseHoldWarning = () => this.page.getByTestId('reverse-settlement-hold-warning')
  private readonly reverseBlockedReason = () => this.page.getByTestId('reverse-settlement-blocked-reason')
  private readonly reverseScopeToggle = () => this.page.getByTestId('reverse-settlement-scope-toggle')
  private readonly reverseMemberList = () => this.page.getByTestId('reverse-settlement-member-list')
  private readonly reverseMemberCheckbox = (memberId: string) =>
    this.page.getByTestId(`reverse-settlement-member-checkbox-${memberId}`)
  private readonly reverseLockedMember = () => this.page.getByTestId('reverse-settlement-locked-member')
  private readonly reverseBankReferenceInput = () => this.page.getByTestId('reverse-settlement-bank-reference')
  private readonly reverseSuccessBanner = () => this.page.getByTestId('settlements-reverse-success')
  private readonly reverseSuccessHoldLink = () =>
    this.page.getByTestId('settlements-reverse-success-hold-link')

  // The bank-return lookup (#433 §3). A page action, not a row action: the
  // treasurer arrives holding a statement and does not know which run it came
  // out of, which is the one fact a row action would presume.
  private readonly recordBankReturnButton = () => this.page.getByTestId('settlements-record-bank-return-btn')
  private readonly lookupInput = () => this.page.getByTestId('record-bank-return-input')
  private readonly lookupCandidates = () => this.page.getByTestId('record-bank-return-candidates')
  private readonly lookupNoMatch = () => this.page.getByTestId('record-bank-return-no-match')
  private readonly lookupCandidate = (settlementId: string, memberId: string) =>
    this.page.getByTestId(`record-bank-return-candidate-${settlementId}-${memberId}`)
  private readonly lookupCandidateBlocked = (settlementId: string, memberId: string) =>
    this.page.getByTestId(`record-bank-return-candidate-${settlementId}-${memberId}-blocked`)

  // The member breakdown behind the expand (#433 §7). `reversals[]` is
  // returned by nothing else, and the settlement detail page was deliberately
  // deleted — what failed to justify a page can still justify a disclosure.
  private readonly expandButton = (settlementId: string) =>
    this.page.getByTestId(`settlements-expand-btn-${settlementId}`)
  private readonly breakdown = () => this.page.getByTestId('settlement-breakdown')
  private readonly breakdownMember = (memberId: string) =>
    this.page.getByTestId(`settlement-breakdown-member-${memberId}`)
  private readonly breakdownReversal = (memberId: string) =>
    this.page.getByTestId(`settlement-breakdown-reversal-${memberId}`)
  private readonly reversedCount = (settlementId: string) =>
    this.page.getByTestId(`settlements-reversed-count-${settlementId}`)

  // Status filter pills (#377): Alle / Entwurf / Exportiert / Übermittelt /
  // Zurückgebucht / Storniert.
  private readonly statusFilterPill = (
    status: 'all' | 'draft' | 'exported' | 'submitted' | 'reversed' | 'cancelled'
  ) => this.page.getByTestId(`settlements-status-filter-${status}`)

  // Pagination (PaginationToolbar rendered with testId="settlements")
  private readonly paginationPageButton = (pageNumber: number) =>
    this.page.getByTestId(`settlements-page-${pageNumber}`)
  private readonly paginationInfo = () => this.page.getByTestId('settlements-info')
  private readonly pageSizeSelect = () => this.page.getByTestId('settlements-page-size-select')
  private readonly periodPickerButton = (period: '1m' | '3m' | '6m' | '1y' | '2y' | 'all') =>
    this.page.getByTestId(`settlements-period-picker-${period}`)

  constructor(page: Page) {
    super(page)
  }

  /**
   * Navigate to settlements page
   */
  async navigate() {
    await super.navigate('http://localhost:5173/settlements')
  }

  /**
   * Wait for page to be fully loaded
   *
   * Waits for loading indicator to disappear, then waits for table or empty state.
   */
  async waitForPageLoad(timeout = 10000) {
    await expect(this.loadingIndicator()).toBeHidden({ timeout })
    await expect(this.table().or(this.emptyState())).toBeVisible({ timeout })
  }

  /**
   * Wait for the phone layout, which renders cards instead of a table.
   *
   * The card and the table share their status test IDs, so every status
   * assertion below works against either — which is the point: the card used
   * to know only cancelled vs active, and a badge that exists in one layout
   * and not the other is exactly how that regression survived (#377).
   */
  async waitForMobileCardsLoad(timeout = 15000) {
    await expect(this.loadingIndicator()).toBeHidden({ timeout })
    await expect(this.mobileCards()).toBeVisible({ timeout })
  }

  /**
   * SETTLEMENT LIST DATA
   */

  async getSettlementMemberCount(settlementId: string): Promise<string | null> {
    try {
      return await this.settlementMemberCount(settlementId).textContent()
    } catch {
      return null
    }
  }

  async getSettlementTotalAmount(settlementId: string): Promise<string | null> {
    try {
      return await this.settlementPrice(settlementId).textContent()
    } catch {
      return null
    }
  }

  async getSettlementStatusText(settlementId: string): Promise<string | null> {
    try {
      return await this.settlementStatusBadge(settlementId).textContent()
    } catch {
      return null
    }
  }

  async getSettlementRowCount(): Promise<number> {
    return await this.settlementRows().count()
  }

  /**
   * PAGINATION
   */

  /** Change rows per page and wait for the list the new size produced. */
  async setPageSize(size: number) {
    const responsePromise = this.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/settlements') &&
        new URL(resp.url()).searchParams.get('per_page') === String(size) &&
        resp.status() === 200
    )
    await this.pageSizeSelect().selectOption(String(size))
    await responsePromise
  }

  /** Click a page number and wait for the list that page produced. */
  async goToPage(pageNumber: number) {
    const responsePromise = this.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/settlements') &&
        new URL(resp.url()).searchParams.get('page') === String(pageNumber) &&
        resp.status() === 200
    )
    await this.paginationPageButton(pageNumber).click()
    await responsePromise
  }

  /**
   * Wait until the settlements list stops issuing requests — see the same
   * method on JournalPage: the #89 regression is a follow-up request that
   * resets the page to 1, so pagination state is only meaningful once the
   * page has gone quiet.
   */
  async waitForListToSettle() {
    await this.page.waitForLoadState('networkidle')
  }

  async expectActivePage(pageNumber: number) {
    await expect(this.paginationPageButton(pageNumber)).toHaveCSS('background-color', 'rgb(59, 130, 246)')
  }

  /** e.g. "Zeige 11-20 von 34" — the range the toolbar claims to be showing. */
  async getPaginationInfo(): Promise<string> {
    return (await this.paginationInfo().textContent())?.replace(/\s+/g, ' ').trim() ?? ''
  }

  async expectPeriodButtonActive(period: '1m' | '3m' | '6m' | '1y' | '2y' | 'all') {
    await expect(this.periodPickerButton(period)).toHaveCSS('background-color', 'rgb(59, 130, 246)')
  }

  async expectSettlementRowVisible(settlementId: string) {
    await expect(this.settlementRow(settlementId)).toBeVisible()
  }

  /**
   * A run made today over transactions booked today must print its date once.
   * The row used to repeat it as a one-day range underneath (#378).
   */
  async expectNoTransactionPeriod(settlementId: string) {
    await expect(this.settlementTransactionPeriod(settlementId)).toHaveCount(0)
  }

  async expectUndoButtonEnabled(settlementId: string) {
    await expect(this.page.getByTestId(`settlements-undo-btn-${settlementId}`)).toBeEnabled()
  }

  async expectUndoButtonDisabled(settlementId: string) {
    await expect(this.page.getByTestId(`settlements-undo-btn-${settlementId}`)).toBeDisabled()
  }

  /**
   * Click the "Export SEPA XML" button and wait for the download response.
   * Returns the Response so tests can assert on content-type and body.
   */
  /**
   * Export the bank file through the UI.
   *
   * Since #393 the export opens a dialog first: member IBANs are sealed and
   * the server cannot open them, so the club's archived private key and a
   * fresh step-up credential are collected here and travel with the request.
   */
  async clickExportSepa(settlementId: string): Promise<import('@playwright/test').Response> {
    await this.openExportSepaDialog(settlementId)
    await this.fillExportKeyAndPassword(DEV_PRIVATE_KEY, TEST_CREDENTIALS.admin.password)

    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/export/sepa-xml') && resp.status() === 200
    )
    await this.exportConfirmButton().click()
    return responsePromise
  }

  /** Open the export dialog without submitting it. */
  async openExportSepaDialog(settlementId: string) {
    await this.exportSepaButton(settlementId).click()
    await expect(this.exportPrivateKeyInput()).toBeVisible()
  }

  /**
   * Fill the dialog's secrets. The seeded admin has 2FA enrolled, so the
   * dialog asks for a code as well — filled only when it is actually shown.
   */
  async fillExportKeyAndPassword(privateKey: string, password: string, totpCode?: string) {
    await this.exportPrivateKeyInput().fill(privateKey)
    await this.exportPasswordInput().fill(password)

    if (await this.exportTotpInput().isVisible()) {
      await this.exportTotpInput().fill(totpCode ?? generateTotp(TEST_CREDENTIALS.totp.adminSecret))
    }
  }

  /** Choose the key from a file rather than pasting it. */
  async chooseExportKeyFile(path: string) {
    await this.exportPrivateKeyFile().setInputFiles(path)
  }

  async getExportKeyFieldValue(): Promise<string> {
    return await this.exportPrivateKeyInput().inputValue()
  }

  /** Submit the export dialog and wait for whatever the API answers. */
  async submitExportSepaDialog(): Promise<import('@playwright/test').Response> {
    const responsePromise = this.page.waitForResponse((resp) => resp.url().includes('/export/sepa-xml'))
    await this.exportConfirmButton().click()
    return responsePromise
  }

  /** The dialog stays open on a rejected key or credential, and says why. */
  async expectExportDialogError(expected: RegExp | string) {
    await expect(this.exportDialogError()).toBeVisible()
    await expect(this.exportDialogError()).toHaveText(expected)
  }

  async expectExportConfirmDisabled() {
    await expect(this.exportConfirmButton()).toBeDisabled()
  }

  /**
   * The banner shown when the SEPA file collects less than the settlement
   * records (#114) — a valid file was downloaded, but it leaves members out.
   */
  async expectExportShortfallWarning(expected: RegExp) {
    await expect(this.exportWarning()).toBeVisible()
    await expect(this.exportWarning()).toHaveText(expected)
  }

  async expectNoExportShortfallWarning() {
    await expect(this.exportWarning()).toBeHidden()
  }

  /**
   * Click the "Export CSV" (summary) button and wait for the download response.
   */
  async clickExportCsv(settlementId: string): Promise<import('@playwright/test').Response> {
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/export/csv') && resp.status() === 200
    )
    await this.page.getByTestId(`settlements-export-csv-btn-${settlementId}`).click()
    return responsePromise
  }

  /**
   * Click the "Export Transactions CSV" button and wait for the download response.
   */
  async clickExportTransactionsCsv(settlementId: string): Promise<import('@playwright/test').Response> {
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/export-transactions') && resp.status() === 200
    )
    await this.page.getByTestId(`settlements-export-transactions-btn-${settlementId}`).click()
    return responsePromise
  }

  /**
   * UNDO (#127)
   */

  /** Open the undo dialog and leave it open for inspection. */
  async openUndoDialog(settlementId: string) {
    await this.undoButton(settlementId).click()
    await expect(this.undoDialog()).toBeVisible()
  }

  /** Dismiss the undo dialog without undoing anything. */
  async dismissUndoDialog() {
    await this.undoDialogCancel().click()
    await expect(this.undoDialog()).toBeHidden()
  }

  /** The figures the dialog states about the settlement it is asking about. */
  async getUndoDialogDetails(): Promise<{
    date: string
    amount: string
    members: string
    transactions: string
  }> {
    return {
      date: (await this.undoDialogDetail('date').textContent())?.trim() ?? '',
      amount: (await this.undoDialogDetail('amount').textContent())?.trim() ?? '',
      members: (await this.undoDialogDetail('members').textContent())?.trim() ?? '',
      transactions: (await this.undoDialogDetail('transactions').textContent())?.trim() ?? '',
    }
  }

  async getUndoDialogTitle(): Promise<string> {
    return (await this.undoDialogTitle().textContent())?.trim() ?? ''
  }

  /**
   * The dialog for a settlement the backend refuses to cancel: it names the
   * reason and offers no confirm button at all, rather than a dead disabled
   * one whose explanation only a hover could reveal.
   */
  async expectUndoBlocked(reason: RegExp) {
    await expect(this.undoDialogBlockedReason()).toBeVisible()
    await expect(this.undoDialogBlockedReason()).toHaveText(reason)
    await expect(this.undoDialogConfirm()).toBeHidden()
  }

  /** The warning shown when a SEPA file has already been generated. */
  async expectUndoExportWarning() {
    await expect(this.undoDialogExportWarning()).toBeVisible()
  }

  async expectNoUndoExportWarning() {
    await expect(this.undoDialogExportWarning()).toBeHidden()
  }

  async expectUndoConfirmDisabled() {
    await expect(this.undoDialogConfirm()).toBeDisabled()
  }

  async expectUndoConfirmEnabled() {
    await expect(this.undoDialogConfirm()).toBeEnabled()
  }

  /** Tick "this file was never submitted to the bank". */
  async acknowledgeUndoExport() {
    await this.undoDialogExportAck().check()
  }

  /**
   * Click the undo button for a settlement, confirm via the undo dialog, and
   * wait for the settlement list to reload.
   *
   * A settlement whose SEPA file was already generated asks for an explicit
   * acknowledgement first (#127); this ticks it when it is there.
   *
   * After undo the settlement row remains visible with status "Storniert".
   */
  async undoSettlement(settlementId: string) {
    await this.openUndoDialog(settlementId)
    if (await this.undoDialogExportAck().isVisible()) {
      await this.acknowledgeUndoExport()
    }
    await this.confirmUndoDialog(settlementId)
  }

  /**
   * The banner confirming an undo went through (#130). Undoing only flips a
   * badge in one row, which on a long list is easy to miss.
   */
  async expectUndoSuccessVisible() {
    await expect(this.page.getByTestId('settlements-undo-success')).toBeVisible()
  }

  async getUndoSuccessMessage(): Promise<string> {
    return (await this.page.getByTestId('settlements-undo-success').textContent())?.trim() ?? ''
  }

  /** Confirm an already-open undo dialog and wait for the reloaded list. */
  async confirmUndoDialog(settlementId: string) {
    const responsePromise = this.page.waitForResponse(
      (resp) =>
        resp.url().includes(`/api/admin/settlements/${settlementId}/cancel`) &&
        resp.request().method() === 'DELETE' &&
        resp.status() === 200
    )
    await this.undoDialogConfirm().click()
    await responsePromise
    await this.waitForPageLoad()
  }

  /**
   * MARK AS SUBMITTED (#377)
   */

  /** Open the "mark as submitted" dialog and leave it open for inspection. */
  async openMarkSubmittedDialog(settlementId: string) {
    await this.markSubmittedButton(settlementId).click()
    await expect(this.markSubmittedDialog()).toBeVisible()
  }

  async dismissMarkSubmittedDialog() {
    await this.markSubmittedCancel().click()
    await expect(this.markSubmittedDialog()).toBeHidden()
  }

  /** The figures the dialog states about the run it is asking about. */
  async getMarkSubmittedDialogDetails(): Promise<{ date: string; amount: string; members: string }> {
    return {
      date: (await this.markSubmittedDetail('date').textContent())?.trim() ?? '',
      amount: (await this.markSubmittedDetail('amount').textContent())?.trim() ?? '',
      members: (await this.markSubmittedDetail('members').textContent())?.trim() ?? '',
    }
  }

  /** The cost the button does not carry: cancellation is foreclosed. */
  async expectMarkSubmittedWarning(expected: RegExp) {
    await expect(this.markSubmittedWarning()).toBeVisible()
    await expect(this.markSubmittedWarning()).toHaveText(expected)
  }

  /**
   * Confirm an already-open dialog and wait for the reloaded list.
   *
   * The reload is awaited as an HTTP response rather than through
   * `waitForPageLoad`, because this flow is exercised from the phone layout
   * too and that one renders cards where the desktop renders a table.
   */
  async confirmMarkSubmitted(settlementId: string) {
    const submitted = this.page.waitForResponse(
      (resp) =>
        resp.url().includes(`/api/admin/settlements/${settlementId}/submit`) &&
        resp.request().method() === 'POST' &&
        resp.status() === 200
    )
    const reloaded = this.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/settlements?') &&
        resp.request().method() === 'GET' &&
        resp.status() === 200
    )
    await this.markSubmittedConfirm().click()
    await submitted
    await reloaded
  }

  /** Open the dialog, confirm it, and wait for the list the submission produced. */
  async markSubmitted(settlementId: string) {
    await this.openMarkSubmittedDialog(settlementId)
    await this.confirmMarkSubmitted(settlementId)
  }

  async expectMarkSubmittedOffered(settlementId: string) {
    await expect(this.markSubmittedButton(settlementId)).toBeVisible()
  }

  /**
   * The action is absent, not disabled: for a run nobody exported there is no
   * claim to make, and a dead button would invite the click anyway.
   */
  async expectMarkSubmittedNotOffered(settlementId: string) {
    await expect(this.markSubmittedButton(settlementId)).toHaveCount(0)
  }

  async expectSubmitSuccessVisible() {
    await expect(this.submitSuccessBanner()).toBeVisible()
  }

  async expectExportSepaEnabled(settlementId: string) {
    await expect(this.exportSepaButton(settlementId)).toBeEnabled()
  }

  /** A file already with the bank must not be generated a second time (#377). */
  async expectExportSepaDisabled(settlementId: string) {
    await expect(this.exportSepaButton(settlementId)).toBeDisabled()
  }

  /**
   * STATUS (#377)
   */

  async expectStatusBadge(settlementId: string, expected: RegExp | string) {
    await expect(this.settlementStatusBadge(settlementId)).toHaveText(expected)
  }

  /** Exported, and nobody has said the file reached the bank. */
  async expectAwaitingConfirmation(settlementId: string) {
    await expect(this.awaitingConfirmationMarker(settlementId)).toBeVisible()
  }

  async expectNoAwaitingConfirmation(settlementId: string) {
    await expect(this.awaitingConfirmationMarker(settlementId)).toHaveCount(0)
  }

  /**
   * Click a status pill and wait for the list that pill produced.
   *
   * "Alle" sends no `status` at all, so the two cases are told apart on the
   * parameter's presence rather than its value.
   */
  async filterByStatus(status: 'all' | 'draft' | 'exported' | 'submitted' | 'reversed' | 'cancelled') {
    const responsePromise = this.page.waitForResponse((resp) => {
      if (!resp.url().includes('/api/admin/settlements') || resp.status() !== 200) return false
      const sent = new URL(resp.url()).searchParams.get('status')
      return status === 'all' ? sent === null : sent === status
    })
    await this.statusFilterPill(status).click()
    await responsePromise
  }

  async expectSettlementRowAbsent(settlementId: string) {
    await expect(this.settlementRow(settlementId)).toHaveCount(0)
  }

  /**
   * REVERSAL (#433)
   */

  /**
   * Which of the two undos the row's single slot is offering.
   *
   * The invariant #81 turns on: never both, and never neither for a live run.
   */
  async expectReversalOffered(settlementId: string) {
    await expect(this.reverseButton(settlementId)).toBeVisible()
    await expect(this.undoButton(settlementId)).toHaveCount(0)
  }

  async expectCancellationOffered(settlementId: string) {
    await expect(this.undoButton(settlementId)).toBeVisible()
    await expect(this.reverseButton(settlementId)).toHaveCount(0)
  }

  /** Open the reversal dialog from the row and leave it open for inspection. */
  async openReverseDialog(settlementId: string) {
    await this.reverseButton(settlementId).click()
    await expect(this.reverseDialog()).toBeVisible()
  }

  async dismissReverseDialog() {
    await this.reverseCancel().click()
    await expect(this.reverseDialog()).toBeHidden()
  }

  /** The figures the reversal dialog states about the run it is asking about. */
  async getReverseDialogDetails(): Promise<{ date: string; amount: string; members: string }> {
    const detail = (field: 'date' | 'amount' | 'members') =>
      this.page.getByTestId(`reverse-settlement-detail-${field}`)

    return {
      date: (await detail('date').textContent())?.trim() ?? '',
      amount: (await detail('amount').textContent())?.trim() ?? '',
      members: (await detail('members').textContent())?.trim() ?? '',
    }
  }

  /** The dialog must say a reversal cannot be taken back, every time (§5). */
  async expectReversalIrreversibleStated() {
    await expect(this.reverseIrreversibleNotice()).toBeVisible()
  }

  /** A bank return freezes the member out of the next run; a club error does not. */
  async expectHoldWarningVisible() {
    await expect(this.reverseHoldWarning()).toBeVisible()
  }

  async expectNoHoldWarning() {
    await expect(this.reverseHoldWarning()).toHaveCount(0)
  }

  async expectReverseBlocked(reason: RegExp) {
    await expect(this.reverseBlockedReason()).toBeVisible()
    await expect(this.reverseBlockedReason()).toHaveText(reason)
    await expect(this.reverseConfirm()).toBeHidden()
  }

  /** Narrow the reversal from the whole run to named members (§2). */
  async chooseMembersToReverse(memberIds: string[]) {
    await this.reverseScopeToggle().check()
    await expect(this.reverseMemberList()).toBeVisible()

    for (const memberId of memberIds) {
      await this.reverseMemberCheckbox(memberId).check()
    }
  }

  /** The reference the lookup already resolved is filled in, not demanded (§4). */
  async getReverseBankReference(): Promise<string> {
    return this.reverseBankReferenceInput().inputValue()
  }

  /** The single member the reference named — no picker on this path. */
  async expectLockedMember(name: string) {
    await expect(this.reverseLockedMember()).toContainText(name)
  }

  /** Confirm an open reversal dialog and wait for the list it reloaded. */
  async confirmReversal(settlementId: string) {
    const reversed = this.page.waitForResponse(
      (resp) =>
        resp.url().includes(`/api/admin/settlements/${settlementId}/reverse`) &&
        resp.request().method() === 'POST' &&
        resp.status() === 201
    )
    const reloaded = this.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/settlements?') &&
        resp.request().method() === 'GET' &&
        resp.status() === 200
    )
    await this.reverseConfirm().click()
    await reversed
    await reloaded
  }

  /** Book the whole run back — the endpoint's default call (no `member_ids`). */
  async reverseWholeSettlement(settlementId: string) {
    await this.openReverseDialog(settlementId)
    await this.confirmReversal(settlementId)
  }

  async expectReverseSuccessVisible() {
    await expect(this.reverseSuccessBanner()).toBeVisible()
  }

  async getReverseSuccessMessage(): Promise<string> {
    return (await this.reverseSuccessBanner().textContent())?.trim() ?? ''
  }

  /** The banner names the hold and links to it — a consequence nobody asked for. */
  async expectHoldLinkOffered() {
    await expect(this.reverseSuccessHoldLink()).toBeVisible()
  }

  /**
   * RECORDING A BANK RETURN (#433 §3)
   */

  /** Always there, whether or not any run is currently reversible. */
  async expectRecordBankReturnOffered() {
    await expect(this.recordBankReturnButton()).toBeVisible()
  }

  async openBankReturnLookup() {
    await this.recordBankReturnButton().click()
    await expect(this.lookupInput()).toBeVisible()
  }

  /** Type a reference and wait for whatever the lookup answered. */
  async lookupReference(reference: string) {
    const answered = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/settlements/reversal-candidates') && resp.status() < 500
    )
    await this.lookupInput().fill(reference)
    await answered
  }

  async expectCandidateVisible(settlementId: string, memberId: string) {
    await expect(this.lookupCandidate(settlementId, memberId)).toBeVisible()
  }

  async getCandidateText(settlementId: string, memberId: string): Promise<string> {
    return (await this.lookupCandidate(settlementId, memberId).textContent())?.replace(/\s+/g, ' ').trim() ?? ''
  }

  /**
   * A candidate the endpoint would refuse is shown disabled with the reason,
   * never filtered out: a reference that genuinely exists must not come back as
   * "no match".
   */
  async expectCandidateBlocked(settlementId: string, memberId: string, reason: RegExp) {
    await expect(this.lookupCandidate(settlementId, memberId)).toBeDisabled()
    await expect(this.lookupCandidateBlocked(settlementId, memberId)).toHaveText(reason)
  }

  async expectNoCandidates() {
    await expect(this.lookupCandidates()).toHaveCount(0)
  }

  /** The empty case names the two likely causes rather than shrugging. */
  async expectLookupNoMatch(reason: RegExp) {
    await expect(this.lookupNoMatch()).toBeVisible()
    await expect(this.lookupNoMatch()).toHaveText(reason)
  }

  /** Pick a candidate; the reversal dialog opens for that one collection. */
  async chooseCandidate(settlementId: string, memberId: string) {
    await this.lookupCandidate(settlementId, memberId).click()
    await expect(this.reverseIrreversibleNotice()).toBeVisible()
  }

  /**
   * THE MEMBER BREAKDOWN (#433 §7)
   */

  async expandSettlement(settlementId: string) {
    await this.expandButton(settlementId).click()
    await expect(this.breakdown()).toBeVisible()
  }

  async expectMemberInBreakdown(memberId: string, expected: RegExp) {
    await expect(this.breakdownMember(memberId)).toHaveText(expected)
  }

  async expectMemberReversedInBreakdown(memberId: string, expected: RegExp) {
    await expect(this.breakdownReversal(memberId)).toHaveText(expected)
  }

  async expectMemberNotReversedInBreakdown(memberId: string) {
    await expect(this.breakdownReversal(memberId)).toHaveCount(0)
  }

  async getBreakdownMemberCount(): Promise<number> {
    return this.page.locator('[data-testid^="settlement-breakdown-member-"]').count()
  }

  /** "1 von 40 zurückgebucht" — how much of a run came back, before expanding. */
  async expectReversedCount(settlementId: string, expected: RegExp) {
    await expect(this.reversedCount(settlementId)).toHaveText(expected)
  }

  async expectNoReversedCount(settlementId: string) {
    await expect(this.reversedCount(settlementId)).toHaveCount(0)
  }

  /**
   * ACCESSIBLE NAMES AND VISIBLE LABELS (#138)
   *
   * Undo is the destructive action on this page and renders as a bare glyph,
   * so its accessible name is the only thing announcing it. The exports were
   * labelled SEPA/CSV/TXN with hardcoded English tooltips; the labels now come
   * from the locale and every button carries an explanatory accessible name.
   */

  async getUndoAccessibleName(settlementId: string): Promise<string | null> {
    return this.page.getByTestId(`settlements-undo-btn-${settlementId}`).getAttribute('aria-label')
  }

  async getExportButtonLabels(settlementId: string): Promise<string[]> {
    return Promise.all([
      this.page.getByTestId(`settlements-export-sepa-btn-${settlementId}`).innerText(),
      this.page.getByTestId(`settlements-export-csv-btn-${settlementId}`).innerText(),
      this.page.getByTestId(`settlements-export-transactions-btn-${settlementId}`).innerText(),
    ])
  }

  async getExportButtonAccessibleNames(settlementId: string): Promise<(string | null)[]> {
    return Promise.all([
      this.page.getByTestId(`settlements-export-sepa-btn-${settlementId}`).getAttribute('aria-label'),
      this.page.getByTestId(`settlements-export-csv-btn-${settlementId}`).getAttribute('aria-label'),
      this.page
        .getByTestId(`settlements-export-transactions-btn-${settlementId}`)
        .getAttribute('aria-label'),
    ])
  }
}
