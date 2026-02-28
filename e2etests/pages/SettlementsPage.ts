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
  private readonly settlementDate = (settlementId: string) =>
    this.page.getByTestId(`settlements-table-cell-date-${settlementId}`)
  private readonly settlementMembers = (settlementId: string) =>
    this.page.getByTestId(`settlements-table-cell-members-${settlementId}`)
  private readonly settlementPrice = (settlementId: string) =>
    this.page.getByTestId(`settlements-price-${settlementId}`)
  private readonly settlementStatusBadge = (settlementId: string) =>
    this.page.getByTestId(`settlements-badge-status-${settlementId}`)

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

  async expectUndoButtonEnabled(settlementId: string) {
    await expect(this.page.getByTestId(`settlements-undo-btn-${settlementId}`)).toBeEnabled()
  }

  async expectUndoButtonDisabled(settlementId: string) {
    await expect(this.page.getByTestId(`settlements-undo-btn-${settlementId}`)).toBeDisabled()
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
        resp.url().includes(`/api/admin/settlements/${settlementId}`) &&
        resp.request().method() === 'DELETE' &&
        resp.status() === 204
    )
    await this.page.getByTestId(`settlements-undo-btn-${settlementId}`).click()
    // Custom confirm dialog should appear
    await expect(this.page.getByTestId('confirm-dialog')).toBeVisible()
    await this.page.getByTestId('confirm-dialog-ok').click()
    await responsePromise
    await this.waitForPageLoad()
  }
}
