/**
 * Notifications (mail queue) Page Object — #407.
 *
 * Patterns 005–008: semantic test IDs, page object, fixture-injected, and
 * `expect()` for every visibility check rather than try-catch.
 *
 * Every method that changes what the list contains waits on the **API
 * response**, never on `networkidle`: the page fetches from a `useEffect`,
 * which fires *after* the network has gone idle, so an idle wait returns
 * before the request the test is waiting for has even started.
 */

import { Page, expect } from '@playwright/test'
import { BasePage } from './BasePage'

const LIST_URL = /\/api\/admin\/notifications(\?|$)/

export class NotificationsPage extends BasePage {
  constructor(page: Page) {
    super(page)
  }

  async navigateTo() {
    const response = this.page.waitForResponse(
      (r) => LIST_URL.test(r.url()) && r.request().method() === 'GET'
    )
    await this.page.goto('/notifications')
    await response
  }

  async expectPageVisible() {
    await expect(this.page.getByTestId('notifications-page')).toBeVisible()
  }

  /** Rows currently rendered, desktop table or mobile cards. */
  async rowCount(): Promise<number> {
    return this.page.locator('[data-testid^="notifications-row-"]').count()
  }

  async expectRowVisible(id: string) {
    await expect(this.page.getByTestId(`notifications-row-${id}`)).toBeVisible()
  }

  async expectRowAbsent(id: string) {
    await expect(this.page.getByTestId(`notifications-row-${id}`)).toHaveCount(0)
  }

  /** The status as the server reported it, read from the badge's data attribute. */
  async statusOf(id: string): Promise<string | null> {
    return this.page.getByTestId(`notifications-status-${id}`).getAttribute('data-status')
  }

  async errorTextOf(id: string): Promise<string> {
    return (await this.page.getByTestId(`notifications-error-${id}`).textContent()) ?? ''
  }

  async recipientOf(id: string): Promise<string> {
    return (await this.page.getByTestId(`notifications-recipient-${id}`).textContent()) ?? ''
  }

  async memberNameOf(id: string): Promise<string> {
    return (await this.page.getByTestId(`notifications-member-${id}`).textContent()) ?? ''
  }

  private async applyFilter(testId: string, value: string) {
    const response = this.page.waitForResponse(
      (r) => LIST_URL.test(r.url()) && r.request().method() === 'GET'
    )
    await this.page.getByTestId(testId).selectOption(value)
    await response
  }

  async filterByKind(kind: string) {
    await this.applyFilter('notifications-filter-kind', kind)
  }

  async filterByStatus(status: string) {
    await this.applyFilter('notifications-filter-status', status)
  }

  /**
   * Retry one message and wait for the list to be re-read.
   *
   * Two responses, in order: the POST that changes the row, then the GET the
   * page issues to re-read it. Waiting only for the POST would assert against
   * a table that has not been refreshed yet.
   */
  async retry(id: string) {
    const posted = this.page.waitForResponse(
      (r) => r.url().includes(`/api/admin/notifications/${id}/retry`) && r.request().method() === 'POST'
    )
    const reloaded = this.page.waitForResponse(
      (r) => LIST_URL.test(r.url()) && r.request().method() === 'GET'
    )
    await this.page.getByTestId(`notifications-retry-${id}`).click()
    await posted
    await reloaded
  }

  async expectRetryAvailable(id: string) {
    await expect(this.page.getByTestId(`notifications-retry-${id}`)).toBeVisible()
  }

  /** A message that is not `failed` offers no retry — the server decides, not the page. */
  async expectNoRetryOffered(id: string) {
    await expect(this.page.getByTestId(`notifications-retry-${id}`)).toHaveCount(0)
  }

  async expectNoticeVisible() {
    await expect(this.page.getByTestId('notifications-notice')).toBeVisible()
  }

  async expectEmptyStateVisible() {
    await expect(this.page.getByTestId('notifications-empty')).toBeVisible()
  }
}
