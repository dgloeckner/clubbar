import { Page, Locator, expect } from '@playwright/test'

/**
 * Settings Page Object Model
 * Encapsulates interactions with the Settings page
 * Pattern 006: Page Object Model - private locators, public methods
 */
export class SettingsPage {
  readonly page: Page

  // Private locators (Pattern 006)
  private readonly pageContainer: Locator
  private readonly sepaTab: Locator
  private readonly sepaForm: Locator
  private readonly creditorIdInput: Locator
  private readonly creditorNameInput: Locator
  private readonly creditorIbanInput: Locator
  private readonly streetInput: Locator
  private readonly cityInput: Locator
  private readonly countryInput: Locator
  private readonly saveButton: Locator
  private readonly cancelButton: Locator
  private readonly errorMessage: Locator
  private readonly successMessage: Locator
  private readonly loadingIndicator: Locator

  constructor(page: Page) {
    this.page = page

    // Initialize locators using test IDs (Pattern 005)
    this.pageContainer = page.getByTestId('settings-page')
    this.sepaTab = page.getByTestId('settings-tab-sepa')
    this.sepaForm = page.getByTestId('settings-sepa-form')
    this.creditorIdInput = page.getByTestId('settings-sepa-input-creditor_id')
    this.creditorNameInput = page.getByTestId('settings-sepa-input-creditor_name')
    this.creditorIbanInput = page.getByTestId('settings-sepa-input-creditor_iban')
    this.streetInput = page.getByTestId('settings-sepa-input-creditor_address_street')
    this.cityInput = page.getByTestId('settings-sepa-input-creditor_address_city')
    this.countryInput = page.getByTestId('settings-sepa-input-creditor_address_country')
    this.saveButton = page.getByTestId('settings-sepa-save-button')
    this.cancelButton = page.getByTestId('settings-sepa-cancel-button')
    this.errorMessage = page.getByTestId('settings-sepa-error-message')
    this.successMessage = page.getByTestId('settings-sepa-success-message')
    this.loadingIndicator = page.getByTestId('settings-page-loading')
  }

  /**
   * Navigate to settings page
   */
  async goto() {
    await this.page.goto('/settings')
    await this.pageContainer.waitFor({ state: 'visible' })
  }

  /**
   * Wait for page to load (loading indicator disappears or content appears)
   */
  async waitForLoad() {
    try {
      // Try to wait for loading indicator to disappear
      await this.page.getByTestId('settings-page-loading').waitFor({ state: 'hidden', timeout: 5000 })
    } catch {
      // Loading indicator may not appear, that's ok
    }

    // Wait for form to be visible
    await this.sepaForm.waitFor({ state: 'visible' })
  }

  /**
   * Get current creditor ID value
   */
  async getCreditorIdValue(): Promise<string> {
    return await this.creditorIdInput.inputValue()
  }

  /**
   * Get current creditor name value
   */
  async getCreditorNameValue(): Promise<string> {
    return await this.creditorNameInput.inputValue()
  }

  /**
   * Get current IBAN value
   */
  async getIbanValue(): Promise<string> {
    return await this.creditorIbanInput.inputValue()
  }

  /**
   * Get current street value
   */
  async getStreetValue(): Promise<string> {
    return await this.streetInput.inputValue()
  }

  /**
   * Get current city value
   */
  async getCityValue(): Promise<string> {
    return await this.cityInput.inputValue()
  }

  /**
   * Get current country value
   */
  async getCountryValue(): Promise<string> {
    return await this.countryInput.inputValue()
  }

  /**
   * Fill SEPA configuration form with provided data
   */
  async fillSepaConfig(data: {
    creditor_id?: string
    creditor_name?: string
    creditor_iban?: string
    creditor_address_street?: string
    creditor_address_city?: string
    creditor_address_country?: string
  }) {
    if (data.creditor_id !== undefined) {
      // Only fill if not disabled (immutability check)
      const disabled = await this.creditorIdInput.isDisabled()
      if (!disabled) {
        await this.creditorIdInput.fill(data.creditor_id)
      }
    }

    if (data.creditor_name !== undefined) {
      await this.creditorNameInput.fill(data.creditor_name)
    }

    if (data.creditor_iban !== undefined) {
      await this.creditorIbanInput.fill(data.creditor_iban)
    }

    if (data.creditor_address_street !== undefined) {
      await this.streetInput.fill(data.creditor_address_street)
    }

    if (data.creditor_address_city !== undefined) {
      await this.cityInput.fill(data.creditor_address_city)
    }

    if (data.creditor_address_country !== undefined) {
      await this.countryInput.fill(data.creditor_address_country)
    }
  }

  /**
   * Clear all form fields
   */
  async clearForm() {
    await this.creditorIdInput.clear()
    await this.creditorNameInput.clear()
    await this.creditorIbanInput.clear()
    await this.streetInput.clear()
    await this.cityInput.clear()
    await this.countryInput.clear()
  }

  /**
   * Click save button
   */
  async save() {
    await this.saveButton.click()
  }

  /**
   * Click cancel button
   */
  async cancel() {
    await this.cancelButton.click()
  }

  /**
   * Check if creditor ID field is disabled (immutability)
   */
  async isCreditorIdDisabled(): Promise<boolean> {
    return await this.creditorIdInput.isDisabled()
  }

  /**
   * Get error message text (returns null if not visible)
   */
  async getErrorMessage(): Promise<string | null> {
    const isVisible = await this.errorMessage.isVisible().catch(() => false)
    if (!isVisible) {
      return null
    }
    return await this.errorMessage.textContent()
  }

  /**
   * Get success message text (returns null if not visible)
   */
  async getSuccessMessage(): Promise<string | null> {
    const isVisible = await this.successMessage.isVisible().catch(() => false)
    if (!isVisible) {
      return null
    }
    return await this.successMessage.textContent()
  }

  /**
   * Expect error message to be visible
   */
  async expectErrorMessage() {
    // Pattern 008: Use Playwright assertions instead of try-catch
    await expect(this.errorMessage).toBeVisible()
  }

  /**
   * Expect error message to be hidden
   */
  async expectNoErrorMessage() {
    // Pattern 008: Use Playwright assertions
    await expect(this.errorMessage).not.toBeVisible()
  }

  /**
   * Expect success message to be visible
   */
  async expectSuccessMessage() {
    // Pattern 008: Use Playwright assertions
    await expect(this.successMessage).toBeVisible()
  }

  /**
   * Expect form to be visible
   */
  async expectFormVisible() {
    // Pattern 008: Use Playwright assertions
    await expect(this.sepaForm).toBeVisible()
  }

  /**
   * Expect page to be visible
   */
  async expectPageVisible() {
    // Pattern 008: Use Playwright assertions
    await expect(this.pageContainer).toBeVisible()
  }

  /**
   * Expect save button to be enabled
   */
  async expectSaveButtonEnabled() {
    // Pattern 008: Use Playwright assertions
    await expect(this.saveButton).toBeEnabled()
  }

  /**
   * Expect save button to be disabled
   */
  async expectSaveButtonDisabled() {
    // Pattern 008: Use Playwright assertions
    await expect(this.saveButton).toBeDisabled()
  }

  /**
   * Expect SEPA tab to be visible
   */
  async expectSepaTabVisible() {
    // Pattern 008: Use Playwright assertions
    await expect(this.sepaTab).toBeVisible()
  }
}
