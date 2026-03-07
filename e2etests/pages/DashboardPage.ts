import { Page, expect } from '@playwright/test'
import { BasePage } from './BasePage'

export class DashboardPage extends BasePage {
  private readonly pageRoot = () => this.page.getByTestId('dashboard-page')
  private readonly title = () => this.page.getByTestId('dashboard-title')
  private readonly metricsSection = () => this.page.getByTestId('dashboard-metrics')
  private readonly recentTransactions = () => this.page.getByTestId('dashboard-recent-transactions')
  private readonly terminalStatusSection = () => this.page.getByTestId('dashboard-terminal-status')
  private readonly alertsSection = () => this.page.getByTestId('dashboard-alerts')
  private readonly systemStatusSection = () => this.page.getByTestId('dashboard-system-status')
  private readonly refreshButton = () => this.page.getByTestId('dashboard-refresh-button')
  private readonly sepaAlertMessage = () => this.page.getByTestId('dashboard-sepa-alert-message')
  private readonly loadingIndicator = () => this.page.getByTestId('dashboard-loading')

  constructor(page: Page) {
    super(page)
  }

  async expectPageVisible() {
    await expect(this.pageRoot()).toBeVisible()
    await expect(this.title()).toBeVisible()
  }

  async expectMetricsVisible() {
    await expect(this.metricsSection()).toBeVisible()
  }

  async expectRecentTransactionsVisible() {
    await expect(this.recentTransactions()).toBeVisible()
  }

  async expectTerminalStatusVisible() {
    await expect(this.terminalStatusSection()).toBeVisible()
  }

  async expectAlertsVisible() {
    await expect(this.alertsSection()).toBeVisible()
  }

  async expectSystemStatusVisible() {
    await expect(this.systemStatusSection()).toBeVisible()
  }

  async clickRefresh() {
    await this.refreshButton().click()
    await this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/dashboard') && resp.status() === 200,
      { timeout: 10000 }
    )
  }

  async getSepaAlertMessage(): Promise<string> {
    return (await this.sepaAlertMessage().textContent()) ?? ''
  }

  async getTransactionCount(): Promise<number> {
    return this.page.locator('[data-testid^="dashboard-transaction-"]').count()
  }

  async getTerminalCount(): Promise<number> {
    return this.page.locator('[data-testid^="dashboard-terminal-"]').count()
  }

  async expectSystemStatusField(testId: string) {
    await expect(this.page.getByTestId(`dashboard-system-${testId}`)).toBeVisible()
  }

  async waitForLoadingToComplete() {
    await expect(this.loadingIndicator()).not.toBeVisible({ timeout: 10000 })
  }
}
