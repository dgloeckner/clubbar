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
  private readonly currentPasswordInput = () => this.page.locator('[data-testid="password-current"]')
  private readonly newPasswordInput = () => this.page.locator('[data-testid="password-new"]')
  private readonly confirmPasswordInput = () => this.page.locator('[data-testid="password-confirm"]')
  private readonly totpCodeInput = () => this.page.locator('[data-testid="password-totp-code"]')
  private readonly changePasswordButton = () => this.page.locator('[data-testid="password-change-button"]')
  private readonly passwordError = () => this.page.locator('[data-testid="password-error"]')
  private readonly passwordSuccess = () => this.page.locator('[data-testid="password-success"]')
  private readonly headerUserBadge = () => this.page.locator('[data-testid="header-user-badge"]')
  // The email change routes through the shared StepUpConfirmDialog, so its
  // test IDs are the ConfirmDialog's plus the credential fields (#337).
  private readonly stepUpPassword = () => this.page.locator('[data-testid="step-up-password"]')
  private readonly stepUpTotpCode = () => this.page.locator('[data-testid="step-up-totp-code"]')
  private readonly stepUpError = () => this.page.locator('[data-testid="step-up-error"]')
  private readonly stepUpConfirm = () => this.page.locator('[data-testid="confirm-dialog-ok"]')

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

  async fillCurrentPassword(password: string) {
    await this.currentPasswordInput().fill(password)
  }

  async fillNewPassword(password: string) {
    await this.newPasswordInput().fill(password)
  }

  async fillConfirmPassword(password: string) {
    await this.confirmPasswordInput().fill(password)
  }

  /** Only rendered when the signed-in admin has 2FA enrolled. */
  async fillTotpCode(code: string) {
    await this.totpCodeInput().fill(code)
  }

  async clickChangePassword() {
    await this.changePasswordButton().click()
  }

  async expectPasswordSuccess() {
    await expect(this.passwordSuccess()).toBeVisible()
  }

  async expectTotpCodeFieldVisible() {
    await expect(this.totpCodeInput()).toBeVisible()
  }

  /**
   * Change the password and wait for the request itself, so the assertion that
   * follows is about the server's answer rather than about client-side
   * validation that returned before any call.
   *
   * Pass `code` for a 2FA-enrolled admin instead of calling `fillTotpCode`
   * separately: the sixth digit submits the form on its own, so the waiter has
   * to be installed *before* the code is typed or it would miss the very
   * response it is waiting for — and no click follows, because a successful
   * change empties the form and clicking an empty one only replaces the
   * success message with "required field". (That the guard swallows a click
   * after auto-submit is asserted on the login form, where the form survives.)
   */
  async submitPasswordChange(code?: string): Promise<number> {
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/auth/change-password') && resp.request().method() === 'PATCH',
      { timeout: 10000 }
    )
    if (code !== undefined) {
      await this.fillTotpCode(code)
    } else {
      await this.changePasswordButton().click()
    }
    return (await responsePromise).status()
  }

  /**
   * EMAIL STEP-UP DIALOG
   */

  async setEmail(email: string) {
    await this.emailInput().clear()
    await this.emailInput().fill(email)
  }

  async expectStepUpDialogVisible() {
    await expect(this.stepUpPassword()).toBeVisible()
  }

  async expectStepUpDialogHidden() {
    await expect(this.stepUpPassword()).toBeHidden()
  }

  async expectStepUpError() {
    await expect(this.stepUpError()).toBeVisible()
  }

  /**
   * Press Save when the email has been changed. No PATCH is expected — the
   * dialog opens instead, which is the behaviour under test, so this waits for
   * the dialog rather than for a request that must not happen.
   */
  async saveProfileExpectingStepUp() {
    await this.saveButton().click()
    await expect(this.stepUpPassword()).toBeVisible()
  }

  /**
   * Fills the dialog and confirms, returning the PATCH status.
   *
   * The waiter goes up before the fields, not after: with the password already
   * entered, the code's sixth digit confirms the dialog by itself, so a waiter
   * installed after the fill would miss the response. The click stays as the
   * assertion that the de-duplication guard swallows it.
   */
  async confirmEmailStepUp(password: string, totpCode?: string): Promise<number> {
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/auth/profile') && resp.request().method() === 'PATCH',
      { timeout: 10000 }
    )
    // Code first, so the dialog waits for the click below rather than
    // confirming itself mid-fill. `confirmEmailStepUpByTyping` is the method
    // that exercises the other order on purpose.
    if (totpCode) {
      await this.stepUpTotpCode().fill(totpCode)
    }
    await this.stepUpPassword().fill(password)
    await this.stepUpConfirm().click()
    return (await responsePromise).status()
  }

  /**
   * Confirm the dialog by typing alone: password first, then the code, and no
   * click at all — the sixth digit is what submits.
   */
  async confirmEmailStepUpByTyping(password: string, totpCode: string): Promise<number> {
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/auth/profile') && resp.request().method() === 'PATCH',
      { timeout: 10000 }
    )
    await this.stepUpPassword().fill(password)
    // Cleared first so the code is always a *change*: re-filling a field with
    // the value it already holds sets no state and fires nothing.
    await this.stepUpTotpCode().clear()
    await this.stepUpTotpCode().fill(totpCode)
    return (await responsePromise).status()
  }

  /** Type a step-up code without touching the password field. */
  async fillStepUpTotpCode(code: string) {
    await this.stepUpTotpCode().fill(code)
  }

  /**
   * NAVIGATION
   */

  async navigateViaUserBadge() {
    await this.headerUserBadge().click()
    await expect(this.page).toHaveURL(/\/profile/)
  }
}
