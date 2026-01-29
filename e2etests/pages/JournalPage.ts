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
  private readonly dateFromInput = () => this.page.getByTestId('journal-date-range-from')
  private readonly dateToInput = () => this.page.getByTestId('journal-date-range-to')
  private readonly filterTypeSelect = () => this.page.getByTestId('journal-filter-type-trigger')

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
  private readonly headerProduct = () => this.page.getByTestId('journal-header-product')
  private readonly headerAmount = () => this.page.getByTestId('journal-header-amount')
  private readonly headerDescription = () => this.page.getByTestId('journal-header-description')

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
    product: string
    amount: string
    description: string
  }> {
    try {
      const row = this.tableRows().nth(index)
      // Wait for row to be visible before trying to read cells
      await row.waitFor({ state: 'visible', timeout: 5000 })

      const cells = row.locator('td')
      await cells.nth(0).waitFor({ state: 'visible', timeout: 3000 })

      const date = await cells.nth(0).textContent({ timeout: 3000 })
      const type = await cells.nth(1).textContent({ timeout: 3000 })
      const member = await cells.nth(2).textContent({ timeout: 3000 })
      const product = await cells.nth(3).textContent({ timeout: 3000 })
      const amount = await cells.nth(4).textContent({ timeout: 3000 })
      const description = await cells.nth(5).textContent({ timeout: 3000 })

      return {
        date: date?.trim() || '',
        type: type?.trim() || '',
        member: member?.trim() || '',
        product: product?.trim() || '',
        amount: amount?.trim() || '',
        description: description?.trim() || '',
      }
    } catch (error) {
      // Return empty row if cells not found
      return {
        date: '',
        type: '',
        member: '',
        product: '',
        amount: '',
        description: '',
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
   * USER INTERACTIONS (actions that change state)
   */

  async search(query: string) {
    await this.searchInput().fill(query)
    // Wait for debounce and request to complete
    await this.page.waitForLoadState('networkidle')
  }

  async setDateFrom(date: string) {
    await this.dateFromInput().fill(date)
    // Wait for request
    await this.page.waitForLoadState('networkidle')
  }

  async setDateTo(date: string) {
    await this.dateToInput().fill(date)
    // Wait for request
    await this.page.waitForLoadState('networkidle')
  }

  async filterByType(type: 'all' | 'purchase' | 'correction') {
    const selectElement = this.filterTypeSelect()
    await selectElement.selectOption(type)
    // Wait for request
    await this.page.waitForLoadState('networkidle')
  }

  async sortBy(field: 'date' | 'type' | 'member' | 'amount') {
    const headerMap = {
      date: this.headerDate(),
      type: this.headerType(),
      member: this.headerMember(),
      amount: this.headerAmount(),
    }
    await headerMap[field].click()
    // Wait for request and re-sort
    await this.page.waitForLoadState('networkidle')
  }

  async goToPage(pageNumber: number) {
    const pageButton = this.page.getByTestId(`journal-page-${pageNumber}`)
    await pageButton.click()
    // Wait for request
    await this.page.waitForLoadState('networkidle')
  }

  async goToNextPage() {
    const nextBtn = this.page.getByTestId('journal-next')
    await nextBtn.click()
    // Wait for request
    await this.page.waitForLoadState('networkidle')
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
}
