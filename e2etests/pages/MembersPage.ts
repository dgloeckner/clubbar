/**
 * Members Page Object
 *
 * Encapsulates all interactions with the members management page.
 * Implements E2E Testing Pattern 006: Page Object Model
 */

import { Page } from '@playwright/test'
import { BasePage } from './BasePage'

export class MembersPage extends BasePage {
  // Stats cards
  private readonly statCardMitglieder = () => this.page.getByTestId('stat-card-mitglieder')
  private readonly statCardOffenePosten = () => this.page.getByTestId('stat-card-offene-posten')
  private readonly statCardLetzteAbrechnung = () => this.page.getByTestId('stat-card-letzte-abrechnung')

  // Search and filter
  private readonly searchInput = () => this.page.locator('input[placeholder*="Search"], input[placeholder*="search"]')
  private readonly createBtn = () => this.page.locator('button:has-text("Create"), button:has-text("Hinzufügen"), button:has-text("Add")')

  // Table elements
  private readonly table = () => this.page.locator('table, [role="table"]')
  private readonly tableRows = () => this.page.locator('tbody tr, [role="row"]').filter({ hasNot: this.page.locator('th') })

  // Modal elements
  private readonly modal = () => this.page.locator('[role="dialog"]').first()
  private readonly emailInput = () => this.modal().locator('input[type="email"]').first()
  private readonly firstNameInput = () => this.modal().locator('input[placeholder*="First"], input[placeholder*="Vorname"]')
  private readonly lastNameInput = () => this.modal().locator('input[placeholder*="Last"], input[placeholder*="Nachname"]')
  private readonly saveBtn = () => this.modal().locator('button:has-text("Save"), button:has-text("Speichern"), button:has-text("Create")').first()
  private readonly cancelBtn = () => this.modal().locator('button:has-text("Cancel"), button:has-text("Abbrechen")')

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
   * Get member count from stat card
   * Note: Do NOT return boolean for "is visible"
   * Tests should use expect() directly (Pattern 008)
   */
  async getMemberCount(): Promise<string> {
    const valueElement = this.statCardMitglieder().locator(':scope >> nth=1')
    return await valueElement.textContent() || '0'
  }

  /**
   * Get open balance from stat card
   */
  async getOpenBalance(): Promise<string> {
    const valueElement = this.statCardOffenePosten().locator(':scope >> nth=1')
    return await valueElement.textContent() || '0,00 €'
  }

  /**
   * Get number of member rows in table
   */
  async getMemberRowCount(): Promise<number> {
    return await this.getElementCount(this.tableRows())
  }

  /**
   * Search for members by email
   */
  async search(email: string) {
    await this.searchInput().fill(email)
    await this.waitForDebounce(500)
  }

  /**
   * Clear search filter
   */
  async clearSearch() {
    await this.searchInput().clear()
    await this.waitForDebounce(500)
  }

  /**
   * Get current search value
   */
  async getSearchValue(): Promise<string> {
    return await this.searchInput().inputValue()
  }

  /**
   * Open create member modal
   */
  async openCreateModal() {
    await this.createBtn().click()
    await this.waitForElement(this.modal(), 5000)
  }

  /**
   * Fill member form fields
   */
  async fillMemberForm(email: string, firstName: string, lastName: string) {
    await this.emailInput().fill(email)
    await this.firstNameInput().fill(firstName)
    await this.lastNameInput().fill(lastName)
  }

  /**
   * Submit member form
   */
  async submitMemberForm() {
    await this.saveBtn().click()
    await this.waitForElementHidden(this.modal(), 5000)
  }

  /**
   * Cancel member form
   */
  async cancelMemberForm() {
    await this.cancelBtn().click()
    await this.waitForElementHidden(this.modal(), 5000)
  }

  /**
   * Create a new member (open form, fill, submit)
   */
  async createMember(email: string, firstName: string, lastName: string) {
    await this.openCreateModal()
    await this.fillMemberForm(email, firstName, lastName)
    await this.submitMemberForm()
  }

  /**
   * Open edit modal for first member in table
   */
  async openEditModalForFirstMember() {
    const firstRow = this.tableRows().first()
    const editBtn = firstRow.locator('button:has-text("Edit"), button:has-text("Bearbeiten")')
    await editBtn.click()
    await this.waitForElement(this.modal(), 5000)
  }

  /**
   * Get email address of member in specific row
   */
  async getMemberEmailInRow(rowIndex: number): Promise<string | null> {
    const rows = this.tableRows()
    const row = rows.nth(rowIndex)
    const cells = row.locator('td')
    // Email is typically in the second column
    const emailCell = cells.nth(1)
    return await this.getElementText(emailCell)
  }

  // Note: Do NOT add isModalVisible() or isModalHidden() helper methods
  // Instead, use Playwright's expect() API directly in tests:
  //   await expect(modal).toBeVisible()
  //   await expect(modal).toBeHidden()
  // This follows Pattern 008: Playwright Assertions & Auto-Waiting
}
