/**
 * Profile Page Object
 *
 * Encapsulates all interactions with the profile/settings page.
 * Implements E2E Testing Pattern 005: Using Test IDs (data-testid)
 * Implements E2E Testing Pattern 006: Page Object Model
 * Implements E2E Testing Pattern 008: Playwright Assertions
 */

import { Page, expect } from '@playwright/test'
import { BasePage } from './BasePage'

export class ProfilePage extends BasePage {
  // Locators (PRIVATE)
  private readonly pageContainer = () => this.page.locator('[data-testid="profile-page"]')
  private readonly profileSection = () => this.page.locator('[data-testid="profile-section"]')
  private readonly passwordSection = () => this.page.locator('[data-testid="password-section"]')
  private readonly emailInput = () => this.page.locator('[data-testid="profile-email"]')
  private readonly displayNameInput = () => this.page.locator('[data-testid="profile-display-name"]')
  private readonly languageTrigger = () => this.page.locator('[data-testid="profile-locale-trigger"]')
  private readonly languageDropdown = () => this.page.locator('[data-testid="profile-locale-dropdown"]')
  private readonly languageOptionDe = () => this.page.locator('[data-testid="profile-locale-option-de"]')
  private readonly languageOptionEn = () => this.page.locator('[data-testid="profile-locale-option-en"]')
  private readonly saveButton = () => this.page.locator('[data-testid="profile-save-button"]')
  private readonly successMessage = () => this.page.locator('[data-testid="profile-success"]')
  private readonly newPasswordInput = () => this.page.locator('[data-testid="password-new"]')
  private readonly confirmPasswordInput = () => this.page.locator('[data-testid="password-confirm"]')
  private readonly changePasswordButton = () => this.page.locator('[data-testid="password-change-button"]')
  private readonly passwordError = () => this.page.locator('[data-testid="password-error"]')
  private readonly headerUserBadge = () => this.page.locator('[data-testid="header-user-badge"]')

  constructor(page: Page) {
    super(page)
  }

  /**
   * Navigate to profile page and wait for it to load
   */
  async navigate() {
    await super.navigate('/profile')
    await this.languageTrigger().waitFor({ state: 'visible', timeout: 10000 })
  }

  /**
   * VISIBILITY EXPECTATIONS
   */

  async expectPageVisible() {
    await expect(this.pageContainer()).toBeVisible()
  }

  async expectSectionsVisible() {
    await expect(this.profileSection()).toBeVisible()
    await expect(this.passwordSection()).toBeVisible()
  }

  async expectSuccessVisible() {
    await expect(this.successMessage()).toBeVisible()
  }

  async expectPasswordError(text?: string) {
    await expect(this.passwordError()).toBeVisible()
    if (text) {
      await expect(this.passwordError()).toContainText(text)
    }
  }

  async expectUserBadgeVisible() {
    await expect(this.headerUserBadge()).toBeVisible()
  }

  /**
   * PROFILE FORM INTERACTIONS
   */

  async getEmailValue(): Promise<string> {
    return await this.emailInput().inputValue()
  }

  async getDisplayNameValue(): Promise<string> {
    return await this.displayNameInput().inputValue()
  }

  async setDisplayName(name: string) {
    await this.displayNameInput().clear()
    await this.displayNameInput().fill(name)
  }

  async saveProfile() {
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/auth/profile') && resp.request().method() === 'PATCH',
      { timeout: 10000 }
    )
    await this.saveButton().click()
    await responsePromise
  }

  /**
   * LANGUAGE INTERACTIONS
   *
   * Opens the dropdown, selects the locale, and saves (PATCH fires after save button).
   */
  async changeLanguage(locale: 'de' | 'en') {
    await this.languageTrigger().click()
    await this.languageDropdown().waitFor({ state: 'visible', timeout: 5000 })

    if (locale === 'de') {
      await this.languageOptionDe().click()
    } else {
      await this.languageOptionEn().click()
    }

    await this.saveProfile()
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

  async expectLanguageSelected(locale: 'de' | 'en') {
    const text = await this.languageTrigger().textContent()
    if (locale === 'de') {
      expect(text).toContain('Deutsch')
    } else {
      expect(text).toContain('English')
    }
  }

  /**
   * PASSWORD FORM INTERACTIONS
   */

  async fillNewPassword(password: string) {
    await this.newPasswordInput().fill(password)
  }

  async fillConfirmPassword(password: string) {
    await this.confirmPasswordInput().fill(password)
  }

  async clickChangePassword() {
    await this.changePasswordButton().click()
  }

  /**
   * NAVIGATION
   */

  async navigateViaUserBadge() {
    await this.headerUserBadge().click()
    await expect(this.page).toHaveURL(/\/profile/)
  }
}
