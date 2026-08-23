/**
 * Base Page Object
 *
 * Provides common functionality for all page objects.
 * Implements E2E Testing Pattern 006: Page Object Model
 * Implements E2E Testing Pattern 008: Playwright Assertions (no visibility helpers)
 *
 * Key principle: Only provide semantic actions and value getters.
 * All visibility/state assertions happen in tests using expect() API.
 */

import { Page } from '@playwright/test'

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
   * Debounced wait (useful after typing/filtering to allow API debounce)
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

  // *** PATTERN 008: NO VISIBILITY HELPERS ***
  // Do NOT add these methods - they violate Pattern 008:
  // - isElementVisible() → Use: await expect(locator).toBeVisible() in tests
  // - waitForElement() → Use: await expect(locator).toBeVisible() in tests
  // - waitForElementHidden() → Use: await expect(locator).toBeHidden() in tests
  // - getElementCount() → Use: await locator.count() in tests or page objects
  // - getElementText() → Use: await locator.textContent() in tests or page objects
  //
  // See: e2etests/patterns/pattern-008-playwright-assertions.md
  // Why: These helpers swallow errors and provide silent failures.
  // Solution: Use Playwright's built-in expect() API with auto-waiting and clear error messages.
}
