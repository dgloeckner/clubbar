/**
 * Journal Page Object
 *
 * Encapsulates all interactions with the global transaction journal page.
 * Implements E2E Testing Pattern 005: Using Test IDs (data-testid)
 * Implements E2E Testing Pattern 006: Page Object Model
 * Implements E2E Testing Pattern 008: Playwright Assertions (no visibility helpers)
 *
 * **CRITICAL PATTERN PRINCIPLES:**
 * 1. Page object provides HIGH-LEVEL SEMANTIC METHODS (not raw locators)
 * 2. Tests use page object methods, NOT page.locator() or page.getByTestId()
 * 3. All locators are PRIVATE and hidden from tests
 * 4. Page object handles data-testid selection internally
 */

import { Page, expect } from '@playwright/test'
import { BasePage } from './BasePage'

export class JournalPage extends BasePage {
  // Toolbar elements
  private readonly searchInput = () => this.page.getByTestId('journal-search-input')
  private readonly periodPickerButton = (period: '1m' | '3m' | '6m' | '1y' | '2y' | 'all') =>
    this.page.getByTestId(`journal-period-picker-${period}`)
  // Table elements
  private readonly table = () => this.page.getByTestId('journal-table')
  private readonly tableRows = () => this.page.locator('[data-testid^="journal-table-row-"]')
  private readonly emptyState = () => this.page.getByTestId('journal-empty-state')
  private readonly loadingIndicator = () => this.page.getByTestId('journal-loading')

  // Table headers
  private readonly headerDate = () => this.page.getByTestId('journal-header-date')
  private readonly headerType = () => this.page.getByTestId('journal-header-type')
  private readonly headerMember = () => this.page.getByTestId('journal-header-member')
  private readonly headerAmount = () => this.page.getByTestId('journal-header-amount')

  // Settlement mode elements
  private readonly settlementStatusFilter = (status: 'all' | 'open' | 'settled') =>
    this.page.getByTestId(`journal-settlement-status-filter-${status}`)
  private readonly concludeSettlementBtn = () => this.page.getByTestId('journal-settlement-conclude-btn')

  // Settlement confirm modal elements
  private readonly settlementConfirmModal = () => this.page.getByTestId('journal-settlement-confirm-modal')
  private readonly settlementConfirmSubmitBtn = () => this.page.getByTestId('journal-settlement-confirm-submit-btn')
  private readonly settlementAllBtn = () => this.page.getByTestId('journal-settlement-all-btn')
  private readonly settlementConfirmTxCount = () => this.page.getByTestId('journal-settlement-confirm-transaction-count')
  private readonly settlementConfirmMemberCount = () => this.page.getByTestId('journal-settlement-confirm-member-count')

  // Storno is a ROW ACTION, not a form: the transaction is the subject of the
  // operation, not a parameter of it (#169). There is no member picker and no
  // amount field — the amount is derived as the exact negation of the row.
  private readonly stornoRowBtn = (transactionId: string) =>
    this.page.getByTestId(`journal-storno-btn-${transactionId}`)
  private readonly stornoDialog = () => this.page.getByTestId('journal-storno-dialog')
  private readonly stornoDialogMember = () => this.page.getByTestId('journal-storno-dialog-member')
  private readonly stornoDialogProduct = () => this.page.getByTestId('journal-storno-dialog-product')
  private readonly stornoDialogAmount = () => this.page.getByTestId('journal-storno-dialog-amount')
  private readonly stornoDialogDate = () => this.page.getByTestId('journal-storno-dialog-date')
  private readonly stornoDialogReasonInput = () => this.page.getByTestId('journal-storno-dialog-reason-input')
  private readonly stornoDialogConfirm = () => this.page.getByTestId('journal-storno-dialog-confirm')
  private readonly stornoDialogCancel = () => this.page.getByTestId('journal-storno-dialog-cancel')
  private readonly stornoDialogError = () => this.page.getByTestId('journal-storno-dialog-error')
  private readonly stornoLink = (transactionId: string) =>
    this.page.getByTestId(`journal-storno-link-${transactionId}`)
  private readonly stornoedBadge = (transactionId: string) =>
    this.page.getByTestId(`journal-stornoed-badge-${transactionId}`)

  constructor(page: Page) {
    super(page)
  }

  /**
   * Navigate to journal page
   */
  async navigate() {
    await super.navigate('http://localhost:5173/journal')
  }

  /**
   * VISIBILITY EXPECTATIONS (Pattern 008: Use expect() for assertions)
   */

  /**
   * Period button state assertions (Pattern 006: POM abstraction)
   */

  async expectPeriodButtonActive(period: '1m' | '3m' | '6m' | '1y' | '2y' | 'all') {
    const button = this.periodPickerButton(period)
    await expect(button).toHaveCSS('background-color', 'rgb(59, 130, 246)') // Blue for active
  }

  async expectPeriodButtonInactive(period: '1m' | '3m' | '6m' | '1y' | '2y' | 'all') {
    const button = this.periodPickerButton(period)
    const bgColor = await button.evaluate((el) => window.getComputedStyle(el).backgroundColor)
    expect(
      bgColor === 'rgba(0, 0, 0, 0)' || bgColor === 'transparent',
      `Period button ${period} should not have blue background`
    ).toBeTruthy()
  }

  /**
   * DATA RETRIEVAL (no visibility checks, just read data)
   */

  async getTransactionCount(): Promise<number> {
    const rows = await this.tableRows().count()
    return rows
  }

  async getTransactionRow(index: number): Promise<{
    date: string
    type: string
    member: string
    details: string
    amount: string
  }> {
    try {
      const row = this.tableRows().nth(index)
      // Wait for row to be visible before trying to read cells
      await row.waitFor({ state: 'visible', timeout: 5000 })

      const cells = row.locator('td')
      await cells.nth(0).waitFor({ state: 'visible', timeout: 3000 })

      // Parse date cell which contains date and time in separate divs
      const dateCell = cells.nth(0)
      const dateAndTime = await dateCell.locator('> div > div').allTextContents()
      const date = dateAndTime.length >= 2
        ? `${dateAndTime[0].trim()}\n${dateAndTime[1].trim()}`
        : (await dateCell.textContent({ timeout: 3000 }))?.trim() || ''

      const type = await cells.nth(1).textContent({ timeout: 3000 })
      const member = await cells.nth(2).textContent({ timeout: 3000 })
      const details = await cells.nth(3).textContent({ timeout: 3000 })
      const amount = await cells.nth(4).textContent({ timeout: 3000 })

      return {
        date: date,
        type: type?.trim() || '',
        member: member?.trim() || '',
        details: details?.trim() || '',
        amount: amount?.trim() || '',
      }
    } catch (error) {
      // Return empty row if cells not found
      return {
        date: '',
        type: '',
        member: '',
        details: '',
        amount: '',
      }
    }
  }

  /**
   * Header text getters (Pattern 006: POM abstraction for header access)
   */

  async getHeaderText(header: 'date' | 'type' | 'member' | 'amount' | 'settlement-date'): Promise<string> {
    const headerMap = {
      date: this.headerDate(),
      type: this.headerType(),
      member: this.headerMember(),
      amount: this.headerAmount(),
      'settlement-date': this.page.getByTestId('journal-header-settlement-date'),
    }
    const text = await headerMap[header].textContent()
    return text?.trim() || ''
  }

  /**
   * Settlement date cell access (Pattern 006: POM abstraction for cell data)
   */

  async getSettlementDateText(rowIndex: number): Promise<string> {
    const row = this.tableRows().nth(rowIndex)
    const testIdAttr = await row.getAttribute('data-testid')
    const transactionId = testIdAttr?.replace('journal-table-row-', '')

    if (!transactionId) {
      return ''
    }

    const settlementDateCell = row.locator(`[data-testid="journal-table-cell-settlement-date-${transactionId}"]`)
    const text = await settlementDateCell.textContent()
    return text?.trim() || ''
  }

  /**
   * USER INTERACTIONS (actions that change state)
   */

  async search(query: string) {
    // Set up response listener BEFORE triggering the search
    const encodedQuery = encodeURIComponent(query)
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/transactions') && resp.url().includes(encodedQuery) && resp.status() === 200
    )
    // Clear first to ensure change event fires even if query is the same
    await this.searchInput().clear()
    await this.searchInput().fill(query)
    await responsePromise
  }

  async selectPeriod(period: '1m' | '3m' | '6m' | '1y' | '2y' | 'all') {
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/transactions') && resp.status() === 200
    )
    await this.periodPickerButton(period).click()
    await responsePromise
  }

  async sortBy(field: 'date' | 'type' | 'member' | 'amount') {
    const sortKeyMap = { date: 'created_at', type: 'type', member: 'member', amount: 'amount' }
    const headerMap = {
      date: this.headerDate(),
      type: this.headerType(),
      member: this.headerMember(),
      amount: this.headerAmount(),
    }
    // Set up response listener BEFORE clicking to avoid race condition
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/transactions') && resp.url().includes(`sort=${sortKeyMap[field]}`) && resp.status() === 200
    )
    await headerMap[field].click()
    await responsePromise
  }

  /**
   * WAIT FOR CONDITIONS
   */

  async waitForTableToLoad() {
    // Wait for loading indicator to disappear first (Pattern 008: expect for auto-waiting).
    // The PeriodPicker's onPeriodChange fires twice (non-memoized callback triggers a second
    // useEffect run), causing a second loadTransactions() → loading:true after the first
    // response. Waiting for loading to be hidden ensures the DOM has fully settled.
    await expect(this.loadingIndicator()).toBeHidden({ timeout: 10000 })
    // Then verify table or empty state is present
    await this.page
      .locator('[data-testid="journal-table"], [data-testid="journal-empty-state"]')
      .first()
      .waitFor({ timeout: 5000 })
  }

  /**
   * LOADING PATTERN (solid loading indicator waits)
   */

  async waitForPageLoad(timeout = 10000) {
    // Wait for loading indicator to disappear
    await expect(this.loadingIndicator()).toBeHidden({ timeout })

    // Wait for content (table or empty state)
    try {
      await Promise.race([
        expect(this.table()).toBeVisible({ timeout: 1000 }),
        expect(this.emptyState()).toBeVisible({ timeout: 1000 }),
      ])
    } catch {
      throw new Error('Page loaded but neither table nor empty state appeared')
    }
  }

  /**
   * SETTLEMENT MODE INTERACTIONS
   */

  async enterSettlementMode() {
    await this.page.getByTestId('journal-settlement-selected-btn').click()
    // Wait for mode switch and checkboxes to appear
    await this.page.waitForTimeout(300)
  }

  async filterBySettlementStatus(status: 'all' | 'open' | 'settled') {
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/transactions') && resp.status() === 200
    )
    await this.settlementStatusFilter(status).click()
    await responsePromise
  }

  async selectTransactionById(transactionId: string) {
    const checkbox = this.page.getByTestId(`journal-select-checkbox-${transactionId}`)
    await checkbox.check()
    await this.page.waitForTimeout(100)
  }

  async getSelectedTransactionCount(): Promise<number> {
    // Count checked checkboxes in transaction rows
    const checkboxes = this.page.locator('[data-testid^="journal-table-row-"] input[type="checkbox"]:checked')
    return await checkboxes.count()
  }

  async concludeSettlement(): Promise<string> {
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/settlements') && resp.status() === 201
    )
    // Open the confirmation modal
    await this.concludeSettlementBtn().click()
    // Wait for modal to appear and confirm
    await expect(this.settlementConfirmModal()).toBeVisible()
    await this.settlementConfirmSubmitBtn().click()
    // Capture API response (happens before navigation)
    const response = await responsePromise
    const body = await response.json()
    return body.id
  }

  /**
   * Click "Abrechnung (alle)" and wait for the confirmation modal to appear.
   * Use getSettlementConfirmStats() to inspect modal content, then
   * confirmOpenSettlement() to proceed.
   */
  async openSettleAllModal() {
    await this.settlementAllBtn().click()
    await expect(this.settlementConfirmModal()).toBeVisible()
  }

  /**
   * Click the confirm button in an already-open settlement confirm modal,
   * wait for the API call, and return the created settlement ID.
   * After success the app navigates to /settlements.
   */
  async confirmOpenSettlement(): Promise<string> {
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/settlements') && resp.status() === 201
    )
    await this.settlementConfirmSubmitBtn().click()
    const response = await responsePromise
    const body = await response.json()
    return body.id
  }

  /**
   * Read the transaction count and member count shown in the settlement confirm modal.
   * Call after opening the modal (via openSettleAllModal or concludeSettlementBtn),
   * before confirming.
   */
  async getSettlementConfirmStats(): Promise<{ transactions: number; members: number }> {
    const txText = await this.settlementConfirmTxCount().textContent()
    const memberText = await this.settlementConfirmMemberCount().textContent()
    return {
      transactions: parseInt(txText?.trim() ?? '0', 10),
      members: parseInt(memberText?.trim() ?? '0', 10),
    }
  }

  async expectTransactionRowVisible(transactionId: string) {
    await expect(this.page.getByTestId(`journal-table-row-${transactionId}`)).toBeVisible()
  }

  /**
   * STORNO — a row action.
   *
   * Click Storno on the transaction that is wrong. The confirmation states what
   * is being reversed (member, product, amount, date) rather than asking a
   * generic "are you sure"; the reason is the only thing the admin supplies.
   */
  async openStornoDialog(transactionId: string) {
    await this.stornoRowBtn(transactionId).click()
    await expect(this.stornoDialog()).toBeVisible()
  }

  /** The row action for a transaction that cannot be stornoed is disabled, not hidden. */
  async expectStornoDisabled(transactionId: string) {
    await expect(this.stornoRowBtn(transactionId)).toBeDisabled()
  }

  /** A storno row carries no storno action of its own — a storno cannot be stornoed. */
  async expectNoStornoAction(transactionId: string) {
    await expect(this.stornoRowBtn(transactionId)).toHaveCount(0)
  }

  /**
   * What the confirmation says it is about to reverse. Tests assert against
   * this so a generic "are you sure" cannot pass (#127 is the same defect on
   * settlement undo).
   */
  async getStornoDialogSubject(): Promise<{
    member: string
    product: string
    amount: string
    date: string
  }> {
    return {
      member: (await this.stornoDialogMember().textContent()) ?? '',
      product: (await this.stornoDialogProduct().textContent()) ?? '',
      amount: (await this.stornoDialogAmount().textContent()) ?? '',
      date: (await this.stornoDialogDate().textContent()) ?? '',
    }
  }

  async fillStornoReason(reason: string) {
    await this.stornoDialogReasonInput().fill(reason)
  }

  /**
   * Confirm the storno and wait for the API response.
   * Returns the Response so tests can assert on the body — notably that the
   * amount came back derived, since the UI never sent one.
   */
  async confirmStorno(expectedStatus = 201): Promise<import('@playwright/test').Response> {
    const responsePromise = this.page.waitForResponse(
      (resp) => /\/admin\/transactions\/[^/]+\/storno$/.test(new URL(resp.url()).pathname) &&
        resp.request().method() === 'POST' &&
        resp.status() === expectedStatus
    )
    await this.stornoDialogConfirm().click()
    return responsePromise
  }

  async cancelStorno() {
    await this.stornoDialogCancel().click()
  }

  async expectStornoDialogHidden() {
    await expect(this.stornoDialog()).toBeHidden()
  }

  async expectStornoDialogError(pattern: RegExp | string) {
    await expect(this.stornoDialogError()).toBeVisible()
    await expect(this.stornoDialogError()).toContainText(pattern)
  }

  /** The storno row shows what it reverses; the reversed row shows it was stornoed. */
  async expectLinkedToOriginal(stornoId: string) {
    await expect(this.stornoLink(stornoId)).toBeVisible()
  }

  async expectMarkedAsStornoed(originalId: string) {
    await expect(this.stornoedBadge(originalId)).toBeVisible()
  }
}
