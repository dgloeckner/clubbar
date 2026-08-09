/**
 * E2E Tests: Terminal Devices Management
 *
 * Tests for the terminals settings tab (CRUD operations + token management)
 * Pattern 001: Test Data Isolation - each test uses unique data
 * Pattern 008: Playwright Assertions - use expect() for visibility checks
 * E2E Integration: Tests verify complete frontend -> API -> backend -> database flow
 */

import { test, expect } from '../../fixtures/pageObjects'
import { SettingsPage } from '../../pages'

/**
 * Helper: Generate unique string for test data isolation
 */
function generateUnique(): string {
  return Math.random().toString(36).substring(2, 10)
}

/**
 * Helper: Generate unique test terminal data
 */
function generateTestTerminal() {
  const unique = generateUnique()
  return {
    name: `Terminal ${unique}`,
    device_id: `DEV-${unique}`,
  }
}

test.describe('Terminal Devices Management', () => {
  /**
   * Test: Settings page displays terminals tab
   */
  test('should display terminals tab', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.expectTerminalsTabVisible()
    await authenticatedSettingsPage.clickTerminalsTab()
    await authenticatedSettingsPage.expectCreateTerminalButtonVisible()
  })

  /**
   * Test: Create terminal and display API token
   */
  test('should create terminal and show API token', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickTerminalsTab()

    const initialCount = await authenticatedSettingsPage.getTerminalCount()
    const testData = generateTestTerminal()

    // Open create modal
    await authenticatedSettingsPage.clickCreateTerminalButton()
    const modalVisible = await authenticatedSettingsPage.isCreateTerminalModalVisible()
    expect(modalVisible).toBe(true)

    // Fill form
    await authenticatedSettingsPage.fillCreateTerminalForm(testData)

    // Set up interceptor for terminals list refresh
    const terminalsLoaded = authenticatedSettingsPage.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/terminals') &&
        resp.request().method() === 'GET' &&
        resp.status() === 200,
    )

    // Submit
    await authenticatedSettingsPage.clickCreateTerminalConfirm()

    // Verify token modal appears
    await authenticatedSettingsPage.waitForTokenModal()
    const token = await authenticatedSettingsPage.getGeneratedToken()
    expect(token).not.toBeNull()
    expect(token!.length).toBeGreaterThan(0)

    // Close token modal
    await authenticatedSettingsPage.closeTokenModal()
    await terminalsLoaded

    // Verify terminal in table
    // The table may have a per_page limit; only assert count increase when below the limit
    const newCount = await authenticatedSettingsPage.getTerminalCount()
    if (initialCount < 90) {
      expect(newCount).toBeGreaterThanOrEqual(initialCount + 1)
    }

    const terminal = await authenticatedSettingsPage.getTerminalByName(testData.name)
    expect(terminal).not.toBeNull()
    expect(terminal?.name).toBe(testData.name)
  })

  /**
   * Test: Edit terminal name
   */
  test('should edit terminal name', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickTerminalsTab()

    // Create terminal first
    const testData = generateTestTerminal()
    await authenticatedSettingsPage.clickCreateTerminalButton()
    await authenticatedSettingsPage.fillCreateTerminalForm(testData)

    const terminalsLoaded = authenticatedSettingsPage.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/terminals') &&
        resp.request().method() === 'GET' &&
        resp.status() === 200,
    )

    await authenticatedSettingsPage.clickCreateTerminalConfirm()
    await authenticatedSettingsPage.waitForTokenModal()
    await authenticatedSettingsPage.closeTokenModal()
    await terminalsLoaded

    // Edit the terminal
    const newName = `Updated ${generateUnique()}`
    await authenticatedSettingsPage.clickEditTerminalButton(testData.name)
    await authenticatedSettingsPage.fillEditTerminalForm({ name: newName })
    await authenticatedSettingsPage.clickEditTerminalConfirm()

    // Verify updated name in table
    const updatedTerminal = await authenticatedSettingsPage.getTerminalByName(newName)
    expect(updatedTerminal).not.toBeNull()
    expect(updatedTerminal?.name).toBe(newName)
  })

  /**
   * Test: Deactivate and reactivate terminal
   */
  test('should deactivate and reactivate terminal', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickTerminalsTab()

    // Create terminal
    const testData = generateTestTerminal()
    await authenticatedSettingsPage.clickCreateTerminalButton()
    await authenticatedSettingsPage.fillCreateTerminalForm(testData)

    const terminalsLoaded = authenticatedSettingsPage.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/terminals') &&
        resp.request().method() === 'GET' &&
        resp.status() === 200,
    )

    await authenticatedSettingsPage.clickCreateTerminalConfirm()
    await authenticatedSettingsPage.waitForTokenModal()
    await authenticatedSettingsPage.closeTokenModal()
    await terminalsLoaded

    // Verify active
    let status = await authenticatedSettingsPage.getTerminalStatus(testData.name)
    expect(status?.toLowerCase()).toContain('active')

    // Deactivate
    await authenticatedSettingsPage.clickDeactivateTerminal(testData.name)

    // Verify inactive
    status = await authenticatedSettingsPage.getTerminalStatus(testData.name)
    expect(status?.toLowerCase()).toContain('inactive')

    // Reactivate
    await authenticatedSettingsPage.clickReactivateTerminal(testData.name)

    // Verify active again
    status = await authenticatedSettingsPage.getTerminalStatus(testData.name)
    expect(status?.toLowerCase()).toContain('active')
  })

  /**
   * Test: Rotate token shows new token
   */
  test('should rotate token and show new token', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickTerminalsTab()

    // Create terminal
    const testData = generateTestTerminal()
    await authenticatedSettingsPage.clickCreateTerminalButton()
    await authenticatedSettingsPage.fillCreateTerminalForm(testData)

    const terminalsLoaded = authenticatedSettingsPage.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/terminals') &&
        resp.request().method() === 'GET' &&
        resp.status() === 200,
    )

    await authenticatedSettingsPage.clickCreateTerminalConfirm()
    await authenticatedSettingsPage.waitForTokenModal()
    const originalToken = await authenticatedSettingsPage.getGeneratedToken()
    expect(originalToken).not.toBeNull()
    await authenticatedSettingsPage.closeTokenModal()
    await terminalsLoaded

    // Rotate token
    await authenticatedSettingsPage.clickRotateTokenButton(testData.name)

    // Confirm dialog appears
    await expect(authenticatedSettingsPage.page.getByTestId('confirm-dialog')).toBeVisible({ timeout: 10000 })
    await authenticatedSettingsPage.page.getByTestId('confirm-dialog-ok').click()

    // Verify new token modal
    await authenticatedSettingsPage.waitForTokenModal()
    const newToken = await authenticatedSettingsPage.getGeneratedToken()
    expect(newToken).not.toBeNull()
    expect(newToken!.length).toBeGreaterThan(0)

    await authenticatedSettingsPage.closeTokenModal()
  })

  /**
   * Test: Revoke terminal access
   */
  test('should revoke terminal access', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickTerminalsTab()

    // Create terminal
    const testData = generateTestTerminal()
    await authenticatedSettingsPage.clickCreateTerminalButton()
    await authenticatedSettingsPage.fillCreateTerminalForm(testData)

    const terminalsLoaded = authenticatedSettingsPage.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/terminals') &&
        resp.request().method() === 'GET' &&
        resp.status() === 200,
    )

    await authenticatedSettingsPage.clickCreateTerminalConfirm()
    await authenticatedSettingsPage.waitForTokenModal()
    await authenticatedSettingsPage.closeTokenModal()
    await terminalsLoaded

    // Revoke access
    await authenticatedSettingsPage.clickRevokeButton(testData.name)

    // Confirm dialog
    await expect(authenticatedSettingsPage.page.getByTestId('confirm-dialog')).toBeVisible({ timeout: 10000 })

    const revokeLoaded = authenticatedSettingsPage.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/terminals') &&
        resp.request().method() === 'GET' &&
        resp.status() === 200,
    )
    await authenticatedSettingsPage.page.getByTestId('confirm-dialog-ok').click()
    await revokeLoaded

    // Verify terminal is now inactive (revoke deactivates the terminal)
    const status = await authenticatedSettingsPage.getTerminalStatus(testData.name)
    expect(status?.toLowerCase()).toContain('inactive')
  })

  /**
   * Token expiry (#106)
   *
   * The seeded "Expired Test Terminal" is the only way to see the expired state
   * in the UI — no admin action can produce a token that is already past its
   * lifetime.
   */
  test('should flag a terminal whose token has expired', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickTerminalsTab()

    const expiry = await authenticatedSettingsPage.getTerminalTokenExpiry('Expired Test Terminal')
    expect(expiry).not.toBeNull()
    await authenticatedSettingsPage.expectTerminalTokenExpiryState('Expired Test Terminal', 'expired')
    await authenticatedSettingsPage.expectTerminalTokenExpiryBadge('Expired Test Terminal', true)
  })

  test('should show a plain expiry date for a freshly issued token', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickTerminalsTab()

    const testData = generateTestTerminal()
    await authenticatedSettingsPage.clickCreateTerminalButton()
    await authenticatedSettingsPage.fillCreateTerminalForm(testData)

    const terminalsLoaded = authenticatedSettingsPage.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/terminals') &&
        resp.request().method() === 'GET' &&
        resp.status() === 200,
    )

    await authenticatedSettingsPage.clickCreateTerminalConfirm()
    await authenticatedSettingsPage.waitForTokenModal()
    await authenticatedSettingsPage.closeTokenModal()
    await terminalsLoaded

    // 90 days out, so no warning badge — just the date the token runs out.
    const expiry = await authenticatedSettingsPage.getTerminalTokenExpiry(testData.name)
    expect(expiry).toBeTruthy()
    await authenticatedSettingsPage.expectTerminalTokenExpiryState(testData.name, 'valid')
    await authenticatedSettingsPage.expectTerminalTokenExpiryBadge(testData.name, false)
  })

  /**
   * Test: Cancel create modal
   */
  test('should close create modal when cancel is clicked', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickTerminalsTab()

    const initialCount = await authenticatedSettingsPage.getTerminalCount()

    // Open and close modal without submitting
    await authenticatedSettingsPage.clickCreateTerminalButton()
    const modalVisible = await authenticatedSettingsPage.isCreateTerminalModalVisible()
    expect(modalVisible).toBe(true)

    await authenticatedSettingsPage.closeCreateTerminalModal()
    await authenticatedSettingsPage.page.waitForTimeout(300)

    const stillVisible = await authenticatedSettingsPage.isCreateTerminalModalVisible()
    expect(stillVisible).toBe(false)

    const newCount = await authenticatedSettingsPage.getTerminalCount()
    expect(newCount).toBe(initialCount)
  })

  /**
   * Test: A rejected create is reported in the modal (#91)
   *
   * A duplicate device ID used to fail silently: the modal stayed open showing
   * nothing, and the message surfaced later on the SEPA tab.
   *
   * E2E Verification Flow:
   * 1. Create a terminal so its device ID is taken
   * 2. Try to create a second terminal with the same device ID
   * 3. Verify the modal reports the rejection and stays open
   * 4. Verify nothing was persisted (count unchanged)
   * 5. Verify the message does not leak onto the SEPA tab
   */
  test('should report a duplicate device ID inside the create modal', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickTerminalsTab()

    // Arrange: a terminal whose device ID is now taken
    const testData = generateTestTerminal()
    await authenticatedSettingsPage.clickCreateTerminalButton()
    await authenticatedSettingsPage.fillCreateTerminalForm(testData)
    await authenticatedSettingsPage.clickCreateTerminalConfirm()
    await authenticatedSettingsPage.waitForTokenModal()
    await authenticatedSettingsPage.closeTokenModal()

    // The list reloads after the create; wait for the new row before acting
    await expect.poll(() => authenticatedSettingsPage.countTerminalsWithName(testData.name)).toBe(1)

    // Act: same device ID, different name
    await authenticatedSettingsPage.clickCreateTerminalButton()
    await authenticatedSettingsPage.fillCreateTerminalForm({
      name: `${testData.name} duplicate`,
      device_id: testData.device_id,
    })
    await authenticatedSettingsPage.clickCreateTerminalConfirm()

    // Assert: the reason is on screen, in the modal the admin is looking at
    const message = await authenticatedSettingsPage.expectCreateTerminalModalError()
    expect(message).toContain('already exists')

    // Assert: nothing was created — the duplicate name never reached the table
    await authenticatedSettingsPage.closeCreateTerminalModal()
    expect(await authenticatedSettingsPage.countTerminalsWithName(`${testData.name} duplicate`)).toBe(0)

    // Assert: the message did not follow the admin to another tab
    await authenticatedSettingsPage.clickSepaTab()
    await authenticatedSettingsPage.expectNoErrorMessage()
  })

  /**
   * Test: Empty required fields are caught before the request (#91)
   *
   * Submit used to be enabled with empty values, which were sent straight to
   * the API and rejected with a 422 nothing rendered.
   */
  test('should reject an empty create terminal form without calling the API', async ({
    authenticatedSettingsPage,
  }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickTerminalsTab()

    // Watch for a POST that must never happen (parallel-safe, Pattern 004)
    let createRequestFired = false
    authenticatedSettingsPage.page.on('response', (resp) => {
      if (resp.url().includes('/api/admin/terminals') && resp.request().method() === 'POST') {
        createRequestFired = true
      }
    })

    await authenticatedSettingsPage.clickCreateTerminalButton()
    await authenticatedSettingsPage.clickCreateTerminalConfirm()

    // Assert: both empty fields are named, and the modal stays open
    await authenticatedSettingsPage.expectCreateTerminalFieldError('name')
    await authenticatedSettingsPage.expectCreateTerminalFieldError('device-id')
    expect(await authenticatedSettingsPage.isCreateTerminalModalVisible()).toBe(true)
    expect(createRequestFired).toBe(false)
  })
})

/**
 * Issue #126: a terminal token is shown exactly once, so the modal must not
 * discard it on a stray click or on a clipboard write that never succeeded.
 */
test.describe('Terminal token modal — one-time secret handling', () => {
  /**
   * Create a terminal and leave the token modal open on screen.
   */
  async function openTokenModal(settingsPage: SettingsPage): Promise<string> {
    await settingsPage.waitForLoad()
    await settingsPage.clickTerminalsTab()

    await settingsPage.clickCreateTerminalButton()
    await settingsPage.fillCreateTerminalForm(generateTestTerminal())
    await settingsPage.clickCreateTerminalConfirm()
    await settingsPage.waitForTokenModal()

    const token = await settingsPage.getGeneratedToken()
    expect(token).not.toBeNull()
    expect(token!.length).toBeGreaterThan(0)
    return token!
  }

  test('keeps the token when the backdrop is clicked', async ({ authenticatedSettingsPage }) => {
    const token = await openTokenModal(authenticatedSettingsPage)

    // Act: click outside the dialog, where the old backdrop handler used to close it
    await authenticatedSettingsPage.clickTokenModalBackdrop()

    // Assert: the secret is still on screen, unchanged
    await authenticatedSettingsPage.expectTokenModalVisible()
    expect(await authenticatedSettingsPage.getGeneratedToken()).toBe(token)

    // And the explicit acknowledgement still closes it
    await authenticatedSettingsPage.closeTokenModal()
    await authenticatedSettingsPage.expectTokenModalHidden()
  })

  test('confirms the copy and keeps the token until acknowledged', async ({
    authenticatedSettingsPage,
  }) => {
    await authenticatedSettingsPage.grantClipboardPermissions()
    const token = await openTokenModal(authenticatedSettingsPage)

    // Act: copy the token
    await authenticatedSettingsPage.copyTokenToClipboard()

    // Assert: the write is confirmed and the clipboard really holds the token
    await authenticatedSettingsPage.expectTokenCopyConfirmed()
    expect(await authenticatedSettingsPage.readClipboard()).toBe(token)

    // Assert: copying alone does not discard the secret
    await authenticatedSettingsPage.expectTokenModalVisible()
    expect(await authenticatedSettingsPage.getGeneratedToken()).toBe(token)

    await authenticatedSettingsPage.closeTokenModal()
    await authenticatedSettingsPage.expectTokenModalHidden()
  })

  test('keeps the token visible when the clipboard write fails', async ({
    authenticatedSettingsPage,
  }) => {
    const token = await openTokenModal(authenticatedSettingsPage)

    // Arrange: a clipboard that rejects, as on a non-secure origin
    await authenticatedSettingsPage.breakClipboard()

    // Act
    await authenticatedSettingsPage.copyTokenToClipboard()

    // Assert: the failure is reported and the token is still recoverable
    await authenticatedSettingsPage.expectTokenCopyFailed()
    await authenticatedSettingsPage.expectTokenModalVisible()
    expect(await authenticatedSettingsPage.getGeneratedToken()).toBe(token)
  })
})
