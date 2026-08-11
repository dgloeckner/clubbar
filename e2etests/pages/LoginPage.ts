/**
 * Login Page Object
 *
 * Encapsulates all interactions with the login page.
 * Implements E2E Testing Pattern 005: Page Object Model
 */

import { Page } from '@playwright/test'
import { BasePage } from './BasePage'
import { generateTotp } from '../utils/totp'

export class LoginPage extends BasePage {
  // Locators as private properties - using data-testid for i18n compatibility
  private readonly emailInput = () => this.page.locator('[data-testid="login-email-input"]')
  private readonly passwordInput = () => this.page.locator('[data-testid="login-password-input"]')
  private readonly loginBtn = () => this.page.locator('[data-testid="login-submit-button"]')
  readonly errorMessage = () => this.page.getByTestId('login-error')
  // The instance name is configurable (ADR-0034 / UC-A64), so this can no
  // longer be selected by its former "Club Bar" text — use the stable test ID.
  private readonly heading = () => this.page.getByTestId('login-brand-name')

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
   * The configured instance name shown on the login screen (ADR-0034 / UC-A64).
   */
  async getBrandNameText(): Promise<string> {
    return ((await this.heading().textContent()) ?? '').trim()
  }

  /**
   * Wait for the instance name to finish loading (it fetches on mount, before
   * any session exists) and be visible.
   */
  async waitForBrandName() {
    await this.heading().waitFor({ state: 'visible' })
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
   * Generate a code from `secret` and submit it via the real UI form,
   * retrying with a freshly generated one if the server rejects it.
   *
   * Guards the shared seeded admin's secret against replay-collisions (#338):
   * two logins landing in the same 30-second window generate the identical
   * code, and the second submission is correctly refused as a replay even
   * though it is not an actual attack. Waits past the next time-step boundary
   * before retrying, plus random jitter so that several callers who collided
   * in the same window don't all retry into the next one together and
   * collide again. Callers must raise the test timeout well past
   * `maxAttempts * 60s` (e.g. `test.setTimeout(240_000)`) to comfortably fit
   * the worst case.
   */
  async submitMfaCodeWithRetry(secret: string, maxAttempts = 4) {
    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
      await this.submitMfaCode(generateTotp(secret))

      const outcome = await Promise.race([
        this.page.waitForURL('**/dashboard', { timeout: 5000 }).then(() => 'success' as const),
        this.mfaError().waitFor({ state: 'visible', timeout: 5000 }).then(() => 'rejected' as const),
      ]).catch(() => 'timeout' as const)

      if (outcome === 'success' || attempt === maxAttempts) {
        return
      }

      const msUntilNextStep = 30_000 - (Date.now() % 30_000)
      const jitter = Math.floor(Math.random() * 30_000)
      await this.page.waitForTimeout(msUntilNextStep + jitter + 250)
    }
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
