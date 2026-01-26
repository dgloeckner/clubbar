/**
 * Members Page Object
 *
 * Encapsulates all interactions with the members management page.
 * Implements E2E Testing Pattern 006: Page Object Model
 * Implements E2E Testing Pattern 008: Playwright Assertions (no visibility helpers)
 *
 * Key principle: Expose semantic user actions, return values tests need.
 * Tests use expect() directly for visibility checks.
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
   * Pattern 008: Return value tests need (string, not boolean)
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
   * Pattern 008: Return value tests need (number, not boolean)
   */
  async getMemberRowCount(): Promise<number> {
    return await this.tableRows().count()
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
    return await this.searchInput().inputValue() || ''
  }

  /**
   * Open create member modal
   * Pattern 008: Test uses await expect(modal).toBeVisible() instead of helper
   */
  async openCreateModal() {
    await this.createBtn().click()
    // Let test verify with: await expect(modal).toBeVisible()
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
   * Pattern 008: Test uses await expect(modal).toBeHidden() instead of helper
   */
  async submitMemberForm() {
    await this.saveBtn().click()
    // Let test verify with: await expect(modal).toBeHidden()
  }

  /**
   * Cancel member form
   * Pattern 008: Test uses await expect(modal).toBeHidden() instead of helper
   */
  async cancelMemberForm() {
    await this.cancelBtn().click()
    // Let test verify with: await expect(modal).toBeHidden()
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
   * Pattern 008: Test uses await expect(modal).toBeVisible() instead of helper
   */
  async openEditModalForFirstMember() {
    const firstRow = this.tableRows().first()
    const editBtn = firstRow.locator('button:has-text("Edit"), button:has-text("Bearbeiten")')
    await editBtn.click()
    // Let test verify with: await expect(modal).toBeVisible()
  }

  /**
   * Get email address of member in specific row
   * Pattern 008: Return value tests need (string, not boolean)
   */
  async getMemberEmailInRow(rowIndex: number): Promise<string | null> {
    const rows = this.tableRows()
    const row = rows.nth(rowIndex)
    const cells = row.locator('td')
    // Email is typically in the second column
    const emailCell = cells.nth(1)
    try {
      return await emailCell.textContent()
    } catch {
      return null
    }
  }

  // *** DO NOT ADD ***
  // The following methods would violate Pattern 008:
  // - isLoaded() - Use: await expect(page.locator('h1')).toBeVisible()
  // - isTableVisible() - Use: await expect(table).toBeVisible()
  // - isModalVisible() - Use: await expect(modal).toBeVisible()
  // - isModalHidden() - Use: await expect(modal).toBeHidden()
  // See: e2etests/patterns/008-playwright-assertions.md
}
