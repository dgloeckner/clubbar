/**
 * Base Page Object
 *
 * Provides common functionality for all page objects.
 * Implements E2E Testing Pattern 005: Page Object Model
 */

import { Page, Locator } from '@playwright/test'

export abstract class BasePage {
  protected page: Page

  constructor(page: Page) {
    this.page = page
  }

  /**
   * Navigate to a URL and wait for page to load
   */
  async navigate(url: string) {
    await this.page.goto(url)
    await this.page.waitForLoadState('domcontentloaded')
  }

  /**
   * Check if a locator is visible without throwing
   */
  async isElementVisible(locator: Locator): Promise<boolean> {
    try {
      return await locator.isVisible({ timeout: 1000 })
    } catch {
      return false
    }
  }

  /**
   * Get text content from a locator
   */
  async getElementText(locator: Locator): Promise<string | null> {
    try {
      return await locator.textContent()
    } catch {
      return null
    }
  }

  /**
   * Wait for an element to be visible
   */
  async waitForElement(locator: Locator, timeout = 5000) {
    await locator.waitFor({ state: 'visible', timeout })
  }

  /**
   * Wait for element to be hidden
   */
  async waitForElementHidden(locator: Locator, timeout = 5000) {
    await locator.waitFor({ state: 'hidden', timeout })
  }

  /**
   * Get count of matching elements
   */
  async getElementCount(locator: Locator): Promise<number> {
    return await locator.count()
  }

  /**
   * Debounced wait (useful after typing/filtering)
   */
  async waitForDebounce(ms = 500) {
    await this.page.waitForTimeout(ms)
  }

  /**
   * Get current URL
   */
  getCurrentUrl(): string {
    return this.page.url()
  }

  /**
   * Get page title
   */
  async getPageTitle(): Promise<string> {
    return await this.page.title()
  }
}
