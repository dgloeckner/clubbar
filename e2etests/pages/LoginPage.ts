/**
 * Login Page Object
 *
 * Encapsulates all interactions with the login page.
 * Implements E2E Testing Pattern 005: Page Object Model
 */

import { Page } from '@playwright/test'
import { BasePage } from './BasePage'

export class LoginPage extends BasePage {
  // Locators as private properties - using data-testid for i18n compatibility
  private readonly emailInput = () => this.page.locator('[data-testid="login-email-input"]')
  private readonly passwordInput = () => this.page.locator('[data-testid="login-password-input"]')
  private readonly loginBtn = () => this.page.locator('[data-testid="login-submit-button"]')
  readonly errorMessage = () => this.page.getByTestId('login-error')
  private readonly heading = () => this.page.locator('h1:has-text("Club Bar")')

  // MFA step (requiresMfa branch)
  readonly mfaCodeInput = () => this.page.getByTestId('mfa-code-input')
  readonly mfaSubmitButton = () => this.page.getByTestId('mfa-submit-button')
  readonly mfaError = () => this.page.getByTestId('mfa-error')

  // TOTP enrollment step (requiresTotpSetup branch)
  readonly totpQrCode = () => this.page.getByTestId('totp-qr-code')
  readonly totpSetupSecret = () => this.page.getByTestId('totp-setup-secret')
  readonly totpSetupCodeInput = () => this.page.getByTestId('setup-code-input')
  readonly totpSetupConfirmButton = () => this.page.getByTestId('setup-confirm-button')
  readonly totpSetupError = () => this.page.getByTestId('totp-setup-error')

  constructor(page: Page) {
    super(page)
  }

  /**
   * Navigate to login page
   */
  async navigate() {
    await super.navigate('http://localhost:5173/login')
  }

  /**
   * Perform login with email and password
   * Waits for page navigation after login
   */
  async login(email: string, password: string, waitForLogin: boolean = false) {
    await this.emailInput().fill(email)
    await this.passwordInput().fill(password)
    await this.loginBtn().click()
    if (waitForLogin) {
      await this.page.waitForURL('**/dashboard', { timeout: 5000 })
      // Verify authentication succeeded
      const adminId = await this.page.evaluate(() => {
        return localStorage.getItem('admin_id')
      })

      if (!adminId) {
        throw new Error('Admin login failed - no admin_id in localStorage')
      }
    }
  }

  /**
   * Get error message text if displayed
   * Returns null if error not visible
   */
  async getErrorMessage(): Promise<string | null> {
    try {
      return await this.errorMessage().textContent()
    } catch {
      return null
    }
  }

  /**
   * Fill only email field
   */
  async fillEmail(email: string) {
    await this.emailInput().fill(email)
  }

  /**
   * Fill only password field
   */
  async fillPassword(password: string) {
    await this.passwordInput().fill(password)
  }

  /**
   * Click login button without filling form
   */
  async clickLogin() {
    await this.loginBtn().click()
  }

  /**
   * Fill and submit the 6-digit MFA code (requiresMfa branch).
   * Does not wait for navigation — callers assert the resulting state.
   */
  async submitMfaCode(code: string) {
    await this.mfaCodeInput().fill(code)
    await this.mfaSubmitButton().click()
  }

  /**
   * Read the plain-text TOTP secret shown as a manual-entry fallback
   * during first-time enrollment (requiresTotpSetup branch).
   */
  async getTotpSetupSecret(): Promise<string> {
    return (await this.totpSetupSecret().textContent()) ?? ''
  }

  /**
   * Fill and submit the 6-digit TOTP confirmation code (requiresTotpSetup branch).
   * Does not wait for navigation — callers assert the resulting state.
   */
  async submitTotpSetupCode(code: string) {
    await this.totpSetupCodeInput().fill(code)
    await this.totpSetupConfirmButton().click()
  }
}
