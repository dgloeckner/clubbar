/**
 * E2E Tests: Instance Branding (ADR-0034 / UC-A64)
 *
 * Tests for Settings → Instance tab: loading the configured instance name,
 * saving a new one, validation, the header/title updating in the same
 * session without a reload, and the (logged-out) login page reflecting it.
 *
 * Pattern 006/007: Page Object Model + Fixtures — all selectors live in
 * e2etests/pages/SettingsPage.ts and e2etests/pages/LoginPage.ts.
 * Pattern 008: Playwright Assertions — expect() over try/catch visibility checks.
 * E2E Integration: verifies frontend -> API -> backend -> database, including
 * the Settings tab's own re-fetch of the shared header/title state.
 */

import { test, expect } from '../../fixtures/pageObjects'

/**
 * Helper: Generate a unique instance name (Pattern 001 — test data isolation)
 */
function generateUniqueInstanceName(): string {
  return `Test Club ${Date.now()}-${Math.random().toString(36).substring(2, 8)}`
}

test.describe('Instance Branding', () => {
  // instance_config is a global singleton row (ADR-0034, same shape as
  // sepa_config) — writers would clobber each other under `fullyParallel`,
  // so they are serialised within this file, as tests/admin/settings-sepa-config.spec.ts
  // already does for the same reason.
  test.describe.configure({ mode: 'serial' })

  // Set by the save test below and read by the logged-out login page test,
  // which runs later in the same serial sequence.
  let savedInstanceName: string

  test('should display the currently configured instance name in Settings → Instance tab', async ({
    authenticatedSettingsPage,
  }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.expectInstanceTabVisible()

    await authenticatedSettingsPage.clickInstanceTab()
    await authenticatedSettingsPage.expectInstanceFormVisible()

    // Seeded by migration 013 with 'Club Bar' and never left blank (UC-A64) —
    // whatever the current value is, it must not be empty.
    const currentName = await authenticatedSettingsPage.getInstanceNameValue()
    expect(currentName.length).toBeGreaterThan(0)
  })

  test('should save a new instance name, show success, and update the header immediately without reloading', async ({
    authenticatedSettingsPage,
  }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickInstanceTab()

    const newName = generateUniqueInstanceName()

    await authenticatedSettingsPage.fillInstanceName(newName)
    expect(await authenticatedSettingsPage.getInstanceNameValue()).toBe(newName)

    const { status } = await authenticatedSettingsPage.saveInstanceAndWaitForResponse()
    expect(status).toBe(200)

    // Assert: tab-level success message, no page-level error (#91 split)
    await authenticatedSettingsPage.expectInstanceSuccessMessage()
    await authenticatedSettingsPage.expectNoErrorMessage()

    // Assert: the admin header reflects the new name in this same session,
    // without a reload — the Settings tab refetches the shared context on
    // save (UC-A64 postconditions).
    await authenticatedSettingsPage.expectHeaderBrandName(newName)

    // Assert: the value round-trips through the database, not just local state
    await authenticatedSettingsPage.page.reload({ waitUntil: 'domcontentloaded' })
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickInstanceTab()
    expect(await authenticatedSettingsPage.getInstanceNameValue()).toBe(newName)

    // The header also reflects the persisted name after a fresh page load
    await authenticatedSettingsPage.expectHeaderBrandName(newName)

    savedInstanceName = newName
  })

  test('should reject an empty instance name with a validation error', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickInstanceTab()

    // Remember the valid name so this test does not leave the singleton row
    // blank for whatever runs after it.
    const originalName = await authenticatedSettingsPage.getInstanceNameValue()

    await authenticatedSettingsPage.fillInstanceName('')
    await authenticatedSettingsPage.saveInstance()

    // Assert: client-side validation rejects the empty value before any
    // request is made — the field error is shown and the form stays put.
    await authenticatedSettingsPage.expectInstanceNameFieldError()
    await authenticatedSettingsPage.expectInstanceFormVisible()

    // Restore a valid value so the singleton row is not left empty
    await authenticatedSettingsPage.fillInstanceName(originalName)
    const { status } = await authenticatedSettingsPage.saveInstanceAndWaitForResponse()
    expect(status).toBe(200)
    await authenticatedSettingsPage.expectInstanceSuccessMessage()
  })

  test.describe('Login page (logged out)', () => {
    // Override the project's pre-authenticated storageState — the login page
    // must be reachable, and show the instance name, before any session exists.
    test.use({ storageState: { cookies: [], origins: [] } })

    test('should show the configured instance name', async ({ loginPage }) => {
      // Serial mode (above) guarantees the save test ran first and set this.
      // If it didn't (e.g. that test failed), asserting against `undefined`
      // fails this test too — a real signal, not something to skip past.
      await loginPage.navigate()
      await loginPage.waitForBrandName()

      const brandName = await loginPage.getBrandNameText()
      expect(brandName).toBe(savedInstanceName)
    })
  })
})
