/**
 * Login Page Object
 *
 * Encapsulates all interactions with the login page.
 * Implements E2E Testing Pattern 005: Page Object Model
 */

import { expect, Page } from '@playwright/test'
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
  readonly totpSetupBackupKey = () => this.page.getByTestId('totp-setup-backup-key')
  readonly totpSetupSecret = () => this.page.getByTestId('totp-setup-secret')
  readonly totpSetupBackupKeyCopyButton = () =>
    this.page.getByTestId('totp-setup-backup-key-copy-button')
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
   * Assert the login screen shows this instance name (ADR-0034 / UC-A64).
   *
   * A retrying assertion rather than a one-shot read, and that is the whole
   * point of the method. `InstanceConfigContext` renders its
   * `DEFAULT_INSTANCE_NAME` fallback immediately and replaces it when the
   * public config request resolves, so the heading is **visible with the wrong
   * text** for as long as that request takes. Waiting for visibility and then
   * sampling `textContent()` once is a race that loses under load, and it loses
   * by reporting the default — which reads as "the save did not persist"
   * rather than as "the fetch had not landed yet" (Pattern 008).
   */
  async expectBrandName(expected: string) {
    await expect(this.heading()).toHaveText(expected)
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
   * Enter the 6-digit MFA code (requiresMfa branch).
   * Does not wait for navigation — callers assert the resulting state.
   *
   * There is no click, and there must not be one: the sixth digit submits the
   * form, so on a correct code the page has already navigated and the button
   * is gone by the time a click could land. `submitMfaCodeAndClick` covers the
   * case that survives — a rejected code, where the form is still there and
   * the click must be swallowed rather than spending a second attempt.
   */
  async submitMfaCode(code: string) {
    await this.mfaCodeInput().fill(code)
  }

  /**
   * Enter the code and then also press the button, the way an admin who has
   * not noticed the form already submitted would. `useOtpAutoSubmit` must
   * swallow the click: a second POST would replay a code the backend has
   * already consumed (#338), costing one of five attempts for nothing.
   *
   * Only safe for a code you expect to be refused — see `submitMfaCode`.
   */
  async submitMfaCodeAndClick(code: string) {
    await this.mfaCodeInput().fill(code)
    await this.mfaSubmitButton().click()
  }

  /** Count of MFA submissions the browser actually sent, from `startCountingMfaRequests`. */
  private mfaRequestCount = 0

  /**
   * Start counting POSTs to the MFA endpoint, so a test can assert how many
   * submissions a sequence of interactions really produced.
   */
  async startCountingMfaRequests() {
    this.mfaRequestCount = 0
    this.page.on('request', (req) => {
      if (req.url().includes('/api/auth/mfa') && req.method() === 'POST') this.mfaRequestCount++
    })
  }

  countedMfaRequests(): number {
    return this.mfaRequestCount
  }

  /**
   * Paste the code from the real clipboard, the way an admin copying it out of
   * an authenticator app does — a genuine Ctrl+V, not a programmatic fill.
   *
   * `text` may carry the spacing an app renders the code with ("123 456"): the
   * field strips everything that is not a digit and keeps the first six, so a
   * paste completes the code in one change and submits it. Requires the
   * clipboard permissions granted by the caller (Chromium only).
   */
  async pasteMfaCode(text: string) {
    await this.page.evaluate((value) => navigator.clipboard.writeText(value), text)
    await this.mfaCodeInput().focus()
    // Select first, as pasting over a value does: the field keeps the first six
    // digits it is given, so pasting *into* a full field would re-submit the
    // code already there rather than the new one.
    await this.page.keyboard.press('ControlOrMeta+A')
    await this.page.keyboard.press('ControlOrMeta+V')
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
   * `maxAttempts * 60s` (e.g. `test.setTimeout(360_000)`) to comfortably fit
   * the worst case.
   *
   * `submit` chooses how the code reaches the form — by default typing it,
   * which is enough to submit it; pass `pasteMfaCode` for the clipboard path.
   */
  async submitMfaCodeWithRetry(
    secret: string,
    maxAttempts = 6,
    submit: (code: string) => Promise<void> = (code) => this.submitMfaCode(code)
  ) {
    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
      await submit(generateTotp(secret))

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
   * Enter the 6-digit TOTP confirmation code (requiresTotpSetup branch).
   * Does not wait for navigation — callers assert the resulting state.
   *
   * No click, for the same reason as `submitMfaCode`: the sixth digit confirms
   * enrollment, and on success this screen is gone before a click could land.
   */
  async submitTotpSetupCode(code: string) {
    await this.totpSetupCodeInput().fill(code)
  }
}
