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
  private readonly errorMessage = () => this.page.locator('[class*="error"], [style*="error"]')
  private readonly heading = () => this.page.locator('h1:has-text("Club Bar")')

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
      await this.page.waitForURL('**/members', { timeout: 5000 })
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
}
