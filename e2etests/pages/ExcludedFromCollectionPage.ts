/**
 * Excluded from Collection Page Object (#188).
 *
 * The standing counterpart of the New Settlement preview's exclusion
 * sections: the members the next run will leave out, visible between runs
 * rather than only inside a preview somebody happens to open.
 *
 * Two listings, two remedies. A member in credit is owed money and is paid by
 * hand; a member on hold owes money the last run failed to collect, and the
 * one write on the page releases them back into the next one.
 *
 * Patterns: 005 (test IDs), 006 (page object — private locators, public
 * semantic methods), 008 (expect, never try/catch visibility checks).
 */

import { expect, Page } from '@playwright/test'
import { BasePage } from './BasePage'

/**
 * Rendered money back into cents, without assuming a locale.
 *
 * `formatPrice` follows the admin's language, so the same amount is
 * "1.234,56 €" or "€1,234.56" depending on who is logged in. Whichever
 * separator comes last is the decimal one; everything before it is grouping.
 */
export function parseMoneyToCents(text: string): number {
  const trimmed = text.trim()
  const negative = /-|−|\(/.test(trimmed)
  const digitsAndSeparators = trimmed.replace(/[^\d.,]/g, '')

  const lastSeparator = Math.max(digitsAndSeparators.lastIndexOf(','), digitsAndSeparators.lastIndexOf('.'))
  const whole =
    lastSeparator === -1
      ? digitsAndSeparators
      : digitsAndSeparators.slice(0, lastSeparator).replace(/[.,]/g, '')
  const fraction = lastSeparator === -1 ? '' : digitsAndSeparators.slice(lastSeparator + 1)

  const cents = Number(whole || '0') * 100 + Number((fraction + '00').slice(0, 2) || '0')
  return negative ? -cents : cents
}

export class ExcludedFromCollectionPage extends BasePage {
  constructor(page: Page) {
    super(page)
  }

  // ── Locators ──────────────────────────────────────────────────────
  private readonly root = () => this.page.getByTestId('excluded-page')
  private readonly loading = () => this.page.getByTestId('excluded-loading')

  private readonly tabAll = () => this.page.getByTestId('members-tab-all')
  private readonly tabExcluded = () => this.page.getByTestId('members-tab-excluded')
  private readonly tabExcludedCount = () => this.page.getByTestId('members-tab-excluded-count')

  private readonly creditTotal = () => this.page.getByTestId('excluded-credit-total-value')
  private readonly holdTotal = () => this.page.getByTestId('excluded-hold-total-value')
  private readonly creditEmpty = () => this.page.getByTestId('excluded-credit-empty')
  private readonly holdEmpty = () => this.page.getByTestId('excluded-hold-empty')

  private readonly creditRow = (memberId: string) =>
    this.page.getByTestId(`excluded-credit-row-${memberId}`)
  private readonly holdRow = (memberId: string) =>
    this.page.getByTestId(`excluded-hold-row-${memberId}`)
  private readonly clearBtn = (memberId: string) =>
    this.page.getByTestId(`excluded-hold-clear-btn-${memberId}`)

  private readonly dialogMessage = () => this.page.getByTestId('excluded-clear-dialog-message')
  private readonly dialogProvenance = () =>
    this.page.getByTestId('excluded-clear-dialog-provenance')
  private readonly dialogConfirm = () => this.page.getByTestId('confirm-dialog-ok')
  private readonly dialogCancel = () => this.page.getByTestId('confirm-dialog-cancel')

  // ── Navigation ────────────────────────────────────────────────────

  async goto() {
    await this.navigate('/members/excluded')
    await expect(this.root()).toBeVisible()
    await expect(this.loading()).toHaveCount(0)
  }

  /** Reach the surface the way a treasurer does — via the Members tab. */
  async gotoViaTab() {
    await this.navigate('/members')
    await this.tabExcluded().click()
    await expect(this.root()).toBeVisible()
    await expect(this.loading()).toHaveCount(0)
  }

  async expectPageVisible() {
    await expect(this.root()).toBeVisible()
  }

  async expectTabsVisible() {
    await expect(this.tabAll()).toBeVisible()
    await expect(this.tabExcluded()).toBeVisible()
  }

  async getTabBadgeCount(): Promise<number> {
    return Number((await this.tabExcludedCount().textContent())?.trim() ?? '0')
  }

  async expectNoTabBadge() {
    await expect(this.tabExcludedCount()).toHaveCount(0)
  }

  // ── In credit ─────────────────────────────────────────────────────

  async expectMemberInCreditSection(memberId: string) {
    await expect(this.creditRow(memberId)).toBeVisible()
  }

  async expectMemberNotInCreditSection(memberId: string) {
    await expect(this.creditRow(memberId)).toHaveCount(0)
  }

  async getCreditRowText(memberId: string): Promise<string> {
    return (await this.creditRow(memberId).textContent())?.trim() ?? ''
  }

  async getCreditTotal(): Promise<string> {
    return (await this.creditTotal().textContent())?.trim() ?? ''
  }

  /** Every credit amount the page is currently showing, in cents. */
  async getCreditAmountsCents(): Promise<number[]> {
    return this.readAmounts('excluded-credit-amount-')
  }

  async getCreditTotalCents(): Promise<number> {
    return parseMoneyToCents(await this.getCreditTotal())
  }

  async expectCreditSectionEmpty() {
    await expect(this.creditEmpty()).toBeVisible()
  }

  // ── On collection hold ────────────────────────────────────────────

  async expectMemberInHoldSection(memberId: string) {
    await expect(this.holdRow(memberId)).toBeVisible()
  }

  async expectMemberNotInHoldSection(memberId: string) {
    await expect(this.holdRow(memberId)).toHaveCount(0)
  }

  async getHoldRowText(memberId: string): Promise<string> {
    return (await this.holdRow(memberId).textContent())?.trim() ?? ''
  }

  async getHoldTotal(): Promise<string> {
    return (await this.holdTotal().textContent())?.trim() ?? ''
  }

  /** Every held-back amount the page is currently showing, in cents. */
  async getHoldAmountsCents(): Promise<number[]> {
    return this.readAmounts('excluded-hold-amount-')
  }

  async getHoldTotalCents(): Promise<number> {
    return parseMoneyToCents(await this.getHoldTotal())
  }

  private async readAmounts(testIdPrefix: string): Promise<number[]> {
    const texts = await this.page.locator(`[data-testid^="${testIdPrefix}"]`).allTextContents()
    return texts.map(parseMoneyToCents)
  }

  async expectHoldSectionEmpty() {
    await expect(this.holdEmpty()).toBeVisible()
  }

  // ── Clearing a hold ───────────────────────────────────────────────

  /** Open the confirmation without answering it. */
  async openClearDialog(memberId: string) {
    await this.clearBtn(memberId).click()
    await expect(this.dialogMessage()).toBeVisible()
  }

  /**
   * What the dialog says it is about to do — the consequence and the hold's
   * own provenance, so the question can be answered without leaving it (#127).
   */
  async getClearDialogText(): Promise<string> {
    const message = (await this.dialogMessage().textContent())?.trim() ?? ''
    const provenance = (await this.dialogProvenance().textContent())?.trim() ?? ''
    return `${message} ${provenance}`
  }

  async confirmClear() {
    await this.dialogConfirm().click()
    await expect(this.dialogMessage()).toHaveCount(0)
  }

  async cancelClear() {
    await this.dialogCancel().click()
    await expect(this.dialogMessage()).toHaveCount(0)
  }

  /** Open the confirmation and answer it — the whole round trip. */
  async clearHold(memberId: string) {
    await this.openClearDialog(memberId)
    await this.confirmClear()
  }
}
