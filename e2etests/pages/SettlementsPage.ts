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
  async clickExportSepa(settlementId: string): Promise<import('@playwright/test').Response> {
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/export/sepa-xml') && resp.status() === 200
    )
    await this.page.getByTestId(`settlements-export-sepa-btn-${settlementId}`).click()
    return responsePromise
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
   * Click the undo button for a settlement, confirm via the custom ConfirmDialog modal,
   * and wait for the settlement list to reload.
   *
   * After undo the settlement row remains visible with status "Storniert".
   */
  async undoSettlement(settlementId: string) {
    const responsePromise = this.page.waitForResponse(
      (resp) =>
        resp.url().includes(`/api/admin/settlements/${settlementId}/cancel`) &&
        resp.request().method() === 'DELETE' &&
        resp.status() === 200
    )
    await this.page.getByTestId(`settlements-undo-btn-${settlementId}`).click()
    // Custom confirm dialog should appear
    await expect(this.page.getByTestId('confirm-dialog')).toBeVisible()
    await this.page.getByTestId('confirm-dialog-ok').click()
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
