/**
 * Profile Page Object
 *
 * Encapsulates all interactions with the profile/settings page.
 * Implements E2E Testing Pattern 006: Page Object Model
 */

import { Page, expect } from '@playwright/test'
import { BasePage } from './BasePage'

export class ProfilePage extends BasePage {
  // Locators as private properties
  private readonly pageContainer = () => this.page.locator('[data-testid="profile-page"]')
  private readonly languageTrigger = () => this.page.locator('[data-testid="profile-locale-trigger"]')
  private readonly languageDropdown = () => this.page.locator('[data-testid="profile-locale-dropdown"]')
  private readonly languageOptionDe = () => this.page.locator('[data-testid="profile-locale-option-de"]')
  private readonly languageOptionEn = () => this.page.locator('[data-testid="profile-locale-option-en"]')
  private readonly saveButton = () => this.page.locator('[data-testid="profile-save-button"]')
  private readonly emailInput = () => this.page.locator('[data-testid="profile-email"]')
  private readonly displayNameInput = () => this.page.locator('[data-testid="profile-display-name"]')

  constructor(page: Page) {
    super(page)
  }

  /**
   * Navigate to profile page
   */
  async navigate() {
    await super.navigate('/profile')
    await this.waitForPage()
  }

  /**
   * Wait for the profile page to load
   */
  async waitForPage() {
    await this.languageTrigger().waitFor({ state: 'visible', timeout: 10000 })
  }

  /**
   * Change the language and wait for the API response
   * @param locale 'de' or 'en'
   */
  async changeLanguage(locale: 'de' | 'en') {
    // Open the dropdown
    await this.languageTrigger().click()
    await this.languageDropdown().waitFor({ state: 'visible', timeout: 5000 })

    // Set up response promise before clicking
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/auth/profile') && resp.request().method() === 'PATCH',
      { timeout: 10000 }
    )

    // Click the option
    if (locale === 'de') {
      await this.languageOptionDe().click()
    } else {
      await this.languageOptionEn().click()
    }

    // Wait for API response
    await responsePromise
  }

  /**
   * Get the current selected language from the trigger button text
   */
  async getCurrentLanguage(): Promise<'de' | 'en'> {
    const text = await this.languageTrigger().textContent()
    if (text?.includes('Deutsch')) return 'de'
    if (text?.includes('English')) return 'en'
    throw new Error(`Unknown language text: ${text}`)
  }

  /**
   * Verify the language dropdown shows the correct current selection
   */
  async expectLanguageSelected(locale: 'de' | 'en') {
    const text = await this.languageTrigger().textContent()
    if (locale === 'de') {
      expect(text).toContain('Deutsch')
    } else {
      expect(text).toContain('English')
    }
  }
}
