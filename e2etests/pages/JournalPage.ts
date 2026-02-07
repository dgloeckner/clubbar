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
  // Page container
  private readonly pageContainer = () => this.page.getByTestId('journal-page')

  // Toolbar elements
  private readonly toolbar = () => this.page.getByTestId('journal-toolbar')
  private readonly countSummary = () => this.page.getByTestId('journal-count-summary')
  private readonly searchInput = () => this.page.getByTestId('journal-search-input')
  private readonly periodPickerButton = (period: '1m' | '3m' | '6m' | '1y' | '2y' | 'all') =>
    this.page.getByTestId(`journal-period-picker-${period}`)
  private readonly typeFilterButton = (type: 'all' | 'purchase' | 'correction') =>
    this.page.getByTestId(`journal-type-filter-${type}`)

  // Table elements
  private readonly table = () => this.page.getByTestId('journal-table')
  private readonly tableWrapper = () => this.page.getByTestId('journal-table-wrapper')
  private readonly tableRows = () => this.page.locator('[data-testid^="journal-table-row-"]')
  private readonly emptyState = () => this.page.getByTestId('journal-empty-state')
  private readonly loadingIndicator = () => this.page.getByTestId('journal-loading')
  private readonly errorMessage = () => this.page.getByTestId('journal-error-message')

  // Pagination elements
  private readonly paginationToolbar = () => this.page.getByTestId('journal-pagination-toolbar')

  // Table headers
  private readonly headerDate = () => this.page.getByTestId('journal-header-date')
  private readonly headerType = () => this.page.getByTestId('journal-header-type')
  private readonly headerMember = () => this.page.getByTestId('journal-header-member')
  private readonly headerDetails = () => this.page.getByTestId('journal-header-details')
  private readonly headerAmount = () => this.page.getByTestId('journal-header-amount')

  // Settlement mode elements
  private readonly settlementStatusFilter = (status: 'all' | 'open' | 'settled') =>
    this.page.getByTestId(`journal-settlement-status-filter-${status}`)
  private readonly transactionCheckbox = (transactionId: string) =>
    this.page.locator(`[data-testid^="journal-table-row-"] input[data-testid="transaction-checkbox-${transactionId}"]`)
  private readonly selectAllCheckbox = () => this.page.getByTestId('journal-select-all-checkbox')
  private readonly concludeSettlementBtn = () => this.page.getByTestId('journal-settlement-conclude-btn')
  private readonly successMessage = () => this.page.getByTestId('journal-success-message')

  // Correction modal elements
  private readonly createCorrectionBtn = () => this.page.getByTestId('journal-create-correction-btn')
  private readonly correctionModal = () => this.page.getByTestId('journal-correction-modal')
  private readonly correctionMemberSelect = () => this.page.getByTestId('journal-correction-member-select')
  private readonly correctionAmountInput = () => this.page.getByTestId('journal-correction-amount-input')
  private readonly correctionReasonInput = () => this.page.getByTestId('journal-correction-reason-input')
  private readonly correctionSubmitBtn = () => this.page.getByTestId('journal-correction-submit-btn')
  private readonly correctionError = () => this.page.getByTestId('journal-correction-error')

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

  async expectPageVisible() {
    await expect(this.pageContainer()).toBeVisible()
    // Wait for table or empty state to load
    await this.page
      .locator('[data-testid="journal-table"], [data-testid="journal-empty-state"]')
      .first()
      .waitFor({ timeout: 5000 })
  }

  async expectTableVisible() {
    await expect(this.table()).toBeVisible()
  }

  async expectTableHidden() {
    await expect(this.table()).not.toBeVisible()
  }

  async expectEmptyState() {
    await expect(this.emptyState()).toBeVisible()
  }

  async expectLoadingVisible() {
    await expect(this.loadingIndicator()).toBeVisible()
  }

  async expectErrorVisible() {
    await expect(this.errorMessage()).toBeVisible()
  }

  async expectCorrectionModalVisible() {
    await expect(this.correctionModal()).toBeVisible()
  }

  async expectCorrectionModalHidden() {
    await expect(this.correctionModal()).not.toBeVisible()
  }

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

  async getCountSummaryText(): Promise<string> {
    const text = await this.countSummary().textContent()
    return text?.trim() || ''
  }

  async getTotalItemsFromSummary(): Promise<number> {
    const text = await this.getCountSummaryText()
    const match = text.match(/(\d+)\s+Transactions/)
    return match ? parseInt(match[1], 10) : 0
  }

  async findTransactionByMemberName(memberName: string): Promise<number | null> {
    try {
      const count = await this.getTransactionCount()
      for (let i = 0; i < count; i++) {
        try {
          const row = await this.getTransactionRow(i)
          if (row.member && row.member.includes(memberName)) {
            return i
          }
        } catch (error) {
          // Skip this row if we can't read it, continue to next
          continue
        }
      }
      return null
    } catch (error) {
      return null
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
   * Row element access (Pattern 006: For edge cases needing cell-specific access)
   */

  getRowElement(rowIndex: number) {
    return this.tableRows().nth(rowIndex)
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

  async filterByType(type: 'all' | 'purchase' | 'correction') {
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/transactions') && resp.status() === 200
    )
    await this.typeFilterButton(type).click()
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

  async goToPage(pageNumber: number) {
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/transactions') && resp.status() === 200
    )
    const pageButton = this.page.getByTestId(`journal-page-${pageNumber}`)
    await pageButton.click()
    await responsePromise
  }

  async goToNextPage() {
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/transactions') && resp.status() === 200
    )
    const nextBtn = this.page.getByTestId('journal-next')
    await nextBtn.click()
    await responsePromise
  }

  /**
   * WAIT FOR CONDITIONS
   */

  async waitForTable() {
    await this.tableWrapper().waitFor({ timeout: 5000 })
  }

  async waitForTableToLoad() {
    // Wait for either table or empty state
    await this.page
      .locator('[data-testid="journal-table"], [data-testid="journal-empty-state"]')
      .first()
      .waitFor({ timeout: 5000 })
  }

  async waitForTransactionCount(expectedCount: number) {
    await this.page.waitForFunction(
      async () => {
        const count = await this.getTransactionCount()
        return count === expectedCount
      },
      { timeout: 5000 }
    )
  }

  async waitForTransactionToAppear(memberName: string) {
    let found = false
    let attempts = 0
    const maxAttempts = 10

    while (!found && attempts < maxAttempts) {
      const count = await this.getTransactionCount()
      if (count > 0) {
        const index = await this.findTransactionByMemberName(memberName)
        if (index !== null) {
          found = true
          break
        }
      }
      attempts++
      // Wait before retrying
      await this.page.waitForTimeout(500)
    }

    if (!found) {
      throw new Error(`Transaction with member name "${memberName}" not found after ${maxAttempts} attempts`)
    }
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

  async selectAllTransactions() {
    const checkbox = this.selectAllCheckbox()
    await checkbox.check()
    // Wait for selection to register
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

  async selectTransactionsByMemberName(memberName: string) {
    const count = await this.getTransactionCount()
    for (let i = 0; i < count; i++) {
      const row = await this.getTransactionRow(i)
      if (row.member && row.member.includes(memberName)) {
        // Find and click the checkbox for this row
        const rowElement = this.tableRows().nth(i)
        const checkbox = rowElement.locator('input[type="checkbox"]')
        await checkbox.check()
        // Wait for selection to register
        await this.page.waitForTimeout(300)
      }
    }
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
    await this.concludeSettlementBtn().click()
    const response = await responsePromise
    const body = await response.json()
    return body.id
  }

  async getSuccessMessage(): Promise<string | null> {
    try {
      const text = await this.successMessage().textContent({ timeout: 5000 })
      return text?.trim() || null
    } catch {
      return null
    }
  }

  /**
   * CORRECTION MODAL INTERACTIONS
   */

  async openCorrectionModal() {
    await this.createCorrectionBtn().click()
  }

  async fillCorrectionForm(memberId: string, amountEur: string, reason: string) {
    // Wait for member dropdown to have options (members load async via getMembers())
    // Use 'attached' state since native <select> options are not considered "visible" by Playwright
    await this.correctionMemberSelect().locator('option:not([value=""])').first().waitFor({ state: 'attached', timeout: 5000 })
    await this.correctionMemberSelect().selectOption({ value: memberId })
    await this.correctionAmountInput().fill(amountEur)
    await this.correctionReasonInput().fill(reason)
  }

  async submitCorrectionForm() {
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/transactions/correction') && resp.ok()
    )
    await this.correctionSubmitBtn().click()
    await responsePromise
  }

  async createCorrection(memberId: string, amountEur: string, reason: string) {
    await this.openCorrectionModal()
    await this.expectCorrectionModalVisible()
    await this.fillCorrectionForm(memberId, amountEur, reason)
    await this.submitCorrectionForm()
  }

  async getCorrectionError(): Promise<string | null> {
    try {
      const text = await this.correctionError().textContent({ timeout: 3000 })
      return text?.trim() || null
    } catch {
      return null
    }
  }
}
