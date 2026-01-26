/**
 * Members Page Object
 *
 * Encapsulates all interactions with the members management page.
 * Implements E2E Testing Pattern 005: Using Test IDs (data-testid)
 * Implements E2E Testing Pattern 006: Page Object Model
 * Implements E2E Testing Pattern 008: Playwright Assertions (no visibility helpers)
 *
 * **CRITICAL PATTERN PRINCIPLES:**
 * 1. Page object provides HIGH-LEVEL SEMANTIC METHODS (not raw locators)
 * 2. Tests use page object methods, NOT page.locator() or page.getByTestId()
 * 3. All locators are PRIVATE and hidden from tests
 * 4. Page object handles data-testid selection internally
 *
 * BAD (tests shouldn't do this):
 *   const modal = page.locator('[role="dialog"]')
 *   const table = page.getByTestId('members-table')
 *
 * GOOD (tests call page object methods):
 *   await membersPage.expectFormModalVisible()
 *   await membersPage.expectTableVisible()
 */

import { Page, expect } from '@playwright/test'
import { BasePage } from './BasePage'

export class MembersPage extends BasePage {
  // Stats cards
  private readonly statCardMitglieder = () => this.page.getByTestId('stat-card-mitglieder')
  private readonly statCardOffenePosten = () => this.page.getByTestId('stat-card-offene-posten')
  private readonly statCardLetzteAbrechnung = () => this.page.getByTestId('stat-card-letzte-abrechnung')

  // Search and filter
  private readonly searchInput = () => this.page.getByTestId('members-search-input')
  private readonly createBtn = () => this.page.getByTestId('members-create-button')

  // Table elements
  private readonly table = () => this.page.getByTestId('members-table')
  private readonly tableRows = () => this.page.locator('[data-testid^="members-table-row-"]')
  private readonly emptyState = () => this.page.getByTestId('members-empty-state')
  private readonly loadingIndicator = () => this.page.getByTestId('members-loading')
  private readonly errorMessage = () => this.page.getByTestId('members-error-message')

  // Modal elements
  private readonly formModal = () => this.page.getByTestId('members-form-modal')
  private readonly formModalContent = () => this.page.getByTestId('members-form-modal-content')
  private readonly formTitle = () => this.page.getByTestId('members-form-title')
  private readonly emailInput = () => this.page.getByTestId('members-form-email-input')
  private readonly firstNameInput = () => this.page.getByTestId('members-form-first-name-input')
  private readonly lastNameInput = () => this.page.getByTestId('members-form-last-name-input')
  private readonly phoneInput = () => this.page.getByTestId('members-form-phone-input')
  private readonly formSubmitBtn = () => this.page.getByTestId('members-form-submit-button')
  private readonly formCancelBtn = () => this.page.getByTestId('members-form-cancel-button')

  // Delete confirmation modal
  private readonly deleteConfirmModal = () => this.page.getByTestId('members-delete-confirm-modal')
  private readonly deleteConfirmOkBtn = () => this.page.getByTestId('members-delete-confirm-ok')
  private readonly deleteConfirmCancelBtn = () => this.page.getByTestId('members-delete-confirm-cancel')

  constructor(page: Page) {
    super(page)
  }

  /**
   * Navigate to members page
   */
  async navigate() {
    await super.navigate('http://localhost:5173/members')
  }

  /**
   * VISIBILITY EXPECTATIONS (Pattern 008: Use expect() for assertions)
   */

  async expectPageVisible() {
    await expect(this.page.getByTestId('members-page')).toBeVisible()
  }

  async expectTableVisible() {
    await expect(this.table()).toBeVisible()
  }

  async expectTableHidden() {
    await expect(this.table()).not.toBeVisible()
  }

  async expectFormModalVisible() {
    await expect(this.formModal()).toBeVisible()
  }

  async expectFormModalHidden() {
    await expect(this.formModal()).not.toBeVisible()
  }

  async expectDeleteConfirmModalVisible() {
    await expect(this.deleteConfirmModal()).toBeVisible()
  }

  async expectDeleteConfirmModalHidden() {
    await expect(this.deleteConfirmModal()).not.toBeVisible()
  }

  async expectEmptyStateVisible() {
    await expect(this.emptyState()).toBeVisible()
  }

  async expectErrorMessageVisible() {
    await expect(this.errorMessage()).toBeVisible()
  }

  async expectMemberRowVisible(memberId: string) {
    await expect(this.page.getByTestId(`members-table-row-${memberId}`)).toBeVisible()
  }

  async expectMemberRowHidden(memberId: string) {
    await expect(this.page.getByTestId(`members-table-row-${memberId}`)).not.toBeVisible()
  }

  /**
   * TABLE INTERACTIONS (Pattern 006: Semantic actions)
   */

  async getMemberRowCount(): Promise<number> {
    return await this.tableRows().count()
  }

  async getMemberEmailInRow(memberId: string): Promise<string> {
    const email = await this.page
      .getByTestId(`members-table-cell-email-${memberId}`)
      .textContent()
    return email || ''
  }

  async getMemberNameInRow(memberId: string): Promise<string> {
    const name = await this.page
      .getByTestId(`members-table-cell-name-${memberId}`)
      .textContent()
    return name || ''
  }

  async getMemberBalanceInRow(memberId: string): Promise<string> {
    const balance = await this.page
      .getByTestId(`members-table-cell-balance-${memberId}`)
      .textContent()
    return balance || ''
  }

  /**
   * SEARCH & FILTER (Pattern 006: Semantic actions)
   */

  async search(query: string) {
    await this.searchInput().fill(query)
    await this.waitForDebounce(500)
  }

  async clearSearch() {
    await this.searchInput().clear()
    await this.waitForDebounce(500)
  }

  async getSearchValue(): Promise<string> {
    return await this.searchInput().inputValue() || ''
  }

  /**
   * FORM MODAL INTERACTIONS (Pattern 006: Semantic actions)
   */

  async openCreateModal() {
    await this.createBtn().click()
  }

  async fillMemberForm(email: string, firstName: string, lastName: string, phone?: string) {
    await this.emailInput().fill(email)
    await this.firstNameInput().fill(firstName)
    await this.lastNameInput().fill(lastName)
    if (phone) {
      await this.phoneInput().fill(phone)
    }
  }

  async submitForm() {
    await this.formSubmitBtn().click()
  }

  async cancelForm() {
    await this.formCancelBtn().click()
  }

  async createMember(email: string, firstName: string, lastName: string, phone?: string) {
    await this.openCreateModal()
    await this.expectFormModalVisible()
    await this.fillMemberForm(email, firstName, lastName, phone)
    await this.submitForm()
  }

  async openEditModalForMember(memberId: string) {
    const editBtn = this.page.getByTestId(`members-table-action-edit-${memberId}`)
    await editBtn.click()
  }

  async editMember(memberId: string, email: string, firstName: string, lastName: string, phone?: string) {
    await this.openEditModalForMember(memberId)
    await this.expectFormModalVisible()
    await this.fillMemberForm(email, firstName, lastName, phone)
    await this.submitForm()
  }

  /**
   * DELETE INTERACTIONS (Pattern 006: Semantic actions)
   */

  async openDeleteConfirmForMember(memberId: string) {
    const deleteBtn = this.page.getByTestId(`members-table-action-delete-${memberId}`)
    await deleteBtn.click()
  }

  async confirmDelete() {
    await this.deleteConfirmOkBtn().click()
  }

  async cancelDelete() {
    await this.deleteConfirmCancelBtn().click()
  }

  async deleteMember(memberId: string) {
    await this.openDeleteConfirmForMember(memberId)
    await this.expectDeleteConfirmModalVisible()
    await this.confirmDelete()
  }

  /**
   * ERROR HANDLING
   */

  async getErrorMessage(): Promise<string | null> {
    try {
      return await this.errorMessage().textContent()
    } catch {
      return null
    }
  }

  /**
   * FORM FIELD HELPERS
   */

  async getFormEmailValue(): Promise<string> {
    return await this.emailInput().inputValue() || ''
  }

  async getFormFirstNameValue(): Promise<string> {
    return await this.firstNameInput().inputValue() || ''
  }

  async getFormLastNameValue(): Promise<string> {
    return await this.lastNameInput().inputValue() || ''
  }

  async getFormPhoneValue(): Promise<string> {
    return await this.phoneInput().inputValue() || ''
  }

  /**
   * STAT CARDS (Pattern 006: Semantic queries)
   */

  async getMemberCount(): Promise<string> {
    const text = await this.statCardMitglieder().textContent()
    // Extract the number from the stat card
    const match = text?.match(/\d+/)
    return match ? match[0] : '0'
  }

  async getOpenBalance(): Promise<string> {
    const text = await this.statCardOffenePosten().textContent()
    return text || '0,00 €'
  }

  async getLastSettlementDate(): Promise<string> {
    const text = await this.statCardLetzteAbrechnung().textContent()
    return text || ''
  }
}
