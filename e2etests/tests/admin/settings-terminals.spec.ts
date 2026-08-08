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
