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

  // Settlement list row/cell locators
  private readonly settlementRow = (settlementId: string) =>
    this.page.getByTestId(`settlements-table-row-${settlementId}`)
  private readonly settlementMemberCount = (settlementId: string) =>
    this.page.getByTestId(`settlements-member-count-${settlementId}`)
  private readonly settlementPrice = (settlementId: string) =>
    this.page.getByTestId(`settlements-price-${settlementId}`)
  private readonly settlementStatusBadge = (settlementId: string) =>
    this.page.getByTestId(`settlements-badge-status-${settlementId}`)
  private readonly settlementRows = () => this.page.locator('[data-testid^="settlements-table-row-"]')
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
    try {
      await Promise.race([
        expect(this.table()).toBeVisible({ timeout: 1000 }),
        expect(this.emptyState()).toBeVisible({ timeout: 1000 }),
      ])
    } catch {
      throw new Error('Page loaded but neither settlements table nor empty state appeared')
    }
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
