/**
 * Statistics Page Object
 *
 * Encapsulates all interactions with the statistics/dashboard page.
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
  private readonly dashboard = () => this.page.getByTestId('statistics-dashboard')
  private readonly refreshBtn = () => this.page.getByTestId('dashboard-refresh-button')

  // Dashboard sections
  private readonly metricsSection = () => this.page.getByTestId('dashboard-metrics')
  private readonly alertsSection = () => this.page.getByTestId('dashboard-alerts')
  private readonly recentSection = () => this.page.getByTestId('dashboard-recent-transactions')
  private readonly actionsSection = () => this.page.getByTestId('dashboard-quick-actions')
  private readonly statusSection = () => this.page.getByTestId('dashboard-system-status')

  // Metric cards
  private readonly metricActiveMembersCard = () => this.page.getByTestId('metric-active-members')
  private readonly metricBalanceCard = () => this.page.getByTestId('metric-outstanding-balance')
  private readonly metricRevenueCard = () => this.page.getByTestId('metric-today-revenue')
  private readonly metricTerminalCard = () => this.page.getByTestId('metric-terminal-status')

  // Alerts
  private readonly sepaAlert = () => this.page.getByTestId('alert-sepa-invalid')

  // Recent transactions
  private readonly recentTable = () => this.page.getByTestId('recent-transactions-table')
  private readonly transactionRows = () => this.page.locator('[data-testid="transaction-row"]')

  // Quick actions
  private readonly newMemberBtn = () => this.page.getByTestId('quick-action-new-member')
  private readonly newSettlementBtn = () => this.page.getByTestId('quick-action-new-settlement')
  private readonly viewReportsBtn = () => this.page.getByTestId('quick-action-view-reports')

  // System status
  private readonly terminalConnectivity = () => this.page.getByTestId('status-terminal-connectivity')
  private readonly lastSettlement = () => this.page.getByTestId('status-last-settlement')

  // Reports view
  private readonly reportsView = () => this.page.getByTestId('reports-view')
  private readonly reportsButton = () => this.page.getByTestId('dashboard-reports-button')
  private readonly reportTypeSelector = () => this.page.getByTestId('report-type-selector')
  private readonly reportDateFilter = () => this.page.getByTestId('report-date-range-filter')
  private readonly reportTable = () => this.page.getByTestId('report-table')
  private readonly reportChart = () => this.page.getByTestId('report-chart')

  // Member ranking view
  private readonly rankingView = () => this.page.getByTestId('member-ranking-view')
  private readonly rankingButton = () => this.page.getByTestId('dashboard-member-ranking-button')
  private readonly rankingTable = () => this.page.getByTestId('member-ranking-table')
  private readonly rankingRows = () => this.page.locator('[data-testid="member-ranking-row"]')
  private readonly displayModeToggle = () => this.page.getByTestId('ranking-display-mode-toggle')
  private readonly topNSelector = () => this.page.getByTestId('ranking-top-n-selector')

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
   * VISIBILITY EXPECTATIONS
   */

  async expectPageVisible() {
    await expect(this.page_main()).toBeVisible()
  }

  async expectDashboardVisible() {
    await expect(this.dashboard()).toBeVisible()
  }

  async expectReportsViewVisible() {
    await expect(this.reportsView()).toBeVisible()
  }

  async expectRankingViewVisible() {
    await expect(this.rankingView()).toBeVisible()
  }

  /**
   * DASHBOARD INTERACTIONS (UC-A80)
   */

  async getActiveMembers(): Promise<string | null> {
    try {
      return await this.metricActiveMembersCard()
        .locator('[data-testid="metric-value"]')
        .textContent()
    } catch {
      return null
    }
  }

  async getOutstandingBalance(): Promise<string | null> {
    try {
      return await this.metricBalanceCard()
        .locator('[data-testid="metric-value"]')
        .textContent()
    } catch {
      return null
    }
  }

  async getTodayRevenue(): Promise<string | null> {
    try {
      return await this.metricRevenueCard()
        .locator('[data-testid="metric-value"]')
        .textContent()
    } catch {
      return null
    }
  }

  async getTerminalStatus(): Promise<string | null> {
    try {
      return await this.metricTerminalCard().textContent()
    } catch {
      return null
    }
  }

  async getSEPAAlertVisible(): Promise<boolean> {
    return await this.sepaAlert().isVisible().catch(() => false)
  }

  async getSEPAAlertText(): Promise<string | null> {
    try {
      return await this.sepaAlert().textContent()
    } catch {
      return null
    }
  }

  async getRecentTransactionCount(): Promise<number> {
    return await this.transactionRows().count()
  }

  async getRecentTransactions(): Promise<
    Array<{
      time: string
      member: string
      product: string
      amount: string
    }>
  > {
    const rows = this.transactionRows()
    const count = await rows.count()
    const transactions = []

    for (let i = 0; i < count; i++) {
      const row = rows.nth(i)
      const time = await row.locator('[data-testid="transaction-time"]').textContent()
      const member = await row.locator('[data-testid="transaction-member"]').textContent()
      const product = await row.locator('[data-testid="transaction-product"]').textContent()
      const amount = await row.locator('[data-testid="transaction-amount"]').textContent()

      if (time && member && product && amount) {
        transactions.push({
          time,
          member,
          product,
          amount,
        })
      }
    }

    return transactions
  }

  async refreshDashboard() {
    await this.refreshBtn().click()
    await this.page.waitForLoadState('networkidle')
  }

  /**
   * QUICK ACTIONS
   */

  async clickNewMember() {
    await this.newMemberBtn().click()
    await this.page.waitForLoadState('networkidle')
  }

  async clickNewSettlement() {
    await this.newSettlementBtn().click()
    await this.page.waitForLoadState('networkidle')
  }

  async clickViewReports() {
    await this.viewReportsBtn().click()
    await this.page.waitForLoadState('networkidle')
  }

  /**
   * SYSTEM STATUS
   */

  async getTerminalConnectivityStatus(): Promise<string | null> {
    try {
      return await this.terminalConnectivity().textContent()
    } catch {
      return null
    }
  }

  async getLastSettlementDate(): Promise<string | null> {
    try {
      return await this.lastSettlement().textContent()
    } catch {
      return null
    }
  }

  /**
   * REPORTS VIEW (UC-A50)
   */

  async openReports() {
    await this.reportsButton().click()
    await this.page.waitForLoadState('networkidle')
  }

  async selectReportType(type: 'revenue' | 'consumption' | 'transactions') {
    const option = this.page.getByTestId(`report-type-${type}`)
    await option.click()
    await this.page.waitForLoadState('networkidle')
  }

  async setReportDateRange(fromDate: string, toDate: string) {
    const dateFilter = this.reportDateFilter()
    const dateFilterVisible = await dateFilter.isVisible().catch(() => false)

    if (dateFilterVisible) {
      const fromInput = dateFilter.locator('[data-testid="date-from"]')
      const toInput = dateFilter.locator('[data-testid="date-to"]')

      await fromInput.fill(fromDate)
      await toInput.fill(toDate)
      await this.page.waitForLoadState('networkidle')
    }
  }

  async toggleReportView(view: 'chart' | 'table') {
    const toggle = this.page.getByTestId(`report-view-toggle-${view}`)
    await toggle.click()
    await this.waitForDebounce(300)
  }

  /**
   * MEMBER RANKING VIEW (UC-A51)
   */

  async openMemberRanking() {
    await this.rankingButton().click()
    await this.page.waitForLoadState('networkidle')
  }

  async setRankingDisplayMode(mode: 'named' | 'anonymized') {
    const option = this.page.getByTestId(`ranking-mode-${mode}`)
    await option.click()
    await this.waitForDebounce(300)
  }

  async setTopN(count: number | 'all') {
    const selector = this.topNSelector()
    const value = count === 'all' ? 'all' : count.toString()
    await selector.selectOption(value)
    await this.page.waitForLoadState('networkidle')
  }

  async getMemberRankingCount(): Promise<number> {
    return await this.rankingRows().count()
  }

  async getMemberRankings(): Promise<
    Array<{
      rank: string
      member: string
      total: string
      count: string
    }>
  > {
    const rows = this.rankingRows()
    const count = await rows.count()
    const rankings = []

    for (let i = 0; i < count; i++) {
      const row = rows.nth(i)
      const rank = await row.locator('[data-testid="ranking-rank"]').textContent()
      const member = await row.locator('[data-testid="ranking-member"]').textContent()
      const total = await row.locator('[data-testid="ranking-total"]').textContent()
      const txnCount = await row.locator('[data-testid="ranking-count"]').textContent()

      if (rank && member && total && txnCount) {
        rankings.push({
          rank,
          member,
          total,
          count: txnCount,
        })
      }
    }

    return rankings
  }

  /**
   * PAGE STATE VERIFICATION
   */

  async isOnStatisticsPage(): Promise<boolean> {
    return this.getCurrentUrl().includes('/statistics')
  }

  async isDashboardLoaded(): Promise<boolean> {
    return await this.dashboard().isVisible().catch(() => false)
  }
}
