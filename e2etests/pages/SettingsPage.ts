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

    // Wait for either admin users table OR sepa form to be visible (depends on which tab is active)
    // Default tab is admin-users, so wait for either content
    await Promise.race([
      this.page.getByTestId('settings-admin-users-table').waitFor({ state: 'visible', timeout: 10000 }),
      this.sepaForm.waitFor({ state: 'visible', timeout: 10000 }),
    ])
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

  /**
   * Click SEPA tab to switch to that tab
   */
  async clickSepaTab() {
    await this.sepaTab.click()
    // Wait for SEPA form to appear
    await this.sepaForm.waitFor({ state: 'visible' })
    // Wait a moment for form data to load from API
    await this.page.waitForTimeout(300)
  }

  // ==================== Admin Users Tab ====================

  /**
   * Click admin users tab to switch to that tab
   */
  async clickAdminUsersTab() {
    const adminUsersTab = this.page.getByTestId('settings-tab-admin-users')
    await adminUsersTab.click()
    // Wait for admin users content to appear
    await this.page.getByTestId('settings-admin-users-table').waitFor({ state: 'visible' })
  }

  /**
   * Expect admin users tab to be visible
   */
  async expectAdminUsersTabVisible() {
    await expect(this.page.getByTestId('settings-tab-admin-users')).toBeVisible()
  }

  /**
   * Click create admin button
   */
  async clickCreateAdminButton() {
    await this.page.getByTestId('settings-admin-create-button').click()
    // Wait for modal to appear
    await this.page.getByTestId('settings-admin-create-modal').waitFor({ state: 'visible' })
  }

  /**
   * Fill create admin form
   */
  async fillCreateAdminForm(data: {
    email: string
    display_name: string
    locale?: string
  }) {
    if (data.email) {
      await this.page.getByTestId('settings-admin-create-email').fill(data.email)
    }
    if (data.display_name) {
      await this.page.getByTestId('settings-admin-create-display-name').fill(data.display_name)
    }
    if (data.locale) {
      // LanguageSelector is a custom dropdown, not a native select
      await this.page.getByTestId('settings-admin-create-locale-trigger').click()
      await this.page.getByTestId(`settings-admin-create-locale-option-${data.locale}`).click()
    }
  }

  /**
   * Click create admin confirm button
   */
  async clickCreateAdminConfirm() {
    await this.page.getByTestId('settings-admin-create-confirm-button').click()
  }

  /**
   * Check if create admin modal is visible
   */
  async isCreateAdminModalVisible(): Promise<boolean> {
    return await this.page.getByTestId('settings-admin-create-modal').isVisible().catch(() => false)
  }

  /**
   * Close create admin modal by clicking cancel
   */
  async closeCreateAdminModal() {
    await this.page.getByTestId('settings-admin-create-cancel-button').click()
  }

  /**
   * Get generated password from password display modal
   */
  async getGeneratedPassword(): Promise<string | null> {
    const modal = this.page.getByTestId('settings-admin-password-modal')
    const isVisible = await modal.isVisible().catch(() => false)
    if (!isVisible) {
      return null
    }
    const passwordText = await this.page.getByTestId('settings-admin-password-display').textContent()
    return passwordText?.trim() || null
  }

  /**
   * Copy password to clipboard from password display modal
   */
  async copyPasswordToClipboard() {
    await this.page.getByTestId('settings-admin-password-copy-button').click()
  }

  /**
   * Close password display modal by clicking the dedicated close button
   */
  async closePasswordModal() {
    const closeButton = this.page.getByTestId('settings-admin-password-close-button')
    await closeButton.click()
  }

  /**
   * Get admin user count in table by counting rows
   */
  async getAdminUserCount(): Promise<number> {
    const rows = this.page.locator('[data-testid^="settings-admin-user-row-"]')
    return await rows.count()
  }

  /**
   * Find admin user row by email text content
   */
  private async findAdminUserRowByEmail(email: string) {
    // Use Playwright's filter() for efficient row lookup without iterating all rows
    const row = this.page
      .locator('[data-testid^="settings-admin-user-row-"]')
      .filter({ has: this.page.locator(`[data-testid^="settings-admin-user-email-"]:text-is("${email}")`) })
    const count = await row.count()
    if (count === 0) {
      return null
    }
    return row.first()
  }

  /**
   * Get admin user ID by email (needed for button test IDs)
   */
  private async getAdminUserIdByEmail(email: string): Promise<string | null> {
    const row = await this.findAdminUserRowByEmail(email)
    if (!row) {
      return null
    }
    const rowTestId = await row.getAttribute('data-testid')
    if (!rowTestId) {
      return null
    }
    // Extract ID from data-testid like "settings-admin-user-row-<id>"
    return rowTestId.replace('settings-admin-user-row-', '')
  }

  /**
   * Get admin user by email
   */
  async getAdminUserByEmail(email: string) {
    const row = await this.findAdminUserRowByEmail(email)
    if (!row) {
      return null
    }

    const emailText = await row.locator('[data-testid^="settings-admin-user-email-"]').textContent()
    const nameText = await row.locator('[data-testid^="settings-admin-user-name-"]').textContent()
    // Status is determined by the toggle button's aria-pressed attribute
    const toggleBtn = row.locator('[data-testid^="settings-admin-user-toggle-"]')
    const isPressed = await toggleBtn.getAttribute('aria-pressed')
    const status = isPressed === 'true' ? 'active' : 'inactive'

    return {
      email: emailText?.trim() || '',
      displayName: nameText?.trim() || '',
      status,
    }
  }

  /**
   * Click edit button for admin user by email
   */
  async clickEditAdminButton(email: string) {
    const adminId = await this.getAdminUserIdByEmail(email)
    if (!adminId) {
      throw new Error(`Admin user with email ${email} not found`)
    }

    await this.page.getByTestId(`settings-admin-edit-button-${adminId}`).click()
    // Wait for edit modal to appear
    await this.page.getByTestId('settings-admin-edit-modal').waitFor({ state: 'visible' })
  }

  /**
   * Fill edit admin form
   */
  async fillEditAdminForm(data: {
    email?: string
    display_name?: string
    locale?: string
  }) {
    if (data.email) {
      const emailInput = this.page.getByTestId('settings-admin-edit-email')
      await emailInput.fill(data.email)
    }
    if (data.display_name) {
      const nameInput = this.page.getByTestId('settings-admin-edit-display-name')
      await nameInput.fill(data.display_name)
    }
    if (data.locale) {
      // LanguageSelector is a custom dropdown, not a native select
      await this.page.getByTestId('settings-admin-edit-locale-trigger').click()
      await this.page.getByTestId(`settings-admin-edit-locale-option-${data.locale}`).click()
    }
  }

  /**
   * Click edit admin confirm button and wait for admin list to reload
   */
  async clickEditAdminConfirm() {
    // Set up response watcher BEFORE click to avoid race condition
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/admin-users') && resp.request().method() === 'GET',
      { timeout: 10000 }
    )
    await this.page.getByTestId('settings-admin-edit-confirm-button').click()
    await responsePromise
  }

  /**
   * Check if edit admin modal is visible
   */
  async isEditAdminModalVisible(): Promise<boolean> {
    return await this.page.getByTestId('settings-admin-edit-modal').isVisible().catch(() => false)
  }

  /**
   * Close edit admin modal by clicking cancel
   */
  async closeEditAdminModal() {
    await this.page.getByTestId('settings-admin-edit-cancel-button').click()
  }

  /**
   * Click reset password button for admin user by email
   */
  async clickResetPasswordButton(email: string) {
    const adminId = await this.getAdminUserIdByEmail(email)
    if (!adminId) {
      throw new Error(`Admin user with email ${email} not found`)
    }

    await this.page.getByTestId(`settings-admin-reset-password-button-${adminId}`).click()
    // Wait for password modal to appear
    await this.page.getByTestId('settings-admin-password-modal').waitFor({ state: 'visible' })
  }

  /**
   * Click toggle to deactivate admin user by email and confirm via ConfirmDialog modal.
   * The Toggle click triggers ConfirmDialog. Waits for the admin users list to reload after deactivation.
   */
  async clickDeactivateButton(email: string) {
    const adminId = await this.getAdminUserIdByEmail(email)
    if (!adminId) {
      throw new Error(`Admin user with email ${email} not found`)
    }

    // Click the toggle (currently ON → triggers deactivate confirm dialog)
    await this.page.getByTestId(`settings-admin-user-toggle-${adminId}`).scrollIntoViewIfNeeded()
    await this.page.getByTestId(`settings-admin-user-toggle-${adminId}`).click()
    // Confirm via the shared ConfirmDialog modal
    await expect(this.page.getByTestId('confirm-dialog')).toBeVisible({ timeout: 10000 })
    // Set up response watcher before clicking OK (to capture the reload GET)
    const responsePromise = this.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/admin-users') &&
        resp.request().method() === 'GET',
      { timeout: 15000 }
    )
    await this.page.getByTestId('confirm-dialog-ok').click()
    // Wait for admin list to reload (triggered by loadAdminUsers() after deactivation)
    await responsePromise
  }

  /**
   * Click toggle to reactivate admin user by email.
   * Waits for the admin users list to reload after reactivation.
   */
  async clickReactivateButton(email: string) {
    const adminId = await this.getAdminUserIdByEmail(email)
    if (!adminId) {
      throw new Error(`Admin user with email ${email} not found`)
    }

    // Set up response watcher before clicking toggle (to capture the reload GET)
    const responsePromise = this.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/admin-users') &&
        resp.request().method() === 'GET',
      { timeout: 15000 }
    )
    // Click the toggle (currently OFF → triggers reactivate directly, no confirm dialog)
    await this.page.getByTestId(`settings-admin-user-toggle-${adminId}`).scrollIntoViewIfNeeded()
    await this.page.getByTestId(`settings-admin-user-toggle-${adminId}`).click()
    // Wait for admin list to reload (triggered by loadAdminUsers() after reactivation)
    await responsePromise
  }

  /**
   * Get admin user status by email
   */
  async getAdminUserStatus(email: string): Promise<string | null> {
    const row = await this.findAdminUserRowByEmail(email)
    if (!row) {
      return null
    }

    // Status is determined by the toggle button's aria-pressed attribute
    const toggleBtn = row.locator('[data-testid^="settings-admin-user-toggle-"]')
    const isPressed = await toggleBtn.getAttribute('aria-pressed')
    return isPressed === 'true' ? 'active' : 'inactive'
  }

  /**
   * Check if table is empty
   */
  async isAdminUsersTableEmpty(): Promise<boolean> {
    const count = await this.getAdminUserCount()
    return count === 0
  }

  /**
   * Expect admin users table to be visible
   */
  async expectAdminUsersTableVisible() {
    await expect(this.page.getByTestId('settings-admin-users-table')).toBeVisible()
  }

  /**
   * Expect create button to be visible
   */
  async expectCreateAdminButtonVisible() {
    await expect(this.page.getByTestId('settings-admin-create-button')).toBeVisible()
  }

  /**
   * Wait for password modal to appear after admin user creation
   * Implements Pattern 008: Use Playwright auto-waiting with expect()
   */
  async waitForPasswordModal() {
    const modal = this.page.getByTestId('settings-admin-password-modal')
    await expect(modal).toBeVisible({ timeout: 5000 })
  }
}
