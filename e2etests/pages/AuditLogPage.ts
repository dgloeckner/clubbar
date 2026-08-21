/**
 * Audit Log Page Object
 * Encapsulates interactions with the audit log page
 * Implements Pattern 006: Page Object Model
 * Implements Pattern 008: Playwright Assertions (no try-catch visibility checks)
 */

import { Page, Locator, expect } from '@playwright/test'
import { BasePage } from './BasePage'

export class AuditLogPage extends BasePage {
  constructor(page: Page) {
    super(page)
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
    await expect(this.page.getByRole('heading', { name: 'Audit-Log' })).toBeVisible()
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
   * All filter methods use waitForResponse instead of waitForTimeout
   */

  async setDateFromFilter(date: string) {
    const responsePromise = this.page.waitForResponse((resp) => {
      if (!resp.url().includes('/api/admin/audit-log') || resp.status() !== 200) return false
      try {
        return new URL(resp.url()).searchParams.get('date_from') === date
      } catch {
        return false
      }
    })
    await this.page.getByTestId('audit-log-filter-date-from').fill(date)
    await responsePromise
  }

  async setDateToFilter(date: string) {
    const responsePromise = this.page.waitForResponse((resp) => {
      if (!resp.url().includes('/api/admin/audit-log') || resp.status() !== 200) return false
      try {
        return new URL(resp.url()).searchParams.get('date_to') === date
      } catch {
        return false
      }
    })
    await this.page.getByTestId('audit-log-filter-date-to').fill(date)
    await responsePromise
  }

  async filterByAdmin(adminId: string) {
    const responsePromise = this.page.waitForResponse((resp) => {
      if (!resp.url().includes('/api/admin/audit-log') || resp.status() !== 200) return false
      try {
        return new URL(resp.url()).searchParams.get('admin_id') === adminId
      } catch {
        return false
      }
    })
    const select = this.page.getByTestId('audit-log-filter-admin')
    await select.selectOption(adminId)
    await responsePromise
  }

  async filterByAction(action: string) {
    // Match the specific action parameter to avoid being satisfied by the initial page-load
    // response (which has no action param) arriving while our listener is set up.
    const responsePromise = this.page.waitForResponse((resp) => {
      if (!resp.url().includes('/api/admin/audit-log') || resp.status() !== 200) return false
      try {
        return new URL(resp.url()).searchParams.get('action') === action
      } catch {
        return false
      }
    })
    const select = this.page.getByTestId('audit-log-filter-action')
    await select.selectOption(action)
    await responsePromise
    // Pattern 008: After the API response arrives, React processes it asynchronously.
    // Wait for the loading indicator to disappear — this confirms the component has
    // committed the new filtered data to the DOM before we read from it.
    await expect(this.page.getByTestId('audit-log-loading')).toBeHidden({ timeout: 10000 })
  }

  async filterByEntityType(entityType: string) {
    // Match the specific entity_type parameter to avoid being satisfied by the initial
    // page-load response (which has no entity_type param) arriving while our listener is set up.
    const responsePromise = this.page.waitForResponse((resp) => {
      if (!resp.url().includes('/api/admin/audit-log') || resp.status() !== 200) return false
      try {
        return new URL(resp.url()).searchParams.get('entity_type') === entityType
      } catch {
        return false
      }
    })
    const select = this.page.getByTestId('audit-log-filter-entity-type')
    await select.selectOption(entityType)
    await responsePromise
    // Pattern 008: After the API response arrives, React processes it asynchronously.
    // Wait for the loading indicator to disappear — this confirms the component has
    // committed the new filtered data to the DOM before we read from it.
    await expect(this.page.getByTestId('audit-log-loading')).toBeHidden({ timeout: 10000 })
  }

  async search(text: string) {
    // Search has a 500ms debounce, so we wait for the response after debounce fires.
    // Match the specific search parameter (handles both %20 and + encoding via URL API).
    const responsePromise = this.page.waitForResponse((resp) => {
      if (!resp.url().includes('/api/admin/audit-log') || resp.status() !== 200) return false
      try {
        return new URL(resp.url()).searchParams.get('search') === text
      } catch {
        return false
      }
    })
    const input = this.page.getByTestId('audit-log-search-input')
    await input.clear()
    await input.fill(text)
    await responsePromise
    // Pattern 008: After the API response arrives, React processes it asynchronously.
    // Wait for the loading indicator to disappear — this confirms the component has
    // committed the new filtered data to the DOM before we read from it.
    await expect(this.page.getByTestId('audit-log-loading')).toBeHidden({ timeout: 10000 })
  }

  async clearSearch() {
    const input = this.page.getByTestId('audit-log-search-input')
    const currentValue = await input.inputValue()

    // If already empty, nothing to clear — no API call will fire
    if (!currentValue) return

    // Clearing a non-empty search triggers an API call without the search param
    const responsePromise = this.page.waitForResponse((resp) => {
      if (!resp.url().includes('/api/admin/audit-log') || resp.status() !== 200) return false
      try {
        const searchParam = new URL(resp.url()).searchParams.get('search')
        return searchParam === null || searchParam === ''
      } catch {
        return false
      }
    })
    await input.clear()
    await responsePromise
  }

  /**
   * PAGINATION
   */

  async getResultsCount(): Promise<string> {
    const text = await this.page.getByTestId('audit-log-results-count').textContent()
    return text || '0'
  }

  async getResultsCountNumber(): Promise<number> {
    const text = await this.getResultsCount()
    const match = text.match(/(\d+)/)
    return match ? parseInt(match[1], 10) : 0
  }

  async goToPage(pageNumber: number) {
    const responsePromise = this.page.waitForResponse((resp) => {
      if (!resp.url().includes('/api/admin/audit-log') || resp.status() !== 200) return false
      try {
        return new URL(resp.url()).searchParams.get('page') === pageNumber.toString()
      } catch {
        return false
      }
    })
    await this.page.getByTestId(`audit-log-pagination-page-${pageNumber}`).click()
    await responsePromise
  }

  async setPageSize(size: number) {
    const responsePromise = this.page.waitForResponse((resp) => {
      if (!resp.url().includes('/api/admin/audit-log') || resp.status() !== 200) return false
      try {
        return new URL(resp.url()).searchParams.get('per_page') === size.toString()
      } catch {
        return false
      }
    })
    const select = this.page.getByTestId('audit-log-pagination-page-size-select')
    await select.selectOption(size.toString())
    await responsePromise
  }

  /**
   * SORTING
   */

  /**
   * Toggle the timestamp sort and wait for the list the API answers with.
   *
   * @param expectedSortBy The `sort_by` value the click must send (#125: the
   *        header used to toggle a local flag the request never carried).
   */
  async sortByTimestamp(expectedSortBy: 'created_at_asc' | 'created_at_desc') {
    const responsePromise = this.page.waitForResponse((resp) => {
      try {
        const url = new URL(resp.url())
        return (
          url.pathname.includes('/api/admin/audit-log') &&
          url.searchParams.get('sort_by') === expectedSortBy &&
          resp.status() === 200
        )
      } catch {
        return false
      }
    })

    const header = this.page.locator('[data-testid="audit-log-table"] th').first()
    await header.click()
    await responsePromise
  }

  /**
   * The timestamps of the visible rows, top to bottom, as rendered.
   *
   * `allTextContents()` is one of the few Playwright calls that does **not**
   * auto-wait: it reads the DOM as it stands and returns `[]` if the list has
   * not rendered yet. Called straight after navigation that is a race, and it
   * lost one on CI — `expect(timestamps.length).toBeGreaterThan(1)` received 0
   * while the first fetch was still in flight.
   *
   * Waiting for the first row and *then* reading closed most of that window
   * and not all of it: the two calls are separate, and `useListQuery` empties
   * the tbody again while a superseding request is in flight, so a row can be
   * visible at the check and gone at the read. It lost that narrower race too.
   *
   * `expect.poll` closes it properly by retrying the **read**, not a separate
   * precondition — the array returned is the one the assertion passed on, so
   * a transient blank cannot be what the caller receives. Every caller is
   * asserting on an order, so none of them wants an empty list.
   */
  async getTimestamps(): Promise<string[]> {
    const timestamps = this.page.locator('[data-testid^="audit-log-timestamp-"]')
    let rendered: string[] = []

    await expect
      .poll(async () => (rendered = await timestamps.allTextContents()).length)
      .toBeGreaterThan(0)

    return rendered
  }

  /**
   * EXPANDABLE ROWS
   */

  async expandDetails(entryId: number) {
    await this.page.getByTestId(`audit-log-expand-button-${entryId}`).click()
  }

  async collapseDetails(entryId: number) {
    await this.page.getByTestId(`audit-log-expand-button-${entryId}`).click()
  }

  async expectDetailsVisible(entryId: number) {
    await expect(this.page.getByTestId(`audit-log-details-row-${entryId}`)).toBeVisible()
  }

  async expectDetailsHidden(entryId: number) {
    // Pattern 008: Use expect().not.toBeVisible() instead of try-catch
    await expect(this.page.getByTestId(`audit-log-details-row-${entryId}`)).not.toBeVisible()
  }

  async getOldValues(entryId: number): Promise<string> {
    const preElement = this.page.getByTestId(`audit-log-old-values-${entryId}`)
    return await preElement.textContent() || ''
  }

  /**
   * Whether this entry renders a *before* side at all.
   *
   * Not every `update` row has one: a terminal toggle and an admin-user edit
   * are both audited as `update` with `old_values` NULL, so the block simply is
   * not in the DOM. A test looking for a row with before/after values has to
   * ask rather than assume — the log is shared, and which `update` sits at the
   * top depends on whichever specs are running beside it (Pattern 003).
   */
  async hasOldValues(entryId: number): Promise<boolean> {
    return (await this.page.getByTestId(`audit-log-old-values-${entryId}`).count()) > 0
  }

  async getNewValues(entryId: number): Promise<string> {
    const preElement = this.page.getByTestId(`audit-log-new-values-${entryId}`)
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

  /**
   * Get admin name cell text for a specific entry by its numeric ID.
   * Used for regression testing that the correct admin user name is displayed
   * (not a fallback string).
   */
  async getAdminName(entryId: number): Promise<string> {
    return await this.page.getByTestId(`audit-log-admin-${entryId}`).textContent() || ''
  }

  /**
   * Raw action slug (e.g. "create", "login_failed"), read from `data-action`
   * rather than the badge's visible text — the badge shows a translated,
   * human-readable label (#381) which would make this assertion locale-dependent.
   */
  async getAction(rowIndex: number = 0): Promise<string> {
    const row = this.page.locator('[data-testid^="audit-log-table-row-"]').nth(rowIndex)
    const cell = row.locator('[data-testid^="audit-log-action-"]')
    return await cell.getAttribute('data-action') || ''
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
   * HELPER METHODS FOR TEST-DRIVEN LOOKUPS
   */

  /**
   * Find a row by entity_id text content
   * Returns the row locator or null if not found
   */
  findRowByEntityId(entityId: string): Locator {
    return this.page.locator('[data-testid^="audit-log-table-row-"]', {
      has: this.page.locator(`[data-testid^="audit-log-entity-id-"]`, { hasText: entityId }),
    })
  }

  /**
   * Extract the numeric entry ID from a row's data-testid attribute
   */
  async getEntryIdFromRow(rowLocator: Locator): Promise<number> {
    const testId = await rowLocator.getAttribute('data-testid')
    if (!testId) throw new Error('Row does not have a data-testid attribute')
    return parseInt(testId.replace('audit-log-table-row-', ''), 10)
  }

  /**
   * Get all cell values for a specific entry by its numeric ID
   */
  async getRowCellValues(entryId: number): Promise<{
    timestamp: string
    admin: string
    action: string
    entityType: string
    entityId: string
  }> {
    // NOTE: ipAddress is in the expanded details row, not the main table row.
    // Use getIpAddress() after expandDetails() to read it.
    return {
      timestamp: await this.page.getByTestId(`audit-log-timestamp-${entryId}`).textContent() || '',
      admin: await this.page.getByTestId(`audit-log-admin-${entryId}`).textContent() || '',
      // Raw action slug via `data-action` — the cell's visible text is a translated,
      // human-readable label (#381), not the raw value.
      action: await this.page.getByTestId(`audit-log-action-${entryId}`).getAttribute('data-action') || '',
      entityType: await this.page.getByTestId(`audit-log-entity-type-${entryId}`).textContent() || '',
      entityId: await this.page.getByTestId(`audit-log-entity-id-${entryId}`).textContent() || '',
    }
  }

  /**
   * Assert that an entry with the given entity_id is visible in the table
   */
  async expectEntryExists(entityId: string) {
    const row = this.findRowByEntityId(entityId)
    await expect(row.first()).toBeVisible()
  }

  /**
   * Get the first entry ID from the first visible row
   */
  async getFirstEntryId(): Promise<number> {
    const firstRow = this.page.locator('[data-testid^="audit-log-table-row-"]').first()
    return this.getEntryIdFromRow(firstRow)
  }

  /**
   * Get the entry ID from a row at a given index (0-based)
   */
  async getEntryIdAtIndex(index: number): Promise<number> {
    const row = this.page.locator('[data-testid^="audit-log-table-row-"]').nth(index)
    return this.getEntryIdFromRow(row)
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
