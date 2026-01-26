/**
 * Login Page Object
 *
 * Encapsulates all interactions with the login page.
 * Implements E2E Testing Pattern 005: Page Object Model
 */

import { Page } from '@playwright/test'
import { BasePage } from './BasePage'

export class LoginPage extends BasePage {
  // Locators as private properties
  private readonly emailInput = () => this.page.getByRole('textbox', { name: /email/i })
  private readonly passwordInput = () => this.page.getByRole('textbox', { name: /password/i })
  private readonly loginBtn = () => this.page.getByRole('button', { name: /login/i })
  private readonly errorMessage = () => this.page.locator('[class*="error"], [style*="error"]')
  private readonly heading = () => this.page.locator('h1:has-text("Ruderbar")')

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
  async login(email: string, password: string) {
    await this.emailInput().fill(email)
    await this.passwordInput().fill(password)
    await this.loginBtn().click()

    // Wait for navigation (client-side routing)
    await this.page.waitForTimeout(1500)
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
