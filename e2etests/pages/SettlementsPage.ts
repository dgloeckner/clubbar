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
  // Main page locators (PRIVATE)
  private readonly table = () => this.page.getByTestId('settlements-table')
  private readonly tableRows = () => this.page.locator('[data-testid="settlement-row"]')
  private readonly emptyState = () => this.page.getByTestId('settlements-empty-state')
  private readonly newSettlementBtn = () => this.page.getByTestId('new-settlement-button')
  private readonly manualSettlementBtn = () => this.page.getByTestId('manual-settlement-button')
  private readonly typeFilter = () => this.page.getByTestId('settlement-type-filter')

  // Settlement list columns
  private readonly settlementCreated = (settlementId: string) =>
    this.page.getByTestId(`settlement-created-${settlementId}`)
  private readonly settlementMemberCount = (settlementId: string) =>
    this.page.getByTestId(`settlement-member-count-${settlementId}`)
  private readonly settlementTotalAmount = (settlementId: string) =>
    this.page.getByTestId(`settlement-total-amount-${settlementId}`)

  // Details page locators
  private readonly detailsPage = () => this.page.getByTestId('settlement-details-page')
  private readonly summarySection = () => this.page.getByTestId('settlement-summary')
  private readonly summaryCreated = () => this.page.getByTestId('settlement-summary-created')
  private readonly summaryExecutionDate = () => this.page.getByTestId('settlement-execution-date')
  private readonly summaryTotal = () => this.page.getByTestId('settlement-summary-total')
  private readonly totalMembers = () => this.page.getByTestId('settlement-total-members')
  private readonly membersTable = () => this.page.getByTestId('settlement-members-table')
  private readonly memberRows = () => this.page.locator('[data-testid="settlement-member-row"]')
  private readonly downloadsSection = () => this.page.getByTestId('settlement-downloads')
  private readonly backBtn = () => this.page.getByTestId('settlement-back-button')

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

  async expectDetailsPageVisible() {
    await expect(this.detailsPage()).toBeVisible()
  }

  /**
   * LIST VIEW INTERACTIONS (UC-A33)
   */

  async getSettlementCount(): Promise<number> {
    return await this.tableRows().count()
  }

  async getSettlementCreatedDate(settlementId: string): Promise<string | null> {
    try {
      return await this.settlementCreated(settlementId).textContent()
    } catch {
      return null
    }
  }

  async getSettlementMemberCount(settlementId: string): Promise<string | null> {
    try {
      return await this.settlementMemberCount(settlementId).textContent()
    } catch {
      return null
    }
  }

  async getSettlementTotalAmount(settlementId: string): Promise<string | null> {
    try {
      return await this.settlementTotalAmount(settlementId).textContent()
    } catch {
      return null
    }
  }

  /**
   * SETTLEMENT DETAILS (UC-A34)
   */

  async viewSettlementDetails(settlementId: string) {
    const viewBtn = this.page.getByTestId(`settlement-view-button-${settlementId}`)
    await viewBtn.click()
    await this.page.waitForLoadState('networkidle')
  }

  async getSettlementSummary(): Promise<{
    created?: string
    executionDate?: string
    totalMembers?: string
    totalAmount?: string
  }> {
    const created = await this.summaryCreated().textContent().catch(() => null)
    const executionDate = await this.summaryExecutionDate().textContent().catch(() => null)
    const totalMembers = await this.totalMembers().textContent().catch(() => null)
    const totalAmount = await this.summaryTotal().textContent().catch(() => null)

    return {
      created: created || undefined,
      executionDate: executionDate || undefined,
      totalMembers: totalMembers || undefined,
      totalAmount: totalAmount || undefined,
    }
  }

  async getSettlementMembers(): Promise<
    Array<{ name: string; amount: string; sepaStatus?: string }>
  > {
    const rows = this.memberRows()
    const count = await rows.count()
    const members = []

    for (let i = 0; i < count; i++) {
      const row = rows.nth(i)
      const name = await row.locator('[data-testid="member-name"]').textContent()
      const amount = await row.locator('[data-testid="member-amount"]').textContent()
      const sepaStatus = await row
        .locator('[data-testid="member-sepa-status"]')
        .textContent()
        .catch(() => null)

      if (name && amount) {
        members.push({
          name,
          amount,
          sepaStatus: sepaStatus || undefined,
        })
      }
    }

    return members
  }

  async goBackToList() {
    await this.backBtn().click()
    await this.page.waitForLoadState('networkidle')
  }

  /**
   * SEPA SETTLEMENT CREATION (UC-A30)
   */

  async openNewSettlement() {
    await this.newSettlementBtn().click()
    await this.page.waitForLoadState('networkidle')
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
    await this.page.waitForLoadState('networkidle')
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
      await this.page.waitForLoadState('networkidle')
    }
  }

  /**
   * MANUAL SETTLEMENT (UC-A35)
   */

  async openManualSettlement() {
    await this.manualSettlementBtn().click()
    await this.page.waitForLoadState('networkidle')
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
    await this.page.waitForLoadState('networkidle')
  }

  async continueFromManualSelection() {
    await this.manualContinueBtn().click()
    await this.page.waitForLoadState('networkidle')
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
    await this.page.waitForLoadState('networkidle')
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
