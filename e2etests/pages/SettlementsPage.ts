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
  private readonly tableRows = () => this.page.locator('[data-testid^="settlements-table-row-"]')
  private readonly emptyState = () => this.page.getByTestId('settlements-empty-state')
  private readonly newSettlementBtn = () => this.page.getByTestId('new-settlement-button')
  private readonly manualSettlementBtn = () => this.page.getByTestId('manual-settlement-button')
  private readonly typeFilter = () => this.page.getByTestId('settlement-type-filter')

  // Settlement list columns (match actual component test IDs)
  private readonly settlementRow = (settlementId: string) =>
    this.page.getByTestId(`settlements-table-row-${settlementId}`)
  private readonly settlementDate = (settlementId: string) =>
    this.page.getByTestId(`settlements-table-cell-date-${settlementId}`)
  private readonly settlementCreatedBy = (settlementId: string) =>
    this.page.getByTestId(`settlements-table-cell-created-by-${settlementId}`)
  private readonly settlementMembers = (settlementId: string) =>
    this.page.getByTestId(`settlements-table-cell-members-${settlementId}`)
  private readonly settlementAmount = (settlementId: string) =>
    this.page.getByTestId(`settlements-table-cell-amount-${settlementId}`)
  private readonly settlementPrice = (settlementId: string) =>
    this.page.getByTestId(`settlements-price-${settlementId}`)
  private readonly settlementStatus = (settlementId: string) =>
    this.page.getByTestId(`settlements-table-cell-status-${settlementId}`)
  private readonly settlementStatusBadge = (settlementId: string) =>
    this.page.getByTestId(`settlements-badge-status-${settlementId}`)

  // SEPA settlement creation locators
  private readonly transactionSelection = () => this.page.getByTestId('settlement-transaction-selection')
  private readonly selectionTable = () => this.page.getByTestId('settlement-selection-table')
  private readonly transactionRows = () => this.page.locator('[data-testid="settlement-transaction-row"]')
  private readonly sepaInvalidSection = () => this.page.getByTestId('settlement-sepa-invalid-members')
  private readonly selectAllBtn = () => this.page.getByTestId('settlement-select-all')
  private readonly selectNoneBtn = () => this.page.getByTestId('settlement-select-none')
  private readonly selectionContinueBtn = () => this.page.getByTestId('settlement-selection-continue')
  private readonly summarySection2 = () => this.page.getByTestId('settlement-summary-section')
  private readonly executionDateInput = () => this.page.getByTestId('settlement-execution-date-input')

  // Manual settlement locators
  private readonly manualSelection = () => this.page.getByTestId('settlement-manual-selection')
  private readonly manualTable = () => this.page.getByTestId('settlement-manual-table')
  private readonly sepaStatusFilter = () => this.page.getByTestId('settlement-sepa-status-filter')
  private readonly manualContinueBtn = () => this.page.getByTestId('settlement-manual-continue')
  private readonly reasonField = () => this.page.getByTestId('settlement-reason')
  private readonly commentField = () => this.page.getByTestId('settlement-comment')
  private readonly submitBtn = () => this.page.getByTestId('settlement-submit')

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
   * Solid waiting pattern based on loading indicator:
   * 1. Wait for loading indicator to disappear (means data is loaded)
   * 2. Wait for content to appear (table or empty state)
   *
   * This replaces fragile Promise.race patterns with reliable state-based waits.
   *
   * @param timeout Maximum time to wait in milliseconds (default: 10 seconds)
   */
  async waitForPageLoad(timeout = 10000) {
    // Step 1: Wait for loading indicator to be hidden/removed from DOM
    await expect(this.loadingIndicator()).toBeHidden({ timeout })

    // Step 2: Wait for either table or empty state to be visible
    // If neither appears after loading completes, that's an error we should catch
    try {
      await Promise.race([
        expect(this.table()).toBeVisible({ timeout: 1000 }),
        expect(this.emptyState()).toBeVisible({ timeout: 1000 }),
      ])
    } catch {
      // If this fails, it means loading is gone but no content appeared
      // This is a real error that tests should know about
      throw new Error('Page loaded but neither settlements table nor empty state appeared')
    }
  }

  /**
   * Wait for table to be visible after loading
   *
   * Use this when you expect the table to be loaded (not empty state).
   * Waits for loading indicator to disappear, then waits for table specifically.
   *
   * @param timeout Maximum time to wait in milliseconds (default: 10 seconds)
   */
  async waitForTableVisible(timeout = 10000) {
    // Wait for loading indicator to be hidden
    await expect(this.loadingIndicator()).toBeHidden({ timeout })
    // Wait for table specifically
    await expect(this.table()).toBeVisible({ timeout: 2000 })
  }

  /**
   * Wait for empty state to be visible after loading
   *
   * Use this when you expect no settlements (empty state).
   * Waits for loading indicator to disappear, then waits for empty state specifically.
   *
   * @param timeout Maximum time to wait in milliseconds (default: 10 seconds)
   */
  async waitForEmptyStateVisible(timeout = 10000) {
    // Wait for loading indicator to be hidden
    await expect(this.loadingIndicator()).toBeHidden({ timeout })
    // Wait for empty state specifically
    await expect(this.emptyState()).toBeVisible({ timeout: 2000 })
  }

  /**
   * Wait for loading to complete (indicator disappears)
   *
   * Use this as a generic "wait for action to complete" in settlement detail/create flows
   * where you're not waiting for a specific content element.
   *
   * @param timeout Maximum time to wait in milliseconds (default: 10 seconds)
   */
  async waitForLoadingComplete(timeout = 10000) {
    // Simply wait for loading indicator to disappear
    await expect(this.loadingIndicator()).toBeHidden({ timeout })
  }

  /**
   * VISIBILITY EXPECTATIONS
   */

  async expectPageVisible() {
    await expect(this.page.getByTestId('settlements-page')).toBeVisible()
  }

  async expectTableVisible() {
    await expect(this.table()).toBeVisible()
  }

  async expectTableHidden() {
    await expect(this.table()).not.toBeVisible()
  }

  async expectEmptyStateVisible() {
    await expect(this.emptyState()).toBeVisible()
  }

  /**
   * LIST VIEW INTERACTIONS (UC-A33)
   */

  async getSettlementCount(): Promise<number> {
    return await this.tableRows().count()
  }

  async getSettlementCreatedDate(settlementId: string): Promise<string | null> {
    try {
      return await this.settlementDate(settlementId).textContent()
    } catch {
      return null
    }
  }

  async getSettlementMemberCount(settlementId: string): Promise<string | null> {
    try {
      return await this.settlementMembers(settlementId).textContent()
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

  async expectSettlementRowVisible(settlementId: string) {
    await expect(this.settlementRow(settlementId)).toBeVisible()
  }



  /**
   * SEPA SETTLEMENT CREATION (UC-A30)
   */

  async openNewSettlement() {
    await this.newSettlementBtn().click()
    // Wait for settlement creation form/view to load
    await this.waitForLoadingComplete()
  }

  async getTransactionSelectionRowCount(): Promise<number> {
    return await this.transactionRows().count()
  }

  async selectAllTransactions() {
    await this.selectAllBtn().click()
    await this.waitForDebounce(300)
  }

  async selectNoneTransactions() {
    await this.selectNoneBtn().click()
    await this.waitForDebounce(300)
  }

  async toggleTransactionSelection(rowIndex: number) {
    const checkbox = this.transactionRows()
      .nth(rowIndex)
      .locator('input[type="checkbox"]')
    await checkbox.check()
    await this.waitForDebounce(300)
  }

  async continueFromTransactionSelection() {
    await this.selectionContinueBtn().click()
    // Wait for settlement summary to load using loading indicator
    await this.waitForLoadingComplete()
  }

  async setExecutionDate(date: string) {
    await this.executionDateInput().fill(date)
    await this.waitForDebounce(300)
  }

  async getExecutionDateValue(): Promise<string | null> {
    try {
      return await this.executionDateInput().inputValue()
    } catch {
      return null
    }
  }

  async filterSettlementsByType(type: 'all' | 'sepa' | 'manual') {
    const filter = this.typeFilter()
    const filterExists = await filter.isVisible().catch(() => false)

    if (filterExists) {
      await filter.selectOption(type)
      // Wait for filtered settlements to load using loading indicator
      await this.waitForPageLoad()
    }
  }

  /**
   * MANUAL SETTLEMENT (UC-A35)
   */

  async openManualSettlement() {
    await this.manualSettlementBtn().click()
    // Wait for manual settlement transaction selection to load
    await this.waitForLoadingComplete()
  }

  async getManualTransactionRowCount(): Promise<number> {
    const rows = this.page.locator('[data-testid="settlement-manual-table"] [data-testid="settlement-manual-table-row"]')
    return await rows.count()
  }

  async selectManualTransaction(rowIndex: number) {
    const checkbox = this.page
      .locator('[data-testid="settlement-manual-table"]')
      .locator('input[type="checkbox"]')
      .nth(rowIndex)
    await checkbox.check()
    await this.waitForDebounce(300)
  }

  async filterBySepaStatus(status: 'all' | 'valid' | 'invalid') {
    await this.sepaStatusFilter().selectOption(status)
    // Wait for filtered transactions to load
    await this.waitForLoadingComplete()
  }

  async continueFromManualSelection() {
    await this.manualContinueBtn().click()
    // Wait for settlement summary to load
    await this.waitForLoadingComplete()
  }

  async setSettlementReason(reason: string) {
    await this.reasonField().selectOption(reason)
    await this.waitForDebounce(300)
  }

  async setSettlementComment(comment: string) {
    await this.commentField().fill(comment)
    await this.waitForDebounce(300)
  }

  async getSettlementComment(): Promise<string | null> {
    try {
      return await this.commentField().inputValue()
    } catch {
      return null
    }
  }

  async submitSettlement() {
    await this.submitBtn().click()
    // Wait for settlement to be created and page to reload (return to list)
    await this.waitForPageLoad()
  }

  /**
   * PAGE STATE VERIFICATION
   */

  async isOnSettlementsPage(): Promise<boolean> {
    return this.getCurrentUrl().includes('/settlements')
  }

  async isTableEmpty(): Promise<boolean> {
    const rowCount = await this.getSettlementCount()
    return rowCount === 0
  }
}
