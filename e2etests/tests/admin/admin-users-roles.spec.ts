/**
 * E2E Tests: Admin role assignment (ADR-0044, #548)
 *
 * Covers what the settings-admin-users.spec.ts suite left out: creating an
 * admin with a specific role, reassigning an existing admin's role, and the
 * two guard rails a role-editing UI needs — admin-exclusivity in the
 * checkbox group, and a caller being unable to touch their own roles.
 *
 * Pattern 001: unique test data per test
 * Pattern 008: expect() over manual visibility checks
 */

import { test, expect } from '../../fixtures/pageObjects'
import { TEST_CREDENTIALS } from '../../config/test-credentials'

function generateUnique(): string {
  return Math.random().toString(36).substring(2, 10)
}

function generateTestAdminUser() {
  const unique = generateUnique()
  return {
    email: `test-role-admin-${unique}@example.com`,
    display_name: `Test Role Admin ${unique}`,
  }
}

test.describe('Creating an admin with a specific role', () => {
  test('creating with Kassenwart persists and displays that role', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    const testData = generateTestAdminUser()

    await authenticatedSettingsPage.clickCreateAdminButton()
    await authenticatedSettingsPage.fillCreateAdminForm({ ...testData, roles: ['kassenwart'] })
    await authenticatedSettingsPage.clickCreateAdminConfirm()
    await authenticatedSettingsPage.waitForPasswordModal()
    await authenticatedSettingsPage.closePasswordModal()

    await expect.poll(() => authenticatedSettingsPage.countAdminUsersWithEmail(testData.email)).toBe(1)

    const roles = await authenticatedSettingsPage.getAdminUserRoles(testData.email)
    expect(roles).toEqual(['Kassenwart'])
  })

  test('creating with both lesser roles persists both', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    const testData = generateTestAdminUser()

    await authenticatedSettingsPage.clickCreateAdminButton()
    await authenticatedSettingsPage.fillCreateAdminForm({
      ...testData,
      roles: ['kassenwart', 'getraenkewart'],
    })
    await authenticatedSettingsPage.clickCreateAdminConfirm()
    await authenticatedSettingsPage.waitForPasswordModal()
    await authenticatedSettingsPage.closePasswordModal()

    await expect.poll(() => authenticatedSettingsPage.countAdminUsersWithEmail(testData.email)).toBe(1)

    const roles = await authenticatedSettingsPage.getAdminUserRoles(testData.email)
    expect(roles.sort()).toEqual(['Getränkewart', 'Kassenwart'])
  })

  /**
   * Admin-exclusivity in the checkbox group itself: checking Admin after
   * Kassenwart was already checked must drop Kassenwart, not add to it — the
   * UI half of `AdminUsersService::applyRoles`'s server-side rule.
   */
  test('checking Admin clears a previously checked lesser role', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()
    await authenticatedSettingsPage.clickCreateAdminButton()

    const page = authenticatedSettingsPage.page
    await page.getByTestId('settings-admin-create-role-checkbox-kassenwart').click()
    await expect(page.getByTestId('settings-admin-create-role-checkbox-kassenwart')).toBeChecked()

    await page.getByTestId('settings-admin-create-role-checkbox-admin').click()

    await expect(page.getByTestId('settings-admin-create-role-checkbox-admin')).toBeChecked()
    await expect(page.getByTestId('settings-admin-create-role-checkbox-kassenwart')).not.toBeChecked()

    await authenticatedSettingsPage.closeCreateAdminModal()
  })

  /**
   * No default is pre-checked (Q3 of the role-assignment design session):
   * submitting with nothing picked is caught client-side, the same as an
   * empty email, before any request goes out.
   */
  test('submitting with no role selected is rejected without calling the API', async ({
    authenticatedSettingsPage,
  }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    let createRequestFired = false
    authenticatedSettingsPage.page.on('response', (resp) => {
      if (resp.url().includes('/api/admin/admin-users') && resp.request().method() === 'POST') {
        createRequestFired = true
      }
    })

    const testData = generateTestAdminUser()
    await authenticatedSettingsPage.clickCreateAdminButton()
    await authenticatedSettingsPage.page.getByTestId('settings-admin-create-email').fill(testData.email)
    await authenticatedSettingsPage.page
      .getByTestId('settings-admin-create-display-name')
      .fill(testData.display_name)
    // Deliberately no role checkbox click.
    await authenticatedSettingsPage.clickCreateAdminConfirm()

    await expect(authenticatedSettingsPage.page.getByTestId('settings-admin-create-role-error')).toBeVisible()
    expect(await authenticatedSettingsPage.isCreateAdminModalVisible()).toBe(true)
    expect(createRequestFired).toBe(false)
  })
})

test.describe('Reassigning an existing admin\'s role', () => {
  test('editing someone else\'s role asks for step-up and persists the new role', async ({
    authenticatedSettingsPage,
  }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    // Arrange: an admin created as a Kassenwart.
    const testData = generateTestAdminUser()
    await authenticatedSettingsPage.clickCreateAdminButton()
    await authenticatedSettingsPage.fillCreateAdminForm({ ...testData, roles: ['kassenwart'] })
    await authenticatedSettingsPage.clickCreateAdminConfirm()
    await authenticatedSettingsPage.waitForPasswordModal()
    await authenticatedSettingsPage.closePasswordModal()
    await expect.poll(() => authenticatedSettingsPage.countAdminUsersWithEmail(testData.email)).toBe(1)

    // Act: reassign to Getränkewart. Unchecking kassenwart then checking
    // getraenkewart is two clicks in the Edit modal's checkbox group.
    await authenticatedSettingsPage.clickEditAdminButton(testData.email)
    await authenticatedSettingsPage.fillEditAdminForm({ toggleRole: 'kassenwart' })
    await authenticatedSettingsPage.fillEditAdminForm({ toggleRole: 'getraenkewart' })

    // The step-up fields only appear once the role selection actually moved.
    await expect(authenticatedSettingsPage.page.getByTestId('step-up-password')).toBeVisible()
    await authenticatedSettingsPage.fillEditAdminStepUpCredential()

    await authenticatedSettingsPage.clickEditAdminConfirm()

    const roles = await authenticatedSettingsPage.getAdminUserRoles(testData.email)
    expect(roles).toEqual(['Getränkewart'])
  })

  /**
   * Editing a field that is not `roles` (display name) must not suddenly
   * demand a password — the step-up gate is conditional on the role set
   * actually changing, mirroring the backend's `rolesWouldChange` check.
   */
  test('editing only the display name asks for no step-up credential', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    const testData = generateTestAdminUser()
    await authenticatedSettingsPage.clickCreateAdminButton()
    await authenticatedSettingsPage.fillCreateAdminForm({ ...testData, roles: ['getraenkewart'] })
    await authenticatedSettingsPage.clickCreateAdminConfirm()
    await authenticatedSettingsPage.waitForPasswordModal()
    await authenticatedSettingsPage.closePasswordModal()
    await expect.poll(() => authenticatedSettingsPage.countAdminUsersWithEmail(testData.email)).toBe(1)

    const newName = `Renamed ${generateUnique()}`
    await authenticatedSettingsPage.clickEditAdminButton(testData.email)
    await authenticatedSettingsPage.fillEditAdminForm({ display_name: newName })

    await expect(authenticatedSettingsPage.page.getByTestId('step-up-password')).not.toBeVisible()

    await authenticatedSettingsPage.clickEditAdminConfirm()

    // The role, untouched, survives the save.
    expect(await authenticatedSettingsPage.getAdminUserRoles(testData.email)).toEqual(['Getränkewart'])
  })
})

/**
 * CONTEXT.md's Role entry: no account can change its own role set, Admin
 * included. `TEST_CREDENTIALS.admin` is the signed-in caller throughout this
 * suite (see settings-admin-users.spec.ts's "Self-lockout protection" for the
 * equivalent on the active-toggle).
 */
test.describe('An admin cannot change their own roles', () => {
  test('the role selector is locked on the signed-in admin\'s own edit form', async ({
    authenticatedSettingsPage,
  }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    await authenticatedSettingsPage.clickEditAdminButton(TEST_CREDENTIALS.admin.email)

    for (const role of ['admin', 'kassenwart', 'getraenkewart'] as const) {
      expect(await authenticatedSettingsPage.isEditAdminRoleDisabled(role)).toBe(true)
    }
    await expect(
      authenticatedSettingsPage.page.getByTestId('settings-admin-edit-role-disabled-reason'),
    ).toBeVisible()

    await authenticatedSettingsPage.closeEditAdminModal()
  })

  test('someone else\'s role selector stays editable', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickAdminUsersTab()

    const testData = generateTestAdminUser()
    await authenticatedSettingsPage.clickCreateAdminButton()
    await authenticatedSettingsPage.fillCreateAdminForm({ ...testData, roles: ['kassenwart'] })
    await authenticatedSettingsPage.clickCreateAdminConfirm()
    await authenticatedSettingsPage.waitForPasswordModal()
    await authenticatedSettingsPage.closePasswordModal()
    await expect.poll(() => authenticatedSettingsPage.countAdminUsersWithEmail(testData.email)).toBe(1)

    await authenticatedSettingsPage.clickEditAdminButton(testData.email)
    expect(await authenticatedSettingsPage.isEditAdminRoleDisabled('kassenwart')).toBe(false)

    await authenticatedSettingsPage.closeEditAdminModal()
  })
})
