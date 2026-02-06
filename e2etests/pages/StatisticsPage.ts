/**
 * Statistics Page Object
 *
 * Encapsulates all interactions with the monthly statistics page.
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

export class StatisticsPage extends BasePage {
  // Main page locators (PRIVATE)
  private readonly page_main = () => this.page.getByTestId('statistics-page')

  // Month switcher
  private readonly monthSwitcher = () => this.page.getByTestId('month-switcher')
  private readonly monthLabel = () => this.page.getByTestId('month-label')
  private readonly monthPrevBtn = () => this.page.getByTestId('month-prev')
  private readonly monthNextBtn = () => this.page.getByTestId('month-next')

  // Summary boxes
  private readonly summaryBoxes = () => this.page.getByTestId('summary-boxes')
  private readonly boxTotalRevenue = () => this.page.getByTestId('box-total-revenue')
  private readonly boxSoldItems = () => this.page.getByTestId('box-sold-items')
  private readonly boxTopProduct = () => this.page.getByTestId('box-top-product')
  private readonly valueTotalRevenue = () => this.page.getByTestId('value-total-revenue')
  private readonly valueSoldItems = () => this.page.getByTestId('value-sold-items')
  private readonly valueTopProductName = () => this.page.getByTestId('value-top-product-name')
  private readonly valueTopProductCount = () => this.page.getByTestId('value-top-product-count')

  // Revenue chart
  private readonly revenueChart = () => this.page.getByTestId('revenue-chart')

  // Top products
  private readonly topProducts = () => this.page.getByTestId('top-products')
  private readonly productRows = () => this.page.locator('[data-testid^="product-row-"]')

  // Top members
  private readonly topMembers = () => this.page.getByTestId('top-members')
  private readonly memberRows = () => this.page.locator('[data-testid^="member-row-"]')

  constructor(page: Page) {
    super(page)
  }

  /**
   * Navigate to statistics page
   */
  async navigate() {
    await super.navigate('http://localhost:5173/statistics')
  }

  /**
   * Wait for statistics data to load
   */
  async waitForDataLoad() {
    await this.page.waitForResponse((resp) => resp.url().includes('/statistics/monthly'))
  }

  /**
   * VISIBILITY EXPECTATIONS
   */

  async expectPageVisible() {
    await expect(this.page_main()).toBeVisible()
  }

  async expectMonthSwitcherVisible() {
    await expect(this.monthSwitcher()).toBeVisible()
  }

  async expectSummaryBoxesVisible() {
    await expect(this.summaryBoxes()).toBeVisible()
  }

  async expectRevenueChartVisible() {
    await expect(this.revenueChart()).toBeVisible()
  }

  async expectTopProductsVisible() {
    await expect(this.topProducts()).toBeVisible()
  }

  async expectTopMembersVisible() {
    await expect(this.topMembers()).toBeVisible()
  }

  /**
   * MONTH NAVIGATION
   */

  async getCurrentMonth(): Promise<string | null> {
    try {
      return await this.monthLabel().textContent()
    } catch {
      return null
    }
  }

  async goToPreviousMonth() {
    const responsePromise = this.page.waitForResponse((resp) => resp.url().includes('/statistics/monthly'))
    await this.monthPrevBtn().click()
    await responsePromise
  }

  async goToNextMonth() {
    const responsePromise = this.page.waitForResponse((resp) => resp.url().includes('/statistics/monthly'))
    await this.monthNextBtn().click()
    await responsePromise
  }

  async isNextMonthDisabled(): Promise<boolean> {
    return await this.monthNextBtn().isDisabled()
  }

  /**
   * SUMMARY BOX VALUES
   */

  async getTotalRevenue(): Promise<string | null> {
    try {
      return await this.valueTotalRevenue().textContent()
    } catch {
      return null
    }
  }

  async getSoldItemsCount(): Promise<string | null> {
    try {
      return await this.valueSoldItems().textContent()
    } catch {
      return null
    }
  }

  async getTopProductName(): Promise<string | null> {
    try {
      return await this.valueTopProductName().textContent()
    } catch {
      return null
    }
  }

  async getTopProductSoldCount(): Promise<string | null> {
    try {
      return await this.valueTopProductCount().textContent()
    } catch {
      return null
    }
  }

  /**
   * TOP PRODUCTS
   */

  async getTopProductsCount(): Promise<number> {
    return await this.productRows().count()
  }

  async getTopProductsList(): Promise<
    Array<{
      rank: number
      name: string
      soldCount: string
      revenue: string
    }>
  > {
    const rows = this.productRows()
    const count = await rows.count()
    const products = []

    for (let i = 0; i < count; i++) {
      const row = rows.nth(i)
      const cells = row.locator('td')

      const rank = await cells.nth(0).textContent()
      const name = await cells.nth(1).textContent()
      const soldCount = await cells.nth(2).textContent()
      const revenue = await cells.nth(3).textContent()

      if (rank && name && soldCount && revenue) {
        products.push({
          rank: parseInt(rank, 10),
          name: name.trim(),
          soldCount: soldCount.trim(),
          revenue: revenue.trim(),
        })
      }
    }

    return products
  }

  /**
   * TOP MEMBERS
   */

  async getTopMembersCount(): Promise<number> {
    return await this.memberRows().count()
  }

  async getTopMembersList(): Promise<
    Array<{
      rank: number
      name: string
      purchaseCount: string
      revenue: string
    }>
  > {
    const rows = this.memberRows()
    const count = await rows.count()
    const members = []

    for (let i = 0; i < count; i++) {
      const row = rows.nth(i)
      const cells = row.locator('td')

      const rank = await cells.nth(0).textContent()
      const name = await cells.nth(1).textContent()
      const purchaseCount = await cells.nth(2).textContent()
      const revenue = await cells.nth(3).textContent()

      if (rank && name && purchaseCount && revenue) {
        members.push({
          rank: parseInt(rank, 10),
          name: name.trim(),
          purchaseCount: purchaseCount.trim(),
          revenue: revenue.trim(),
        })
      }
    }

    return members
  }

  /**
   * CHART INTERACTIONS
   */

  async getChartBarCount(): Promise<number> {
    return await this.page.locator('[data-testid^="chart-bar-"]').count()
  }

  /**
   * PAGE STATE VERIFICATION
   */

  async isOnStatisticsPage(): Promise<boolean> {
    return this.getCurrentUrl().includes('/statistics')
  }

  async isDataLoaded(): Promise<boolean> {
    return await this.summaryBoxes().isVisible().catch(() => false)
  }
}
