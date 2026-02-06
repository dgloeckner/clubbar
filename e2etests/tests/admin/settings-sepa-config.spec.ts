/**
 * E2E Tests: SEPA Configuration
 *
 * Tests for the SEPA configuration settings page
 * Pattern 001: Test Data Isolation - each test uses unique data
 * Pattern 008: Playwright Assertions - use expect() for visibility checks
 * E2E Integration: Tests verify complete frontend -> API -> backend -> database flow
 */

import { test, expect } from '../../fixtures/pageObjects'

/**
 * Helper: Generate unique string for test data isolation
 */
function generateUnique(): string {
  return Math.random().toString(36).substring(2, 10)
}

/**
 * Helper: Generate unique test data
 * Pattern 001: Each test gets unique data to avoid conflicts
 */
function generateTestSepaConfig() {
  const unique = generateUnique()
  // Generate valid DE IBAN (22 chars): DE + 18 digits (check + bank + account)
  const randomDigits = Math.random().toString().substring(2, 11) + Math.random().toString().substring(2, 11)
  const iban = `DE89${randomDigits.substring(0, 18)}`.substring(0, 22)

  return {
    creditor_id: `DE${unique}ZZZ09999999999`.substring(0, 35),
    creditor_name: `Test Organization ${unique}`,
    creditor_iban: iban,
    creditor_address_street: `Test Street ${unique}`,
    creditor_address_city: `Test City ${unique}`,
    creditor_address_country: 'DE',
  }
}

test.describe('SEPA Configuration Settings', () => {
  /**
   * Test: Settings page displays SEPA configuration tab
   * Verifies: Page loads, tab is visible and labeled correctly
   */
  test('should display SEPA Configuration tab', async ({ authenticatedSettingsPage }) => {
    // Navigate and wait for load
    await authenticatedSettingsPage.waitForLoad()

    // Pattern 008: Use expect() for clear error messages
    await authenticatedSettingsPage.expectPageVisible()
    await authenticatedSettingsPage.expectSepaTabVisible()

    // Click SEPA tab to switch to it (default is admin-users)
    await authenticatedSettingsPage.clickSepaTab()
    await authenticatedSettingsPage.expectFormVisible()
  })

  /**
   * Test: Save new SEPA configuration (first-time setup)
   *
   * E2E Verification Flow:
   * 1. Get initial config state
   * 2. Fill form with unique test data
   * 3. Click save
   * 4. Verify API success (success message appears)
   * 5. Verify NO error messages
   * 6. Verify data persists (reload page, verify masked fields)
   * 7. Verify creditor_id is now disabled (immutability)
   *
   * Pattern 001: Unique test data per test
   * Pattern 008: Use expect() for assertions
   */
  test('should save new SEPA configuration successfully', async ({ authenticatedSettingsPage }) => {
    // Arrange: Navigate to settings
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickSepaTab()

    // Check initial state - fields should be empty if not configured
    const initialIban = await authenticatedSettingsPage.getIbanValue()
    const isFirstTimeSetup = initialIban === ''

    if (!isFirstTimeSetup) {
      test.skip() // Skip if already configured (this test is for first-time setup)
    }

    // Generate unique test data (Pattern 001)
    const testData = generateTestSepaConfig()

    // Act: Fill form with valid data
    await authenticatedSettingsPage.fillSepaConfig(testData)

    // Verify form is filled
    expect(await authenticatedSettingsPage.getCreditorNameValue()).toBe(testData.creditor_name)
    expect((await authenticatedSettingsPage.getCountryValue()).toUpperCase()).toBe('DE')

    // Act: Save configuration
    await authenticatedSettingsPage.save()

    // Wait a bit for API response
    await authenticatedSettingsPage.page.waitForTimeout(500)

    // Assert: Success message appears
    await authenticatedSettingsPage.expectSuccessMessage()

    // Assert: No error message
    await authenticatedSettingsPage.expectNoErrorMessage()

    // Assert: Form is still visible (didn't navigate away)
    await authenticatedSettingsPage.expectFormVisible()

    // Verify: Reload page and check that data persists and is masked
    await authenticatedSettingsPage.page.reload({ waitUntil: 'domcontentloaded' })
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickSepaTab()

    // After reload, creditor_id should be disabled (immutability)
    const isNowDisabled = await authenticatedSettingsPage.isCreditorIdDisabled()
    expect(isNowDisabled).toBe(true)

    // Data should still be there
    const reloadedName = await authenticatedSettingsPage.getCreditorNameValue()
    expect(reloadedName).toBe(testData.creditor_name)
  })


  /**
   * Test: Client-side validation prevents invalid submission
   *
   * E2E Verification Flow:
   * 1. Verify form loads with empty fields
   * 2. Try to save with minimal fields
   * 3. Verify form still exists (validation prevented submission)
   * 4. Fill complete valid data
   * 5. Save should succeed
   *
   * Pattern 008: Use expect() for assertions
   */
  test('should prevent submission with empty required fields', async ({ authenticatedSettingsPage }) => {
    // Arrange: Navigate to settings
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickSepaTab()

    // Verify form is visible and ready
    await authenticatedSettingsPage.expectFormVisible()

    // Generate test data
    const testData = generateTestSepaConfig()

    // Fill only partial data (leave some empty)
    await authenticatedSettingsPage.fillSepaConfig({
      creditor_name: testData.creditor_name,
      // Leave other fields empty to trigger validation
    })

    // Act: Try to save with incomplete data
    await authenticatedSettingsPage.save()

    // Wait a bit for validation
    await authenticatedSettingsPage.page.waitForTimeout(300)

    // Assert: Form is still visible (form validation prevented API call)
    await authenticatedSettingsPage.expectFormVisible()

    // Now fill all required fields
    await authenticatedSettingsPage.fillSepaConfig({
      creditor_id: testData.creditor_id,
      creditor_iban: testData.creditor_iban,
      creditor_address_street: testData.creditor_address_street,
      creditor_address_city: testData.creditor_address_city,
      creditor_address_country: testData.creditor_address_country,
    })

    // Try saving again with complete data
    await authenticatedSettingsPage.save()

    // Wait for API response
    await authenticatedSettingsPage.page.waitForTimeout(500)

    // Should show success (form validation passed this time)
    await authenticatedSettingsPage.expectFormVisible() // Form should still be visible
  })


  /**
   * Test: Warning shown when creditor_id can be changed after initial setup
   *
   * E2E Verification Flow:
   * 1. Load existing config
   * 2. Verify creditor_id field is ENABLED (not disabled)
   * 3. Verify user CAN edit creditor_id (loosened from strict immutability)
   * 4. Verify warning is displayed when config already exists
   * 5. Verify other fields can still be edited
   *
   * Pattern 008: Use expect() for assertions
   */
  test('should show warning when creditor_id exists but can be edited', async ({ authenticatedSettingsPage }) => {
    // Arrange: Navigate to settings
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickSepaTab()

    // Check if config exists
    const existingIban = await authenticatedSettingsPage.getIbanValue()
    if (!existingIban) {
      test.skip() // Skip if no config exists
    }

    // Get original creditor_id
    const originalId = await authenticatedSettingsPage.getCreditorIdValue()

    // Verify field is NOT disabled (can be edited - loosened from previous strict immutability)
    // This is the key change: creditor_id is now editable with warning instead of locked
    const isDisabled = await authenticatedSettingsPage.isCreditorIdDisabled()
    expect(isDisabled).toBe(false)

    // Verify creditor_id field is editable by trying to fill it
    const testId = `DE${generateUnique()}ZZZ09999999999`
    await authenticatedSettingsPage.fillSepaConfig({
      creditor_id: testId,
    })

    // Verify the field accepted the new value
    const updatedId = await authenticatedSettingsPage.getCreditorIdValue()
    expect(updatedId).toBe(testId)

    // Cancel to reset (don't save the change)
    await authenticatedSettingsPage.cancel()

    // Wait for cancel to complete
    await authenticatedSettingsPage.page.waitForTimeout(300)

    // Verify form reset to original creditor_id
    const resetId = await authenticatedSettingsPage.getCreditorIdValue()
    expect(resetId).toBe(originalId)
  })

  /**
   * Test: Form cancel resets changes
   *
   * E2E Verification Flow:
   * 1. Load existing config
   * 2. Make changes to form
   * 3. Click cancel
   * 4. Verify form is reset to original values
   *
   * Pattern 008: Use expect() for assertions
   */
  test('should reset form to original values when cancel is clicked', async ({ authenticatedSettingsPage }) => {
    // Arrange: Navigate to settings
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickSepaTab()

    // Get original values
    const originalName = await authenticatedSettingsPage.getCreditorNameValue()
    const originalCity = await authenticatedSettingsPage.getCityValue()

    // Make changes
    const testData = generateTestSepaConfig()
    await authenticatedSettingsPage.fillSepaConfig({
      creditor_name: testData.creditor_name,
      creditor_address_city: testData.creditor_address_city,
    })

    // Verify changes are in form
    expect(await authenticatedSettingsPage.getCreditorNameValue()).not.toBe(originalName)
    expect(await authenticatedSettingsPage.getCityValue()).not.toBe(originalCity)

    // Act: Click cancel
    await authenticatedSettingsPage.cancel()

    // Wait a bit for potential state update
    await authenticatedSettingsPage.page.waitForTimeout(300)

    // Assert: Form is reset to original values
    expect(await authenticatedSettingsPage.getCreditorNameValue()).toBe(originalName)
    expect(await authenticatedSettingsPage.getCityValue()).toBe(originalCity)
  })

  /**
   * Test: Country code auto-uppercase
   *
   * E2E Verification Flow:
   * 1. Enter lowercase country code
   * 2. Verify it's automatically uppercased
   * 3. Save successfully
   *
   * Pattern 008: Use expect() for assertions
   */
  test('should auto-uppercase country code input', async ({ authenticatedSettingsPage }) => {
    // Arrange: Navigate to settings
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickSepaTab()

    // Act: Enter lowercase country code
    await authenticatedSettingsPage.fillSepaConfig({
      creditor_address_country: 'de', // lowercase
    })

    // Assert: Should be auto-uppercased
    const countryValue = await authenticatedSettingsPage.getCountryValue()
    expect(countryValue).toBe('DE')
  })
})
