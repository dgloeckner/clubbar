/**
 * Journal Page Object
 *
 * Page object for the Journal (Buchungsjournal) page showing transaction history.
 * Implements E2E Testing Pattern 006: Page Object Model
 *
 * Usage:
 *   const journalPage = new JournalPage(page)
 *   await journalPage.selectMember('John Doe')
 *   await journalPage.expectTransactionListVisible()
 */

import { Page, Locator } from '@playwright/test'
import { BasePage } from './BasePage'

export class JournalPage extends BasePage {
  constructor(page: Page) {
    super(page)
  }

  /**
   * Verify journal page is visible
   */
  async expectPageVisible(): Promise<void> {
    const title = this.page.locator('text=Buchungsjournal')
    await title.waitFor({ timeout: 5000 })
  }

  /**
   * Get members list container
   */
  getMembersList(): Locator {
    return this.page.locator('[data-testid="journal-members-list"], [data-testid="member-selector"]').first()
  }

  /**
   * Get member row by index
   */
  getMemberRow(index: number = 0): Locator {
    return this.page.locator('[data-testid="journal-member-row"]').nth(index)
  }

  /**
   * Get count of member rows
   */
  async getMemberRowCount(): Promise<number> {
    return await this.page.locator('[data-testid="journal-member-row"]').count()
  }

  /**
   * Click member to view their transactions
   */
  async selectMember(index: number = 0): Promise<void> {
    const memberRow = this.getMemberRow(index)
    await memberRow.click()
  }

  /**
   * Expect transaction list is visible
   */
  async expectTransactionListVisible(): Promise<void> {
    const transactionList = this.page.locator('[data-testid="transaction-list"], [data-testid="transactions-table"]').first()
    await transactionList.waitFor({ timeout: 5000 })
  }

  /**
   * Get transaction rows
   */
  getTransactionRows(): Locator {
    return this.page.locator('[data-testid="transaction-row"]')
  }

  /**
   * Get count of transaction rows
   */
  async getTransactionRowCount(): Promise<number> {
    return await this.page.locator('[data-testid="transaction-row"]').count()
  }

  /**
   * Get empty state message if no transactions
   */
  async expectEmptyStateVisible(): Promise<void> {
    const emptyState = this.page.locator('[data-testid="empty-transactions"], text=No transactions')
    const isVisible = await emptyState.isVisible().catch(() => false)
    if (!isVisible) {
      throw new Error('Empty state not visible')
    }
  }

  /**
   * Get current member balance
   */
  async getMemberBalance(): Promise<string | null> {
    const balanceDisplay = this.page.locator('[data-testid="member-balance"]').first()
    const isVisible = await balanceDisplay.isVisible().catch(() => false)
    if (isVisible) {
      return await balanceDisplay.textContent()
    }
    return null
  }

  /**
   * Filter transactions by type (all, purchase, correction)
   */
  async filterByType(type: 'all' | 'purchase' | 'correction'): Promise<void> {
    const filterButton = this.page.locator(`[data-testid="transaction-filter-${type}"]`)
    await filterButton.click()
  }

  /**
   * Click back button to return to members list
   */
  async goBackToMembers(): Promise<void> {
    const backButton = this.page.locator('[data-testid="journal-back"]')
    const isVisible = await backButton.isVisible().catch(() => false)
    if (isVisible) {
      await backButton.click()
    }
  }

  /**
   * Search for member by name
   */
  async searchMember(query: string): Promise<void> {
    const searchBox = this.page.locator('[data-testid="journal-search"]').first()
    await searchBox.fill(query)
    await this.page.waitForTimeout(500) // Wait for debounce
  }

  /**
   * Get member name from currently selected member
   */
  async getSelectedMemberName(): Promise<string | null> {
    const memberHeader = this.page.locator('[data-testid="selected-member-name"]').first()
    const isVisible = await memberHeader.isVisible().catch(() => false)
    if (isVisible) {
      return await memberHeader.textContent()
    }
    return null
  }
}
