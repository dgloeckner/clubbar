import { Page, Locator, expect } from '@playwright/test'
import { TEST_CREDENTIALS } from '../config/test-credentials'
import { generateTotp } from '../utils/totp'

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
  private readonly paymentPrefixInput: Locator
  private readonly creditorIdWarning: Locator
  private readonly saveButton: Locator
  private readonly cancelButton: Locator
  private readonly errorMessage: Locator
  private readonly successMessage: Locator
  private readonly loadingIndicator: Locator
  private readonly ibanValidationIndicator: Locator

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
    this.paymentPrefixInput = page.getByTestId('settings-sepa-input-payment_reference_prefix')
    this.creditorIdWarning = page.getByTestId('settings-sepa-alert-warning')
    this.saveButton = page.getByTestId('settings-sepa-save-button')
    this.cancelButton = page.getByTestId('settings-sepa-cancel-button')
    // One banner for the whole page: every tab reports its failures there (#91)
    this.errorMessage = page.getByTestId('settings-error-message')
    this.successMessage = page.getByTestId('settings-sepa-success-message')
    this.loadingIndicator = page.getByTestId('settings-page-loading')
    this.ibanValidationIndicator = page.getByTestId('settings-sepa-validation-creditor_iban')
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
   * Get current payment reference prefix value
   */
  async getPaymentReferencePrefixValue(): Promise<string> {
    return await this.paymentPrefixInput.inputValue()
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
    payment_reference_prefix?: string
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

    if (data.payment_reference_prefix !== undefined) {
      await this.paymentPrefixInput.fill(data.payment_reference_prefix)
    }
  }

  /**
   * Clear all form fields
   */
  async clearForm() {
    // The creditor ID is disabled once set (immutable, ADR-0007)
    if (!(await this.creditorIdInput.isDisabled())) {
      await this.creditorIdInput.clear()
    }
    await this.creditorNameInput.clear()
    await this.creditorIbanInput.clear()
    await this.streetInput.clear()
    await this.cityInput.clear()
    await this.countryInput.clear()
    await this.paymentPrefixInput.clear()
  }

  /**
   * Click save button
   */
  async save() {
    await this.saveButton.click()
  }

  /**
   * Click save and wait for the write to reach the backend.
   * Returns the HTTP method and status of the save request, so a test can
   * assert the request the frontend actually made.
   */
  async saveAndWaitForResponse(): Promise<{ method: string; status: number }> {
    // Watcher set up before the click to avoid a race with a fast response
    const responsePromise = this.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/sepa-config') &&
        ['POST', 'PATCH'].includes(resp.request().method()),
      { timeout: 15000 },
    )
    await this.save()
    const response = await responsePromise
    return { method: response.request().method(), status: response.status() }
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
   * Expect the creditor ID to be locked: disabled input plus the warning
   * explaining that it cannot be changed after initial setup (ADR-0007)
   */
  async expectCreditorIdLocked() {
    // Pattern 008: Use Playwright assertions
    await expect(this.creditorIdInput).toBeDisabled()
    await expect(this.creditorIdWarning).toBeVisible()
  }

  /**
   * Get error message text (returns null if not visible)
   */
  async getErrorMessage(): Promise<string | null> {
    if (await this.errorMessage.count() === 0) {
      return null
    }
    return await this.errorMessage.textContent()
  }

  /**
   * Get success message text (returns null if not visible)
   */
  async getSuccessMessage(): Promise<string | null> {
    if (await this.successMessage.count() === 0) {
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

  async expectIbanValidIndicator() {
    await expect(this.ibanValidationIndicator).toBeVisible()
    await expect(this.ibanValidationIndicator).toContainText('✓')
  }

  async expectIbanInvalidIndicator() {
    await expect(this.ibanValidationIndicator).toBeVisible()
    await expect(this.ibanValidationIndicator).toContainText('✗')
  }

  /**
   * Click SEPA tab to switch to that tab
   */
  async clickSepaTab() {
    await this.sepaTab.click()
    // Wait for SEPA form to appear (SEPA config is loaded on page mount, not on tab switch)
    await this.sepaForm.waitFor({ state: 'visible' })
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
    return await this.page.getByTestId('settings-admin-create-modal').count() > 0
  }

  /**
   * Close create admin modal by clicking cancel
   */
  async closeCreateAdminModal() {
    await this.page.getByTestId('settings-admin-create-cancel-button').click()
  }

  /**
   * What the create admin modal currently holds — a cancelled entry must not
   * still be sitting there when it is opened again (#131)
   */
  async getCreateAdminFormValues(): Promise<{ email: string; display_name: string }> {
    return {
      email: await this.page.getByTestId('settings-admin-create-email').inputValue(),
      display_name: await this.page.getByTestId('settings-admin-create-display-name').inputValue(),
    }
  }

  /**
   * Click the create admin modal backdrop (outside the dialog).
   * A half-typed entry must survive this — see #131.
   */
  async clickCreateAdminModalBackdrop() {
    await this.page.getByTestId('settings-admin-create-modal').click({ position: { x: 5, y: 5 } })
  }

  /**
   * What the create terminal modal currently holds — see above (#131)
   */
  async getCreateTerminalFormValues(): Promise<{ name: string; device_id: string }> {
    return {
      name: await this.page.getByTestId('settings-terminal-create-name').inputValue(),
      device_id: await this.page.getByTestId('settings-terminal-create-device-id').inputValue(),
    }
  }

  /**
   * Expect the create admin modal to report a failure, and to stay open so the
   * typed values are still there to correct (#91)
   */
  async expectCreateAdminModalError(): Promise<string> {
    const modalError = this.page.getByTestId('settings-admin-create-error')
    await expect(modalError).toBeVisible()
    await expect(this.page.getByTestId('settings-admin-create-modal')).toBeVisible()
    return (await modalError.textContent()) ?? ''
  }

  /**
   * Expect the create admin modal to reject a field before anything is sent
   */
  async expectCreateAdminFieldError(field: 'email' | 'display-name') {
    await expect(this.page.getByTestId(`settings-admin-create-${field}-error`)).toBeVisible()
  }

  /**
   * Expect the create terminal modal to report a failure, and to stay open so
   * the typed values are still there to correct (#91)
   */
  async expectCreateTerminalModalError(): Promise<string> {
    const modalError = this.page.getByTestId('settings-terminal-create-error')
    await expect(modalError).toBeVisible()
    await expect(this.page.getByTestId('settings-terminal-create-modal')).toBeVisible()
    return (await modalError.textContent()) ?? ''
  }

  /**
   * Expect the create terminal modal to reject a field before anything is sent
   */
  async expectCreateTerminalFieldError(field: 'name' | 'device-id') {
    await expect(this.page.getByTestId(`settings-terminal-create-${field}-error`)).toBeVisible()
  }

  /**
   * Get generated password from password display modal
   */
  async getGeneratedPassword(): Promise<string | null> {
    const modal = this.page.getByTestId('settings-admin-password-modal')
    if (await modal.count() === 0) {
      return null
    }
    const passwordText = await this.page.getByTestId('settings-admin-password-display').textContent()
    return passwordText?.trim() || null
  }

  /**
   * Copy password to clipboard from password display modal.
   * The modal stays open — the copy has to be confirmed before the one-time
   * secret may be discarded.
   */
  async copyPasswordToClipboard() {
    await this.page.getByTestId('settings-admin-password-copy-button').click()
  }

  /**
   * Expect the password modal to confirm a successful clipboard write
   */
  async expectPasswordCopyConfirmed() {
    await expect(this.page.getByTestId('settings-admin-password-copy-status')).toBeVisible()
  }

  /**
   * Expect the password modal to report a failed clipboard write
   */
  async expectPasswordCopyFailed() {
    await expect(this.page.getByTestId('settings-admin-password-copy-error')).toBeVisible()
  }

  /**
   * Click the password modal backdrop (outside the dialog).
   * A one-time secret must survive this — see #126.
   */
  async clickPasswordModalBackdrop() {
    await this.page.getByTestId('settings-admin-password-modal').click({ position: { x: 5, y: 5 } })
  }

  /**
   * Expect the password modal to still be showing the secret
   */
  async expectPasswordModalVisible() {
    await expect(this.page.getByTestId('settings-admin-password-modal')).toBeVisible()
  }

  /**
   * Expect the password modal to be gone
   */
  async expectPasswordModalHidden() {
    await expect(this.page.getByTestId('settings-admin-password-modal')).toBeHidden()
  }

  /**
   * Close password display modal by acknowledging that the secret was saved
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
    // Wait for the admin users list to finish loading before searching.
    // After a GET response, adminUsersLoading may still be true and the rows are
    // not yet in the DOM. Waiting for ANY row to appear ensures the list has rendered.
    // If no rows exist at all (empty list), the wait times out silently and count() returns 0.
    await this.page
      .locator('[data-testid^="settings-admin-user-row-"]')
      .first()
      .waitFor({ state: 'attached', timeout: 5000 })
      .catch(() => {})

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
   * How many rows carry this email. Counting a known email instead of the whole
   * table (Pattern 003) keeps the assertion valid while other workers add rows,
   * and survives a list that is still reloading.
   */
  async countAdminUsersWithEmail(email: string): Promise<number> {
    return await this.page.locator(`[data-testid^="settings-admin-user-email-"]:text-is("${email}")`).count()
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
    return await this.page.getByTestId('settings-admin-edit-modal').count() > 0
  }

  /**
   * Close edit admin modal by clicking cancel
   */
  async closeEditAdminModal() {
    await this.page.getByTestId('settings-admin-edit-cancel-button').click()
  }

  /**
   * Fill the step-up credential (#337) shown by the reset-password and
   * reset-2FA confirmations: the *signed-in* admin's own password, plus a
   * fresh TOTP code when the `step-up-totp-code` field is present (it only
   * renders when the signed-in admin has 2FA enabled — true for the seeded
   * test admin used across these specs).
   */
  private async fillStepUpCredential() {
    await this.page.getByTestId('step-up-password').fill(TEST_CREDENTIALS.admin.password)

    const totpField = this.page.getByTestId('step-up-totp-code')
    if (await totpField.count() > 0) {
      await totpField.fill(generateTotp(TEST_CREDENTIALS.totp.adminSecret))
    }
  }

  /**
   * Click reset password button for admin user by email and confirm via
   * ConfirmDialog — the reset invalidates a colleague's current password, so
   * it asks first (#130). Requires the signed-in admin's own step-up
   * credential (#337).
   */
  async clickResetPasswordButton(email: string) {
    const adminId = await this.getAdminUserIdByEmail(email)
    if (!adminId) {
      throw new Error(`Admin user with email ${email} not found`)
    }

    await this.page.getByTestId(`settings-admin-reset-password-button-${adminId}`).click()
    await expect(this.page.getByTestId('confirm-dialog')).toBeVisible({ timeout: 5000 })
    await this.fillStepUpCredential()
    await this.page.getByTestId('confirm-dialog-ok').click()
    // Wait for password modal to appear
    await this.page.getByTestId('settings-admin-password-modal').waitFor({ state: 'visible' })
  }

  /**
   * Open the reset-password confirmation without answering it.
   */
  async openResetPasswordConfirm(email: string) {
    const adminId = await this.getAdminUserIdByEmail(email)
    if (!adminId) {
      throw new Error(`Admin user with email ${email} not found`)
    }

    await this.page.getByTestId(`settings-admin-reset-password-button-${adminId}`).click()
    await expect(this.page.getByTestId('confirm-dialog')).toBeVisible({ timeout: 5000 })
  }

  async cancelConfirmDialog() {
    await this.page.getByTestId('confirm-dialog-cancel').click()
    await expect(this.page.getByTestId('confirm-dialog')).toBeHidden()
  }

  /**
   * The page-level banner that confirms an action leaving no visible trace
   * (#130) — distinct from the SEPA tab's own success message.
   */
  async expectActionSuccessVisible() {
    await expect(this.page.getByTestId('settings-success-message')).toBeVisible()
  }

  async getActionSuccessMessage(): Promise<string> {
    return (await this.page.getByTestId('settings-success-message').textContent()) ?? ''
  }

  /**
   * Click reset 2FA button for admin user by email and confirm via
   * ConfirmDialog. Requires the signed-in admin's own step-up credential
   * (#337).
   */
  async clickReset2faButton(email: string) {
    const adminId = await this.getAdminUserIdByEmail(email)
    if (!adminId) {
      throw new Error(`Admin user with email ${email} not found`)
    }

    await this.page.getByTestId(`settings-admin-reset-2fa-button-${adminId}`).click()
    // Wait for confirm dialog
    await expect(this.page.getByTestId('confirm-dialog')).toBeVisible({ timeout: 5000 })
    await this.fillStepUpCredential()
    // Set up response watcher before confirming
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/auth/2fa/reset') && resp.request().method() === 'POST' && resp.status() === 200,
      { timeout: 10000 }
    )
    await this.page.getByTestId('confirm-dialog-ok').click()
    await responsePromise
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

    // Status is determined by the toggle switch's aria-checked attribute
    const toggleBtn = row.locator('[data-testid^="settings-admin-user-toggle-"]')
    const isChecked = await toggleBtn.getAttribute('aria-checked')
    return isChecked === 'true' ? 'active' : 'inactive'
  }

  /**
   * The active/inactive switch on an admin's row. Private per Pattern 006 —
   * tests assert through the expectations below.
   */
  private async adminUserToggle(email: string) {
    const adminId = await this.getAdminUserIdByEmail(email)
    if (!adminId) {
      throw new Error(`Admin user with email ${email} not found`)
    }
    return this.page.getByTestId(`settings-admin-user-toggle-${adminId}`)
  }

  /**
   * Expect the active switch on this admin's row to be locked — the signed-in
   * admin must not be able to deactivate themselves (#382, UC-A61).
   */
  async expectAdminUserToggleDisabled(email: string) {
    await expect(await this.adminUserToggle(email)).toBeDisabled()
  }

  /** Expect the active switch on this admin's row to be operable. */
  async expectAdminUserToggleEnabled(email: string) {
    await expect(await this.adminUserToggle(email)).toBeEnabled()
  }

  /** Expect the "own account" marker on this admin's row (#382). */
  async expectOwnAccountBadgeVisible(email: string) {
    const adminId = await this.getAdminUserIdByEmail(email)
    if (!adminId) {
      throw new Error(`Admin user with email ${email} not found`)
    }
    await expect(this.page.getByTestId(`settings-admin-user-self-badge-${adminId}`)).toBeVisible()
  }

  /** Expect no "own account" marker on this admin's row (#382). */
  async expectOwnAccountBadgeHidden(email: string) {
    const adminId = await this.getAdminUserIdByEmail(email)
    if (!adminId) {
      throw new Error(`Admin user with email ${email} not found`)
    }
    await expect(this.page.getByTestId(`settings-admin-user-self-badge-${adminId}`)).toHaveCount(0)
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

  // ==================== Terminals Tab ====================

  /**
   * Click terminals tab to switch to that tab
   */
  async clickTerminalsTab() {
    const terminalsTab = this.page.getByTestId('settings-tab-terminals')
    await terminalsTab.click()
    // Wait for terminals content to appear (table or "no terminals" message)
    await Promise.race([
      this.page.getByTestId('settings-terminals-table').waitFor({ state: 'visible', timeout: 10000 }),
      this.page.getByText('No terminals configured').waitFor({ state: 'visible', timeout: 10000 }),
    ])
  }

  /**
   * Expect terminals tab to be visible
   */
  async expectTerminalsTabVisible() {
    await expect(this.page.getByTestId('settings-tab-terminals')).toBeVisible()
  }

  /**
   * Expect terminals table to be visible
   */
  async expectTerminalsTableVisible() {
    await expect(this.page.getByTestId('settings-terminals-table')).toBeVisible()
  }

  /**
   * Click create terminal button
   */
  async clickCreateTerminalButton() {
    await this.page.getByTestId('settings-terminal-create-button').click()
    await this.page.getByTestId('settings-terminal-create-modal').waitFor({ state: 'visible' })
  }

  /**
   * Fill create terminal form
   */
  async fillCreateTerminalForm(data: { name: string; device_id: string }) {
    if (data.name) {
      await this.page.getByTestId('settings-terminal-create-name').fill(data.name)
    }
    if (data.device_id) {
      await this.page.getByTestId('settings-terminal-create-device-id').fill(data.device_id)
    }
  }

  /**
   * Click create terminal confirm button
   */
  async clickCreateTerminalConfirm() {
    await this.page.getByTestId('settings-terminal-create-confirm-button').click()
  }

  /**
   * Check if create terminal modal is visible
   */
  async isCreateTerminalModalVisible(): Promise<boolean> {
    return await this.page.getByTestId('settings-terminal-create-modal').count() > 0
  }

  /**
   * Close create terminal modal
   */
  async closeCreateTerminalModal() {
    await this.page.getByTestId('settings-terminal-create-cancel-button').click()
  }

  /**
   * Get terminal count by counting rows
   */
  async getTerminalCount(): Promise<number> {
    const rows = this.page.locator('[data-testid^="settings-terminal-row-"]')
    return await rows.count()
  }

  /**
   * Private: Find terminal row by name text content
   */
  private async findTerminalRowByName(name: string) {
    await this.page
      .locator('[data-testid^="settings-terminal-row-"]')
      .first()
      .waitFor({ state: 'attached', timeout: 5000 })
      .catch(() => {})

    const row = this.page
      .locator('[data-testid^="settings-terminal-row-"]')
      .filter({ has: this.page.locator(`[data-testid^="settings-terminal-name-"]:text-is("${name}")`) })
    const count = await row.count()
    if (count === 0) {
      return null
    }
    return row.first()
  }

  /**
   * Private: Get terminal ID by name
   */
  private async getTerminalIdByName(name: string): Promise<string | null> {
    const row = await this.findTerminalRowByName(name)
    if (!row) return null
    const rowTestId = await row.getAttribute('data-testid')
    if (!rowTestId) return null
    return rowTestId.replace('settings-terminal-row-', '')
  }

  /**
   * Get terminal by name
   */
  async getTerminalByName(name: string) {
    const row = await this.findTerminalRowByName(name)
    if (!row) return null

    const nameText = await row.locator('[data-testid^="settings-terminal-name-"]').textContent()
    const deviceIdText = await row.locator('[data-testid^="settings-terminal-device-id-"]').textContent()
    const toggleBtn = row.locator('[data-testid^="settings-terminal-toggle-"]')
    const isPressed = await toggleBtn.getAttribute('aria-pressed')
    const status = isPressed === 'true' ? 'active' : 'inactive'

    return {
      name: nameText?.trim() || '',
      device_id: deviceIdText?.trim() || '',
      status,
    }
  }

  /**
   * What the terminals table says about a terminal's token lifetime (#106).
   *
   * Returns the cell's text, which is the expiry date plus — when the token has
   * run out or is about to — the badge that says so. `null` if no row carries
   * that name.
   */
  async getTerminalTokenExpiry(name: string): Promise<string | null> {
    const terminalId = await this.getTerminalIdByName(name)
    if (!terminalId) return null

    const cell = this.page.getByTestId(`settings-terminal-token-expiry-${terminalId}`)
    return (await cell.textContent())?.trim() ?? null
  }

  /**
   * The token lifetime state the row publishes: `expired`, `expiringSoon`,
   * `valid`, or `none` when no token is outstanding.
   *
   * Asserted on the attribute rather than the badge's label, which is
   * translated and differs between the German and English UI.
   */
  async expectTerminalTokenExpiryState(name: string, state: string): Promise<void> {
    const terminalId = await this.getTerminalIdByName(name)
    expect(terminalId, `no terminal row named ${name}`).not.toBeNull()
    await expect(this.page.getByTestId(`settings-terminal-token-expiry-${terminalId}`)).toHaveAttribute(
      'data-token-expiry-state',
      state,
    )
  }

  /**
   * A terminal whose token has run out — or is about to — carries a badge in
   * the expiry cell; one with a healthy token shows the date alone.
   */
  async expectTerminalTokenExpiryBadge(name: string, visible: boolean): Promise<void> {
    const terminalId = await this.getTerminalIdByName(name)
    expect(terminalId, `no terminal row named ${name}`).not.toBeNull()
    const badge = this.page.getByTestId(`settings-terminal-token-expiry-badge-${terminalId}`)
    await (visible ? expect(badge).toBeVisible() : expect(badge).toHaveCount(0))
  }

  /**
   * How many rows carry this terminal name. Counting a known name instead of
   * the whole table (Pattern 003) keeps the assertion valid while other workers
   * add rows, and survives a list that is still reloading.
   */
  async countTerminalsWithName(name: string): Promise<number> {
    return await this.page.locator(`[data-testid^="settings-terminal-name-"]:text-is("${name}")`).count()
  }

  /**
   * Click edit button for terminal by name
   */
  async clickEditTerminalButton(name: string) {
    const terminalId = await this.getTerminalIdByName(name)
    if (!terminalId) throw new Error(`Terminal with name ${name} not found`)
    await this.page.getByTestId(`settings-terminal-edit-button-${terminalId}`).click()
    await this.page.getByTestId('settings-terminal-edit-modal').waitFor({ state: 'visible' })
  }

  /**
   * Fill edit terminal form
   */
  async fillEditTerminalForm(data: { name: string }) {
    await this.page.getByTestId('settings-terminal-edit-name').fill(data.name)
  }

  /**
   * Click edit terminal confirm button and wait for list reload
   */
  async clickEditTerminalConfirm() {
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/terminals') && resp.request().method() === 'GET',
      { timeout: 10000 }
    )
    await this.page.getByTestId('settings-terminal-edit-confirm-button').click()
    await responsePromise
  }

  /**
   * Check if edit terminal modal is visible
   */
  async isEditTerminalModalVisible(): Promise<boolean> {
    return await this.page.getByTestId('settings-terminal-edit-modal').count() > 0
  }

  /**
   * Click rotate token button for terminal by name
   */
  async clickRotateTokenButton(name: string) {
    const terminalId = await this.getTerminalIdByName(name)
    if (!terminalId) throw new Error(`Terminal with name ${name} not found`)
    await this.page.getByTestId(`settings-terminal-rotate-token-button-${terminalId}`).click()
  }

  /**
   * Click revoke button for terminal by name
   */
  async clickRevokeButton(name: string) {
    const terminalId = await this.getTerminalIdByName(name)
    if (!terminalId) throw new Error(`Terminal with name ${name} not found`)
    await this.page.getByTestId(`settings-terminal-revoke-button-${terminalId}`).click()
  }

  /**
   * Click toggle to deactivate terminal by name and confirm via ConfirmDialog
   */
  async clickDeactivateTerminal(name: string) {
    const terminalId = await this.getTerminalIdByName(name)
    if (!terminalId) throw new Error(`Terminal with name ${name} not found`)

    await this.page.getByTestId(`settings-terminal-toggle-${terminalId}`).click()
    await expect(this.page.getByTestId('confirm-dialog')).toBeVisible({ timeout: 10000 })
    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/terminals') && resp.request().method() === 'GET',
      { timeout: 15000 }
    )
    await this.page.getByTestId('confirm-dialog-ok').click()
    await responsePromise
  }

  /**
   * Click toggle to reactivate terminal by name (no confirm dialog)
   */
  async clickReactivateTerminal(name: string) {
    const terminalId = await this.getTerminalIdByName(name)
    if (!terminalId) throw new Error(`Terminal with name ${name} not found`)

    const responsePromise = this.page.waitForResponse(
      (resp) => resp.url().includes('/api/admin/terminals') && resp.request().method() === 'GET',
      { timeout: 15000 }
    )
    await this.page.getByTestId(`settings-terminal-toggle-${terminalId}`).click()
    await responsePromise
  }

  /**
   * Get terminal status by name
   */
  async getTerminalStatus(name: string): Promise<string | null> {
    const row = await this.findTerminalRowByName(name)
    if (!row) return null
    const toggleBtn = row.locator('[data-testid^="settings-terminal-toggle-"]')
    const isPressed = await toggleBtn.getAttribute('aria-pressed')
    return isPressed === 'true' ? 'active' : 'inactive'
  }

  /**
   * Wait for token display modal to appear
   */
  async waitForTokenModal() {
    const modal = this.page.getByTestId('settings-terminal-token-modal')
    await expect(modal).toBeVisible({ timeout: 5000 })
  }

  /**
   * Get generated token from token display modal
   */
  async getGeneratedToken(): Promise<string | null> {
    const modal = this.page.getByTestId('settings-terminal-token-modal')
    if (await modal.count() === 0) return null
    const tokenText = await this.page.getByTestId('settings-terminal-token-display').textContent()
    return tokenText?.trim() || null
  }

  /**
   * Copy token to clipboard.
   * The modal stays open — the copy has to be confirmed before the one-time
   * secret may be discarded.
   */
  async copyTokenToClipboard() {
    await this.page.getByTestId('settings-terminal-token-copy-button').click()
  }

  /**
   * Expect the token modal to confirm a successful clipboard write
   */
  async expectTokenCopyConfirmed() {
    await expect(this.page.getByTestId('settings-terminal-token-copy-status')).toBeVisible()
  }

  /**
   * Expect the token modal to report a failed clipboard write
   */
  async expectTokenCopyFailed() {
    await expect(this.page.getByTestId('settings-terminal-token-copy-error')).toBeVisible()
  }

  /**
   * Click the token modal backdrop (outside the dialog).
   * A one-time secret must survive this — see #126.
   */
  async clickTokenModalBackdrop() {
    await this.page.getByTestId('settings-terminal-token-modal').click({ position: { x: 5, y: 5 } })
  }

  /**
   * Expect the token modal to still be showing the secret
   */
  async expectTokenModalVisible() {
    await expect(this.page.getByTestId('settings-terminal-token-modal')).toBeVisible()
  }

  /**
   * Expect the token modal to be gone
   */
  async expectTokenModalHidden() {
    await expect(this.page.getByTestId('settings-terminal-token-modal')).toBeHidden()
  }

  /**
   * Break the clipboard API on the live page so a copy attempt rejects,
   * reproducing a non-secure origin / denied-permission environment.
   */
  async breakClipboard() {
    await this.page.evaluate(() => {
      Object.defineProperty(navigator, 'clipboard', {
        configurable: true,
        value: {
          writeText: () => Promise.reject(new Error('NotAllowedError')),
        },
      })
    })
  }

  /**
   * Grant clipboard permissions so a copy attempt can succeed headlessly
   */
  async grantClipboardPermissions() {
    await this.page.context().grantPermissions(['clipboard-read', 'clipboard-write'])
  }

  /**
   * Read the current clipboard contents (requires granted permissions)
   */
  async readClipboard(): Promise<string> {
    return await this.page.evaluate(() => navigator.clipboard.readText())
  }

  /**
   * Close token display modal by acknowledging that the secret was saved
   */
  async closeTokenModal() {
    const closeButton = this.page.getByTestId('settings-terminal-token-close-button')
    await closeButton.click()
  }

  /**
   * Expect create terminal button to be visible
   */
  async expectCreateTerminalButtonVisible() {
    await expect(this.page.getByTestId('settings-terminal-create-button')).toBeVisible()
  }

  // ==================== Security Tab ====================

  /**
   * Open the security self-check tab and wait for the measurement to land.
   *
   * The report is taken when the tab is opened, never cached — the question it
   * answers ("did a host change break something since?") is only meaningful
   * about the moment it is asked (#247).
   */
  async clickSecurityTab() {
    await this.page.getByTestId('settings-tab-security').click()
    await expect(this.page.getByTestId('security-check-summary')).toBeVisible({ timeout: 15000 })
  }

  /**
   * The overall verdict: `pass`, `warn` or `fail`.
   */
  async getSecurityVerdict(): Promise<string> {
    for (const status of ['pass', 'warn', 'fail']) {
      if (await this.page.getByTestId(`security-check-status-${status}`).isVisible()) {
        return status
      }
    }
    return 'none'
  }

  /**
   * The status of one measured row, found by its stable id rather than by
   * position (Pattern 003).
   */
  async getSecurityFindingStatus(id: string): Promise<string | null> {
    return await this.page.getByTestId(`security-check-finding-${id}`).getAttribute('data-status')
  }

  async getSecurityFindingObserved(id: string): Promise<string> {
    return (await this.page.getByTestId(`security-check-observed-${id}`).textContent()) ?? ''
  }

  /** Every row the report rendered, with its status. */
  async getSecurityFindings(): Promise<Array<{ id: string; status: string }>> {
    return await this.page.locator('[data-testid^="security-check-finding-"]').evaluateAll((nodes) =>
      nodes.map((node) => ({
        id: (node.getAttribute('data-testid') ?? '').replace('security-check-finding-', ''),
        status: node.getAttribute('data-status') ?? '',
      }))
    )
  }

  async expectSecurityFindingVisible(id: string) {
    await expect(this.page.getByTestId(`security-check-finding-${id}`)).toBeVisible()
  }

  async expectSecurityRemedyVisible(id: string) {
    await expect(this.page.getByTestId(`security-check-remedy-${id}`)).toBeVisible()
  }

  /** Re-run the measurement without leaving the page. */
  async clickSecurityRecheck() {
    await this.page.getByTestId('security-check-refresh').click()
  }

  // ==================== Credentials Tab (#394, ADR-0036) ====================

  /**
   * Open the Security & Credentials tab and wait for the key list to land.
   *
   * Like the self-check it is fetched when the tab is opened rather than cached
   * with the page: the warning tiers are computed at request time, so a list
   * from five minutes ago would be a stale warning.
   */
  async clickCredentialsTab() {
    await this.page.getByTestId('settings-tab-credentials').click()
    await expect(this.page.getByTestId('credentials-tab')).toBeVisible({ timeout: 15000 })
    await expect(this.page.getByTestId('credentials-loading')).toBeHidden({ timeout: 15000 })
  }

  /** Every key the page rendered, found by its stable id (Pattern 003). */
  async getEncryptionKeys(): Promise<
    Array<{ id: string; status: string; lifecycleState: string; sealedRecordCount: number }>
  > {
    return await this.page.locator('[data-testid^="credentials-key-"]').evaluateAll((nodes) =>
      nodes
        // The status badge and backlog line carry the same prefix; only the
        // card itself declares a status attribute.
        .filter((node) => node.hasAttribute('data-status'))
        .map((node) => ({
          id: (node.getAttribute('data-testid') ?? '').replace('credentials-key-', ''),
          status: node.getAttribute('data-status') ?? '',
          lifecycleState: node.getAttribute('data-lifecycle-state') ?? '',
          sealedRecordCount: Number(node.getAttribute('data-sealed-record-count') ?? '0'),
        }))
    )
  }

  async expectEncryptionKeyVisible(id: string) {
    await expect(this.page.getByTestId(`credentials-key-${id}`)).toBeVisible()
  }

  /** Open the "register a key" dialog, which collects a step-up credential. */
  async clickRegisterKey() {
    await this.page.getByTestId('credentials-register-button').click()
    await expect(this.page.getByTestId('credentials-public-key')).toBeVisible()
  }

  /** Start the rotation wizard for a key that still has rows under it. */
  async clickRotateKey(id: string) {
    await this.page.getByTestId(`credentials-rotate-${id}`).click()
  }

  // ==================== Instance Branding Tab (ADR-0034 / UC-A64) ====================

  /**
   * Click the Instance tab to switch to it and wait for its form to load.
   */
  async clickInstanceTab() {
    await this.page.getByTestId('settings-tab-instance').click()
    await this.page.getByTestId('settings-instance-form').waitFor({ state: 'visible' })
  }

  /**
   * Expect the Instance tab button to be visible.
   */
  async expectInstanceTabVisible() {
    await expect(this.page.getByTestId('settings-tab-instance')).toBeVisible()
  }

  /**
   * Expect the Instance form to be visible.
   */
  async expectInstanceFormVisible() {
    await expect(this.page.getByTestId('settings-instance-form')).toBeVisible()
  }

  /**
   * Get the current value of the instance name input.
   */
  async getInstanceNameValue(): Promise<string> {
    return await this.page.getByTestId('settings-instance-input-name').inputValue()
  }

  /**
   * Fill the instance name input.
   */
  async fillInstanceName(name: string) {
    await this.page.getByTestId('settings-instance-input-name').fill(name)
  }

  /**
   * Click the Instance tab's save button.
   */
  async saveInstance() {
    await this.page.getByTestId('settings-instance-save-button').click()
  }

  /**
   * Click save and wait for the write to reach the backend. Returns the
   * status of the PATCH, so a test can assert what the frontend actually sent.
   */
  async saveInstanceAndWaitForResponse(): Promise<{ status: number }> {
    const responsePromise = this.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/instance-config') && resp.request().method() === 'PATCH',
      { timeout: 15000 },
    )
    await this.saveInstance()
    const response = await responsePromise
    return { status: response.status() }
  }

  /**
   * Click the Instance tab's cancel button.
   */
  async cancelInstance() {
    await this.page.getByTestId('settings-instance-cancel-button').click()
  }

  /**
   * Expect the Instance tab's own success message to be visible (#91 split:
   * distinct from the page-level failure banner).
   */
  async expectInstanceSuccessMessage() {
    await expect(this.page.getByTestId('settings-instance-success-message')).toBeVisible()
  }

  /**
   * Expect the instance name field's inline validation error to be visible,
   * and return its text.
   */
  async expectInstanceNameFieldError(): Promise<string> {
    const fieldError = this.page.getByTestId('settings-instance-error-name')
    await expect(fieldError).toBeVisible()
    return (await fieldError.textContent()) ?? ''
  }

  /**
   * The admin header's brand name — reflects the instance name (ADR-0034).
   */
  async getHeaderBrandName(): Promise<string> {
    return ((await this.page.getByTestId('header-brand-name').textContent()) ?? '').trim()
  }

  /**
   * Expect the admin header's brand name to show the given instance name,
   * without a page reload (UC-A64 postcondition).
   */
  async expectHeaderBrandName(name: string) {
    await expect(this.page.getByTestId('header-brand-name')).toHaveText(name)
  }
}
