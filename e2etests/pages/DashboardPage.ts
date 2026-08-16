import { Page, expect } from '@playwright/test'
import { BasePage } from './BasePage'

export class DashboardPage extends BasePage {
  private readonly pageRoot = () => this.page.getByTestId('dashboard-page')
  private readonly metricsSection = () => this.page.getByTestId('dashboard-metrics')
  private readonly recentTransactions = () => this.page.getByTestId('dashboard-recent-transactions')
  private readonly terminalStatusSection = () => this.page.getByTestId('dashboard-terminal-status')
  private readonly alertsSection = () => this.page.getByTestId('dashboard-alerts')
  private readonly systemStatusSection = () => this.page.getByTestId('dashboard-system-status')
  private readonly sepaAlertMessage = () => this.page.getByTestId('dashboard-sepa-alert-message')
  private readonly membersNearLimitSection = () => this.page.getByTestId('dashboard-members-near-limit')
  private readonly noMembersNearLimit = () => this.page.getByTestId('dashboard-no-members-near-limit')
  private readonly memberNearLimit = (memberId: string) =>
    this.page.getByTestId(`dashboard-member-near-limit-${memberId}`)
  private readonly transactionAmount = (transactionId: string) =>
    this.page.getByTestId(`dashboard-transaction-amount-${transactionId}`)
  private readonly loadingIndicator = () => this.page.getByTestId('dashboard-loading')
  private readonly staleWarning = () => this.page.getByTestId('dashboard-stale-warning')

  constructor(page: Page) {
    super(page)
  }

  async goto() {
    await this.navigate('/dashboard')
    await expect(this.pageRoot()).toBeVisible()
    await expect(this.loadingIndicator()).toHaveCount(0)
  }

  async expectPageVisible() {
    await expect(this.pageRoot()).toBeVisible()
    await expect(this.metricsSection()).toBeVisible()
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

  /** Shown once an auto-refresh fails while the page still renders the numbers
   *  from the last one that worked (#132). */
  async expectStaleWarningVisible() {
    await expect(this.staleWarning()).toBeVisible({ timeout: 20000 })
  }

  async expectStaleWarningHidden() {
    await expect(this.staleWarning()).toBeHidden()
  }

  async getStaleWarningText(): Promise<string> {
    return ((await this.staleWarning().textContent()) ?? '').trim()
  }

  async waitForRefresh() {
    await this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/dashboard') && resp.status() === 200,
      { timeout: 15000 }
    )
  }

  async getSepaAlertMessage(): Promise<string> {
    return (await this.sepaAlertMessage().textContent()) ?? ''
  }

  /** Members whose tab has reached the terminal's credit-limit warning band (#385). */
  async expectMembersNearLimitVisible() {
    await expect(this.membersNearLimitSection()).toBeVisible()
  }

  async expectMemberNearLimit(memberId: string) {
    await expect(this.memberNearLimit(memberId)).toBeVisible()
  }

  async expectMemberNotNearLimit(memberId: string) {
    await expect(this.memberNearLimit(memberId)).toBeHidden()
  }

  async expectNoMembersNearLimit() {
    await expect(this.noMembersNearLimit()).toBeVisible()
  }

  async getMemberNearLimitBalance(memberId: string): Promise<string> {
    return (
      (await this.page.getByTestId(`dashboard-member-near-limit-balance-${memberId}`).textContent()) ?? ''
    ).trim()
  }

  /** The verdict itself — `approaching` or `exceeded` — free of the locale's wording. */
  async getMemberNearLimitState(memberId: string): Promise<string | null> {
    return this.memberNearLimit(memberId).getAttribute('data-status')
  }

  async getMemberNearLimitStatus(memberId: string): Promise<string> {
    return (
      (await this.page.getByTestId(`dashboard-member-near-limit-status-${memberId}`).textContent()) ?? ''
    ).trim()
  }

  /**
   * ADR-0042: an everyday charge is not an error, so it is never red — it stays
   * in the primary text colour `theme.colors.text.primary` (#f1f5f9).
   */
  async expectTransactionAmountNeutral(transactionId: string) {
    await expect(this.transactionAmount(transactionId)).toHaveCSS('color', 'rgb(241, 245, 249)')
  }

  /**
   * ADR-0042: green is reserved for money in the member's favour — a storno or
   * a refund — `theme.colors.semantic.success` (#22c55e).
   */
  async expectTransactionAmountCredit(transactionId: string) {
    await expect(this.transactionAmount(transactionId)).toHaveCSS('color', 'rgb(34, 197, 94)')
  }

  async getTransactionCount(): Promise<number> {
    // The rows are counted by prefix, and elements *inside* a row share that
    // prefix (`dashboard-transaction-amount-{id}`), so they have to be excluded
    // or every row counts twice — the same trap the stat-card count avoids.
    return this.page
      .locator('[data-testid^="dashboard-transaction-"]:not([data-testid^="dashboard-transaction-amount-"])')
      .count()
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
