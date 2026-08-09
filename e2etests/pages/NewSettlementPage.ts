/**
 * New Settlement Page Object (UC-A30, ADR-0030)
 *
 * The unit of selection here is the **member**. A run sweeps each selected
 * member's whole unsettled position, so there is deliberately no
 * per-transaction checkbox to drive — the member's transactions are expandable
 * detail, and asserting they are read-only is part of the contract.
 *
 * Patterns: 005 (test IDs), 006 (page object — private locators, public
 * semantic methods), 008 (expect, never try/catch visibility checks).
 */

import { expect, Page } from '@playwright/test'
import { BasePage } from './BasePage'

export class NewSettlementPage extends BasePage {
  constructor(page: Page) {
    super(page)
  }

  // ── Locators ──────────────────────────────────────────────────────
  private readonly root = () => this.page.getByTestId('new-settlement-page')
  private readonly loading = () => this.page.getByTestId('new-settlement-loading')
  private readonly errorMessage = () => this.page.getByTestId('new-settlement-error-message')
  private readonly submitError = () => this.page.getByTestId('new-settlement-submit-error')

  private readonly summaryMembers = () => this.page.getByTestId('new-settlement-summary-members')
  private readonly summaryTransactions = () => this.page.getByTestId('new-settlement-summary-transactions')
  private readonly summaryTotal = () => this.page.getByTestId('new-settlement-summary-total')
  private readonly summaryExecutionDate = () => this.page.getByTestId('new-settlement-summary-execution-date')

  private readonly searchInput = () => this.page.getByTestId('new-settlement-search-input')
  private readonly selectAllBtn = () => this.page.getByTestId('new-settlement-select-all-btn')
  private readonly createBtn = () => this.page.getByTestId('new-settlement-create-btn')

  private readonly memberRow = (memberId: string) =>
    this.page.getByTestId(`new-settlement-member-row-${memberId}`)
  private readonly memberCheckbox = (memberId: string) =>
    this.page.getByTestId(`new-settlement-member-checkbox-${memberId}`)
  private readonly memberTransactions = (memberId: string) =>
    this.page.getByTestId(`new-settlement-member-transactions-${memberId}`)
  private readonly memberBalance = (memberId: string) =>
    this.page.getByTestId(`new-settlement-member-balance-${memberId}`)
  private readonly memberExpand = (memberId: string) =>
    this.page.getByTestId(`new-settlement-member-expand-${memberId}`)
  private readonly memberDetail = (memberId: string) =>
    this.page.getByTestId(`new-settlement-member-detail-${memberId}`)
  private readonly memberDetailList = (memberId: string) =>
    this.page.getByTestId(`new-settlement-member-detail-list-${memberId}`)
  private readonly memberDetailLoading = (memberId: string) =>
    this.page.getByTestId(`new-settlement-member-detail-loading-${memberId}`)

  private readonly ineligibleRow = (memberId: string) =>
    this.page.getByTestId(`new-settlement-ineligible-row-${memberId}`)
  private readonly creditRow = (memberId: string) =>
    this.page.getByTestId(`new-settlement-credit-row-${memberId}`)
  private readonly heldRow = (memberId: string) =>
    this.page.getByTestId(`new-settlement-held-row-${memberId}`)
  private readonly heldReason = (memberId: string) =>
    this.page.getByTestId(`new-settlement-held-reason-${memberId}`)

  // ── Navigation ────────────────────────────────────────────────────

  async goto() {
    await this.page.goto('/settlements/new', { waitUntil: 'domcontentloaded' })
    await this.waitForLoaded()
  }

  /** Enter the way an admin does: from the Settlements page. */
  async gotoViaSettlements() {
    await this.page.goto('/settlements', { waitUntil: 'domcontentloaded' })
    await this.page.getByTestId('settlements-new-btn').click()
    await this.waitForLoaded()
  }

  async waitForLoaded() {
    await expect(this.root()).toBeVisible()
    await expect(this.loading()).toBeHidden()
  }

  // ── The run summary ───────────────────────────────────────────────

  /**
   * What the screen says the run will contain. `transactions` is the swept
   * count — every open row of every selected member, not the rows on screen.
   */
  async getRunSummary(): Promise<{ members: number; transactions: number; total: string }> {
    return {
      members: parseInt((await this.summaryMembers().textContent())?.trim() ?? '0', 10),
      transactions: parseInt((await this.summaryTransactions().textContent())?.trim() ?? '0', 10),
      total: (await this.summaryTotal().textContent())?.trim() ?? '',
    }
  }

  async getExecutionDate(): Promise<string> {
    return (await this.summaryExecutionDate().textContent())?.trim() ?? ''
  }

  // ── Selection ─────────────────────────────────────────────────────

  async selectMember(memberId: string) {
    await this.memberCheckbox(memberId).check()
  }

  async deselectMember(memberId: string) {
    await this.memberCheckbox(memberId).uncheck()
  }

  async expectMemberSelected(memberId: string) {
    await expect(this.memberCheckbox(memberId)).toBeChecked()
  }

  async expectMemberNotSelected(memberId: string) {
    await expect(this.memberCheckbox(memberId)).not.toBeChecked()
  }

  async toggleSelectAll() {
    await this.selectAllBtn().click()
  }

  async search(term: string) {
    await this.searchInput().fill(term)
  }

  async expectMemberVisible(memberId: string) {
    await expect(this.memberRow(memberId)).toBeVisible()
  }

  async expectMemberHidden(memberId: string) {
    await expect(this.memberRow(memberId)).toHaveCount(0)
  }

  // ── A member's row ────────────────────────────────────────────────

  /** The count of open rows this member would contribute to the run. */
  async getMemberTransactionCount(memberId: string): Promise<number> {
    return parseInt((await this.memberTransactions(memberId).textContent())?.trim() ?? '0', 10)
  }

  async getMemberBalance(memberId: string): Promise<string> {
    return (await this.memberBalance(memberId).textContent())?.trim() ?? ''
  }

  /**
   * Expand a member and wait for the detail to actually arrive.
   *
   * The row appears the instant it is expanded, carrying a spinner — counting
   * the list before the fetch lands reads zero every time.
   */
  async expandMember(memberId: string) {
    await this.memberExpand(memberId).click()
    await expect(this.memberDetail(memberId)).toBeVisible()
    await expect(this.memberDetailLoading(memberId)).toHaveCount(0)
  }

  /** How many transactions the expanded detail lists for this member. */
  async getExpandedTransactionCount(memberId: string): Promise<number> {
    if ((await this.memberDetailList(memberId).count()) === 0) return 0
    return await this.memberDetailList(memberId).locator('li').count()
  }

  /**
   * The detail is informational. A checkbox here would re-introduce exactly
   * the affordance ADR-0030 removed, so its absence is asserted.
   */
  async expectDetailHasNoCheckboxes(memberId: string) {
    await expect(this.memberDetail(memberId).locator('input[type="checkbox"]')).toHaveCount(0)
  }

  // ── The excluded sections ─────────────────────────────────────────

  async expectMemberInIneligibleSection(memberId: string) {
    await expect(this.ineligibleRow(memberId)).toBeVisible()
  }

  async expectMemberInCreditSection(memberId: string) {
    await expect(this.creditRow(memberId)).toBeVisible()
  }

  /**
   * A hold is the exclusion that must never be silent (ruling #148 §4) — a held
   * member is skipped run after run until somebody clears it.
   */
  async expectMemberInHeldSection(memberId: string) {
    await expect(this.heldRow(memberId)).toBeVisible()
  }

  async getHoldReason(memberId: string): Promise<string> {
    return (await this.heldReason(memberId).textContent())?.trim() ?? ''
  }

  /** Excluded members are read-only — there is no way to drag them into a run. */
  async expectMemberNotSelectable(memberId: string) {
    await expect(this.memberCheckbox(memberId)).toHaveCount(0)
  }

  // ── Creating the run ──────────────────────────────────────────────

  /** Click create and return the created settlement's id. */
  async create(): Promise<string> {
    const responsePromise = this.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/settlements') &&
        resp.request().method() === 'POST' &&
        resp.status() === 201,
    )
    await this.createBtn().click()
    const response = await responsePromise
    return (await response.json()).id
  }

  async expectCreateDisabled() {
    await expect(this.createBtn()).toBeDisabled()
  }

  async expectCreateEnabled() {
    await expect(this.createBtn()).toBeEnabled()
  }

  async getSubmitError(): Promise<string | null> {
    if ((await this.submitError().count()) === 0) return null
    return (await this.submitError().textContent())?.trim() ?? null
  }

  async getErrorMessage(): Promise<string | null> {
    if ((await this.errorMessage().count()) === 0) return null
    return (await this.errorMessage().textContent())?.trim() ?? null
  }
}
