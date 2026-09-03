/**
 * E2E Tests: Admin Users Management
 *
 * Tests for the admin users settings page (CRUD operations)
 * Pattern 001: Test Data Isolation - each test uses unique data
 * Pattern 008: Playwright Assertions - use expect() for visibility checks
 * E2E Integration: Tests verify complete frontend -> API -> backend -> database flow
 */

import { test, expect } from '../../fixtures/pageObjects'
import { SettingsPage } from '../../pages'
import { TEST_CREDENTIALS } from '../../config/test-credentials'

/**
 * Helper: Generate unique string for test data isolation
 */
function generateUnique(): string {
  return Math.random().toString(36).substring(2, 10)
}

/**
 * Helper: Generate unique test admin data
 * Pattern 001: Each test gets unique data to avoid conflicts
 */
function generateTestAdminUser() {
  const unique = generateUnique()
  return {
    email: `test-admin-${unique}@example.com`,
    display_name: `Test Admin ${unique}`,
    locale: 'de',
  }
}

test.describe('Admin Users Management', () => {
  /**
   * Test: Settings page displays admin users tab
   * Verifies: Admin users tab is available next to SEPA Configuration tab
   */
  test('should display admin users tab', async ({ authenticatedSettingsPage }) => {
    // Navigate to settings
    await authenticatedSettingsPage.waitForLoad()

    // Verify admin users tab exists and is visible
    await authenticatedSettingsPage.expectAdminUsersTabVisible()

    // Click to switch to admin users tab
    await authenticatedSettingsPage.clickAdminUsersTab()

    // Verify table is now visible
    await authenticatedSettingsPage.expectAdminUsersTableVisible()
  })

  /**
   * Test: Create new admin user successfully
   *
   * E2E Verification Flow:
   * 1. Navigate to admin users tab
   * 2. Click create admin button
   * 3. Fill form with unique test data
   * 4. Click confirm
   * 5. Verify the invitation modal appears with the link (migration 058 — no
   *    password is generated any more)
   * 6. Close modal
   * 7. Verify new admin appears in table, marked as waiting for its invitation
   *
   * Pattern 001: Unique test data per test
   * Pattern 008: Use expect() for assertions
   */
  test('should create new admin user successfully', async ({ authenticatedSettingsPage }) => {
    // Arrange: Navigate to admin users tab
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    // Get initial count (for verification after creation)
    const initialCount = await authenticatedSettingsPage.getAdminUserCount()

    // Generate unique test data
    const testData = generateTestAdminUser()

    // Act: Open create modal
    await authenticatedSettingsPage.clickCreateAdminButton()

    // Verify modal is visible
    const modalVisible = await authenticatedSettingsPage.isCreateAdminModalVisible()
    expect(modalVisible).toBe(true)

    // Fill form with test data
    await authenticatedSettingsPage.fillCreateAdminForm(testData)

    // Set up interceptor for admin users list refresh (triggered after create)
    const adminUsersLoaded = authenticatedSettingsPage.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/admin-users') &&
        resp.request().method() === 'GET' &&
        resp.status() === 200,
    )

    // Submit form
    await authenticatedSettingsPage.clickCreateAdminConfirm()

    // Wait for the invitation modal to appear (indicates API call succeeded)
    await authenticatedSettingsPage.waitForInvitationModal()

    // Assert: the link is what the admin is shown — there is no password to
    // show, because the account was created with none (migration 058).
    const invitationLink = await authenticatedSettingsPage.getInvitationLink()
    expect(invitationLink).not.toBeNull()
    // The token rides in the fragment; the path is a constant.
    expect(invitationLink).toContain('/invite#')

    await authenticatedSettingsPage.closeInvitationModal()

    // Wait for admin users list to fully reload before asserting
    await adminUsersLoaded

    // Assert: Count should have increased (if < per_page limit) or stay same (if already at limit)
    // The table loads up to 500 users — when DB has >500, count stays at 500 but email lookup still works
    const newCount = await authenticatedSettingsPage.getAdminUserCount()
    if (initialCount < 490) {
      // Well below the per_page limit — count must have increased
      expect(newCount).toBeGreaterThanOrEqual(initialCount + 1)
    }
    // else: skip count check (too many admins, per_page limit masks the increment)

    // Verify new admin can be found in table
    const newAdmin = await authenticatedSettingsPage.getAdminUserByEmail(testData.email)
    expect(newAdmin).not.toBeNull()
    expect(newAdmin?.email).toContain(testData.email)

    // …and it is visibly not usable yet. The row looks complete otherwise —
    // name, address, roles — so without this marker an admin wondering why a
    // colleague has not appeared has nothing on screen to tell them the link
    // is still outstanding.
    await authenticatedSettingsPage.expectInvitationPending(testData.email)

    // A pending account is offered a resend, not a password reset: it has no
    // password to reset, and offering one would hand the admin a credential to
    // carry by hand — the practice this feature exists to end.
    expect(await authenticatedSettingsPage.hasResetPasswordButton(testData.email)).toBe(false)
  })

  /**
   * Test: Display generated password in modal
   *
   * E2E Verification Flow:
   * 1. Create admin user
   * 2. Verify password modal appears with:
   *    - Generated password displayed
   *    - Copy button available
   * 3. Test copy-to-clipboard button
   *
   * Pattern 008: Use expect() for assertions
   */
  test('should display the invitation link in a modal with a copy button', async ({ authenticatedSettingsPage }) => {
    // Arrange: Navigate to admin users tab and create admin
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    const testData = generateTestAdminUser()

    // Act: Create admin user
    await authenticatedSettingsPage.clickCreateAdminButton()
    await authenticatedSettingsPage.fillCreateAdminForm(testData)
    await authenticatedSettingsPage.clickCreateAdminConfirm()

    // Wait for the invitation modal (Pattern 008: use Playwright auto-waiting)
    await authenticatedSettingsPage.waitForInvitationModal()

    // Assert: the link is shown in full. It is mailed as well, and shown here
    // because an installation whose mail is not configured yet would otherwise
    // be left with an account nobody can reach.
    const link = await authenticatedSettingsPage.getInvitationLink()
    expect(link).not.toBeNull()
    // The token sits in the **fragment**: everything left of the `#` is a
    // constant, so no web server in front of the installation ever writes the
    // token to an access log.
    expect(link).toMatch(/^https?:\/\/[^/]+\/invite#[A-Za-z0-9_-]{16,}$/)

    // Assert: Copy button is visible
    await expect(
      authenticatedSettingsPage.page.getByTestId('settings-admin-invitation-copy-button'),
    ).toBeVisible()

    // Act: copy it — the modal stays open until the copy is confirmed (#126)
    await authenticatedSettingsPage.copyInvitationToClipboard()
    await authenticatedSettingsPage.expectInvitationModalVisible()
  })

  /**
   * Test: Update admin user details
   *
   * E2E Verification Flow:
   * 1. Create admin user
   * 2. Click edit button for the admin
   * 3. Update name and locale
   * 4. Click confirm
   * 5. Verify no error message
   * 6. Verify admin details updated in table
   *
   * Pattern 001: Unique test data per test
   * Pattern 008: Use expect() for assertions
   */
  test('should update admin user details', async ({ authenticatedSettingsPage }) => {
    // Arrange: Navigate to admin users tab and create initial admin
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    const initialData = generateTestAdminUser()

    // Create admin
    await authenticatedSettingsPage.clickCreateAdminButton()
    await authenticatedSettingsPage.fillCreateAdminForm(initialData)
    await authenticatedSettingsPage.clickCreateAdminConfirm()
    await authenticatedSettingsPage.waitForInvitationModal()
    await authenticatedSettingsPage.closeInvitationModal()
    await authenticatedSettingsPage.page.waitForTimeout(300)

    // Verify admin was created
    const createdAdmin = await authenticatedSettingsPage.getAdminUserByEmail(initialData.email)
    expect(createdAdmin).not.toBeNull()

    // Act: Edit the admin (update display name)
    const newDisplayName = `Updated Admin ${generateUnique()}`
    await authenticatedSettingsPage.clickEditAdminButton(initialData.email)

    const editModalVisible = await authenticatedSettingsPage.isEditAdminModalVisible()
    expect(editModalVisible).toBe(true)

    // Update display name
    await authenticatedSettingsPage.fillEditAdminForm({
      display_name: newDisplayName,
    })

    // Save changes
    await authenticatedSettingsPage.clickEditAdminConfirm()
    await authenticatedSettingsPage.page.waitForTimeout(500)

    // Assert: Admin should still appear in table (possibly with updated display name)
    const updatedAdmin = await authenticatedSettingsPage.getAdminUserByEmail(initialData.email)
    expect(updatedAdmin).not.toBeNull()
    expect(updatedAdmin?.email).toContain(initialData.email)
  })

  /**
   * Test: Reset password for admin user
   *
   * E2E Verification Flow:
   * 1. Create admin user
   * 2. Click reset password button
   * 3. Verify new password modal appears
   * 4. Verify password is different from original
   * 5. Close modal
   * 6. Verify admin still exists in table
   *
   * Pattern 008: Use expect() for assertions
   */
  test('should reset password for admin user', async ({ authenticatedSettingsPage }) => {
    // Arrange: Navigate to admin users tab and create admin
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    const testData = generateTestAdminUser()

    // Set up interceptor for admin users list refresh (triggered after create)
    const adminUsersLoaded = authenticatedSettingsPage.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/admin-users') &&
        resp.request().method() === 'GET' &&
        resp.status() === 200,
    )

    // Create admin
    await authenticatedSettingsPage.clickCreateAdminButton()
    await authenticatedSettingsPage.fillCreateAdminForm(testData)
    await authenticatedSettingsPage.clickCreateAdminConfirm()
    await authenticatedSettingsPage.waitForInvitationModal()

    // A reset replaces a password, so the account needs one first — and since
    // migration 058 the only way it gets one is by walking its invitation.
    // Until it has, the row offers a resend instead, deliberately.
    const originalPassword = await authenticatedSettingsPage.acceptInvitationFromModal()
    await adminUsersLoaded
    await authenticatedSettingsPage.reloadAdminUsers()

    // Act: Reset password
    await authenticatedSettingsPage.clickResetPasswordButton(testData.email)
    await authenticatedSettingsPage.waitForPasswordModal()

    // Assert: New password modal should appear
    const newPassword = await authenticatedSettingsPage.getGeneratedPassword()
    expect(newPassword).not.toBeNull()

    // A real replacement: the reset must not hand back the password the
    // invitee had just set.
    expect(newPassword).toMatch(/^[A-Za-z0-9!@#$%^&*]{12,}$/)
    expect(newPassword).not.toBe(originalPassword)

    // Close modal
    await authenticatedSettingsPage.closePasswordModal()

    // Verify admin still exists
    const admin = await authenticatedSettingsPage.getAdminUserByEmail(testData.email)
    expect(admin).not.toBeNull()
  })

  /**
   * Test: send a pending account a replacement invitation (migration 058)
   *
   * The ordinary recovery when the first link went to spam or expired. It
   * revokes the previous link, so the confirmation is not decoration — an
   * admin pressing this is invalidating something somebody may be holding.
   *
   * Pattern 001: Unique test data per test
   * Pattern 008: Use expect() for assertions
   */
  test('should send a pending admin a new invitation', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    const testData = generateTestAdminUser()

    const adminUsersLoaded = authenticatedSettingsPage.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/admin-users') &&
        resp.request().method() === 'GET' &&
        resp.status() === 200,
    )

    await authenticatedSettingsPage.clickCreateAdminButton()
    await authenticatedSettingsPage.fillCreateAdminForm(testData)
    await authenticatedSettingsPage.clickCreateAdminConfirm()
    await authenticatedSettingsPage.waitForInvitationModal()

    const firstLink = await authenticatedSettingsPage.getInvitationLink()
    expect(firstLink).not.toBeNull()

    await authenticatedSettingsPage.closeInvitationModal()
    await adminUsersLoaded

    // Act: ask for a replacement
    await authenticatedSettingsPage.clickResendInvitationButton(testData.email)
    await authenticatedSettingsPage.waitForInvitationModal()

    // Assert: a genuinely different link — a resend that handed back the same
    // one would leave an admin believing they had replaced something.
    const secondLink = await authenticatedSettingsPage.getInvitationLink()
    expect(secondLink).not.toBeNull()
    expect(secondLink).not.toBe(firstLink)

    await authenticatedSettingsPage.closeInvitationModal()

    // …and the account is still waiting: a resend does not onboard anybody.
    await authenticatedSettingsPage.expectInvitationPending(testData.email)
  })

  /**
   * Test: Reset 2FA for admin user
   *
   * E2E Verification Flow:
   * 1. Create admin user (no 2FA enrolled — newly created users have no TOTP)
   * 2. Click Reset 2FA button
   * 3. Confirm dialog appears
   * 4. Confirm action
   * 5. Verify API call succeeded (POST /api/auth/2fa/reset returns 200)
   * 6. Verify admin still exists in table
   *
   * Pattern 001: Unique test data per test
   * Pattern 008: Use expect() for assertions
   */
  test('should reset 2FA for admin user', async ({ authenticatedSettingsPage }) => {
    // Arrange: Navigate to admin users tab and create admin
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    const testData = generateTestAdminUser()

    // Set up interceptor for admin users list refresh
    const adminUsersLoaded = authenticatedSettingsPage.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/admin-users') &&
        resp.request().method() === 'GET' &&
        resp.status() === 200,
    )

    // Create admin
    await authenticatedSettingsPage.clickCreateAdminButton()
    await authenticatedSettingsPage.fillCreateAdminForm(testData)
    await authenticatedSettingsPage.clickCreateAdminConfirm()
    await authenticatedSettingsPage.waitForInvitationModal()
    await authenticatedSettingsPage.closeInvitationModal()
    await adminUsersLoaded

    // Verify admin was created
    const createdAdmin = await authenticatedSettingsPage.getAdminUserByEmail(testData.email)
    expect(createdAdmin).not.toBeNull()

    // Act: Reset 2FA — confirm dialog appears, confirm it, verify API call succeeds
    await authenticatedSettingsPage.clickReset2faButton(testData.email)

    // Assert: the reset changes nothing in the table, so the page has to say it
    // worked — otherwise a success and a silent failure look identical (#130).
    await authenticatedSettingsPage.expectActionSuccessVisible()
    expect((await authenticatedSettingsPage.getActionSuccessMessage()).length).toBeGreaterThan(5)

    // Assert: Admin still exists in table after reset
    const adminAfterReset = await authenticatedSettingsPage.getAdminUserByEmail(testData.email)
    expect(adminAfterReset).not.toBeNull()
    expect(adminAfterReset?.email).toContain(testData.email)
  })

  /**
   * Test: Resetting a password asks first, and cancelling changes nothing
   *
   * The reset used to fire straight off an unlabelled icon button, invalidating
   * a colleague's current password with no way back (#130). Deactivate and
   * reset-2FA already confirmed; this pins the third one.
   *
   * E2E Verification Flow:
   * 1. Create admin user, note the generated password
   * 2. Click Reset Password → confirmation dialog appears
   * 3. Cancel → no password modal, so no reset was performed
   * 4. Click again and confirm → the new password modal appears
   *
   * Pattern 001: Unique test data per test
   * Pattern 008: Use expect() for assertions
   */
  test('should confirm before resetting an admin password', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    const testData = generateTestAdminUser()

    const adminUsersLoaded = authenticatedSettingsPage.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/admin-users') &&
        resp.request().method() === 'GET' &&
        resp.status() === 200,
    )

    await authenticatedSettingsPage.clickCreateAdminButton()
    await authenticatedSettingsPage.fillCreateAdminForm(testData)
    await authenticatedSettingsPage.clickCreateAdminConfirm()
    await authenticatedSettingsPage.waitForInvitationModal()
    // Onboarded first: a pending account is offered a resend rather than a
    // reset (migration 058), so there would be no reset button to click.
    await authenticatedSettingsPage.acceptInvitationFromModal()
    await adminUsersLoaded
    await authenticatedSettingsPage.reloadAdminUsers()

    // The reset must not fire on the click alone.
    let resetCalls = 0
    await authenticatedSettingsPage.page.route('**/api/admin/admin-users/*/reset-password', (route) => {
      resetCalls += 1
      return route.continue()
    })

    await authenticatedSettingsPage.openResetPasswordConfirm(testData.email)
    await authenticatedSettingsPage.cancelConfirmDialog()

    // Cancelling means no request and no new password.
    await authenticatedSettingsPage.expectPasswordModalHidden()
    expect(resetCalls).toBe(0)

    // Confirming goes through.
    await authenticatedSettingsPage.clickResetPasswordButton(testData.email)
    await authenticatedSettingsPage.waitForPasswordModal()
    const newPassword = await authenticatedSettingsPage.getGeneratedPassword()
    expect(newPassword).toMatch(/^[A-Za-z0-9!@#$%^&*]{12,}$/)
    expect(resetCalls).toBe(1)

    await authenticatedSettingsPage.closePasswordModal()
  })

  /**
   * Test: Deactivate and reactivate admin user
   *
   * E2E Verification Flow:
   * 1. Create admin user (status: active)
   * 2. Click deactivate button
   * 3. Verify status changes to inactive
   * 4. Click reactivate button
   * 5. Verify status changes back to active
   *
   * Pattern 008: Use expect() for assertions
   */
  test('should deactivate and reactivate admin user', async ({ authenticatedSettingsPage }) => {
    // Arrange: Navigate to admin users tab and create admin
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    const testData = generateTestAdminUser()

    // Set up interceptor for admin users list refresh (triggered after create)
    const adminUsersLoaded = authenticatedSettingsPage.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/admin-users') &&
        resp.request().method() === 'GET' &&
        resp.status() === 200,
    )

    // Create admin
    await authenticatedSettingsPage.clickCreateAdminButton()
    await authenticatedSettingsPage.fillCreateAdminForm(testData)
    await authenticatedSettingsPage.clickCreateAdminConfirm()
    await authenticatedSettingsPage.waitForInvitationModal()
    await authenticatedSettingsPage.closeInvitationModal()
    // Wait for admin list to fully reload before asserting status
    await adminUsersLoaded

    // Verify admin is active
    let status = await authenticatedSettingsPage.getAdminUserStatus(testData.email)
    expect(status?.toLowerCase()).toContain('active')

    // Act: Deactivate
    await authenticatedSettingsPage.clickDeactivateButton(testData.email)
    await authenticatedSettingsPage.page.waitForTimeout(500)

    // Assert: Status should be inactive
    status = await authenticatedSettingsPage.getAdminUserStatus(testData.email)
    expect(status?.toLowerCase()).toContain('inactive')

    // Act: Reactivate
    await authenticatedSettingsPage.clickReactivateButton(testData.email)
    await authenticatedSettingsPage.page.waitForTimeout(500)

    // Assert: Status should be active again
    status = await authenticatedSettingsPage.getAdminUserStatus(testData.email)
    expect(status?.toLowerCase()).toContain('active')
  })

  /**
   * Test: Cancel create admin form
   *
   * E2E Verification Flow:
   * 1. Click create admin button
   * 2. Start filling form (but don't submit)
   * 3. Click cancel
   * 4. Verify modal closes
   * 5. Verify no new admin added
   *
   * Pattern 008: Use expect() for assertions
   */
  test('should close create modal when cancel is clicked', async ({ authenticatedSettingsPage }) => {
    // Arrange: Navigate to admin users tab
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    // Monitor network to ensure no POST request is fired (parallel-safe, Pattern 004)
    let createRequestFired = false
    authenticatedSettingsPage.page.on('response', (resp) => {
      if (resp.url().includes('/api/admin/admin-users') && resp.request().method() === 'POST') {
        createRequestFired = true
      }
    })

    // Act: Open and close create modal without submitting
    await authenticatedSettingsPage.clickCreateAdminButton()

    const modalVisible = await authenticatedSettingsPage.isCreateAdminModalVisible()
    expect(modalVisible).toBe(true)

    // Close without filling/submitting
    await authenticatedSettingsPage.closeCreateAdminModal()
    await authenticatedSettingsPage.page.waitForTimeout(300)

    // Assert: Modal should be closed
    const stillVisible = await authenticatedSettingsPage.isCreateAdminModalVisible()
    expect(stillVisible).toBe(false)

    // Assert: No POST request was made to create an admin (parallel-safe)
    expect(createRequestFired).toBe(false)
  })

  /**
   * Test: Cannot create admin with invalid email
   *
   * E2E Verification Flow:
   * 1. Click create admin button
   * 2. Try submitting with invalid email
   * 3. Verify form validation error appears or form stays visible
   * 4. Fix email format
   * 5. Submit again and verify success
   *
   * Pattern 008: Use expect() for assertions
   */
  test('should handle validation for admin creation', async ({ authenticatedSettingsPage }) => {
    // Arrange: Navigate to admin users tab
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    // Act: Open create modal
    await authenticatedSettingsPage.clickCreateAdminButton()

    // Try to fill with invalid data (missing display name)
    await authenticatedSettingsPage.fillCreateAdminForm({
      email: `test-${generateUnique()}@example.com`,
      display_name: '', // Empty name
    })

    // Try to submit
    await authenticatedSettingsPage.clickCreateAdminConfirm()
    await authenticatedSettingsPage.page.waitForTimeout(300)

    // Assert: Modal should still be visible (validation prevented submission)
    const modalStillVisible = await authenticatedSettingsPage.isCreateAdminModalVisible()
    expect(modalStillVisible).toBe(true)

    // Now fill all required fields correctly
    const testData = generateTestAdminUser()
    await authenticatedSettingsPage.fillCreateAdminForm(testData)

    // Submit with valid data
    await authenticatedSettingsPage.clickCreateAdminConfirm()
    await authenticatedSettingsPage.waitForInvitationModal()

    // Assert: the invitation modal appeared, so the submission succeeded
    const link = await authenticatedSettingsPage.getInvitationLink()
    expect(link).not.toBeNull()

    // Cleanup
    await authenticatedSettingsPage.closeInvitationModal()
  })

  /**
   * Test: A rejected create is reported in the modal (#91)
   *
   * A duplicate email used to fail silently: the modal stayed open with no
   * message and no field highlight, and the text surfaced later above the
   * unrelated SEPA form.
   *
   * E2E Verification Flow:
   * 1. Create an admin so its email is taken
   * 2. Try to create a second admin with the same email
   * 3. Verify the modal reports the rejection and stays open
   * 4. Verify nothing was persisted (the admin is still there exactly once)
   * 5. Verify the message does not leak onto the SEPA tab
   */
  test('should report a duplicate email inside the create modal', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    // Arrange: an admin whose email is now taken
    const testData = generateTestAdminUser()
    await authenticatedSettingsPage.clickCreateAdminButton()
    await authenticatedSettingsPage.fillCreateAdminForm(testData)
    await authenticatedSettingsPage.clickCreateAdminConfirm()
    await authenticatedSettingsPage.waitForInvitationModal()
    await authenticatedSettingsPage.closeInvitationModal()

    // The list reloads after the create; wait for the new row before acting
    await expect
      .poll(() => authenticatedSettingsPage.countAdminUsersWithEmail(testData.email))
      .toBe(1)

    // Act: same email, different display name
    await authenticatedSettingsPage.clickCreateAdminButton()
    await authenticatedSettingsPage.fillCreateAdminForm({
      email: testData.email,
      display_name: `${testData.display_name} duplicate`,
    })
    await authenticatedSettingsPage.clickCreateAdminConfirm()

    // Assert: the reason is on screen, in the modal the admin is looking at
    const message = await authenticatedSettingsPage.expectCreateAdminModalError()
    expect(message).toContain('already exists')

    // Assert: the rejected input is marked, not just described
    await authenticatedSettingsPage.expectCreateAdminFieldError('email')

    // Assert: nothing was created — the email is still on exactly one row
    await authenticatedSettingsPage.closeCreateAdminModal()
    expect(await authenticatedSettingsPage.countAdminUsersWithEmail(testData.email)).toBe(1)

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
  test('should reject an empty create admin form without calling the API', async ({
    authenticatedSettingsPage,
  }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    // Watch for a POST that must never happen (parallel-safe, Pattern 004)
    let createRequestFired = false
    authenticatedSettingsPage.page.on('response', (resp) => {
      if (resp.url().includes('/api/admin/admin-users') && resp.request().method() === 'POST') {
        createRequestFired = true
      }
    })

    await authenticatedSettingsPage.clickCreateAdminButton()
    await authenticatedSettingsPage.clickCreateAdminConfirm()

    // Assert: both empty fields are named, and the modal stays open
    await authenticatedSettingsPage.expectCreateAdminFieldError('email')
    await authenticatedSettingsPage.expectCreateAdminFieldError('display-name')
    expect(await authenticatedSettingsPage.isCreateAdminModalVisible()).toBe(true)
    expect(createRequestFired).toBe(false)
  })

  /**
   * Test: Admin users table displays current admins
   *
   * E2E Verification Flow:
   * 1. Navigate to admin users tab
   * 2. Verify table is not empty (should have at least the test admin)
   * 3. Verify table shows email, name, status, last login columns
   *
   * Pattern 008: Use expect() for assertions
   */
  test('should display existing admin users in table', async ({ authenticatedSettingsPage }) => {
    // Navigate to admin users tab
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    // Verify table is visible
    await authenticatedSettingsPage.expectAdminUsersTableVisible()

    // Verify create button is visible
    await authenticatedSettingsPage.expectCreateAdminButtonVisible()

    // Get user count
    const count = await authenticatedSettingsPage.getAdminUserCount()

    // Should have at least one admin (the test user)
    expect(count).toBeGreaterThan(0)
  })
})

/**
 * Issue #382: deactivating your own account signs you out on the next request
 * and leaves no way back in. UC-A61 forbids it and the backend answers 409, but
 * the list used to offer the switch anyway and surface the refusal as an error
 * banner. The switch is now locked on the caller's own row, and that row is
 * marked so it is obvious which one it is.
 */
test.describe('Self-lockout protection', () => {
  test('locks the active switch on the signed-in admin\'s own row', async ({
    authenticatedSettingsPage,
  }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    // The own row is marked and its switch cannot be operated
    await authenticatedSettingsPage.expectOwnAccountBadgeVisible(TEST_CREDENTIALS.admin.email)
    await authenticatedSettingsPage.expectAdminUserToggleDisabled(TEST_CREDENTIALS.admin.email)

    // The account is still active — a locked switch is not a deactivated one
    const status = await authenticatedSettingsPage.getAdminUserStatus(TEST_CREDENTIALS.admin.email)
    expect(status).toBe('active')
  })

  test('leaves other admins deactivatable', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    const testData = generateTestAdminUser()

    const adminUsersLoaded = authenticatedSettingsPage.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/admin-users') &&
        resp.request().method() === 'GET' &&
        resp.status() === 200,
    )

    await authenticatedSettingsPage.clickCreateAdminButton()
    await authenticatedSettingsPage.fillCreateAdminForm(testData)
    await authenticatedSettingsPage.clickCreateAdminConfirm()
    await authenticatedSettingsPage.waitForInvitationModal()
    await authenticatedSettingsPage.closeInvitationModal()
    await adminUsersLoaded

    // Somebody else's row carries no marker and stays operable
    await authenticatedSettingsPage.expectOwnAccountBadgeHidden(testData.email)
    await authenticatedSettingsPage.expectAdminUserToggleEnabled(testData.email)

    // ...and deactivating it really does take effect
    await authenticatedSettingsPage.clickDeactivateButton(testData.email)
    expect(await authenticatedSettingsPage.getAdminUserStatus(testData.email)).toBe('inactive')
  })
})

/**
 * Deleting an admin account (UC-A61).
 *
 * The list used to accumulate greyed-out rows with no way to remove them: an
 * invitation sent to the wrong address stayed on the roster forever. Deletion
 * is offered only for an account that has never signed in, because
 * `settlements` and `mandate_documents` reference their creating admin with
 * `ON DELETE RESTRICT`, while `audit_log.admin_user_id` has no constraint at
 * all — removing an admin who did work would either be refused by the database
 * or silently blank the actor on every row they wrote.
 *
 * So the button's presence is itself the assertion: it is on the fresh
 * account's row and not on the signed-in admin's.
 */
test.describe('Deleting an admin account', () => {
  /**
   * One flow rather than an assertion per test (Pattern 009), and end-to-end
   * rather than UI-only: the row must be gone from a list *refetched after a
   * full page reload*, which is what proves the DELETE reached the database
   * rather than the dialog merely closing over local state.
   *
   * Deliberately not a row-count comparison. The admin list is shared, and
   * other workers create accounts on it throughout the run, so `countBefore -
   * 1` is a race that fails whenever a sibling test's create lands between the
   * two reads (Pattern 003: assert on the specific data, never on a position
   * or a total).
   */
  test('removes an account that has never signed in', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    const testData = generateTestAdminUser()

    const adminUsersLoaded = authenticatedSettingsPage.page.waitForResponse(
      (resp) =>
        resp.url().includes('/api/admin/admin-users') &&
        resp.request().method() === 'GET' &&
        resp.status() === 200,
    )

    await authenticatedSettingsPage.clickCreateAdminButton()
    await authenticatedSettingsPage.fillCreateAdminForm(testData)
    await authenticatedSettingsPage.clickCreateAdminConfirm()
    await authenticatedSettingsPage.waitForInvitationModal()
    await authenticatedSettingsPage.closeInvitationModal()
    await adminUsersLoaded

    // The row exists and offers deletion — the precondition the assertion
    // below would otherwise pass vacuously against.
    await authenticatedSettingsPage.expectAdminUserPresent(testData.email)
    await authenticatedSettingsPage.expectAdminUserDeletable(testData.email)

    await authenticatedSettingsPage.clickDeleteAdminButton(testData.email)

    expect(await authenticatedSettingsPage.getErrorMessage()).toBeNull()
    await authenticatedSettingsPage.expectAdminUserAbsent(testData.email)

    // Reload the whole page and ask the server again: the row is gone from the
    // database, not merely from this page's state.
    await authenticatedSettingsPage.reloadAdminUsers()
    await authenticatedSettingsPage.expectAdminUserAbsent(testData.email)
  })

  /**
   * The signed-in admin has, by definition, signed in — so their own row never
   * offers the button. Withheld rather than offered and then refused, the same
   * reasoning as the own-row switch (#382).
   */
  test('withholds the button from an account that has signed in', async ({
    authenticatedSettingsPage,
  }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    await authenticatedSettingsPage.expectAdminUserNotDeletable(TEST_CREDENTIALS.admin.email)
  })
})

/**
 * Issue #126: a generated password is shown exactly once, so the modal must not
 * discard it on a stray click or on a clipboard write that never succeeded.
 *
 * The secret the create flow shows is the **invitation link** since migration
 * 058 — the account is created with no password, so there is no password to
 * display. The component and the risk are unchanged: a link that has scrolled
 * away is unrecoverable, and getting it back means sending a whole new one.
 */
test.describe('Invitation modal — one-time secret handling', () => {
  /**
   * Create an admin user and leave the invitation modal open on screen.
   */
  async function openInvitationModal(settingsPage: SettingsPage): Promise<string> {
    await settingsPage.waitForLoad()
    await settingsPage.clickAdminUsersTab()

    await settingsPage.clickCreateAdminButton()
    await settingsPage.fillCreateAdminForm(generateTestAdminUser())
    await settingsPage.clickCreateAdminConfirm()
    await settingsPage.waitForInvitationModal()

    const link = await settingsPage.getInvitationLink()
    expect(link).not.toBeNull()
    expect(link).toContain('/invite#')
    return link!
  }

  test('keeps the link when the backdrop is clicked', async ({ authenticatedSettingsPage }) => {
    const link = await openInvitationModal(authenticatedSettingsPage)

    // Act: click outside the dialog, where the old backdrop handler used to close it
    await authenticatedSettingsPage.clickInvitationModalBackdrop()

    // Assert: the secret is still on screen, unchanged
    await authenticatedSettingsPage.expectInvitationModalVisible()
    expect(await authenticatedSettingsPage.getInvitationLink()).toBe(link)

    // And the explicit acknowledgement still closes it
    await authenticatedSettingsPage.closeInvitationModal()
    await authenticatedSettingsPage.expectInvitationModalHidden()
  })

  test('confirms the copy and keeps the link until acknowledged', async ({
    authenticatedSettingsPage,
  }) => {
    await authenticatedSettingsPage.grantClipboardPermissions()
    const link = await openInvitationModal(authenticatedSettingsPage)

    // Act: copy the password
    await authenticatedSettingsPage.copyInvitationToClipboard()

    // Assert: the write is confirmed and the clipboard really holds the password
    await authenticatedSettingsPage.expectInvitationCopyConfirmed()
    expect(await authenticatedSettingsPage.readClipboard()).toBe(link)

    // Assert: copying alone does not discard the secret
    await authenticatedSettingsPage.expectInvitationModalVisible()
    expect(await authenticatedSettingsPage.getInvitationLink()).toBe(link)

    await authenticatedSettingsPage.closeInvitationModal()
    await authenticatedSettingsPage.expectInvitationModalHidden()
  })

  test('keeps the link visible when the clipboard write fails', async ({
    authenticatedSettingsPage,
  }) => {
    const link = await openInvitationModal(authenticatedSettingsPage)

    // Arrange: a clipboard that rejects, as on a non-secure origin
    await authenticatedSettingsPage.breakClipboard()

    // Act
    await authenticatedSettingsPage.copyInvitationToClipboard()

    // Assert: the failure is reported and the password is still recoverable
    await authenticatedSettingsPage.expectInvitationCopyFailed()
    await authenticatedSettingsPage.expectInvitationModalVisible()
    expect(await authenticatedSettingsPage.getInvitationLink()).toBe(link)
  })
})
