/**
 * Registrations Page Object
 *
 * The self-registration inbox (UC-A17), as far as "accept first, print in
 * bulk, sign later" drives it: finding rows, accepting one ahead of its
 * signature, and accepting everything on screen in one go.
 *
 * Implements E2E Testing Pattern 005 (test ids), 006 (page object) and 008
 * (Playwright assertions): locators are private, tests call semantic methods,
 * and every wait is an `expect()`.
 */

import { Page, Response, expect } from '@playwright/test'
import { BasePage } from './BasePage'

export class RegistrationsPage extends BasePage {
  private readonly root = () => this.page.getByTestId('registrations-page')
  private readonly searchInput = () => this.page.getByTestId('registrations-search')
  private readonly row = (id: string) => this.page.getByTestId(`registration-row-${id}`)
  private readonly rows = () => this.page.locator('[data-testid^="registration-row-"]')
  private readonly openButton = (id: string) => this.page.getByTestId(`registration-open-${id}`)
  private readonly emptyState = () => this.page.getByTestId('registrations-empty')

  // The review panel
  private readonly panel = () => this.page.getByTestId('registration-panel')
  private readonly panelApprove = () => this.page.getByTestId('panel-approve')
  private readonly approveConfirm = () => this.page.getByTestId('approve-confirm')
  private readonly approveWithoutSignature = () => this.page.getByTestId('approve-without-signature')
  private readonly approveWithoutSignatureSection = () =>
    this.page.getByTestId('approve-without-signature-section')

  // "Alle aufnehmen"
  private readonly acceptAllButton = () => this.page.getByTestId('registrations-accept-all-button')
  private readonly acceptAllCount = () => this.page.getByTestId('accept-all-count')
  private readonly acceptAllUnsigned = () => this.page.getByTestId('accept-all-unsigned')
  private readonly acceptAllDuplicates = () => this.page.getByTestId('accept-all-duplicates')
  private readonly dialogConfirm = () => this.page.getByTestId('confirm-dialog-ok')
  private readonly dialog = () => this.page.getByTestId('confirm-dialog')
  private readonly batchResult = () => this.page.getByTestId('registrations-batch-result')
  private readonly batchAccepted = () => this.page.getByTestId('batch-result-accepted')
  private readonly batchSkipped = () => this.page.getByTestId('batch-result-skipped')
  private readonly batchReason = (code: string) => this.page.getByTestId(`batch-result-reason-${code}`)

  constructor(page: Page) {
    super(page)
  }

  async open() {
    await this.page.goto('/registrations')
    await expect(this.root()).toBeVisible()
  }

  /**
   * Narrow the queue to rows matching `query`.
   *
   * Waits for the response carrying this query: the search is debounced, so
   * the list on screen a moment after typing is still the previous one — and
   * "Alle aufnehmen" names whatever is on screen.
   */
  async search(query: string) {
    const settled = this.page.waitForResponse((response) => {
      if (!response.url().includes('/api/admin/registrations') || response.status() !== 200) return false
      try {
        return new URL(response.url()).searchParams.get('search') === query
      } catch {
        return false
      }
    })
    await this.searchInput().fill(query)
    await settled
  }

  async expectRowVisible(id: string) {
    await expect(this.row(id)).toBeVisible()
  }

  async expectRowGone(id: string) {
    await expect(this.row(id)).toHaveCount(0)
  }

  async expectRowCount(count: number) {
    await expect(this.rows()).toHaveCount(count)
  }

  async expectQueueEmpty() {
    await expect(this.emptyState()).toBeVisible()
  }

  // ── one registration, ahead of its signature ───────────────────────────

  async openApproval(id: string) {
    await this.openButton(id).click()
    await expect(this.panel()).toBeVisible()
    await this.panelApprove().click()
  }

  /** The attested approval is still locked: neither input has been given. */
  async expectAttestedApprovalLocked() {
    await expect(this.approveConfirm()).toBeDisabled()
  }

  async expectUnsignedAcceptanceExplained() {
    await expect(this.approveWithoutSignatureSection()).toBeVisible()
    await expect(this.approveWithoutSignatureSection()).not.toBeEmpty()
  }

  /** Accept without a signature and hand back what the API answered. */
  async acceptWithoutSignature(): Promise<Response> {
    const answered = this.page.waitForResponse(
      (response) => /\/api\/admin\/registrations\/[^/]+\/approve$/.test(response.url()),
    )
    await this.approveWithoutSignature().click()

    return answered
  }

  // ── everything on screen ────────────────────────────────────────────────

  async expectAcceptAllDisabled() {
    await expect(this.acceptAllButton()).toBeDisabled()
  }

  async openAcceptAll() {
    await this.acceptAllButton().click()
    await expect(this.dialog()).toBeVisible()
  }

  /** What the confirmation says, for the test to hold against the request. */
  async acceptAllConfirmationText(): Promise<{ count: string; unsigned: string; duplicates: string }> {
    return {
      count: (await this.acceptAllCount().textContent()) ?? '',
      unsigned: (await this.acceptAllUnsigned().textContent()) ?? '',
      duplicates: (await this.acceptAllDuplicates().textContent()) ?? '',
    }
  }

  /** Confirm and hand back what the batch endpoint answered. */
  async confirmAcceptAll(): Promise<Response> {
    const answered = this.page.waitForResponse((response) =>
      response.url().includes('/api/admin/registrations/approve-batch'),
    )
    await this.dialogConfirm().click()
    const response = await answered

    await expect(this.dialog()).toBeHidden()

    return response
  }

  async expectBatchResult(expected: { accepted: number; skipped?: { count: number; reason: string } }) {
    await expect(this.batchResult()).toBeVisible()
    await expect(this.batchAccepted()).toContainText(String(expected.accepted))

    if (expected.skipped) {
      await expect(this.batchSkipped()).toContainText(String(expected.skipped.count))
      await expect(this.batchReason(expected.skipped.reason)).toBeVisible()
      // In the admin's language: the reason code itself never reaches the page.
      await expect(this.batchReason(expected.skipped.reason)).not.toContainText(expected.skipped.reason)
    } else {
      await expect(this.batchSkipped()).toHaveCount(0)
    }
  }
}
