/**
 * Audit Log Page Object
 * Encapsulates interactions with the audit log page
 * Implements Pattern 006: Page Object Model
 */

import { Page, expect } from '@playwright/test'
import { BasePage } from './BasePage'

export class AuditLogPage extends BasePage {
  constructor(page: Page) {
    super(page)
  }

  /**
   * Provide access to page for advanced test scenarios (e.g., extracting test IDs from elements)
   * Tests can access via: auditLogPage.getPageForAdvancedQueries()
   * Or use the typed accessor: (auditLogPage as any).page
   */
  getPageForAdvancedQueries(): Page {
    return this['page']
  }

  /**
   * NAVIGATION
   */

  async navigateTo() {
    await this.page.goto('/audit-log')
  }

  /**
   * PAGE STATE VERIFICATION
   */

  async expectPageVisible() {
    await expect(this.page.getByTestId('audit-log-page')).toBeVisible()
  }

  async expectPageTitle() {
    const heading = this.page.locator('h1')
    await expect(heading).toContainText('Audit-Log')
  }

  /**
   * TABLE INTERACTIONS
   */

  async getTableRowCount(): Promise<number> {
    return await this.page.locator('[data-testid^="audit-log-table-row-"]').count()
  }

  async expectTableVisible() {
    await expect(this.page.getByTestId('audit-log-table')).toBeVisible()
  }

  async expectEmptyStateVisible() {
    await expect(this.page.getByTestId('audit-log-empty-state')).toBeVisible()
  }

  async expectLoadingVisible() {
    await expect(this.page.getByTestId('audit-log-loading')).toBeVisible()
  }

  /**
   * FILTER INTERACTIONS
   */

  async setDateFromFilter(date: string) {
    // date format: YYYY-MM-DD
    await this.page.getByTestId('audit-log-filter-date-from').fill(date)
    // Wait for table to update
    await this.page.waitForTimeout(800) // Debounce delay + some buffer
  }

  async setDateToFilter(date: string) {
    await this.page.getByTestId('audit-log-filter-date-to').fill(date)
    await this.page.waitForTimeout(800)
  }

  async filterByAdmin(adminId: string) {
    const select = this.page.getByTestId('audit-log-filter-admin')
    await select.selectOption(adminId)
    await this.page.waitForTimeout(500)
  }

  async filterByAction(action: string) {
    const select = this.page.getByTestId('audit-log-filter-action')
    await select.selectOption(action)
    await this.page.waitForTimeout(500)
  }

  async filterByEntityType(entityType: string) {
    const select = this.page.getByTestId('audit-log-filter-entity-type')
    await select.selectOption(entityType)
    await this.page.waitForTimeout(500)
  }

  async search(text: string) {
    const input = this.page.getByTestId('audit-log-search-input')
    await input.fill(text)
    await this.page.waitForTimeout(800) // Debounce delay
  }

  async clearSearch() {
    const input = this.page.getByTestId('audit-log-search-input')
    await input.fill('')
    await this.page.waitForTimeout(800)
  }

  /**
   * PAGINATION
   */

  async getResultsCount(): Promise<string> {
    const text = await this.page.getByTestId('audit-log-results-count').textContent()
    return text || '0'
  }

  async goToPage(pageNumber: number) {
    await this.page.getByTestId(`pagination-page-${pageNumber}`).click()
    await this.page.waitForTimeout(500)
  }

  async setPageSize(size: number) {
    const select = this.page.getByTestId('pagination-page-size-select')
    await select.selectOption(size.toString())
    await this.page.waitForTimeout(500)
  }

  /**
   * SORTING
   */

  async sortByTimestamp() {
    // Click on the sortable header for timestamp
    const header = this.page.locator('[data-testid="audit-log-table"] th').first()
    await header.click()
    await this.page.waitForTimeout(500)
  }

  /**
   * EXPANDABLE ROWS
   */

  async expandDetails(entryId: number) {
    await this.page.getByTestId(`audit-log-expand-button-${entryId}`).click()
    await this.page.waitForTimeout(300)
  }

  async collapseDetails(entryId: number) {
    await this.page.getByTestId(`audit-log-expand-button-${entryId}`).click()
    await this.page.waitForTimeout(300)
  }

  async expectDetailsVisible(entryId: number) {
    await expect(this.page.getByTestId(`audit-log-details-row-${entryId}`)).toBeVisible()
  }

  async expectDetailsHidden(entryId: number) {
    // Details row should not be visible
    const detailsRow = this.page.getByTestId(`audit-log-details-row-${entryId}`)
    const isVisible = await detailsRow.isVisible().catch(() => false)
    expect(isVisible).toBe(false)
  }

  async getOldValues(entryId: number): Promise<string> {
    const detailsRow = this.page.getByTestId(`audit-log-details-row-${entryId}`)
    const preElement = detailsRow.locator('pre').first()
    return await preElement.textContent() || ''
  }

  async getNewValues(entryId: number): Promise<string> {
    const detailsRow = this.page.getByTestId(`audit-log-details-row-${entryId}`)
    const preElement = detailsRow.locator('pre').nth(1)
    return await preElement.textContent() || ''
  }

  /**
   * CELL CONTENT GETTERS
   */

  async getTimestamp(rowIndex: number = 0): Promise<string> {
    const row = this.page.locator('[data-testid^="audit-log-table-row-"]').nth(rowIndex)
    const cell = row.locator('[data-testid^="audit-log-timestamp-"]')
    return await cell.textContent() || ''
  }

  async getAdmin(rowIndex: number = 0): Promise<string> {
    const row = this.page.locator('[data-testid^="audit-log-table-row-"]').nth(rowIndex)
    const cell = row.locator('[data-testid^="audit-log-admin-"]')
    return await cell.textContent() || ''
  }

  async getAction(rowIndex: number = 0): Promise<string> {
    const row = this.page.locator('[data-testid^="audit-log-table-row-"]').nth(rowIndex)
    const cell = row.locator('[data-testid^="audit-log-action-"]')
    return await cell.textContent() || ''
  }

  async getEntityType(rowIndex: number = 0): Promise<string> {
    const row = this.page.locator('[data-testid^="audit-log-table-row-"]').nth(rowIndex)
    const cell = row.locator('[data-testid^="audit-log-entity-type-"]')
    return await cell.textContent() || ''
  }

  async getEntityId(rowIndex: number = 0): Promise<string> {
    const row = this.page.locator('[data-testid^="audit-log-table-row-"]').nth(rowIndex)
    const cell = row.locator('[data-testid^="audit-log-entity-id-"]')
    return await cell.textContent() || ''
  }

  async getIpAddress(rowIndex: number = 0): Promise<string> {
    const row = this.page.locator('[data-testid^="audit-log-table-row-"]').nth(rowIndex)
    const cell = row.locator('[data-testid^="audit-log-ip-"]')
    return await cell.textContent() || ''
  }

  /**
   * ERROR HANDLING
   */

  async expectErrorMessage() {
    await expect(this.page.getByTestId('audit-log-error-message')).toBeVisible()
  }

  async getErrorMessage(): Promise<string> {
    const error = this.page.getByTestId('audit-log-error-message')
    return await error.textContent() || ''
  }
}
