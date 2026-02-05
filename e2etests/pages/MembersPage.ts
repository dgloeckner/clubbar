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
  private readonly firstNameInput = () => this.page.getByTestId('members-form-first-name-input')
  private readonly lastNameInput = () => this.page.getByTestId('members-form-last-name-input')
  private readonly emailInput = () => this.page.getByTestId('members-form-email-input')
  private readonly ibanInput = () => this.page.getByTestId('members-form-iban-input')
  private readonly accountHolderNameInput = () => this.page.getByTestId('members-form-account-holder-name-input')
  private readonly mandateReferenceInput = () => this.page.getByTestId('members-form-mandate-reference-input')
  private readonly mandateDateInput = () => this.page.getByTestId('members-form-mandate-date-input')
  private readonly languageSelect = () => this.page.getByTestId('members-form-language-select')
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
    // Wait for table or empty state to load
    await this.page.locator('[data-testid="members-table"], [data-testid="members-empty-state"]').first().waitFor({ timeout: 5000 })
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

  async waitForLoadingToComplete() {
    // Wait for loading indicator to disappear
    await expect(this.loadingIndicator()).not.toBeVisible({ timeout: 10000 })
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
    // Wait for table or empty state to be visible first
    await this.page.locator('[data-testid="members-table"], [data-testid="members-empty-state"]').first().waitFor({ timeout: 5000 })
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

  async getMemberNameAtRowIndex(rowIndex: number): Promise<string> {
    // Get member name at specific row index (for sorting verification)
    const nameCell = this.page.locator('[data-testid^="members-table-cell-name-"]').nth(rowIndex)
    return await nameCell.textContent() || ''
  }

  async getMemberBalanceAtRowIndex(rowIndex: number): Promise<string> {
    // Get member balance at specific row index (for sorting verification)
    const balanceCell = this.page.locator('[data-testid^="members-table-cell-balance-"]').nth(rowIndex)
    return await balanceCell.textContent() || ''
  }

  async getMemberFirstNameInTable(firstName: string): Promise<string | null> {
    // Search for member by first name in the table
    const nameLocators = this.page.locator('[data-testid^="members-table-cell-name-"]')
    const count = await nameLocators.count()

    for (let i = 0; i < count; i++) {
      const text = await nameLocators.nth(i).textContent()
      if (text && text.includes(firstName)) {
        return text
      }
    }

    return null
  }

  async getMemberLastNameInTable(lastName: string): Promise<string | null> {
    // Search for member by last name in the table
    const nameLocators = this.page.locator('[data-testid^="members-table-cell-name-"]')
    const count = await nameLocators.count()

    for (let i = 0; i < count; i++) {
      const text = await nameLocators.nth(i).textContent()
      if (text && text.includes(lastName)) {
        return text
      }
    }

    return null
  }

  async getMemberEmailInTable(email: string): Promise<string | null> {
    // Search for member by email in the table
    const emailLocators = this.page.locator('[data-testid^="members-table-cell-email-"]')
    const count = await emailLocators.count()

    for (let i = 0; i < count; i++) {
      const text = await emailLocators.nth(i).textContent()
      if (text && text.includes(email)) {
        return text
      }
    }

    return null
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

  async fillMemberForm(
    firstName: string,
    lastName: string,
    iban: string,
    mandateDate: string,
    email?: string,
    language?: string
  ) {
    await this.firstNameInput().fill(firstName)
    await this.lastNameInput().fill(lastName)
    if (email) {
      await this.emailInput().fill(email)
    }
    await this.ibanInput().fill(iban.toUpperCase())
    await this.mandateDateInput().fill(mandateDate)
    if (language) {
      await this.selectLanguage(language)
    }
  }

  async submitForm() {
    await this.formSubmitBtn().click()
  }

  async cancelForm() {
    await this.formCancelBtn().click()
  }

  async createMember(firstName: string, lastName: string, iban: string, mandateDate: string, email?: string, language?: string) {
    await this.openCreateModal()
    await this.expectFormModalVisible()
    await this.fillMemberForm(firstName, lastName, iban, mandateDate, email, language)
    await this.submitForm()
  }

  async openEditModalForMember(memberId: string) {
    const editBtn = this.page.getByTestId(`members-table-action-edit-${memberId}`)
    await editBtn.click()
  }

  async clickEditButtonAtRowIndex(rowIndex: number) {
    // Click edit button on member at specific row index
    const editButtons = this.page.locator('[data-testid^="members-table-action-edit-"]')
    await editButtons.nth(rowIndex).click()
  }

  async clickEditButtonForMember(firstName: string) {
    // Find member by first name and click edit button
    const nameLocators = this.page.locator('[data-testid^="members-table-cell-name-"]')
    const count = await nameLocators.count()

    for (let i = 0; i < count; i++) {
      const text = await nameLocators.nth(i).textContent()
      if (text && text.includes(firstName)) {
        // Extract member ID from the name cell's test ID
        const nameCell = nameLocators.nth(i)
        const testId = await nameCell.getAttribute('data-testid')

        if (testId) {
          const memberId = testId.replace('members-table-cell-name-', '')

          // Click the edit button for this member
          const editButton = this.page.getByTestId(`members-table-action-edit-${memberId}`)
          await expect(editButton).toBeVisible()
          await editButton.click()
          return
        }
      }
    }

    throw new Error(`Member with first name "${firstName}" not found in table`)
  }

  async editMember(memberId: string, firstName: string, lastName: string, iban: string, mandateDate: string, email?: string, language?: string) {
    await this.openEditModalForMember(memberId)
    await this.expectFormModalVisible()
    await this.fillMemberForm(firstName, lastName, iban, mandateDate, email, language)
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

  async getFormFirstNameValue(): Promise<string> {
    return await this.firstNameInput().inputValue() || ''
  }

  async getFormLastNameValue(): Promise<string> {
    return await this.lastNameInput().inputValue() || ''
  }

  async getFormEmailValue(): Promise<string> {
    return await this.emailInput().inputValue() || ''
  }

  async getFormIbanValue(): Promise<string> {
    return await this.ibanInput().inputValue() || ''
  }

  async getFormMandateDateValue(): Promise<string> {
    return await this.mandateDateInput().inputValue() || ''
  }

  async getFormAccountHolderNameValue(): Promise<string> {
    return await this.accountHolderNameInput().inputValue() || ''
  }

  async getFormMandateReferenceValue(): Promise<string> {
    return await this.mandateReferenceInput().inputValue() || ''
  }

  async fillAccountHolderName(name: string) {
    await this.accountHolderNameInput().fill(name)
  }

  async fillMandateReference(reference: string) {
    await this.mandateReferenceInput().fill(reference.toUpperCase())
  }

  async selectLanguage(language: string) {
    // Click the trigger button to open dropdown
    const trigger = this.page.getByTestId('members-form-language-select-trigger')
    await expect(trigger).toBeVisible()
    await trigger.click()

    // Click the option in the dropdown
    const option = this.page.getByTestId(`members-form-language-select-option-${language}`)
    await expect(option).toBeVisible()
    await option.click()

    // Wait for React to process the change
    await this.page.waitForTimeout(300)
  }

  async getFormLanguageValue(): Promise<string> {
    // Read the trigger button text to determine selected language
    const trigger = this.page.getByTestId('members-form-language-select-trigger')
    const text = await trigger.textContent() || ''

    if (text.includes('English')) return 'en'
    return 'de'  // Default to German
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

  /**
   * SORTING AND FILTERING CONTROLS
   */

  async setSortBy(sortOption: string) {
    const dropdown = this.page.getByTestId('members-sort-by')
    await expect(dropdown).toBeVisible()

    // Set the value directly using evaluate
    await dropdown.evaluate((el: HTMLSelectElement, value: string) => {
      el.value = value
    }, sortOption)

    // Dispatch change event to trigger React's onChange
    await dropdown.evaluate((el: HTMLSelectElement) => {
      el.dispatchEvent(new Event('change', { bubbles: true }))
      el.dispatchEvent(new Event('input', { bubbles: true }))
    })

    // Wait for React to process
    await this.page.waitForTimeout(300)
  }

  async setStatusFilter(filterOption: 'all' | 'active' | 'inactive') {
    // Click the trigger button to open dropdown
    const trigger = this.page.getByTestId('members-filter-status-trigger')
    await expect(trigger).toBeVisible()
    await trigger.click()

    // Click the option in the dropdown
    const option = this.page.getByTestId(`members-filter-status-option-${filterOption}`)
    await expect(option).toBeVisible()
    await option.click()

    // Wait for React to process the change
    await this.page.waitForTimeout(300)
  }

  async setSortBy(sortValue: string) {
    // Click the trigger button to open dropdown
    const trigger = this.page.getByTestId('members-sort-trigger')
    await expect(trigger).toBeVisible()
    await trigger.click()

    // Click the option in the dropdown
    const option = this.page.getByTestId(`members-sort-option-${sortValue}`)
    await expect(option).toBeVisible()
    await option.click()

    // Wait for React to process the change
    await this.page.waitForTimeout(300)
  }

  async getSortByValue(): Promise<string> {
    // Read the trigger button text to determine selected sort
    const trigger = this.page.getByTestId('members-sort-trigger')
    const text = await trigger.textContent() || ''

    // Extract the sort option from button text (contains direction arrow + label)
    if (text.includes('Newest first')) return 'created_at-desc'
    if (text.includes('Oldest first')) return 'created_at-asc'
    if (text.includes('First Name (A-Z)')) return 'first_name-asc'
    if (text.includes('First Name (Z-A)')) return 'first_name-desc'
    if (text.includes('Last Name (A-Z)')) return 'last_name-asc'
    if (text.includes('Last Name (Z-A)')) return 'last_name-desc'

    return 'created_at-desc'  // Default
  }

  async getStatusFilterValue(): Promise<'all' | 'active' | 'inactive'> {
    // Read the trigger button text to determine selected status
    const trigger = this.page.getByTestId('members-filter-status-trigger')
    const text = await trigger.textContent() || ''

    if (text.includes('Active Only')) return 'active'
    if (text.includes('Inactive Only')) return 'inactive'
    return 'all'
  }

  /**
   * Get member row by first name (returns row content or null if not found)
   * Pattern 003: Database-Agnostic Assertions - search by data, not position
   */
  async getMemberRowByName(firstName: string): Promise<string | null> {
    // Search for member by first name in the table
    const nameLocators = this.page.locator('[data-testid^="members-table-cell-name-"]')
    const count = await nameLocators.count()

    for (let i = 0; i < count; i++) {
      const text = await nameLocators.nth(i).textContent()
      if (text && text.includes(firstName)) {
        return text
      }
    }

    return null
  }

  /**
   * Toggle member status (active/inactive) by finding them by first name
   * Searches table by name to find member ID, then clicks toggle button
   */
  async toggleMemberStatus(firstName: string) {
    // Find the member row by searching through name cells
    const nameLocators = this.page.locator('[data-testid^="members-table-cell-name-"]')
    const count = await nameLocators.count()

    for (let i = 0; i < count; i++) {
      const text = await nameLocators.nth(i).textContent()
      if (text && text.includes(firstName)) {
        // Extract member ID from the name cell's test ID
        // Format: members-table-cell-name-{memberId}
        const nameCell = nameLocators.nth(i)
        const testId = await nameCell.getAttribute('data-testid')

        if (testId) {
          const memberId = testId.replace('members-table-cell-name-', '')

          // Click the toggle button for this member
          const toggleButton = this.page.getByTestId(`members-status-toggle-${memberId}`)
          await expect(toggleButton).toBeVisible()
          await toggleButton.click()

          // Wait for API to process
          await this.page.waitForTimeout(500)
          return
        }
      }
    }

    throw new Error(`Member with first name "${firstName}" not found in table`)
  }

  /**
   * CREATED DATE COLUMN INTERACTIONS
   */

  async expectSortDropdownHidden() {
    // Pattern 008: Verify dropdown is not present (removed as obsolete)
    const dropdown = this.page.getByTestId('members-sort-trigger')
    await expect(dropdown).not.toBeVisible()
  }

  async expectCreatedColumnHeaderVisible() {
    // Pattern 008: Use expect() for visibility assertions
    const header = this.page.locator('th:has-text("Created")')
    await expect(header).toBeVisible()
  }

  async clickCreatedColumnHeader() {
    // Click the "Created" column header to toggle sort
    const header = this.page.locator('th:has-text("Created")')
    await expect(header).toBeVisible()
    await header.click()

    // Wait for API to process sort change
    await this.page.waitForTimeout(500)
  }

  async getMemberCreatedDateAtRowIndex(rowIndex: number): Promise<string> {
    // Get created date at specific row index
    const dateCell = this.page.locator('[data-testid^="members-table-cell-created-"]').nth(rowIndex)
    return await dateCell.textContent() || ''
  }
}
