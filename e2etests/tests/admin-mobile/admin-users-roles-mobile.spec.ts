/**
 * Admin Panel - Admin role assignment, mobile layout (ADR-0044, #548)
 *
 * The create/edit admin dialogs and the role badges on the admin list must
 * work on the mobile card layout the same as the desktop table
 * (admin-users-roles.spec.ts covers desktop). Uses raw locators rather than
 * `SettingsPage`'s row-lookup helpers, which are built around the desktop
 * table's `settings-admin-user-row-*` wrapper — the mobile card carries its
 * own `admin-user-card-*` wrapper instead (see mobile-responsive.spec.ts for
 * the same convention on other list pages).
 *
 * Project config uses `devices['iPhone 14']` (390x844).
 */

import { test, expect } from '@playwright/test'
import { TEST_CREDENTIALS } from '../../config/test-credentials'
import { generateTotp } from '../../utils/totp'

function generateUnique(): string {
  return Math.random().toString(36).substring(2, 10)
}

async function openAdminUsersTab(page: import('@playwright/test').Page) {
  await page.goto('/settings')
  await page.getByTestId('settings-tab-admin-users').click()
  await page.getByTestId('settings-admin-users-mobile-cards').waitFor({ state: 'visible', timeout: 10000 })
}

async function fillStepUp(page: import('@playwright/test').Page) {
  await page.getByTestId('step-up-password').fill(TEST_CREDENTIALS.admin.password)
  const totpField = page.getByTestId('step-up-totp-code')
  if (await totpField.count() > 0) {
    await totpField.fill(generateTotp(TEST_CREDENTIALS.totp.adminSecret))
  }
}

test.describe('Admin role assignment — mobile', () => {
  test('create modal renders the role selector as a scrollable card, and the role badge shows on the mobile list', async ({
    page,
  }) => {
    await openAdminUsersTab(page)

    const unique = generateUnique()
    const email = `test-role-mobile-${unique}@example.com`

    await page.getByTestId('settings-admin-create-button').click()
    await page.getByTestId('settings-admin-create-modal').waitFor({ state: 'visible' })

    await page.getByTestId('settings-admin-create-email').fill(email)
    await page.getByTestId('settings-admin-create-display-name').fill(`Test Role Mobile ${unique}`)

    // The selector and both its options are reachable without the dialog
    // clipping them off-screen at 390px wide.
    await expect(page.getByTestId('settings-admin-create-role')).toBeVisible()
    await page.getByTestId('settings-admin-create-role-option-kassenwart').click()
    await expect(page.getByTestId('settings-admin-create-role-checkbox-kassenwart')).toBeChecked()

    await fillStepUp(page)
    await page.getByTestId('settings-admin-create-confirm-button').click()

    // The invitation modal, not a password one: an account is created with no
    // password at all (migration 058).
    await page.getByTestId('settings-admin-invitation-modal').waitFor({ state: 'visible', timeout: 10000 })
    await page.getByTestId('settings-admin-invitation-close-button').click()

    // Find the new admin's mobile card by its email, scoped so the role
    // badge lookup cannot pick up another card's badge.
    const card = page
      .locator('[data-testid^="admin-user-card-"]')
      .filter({ has: page.locator(`[data-testid^="settings-admin-user-email-"]:text-is("${email}")`) })
    await expect(card).toBeVisible({ timeout: 10000 })
    await expect(card.locator('[data-testid$="-kassenwart"][data-testid^="settings-admin-user-role-badge-"]')).toHaveText(
      'Kassenwart',
    )
  })

  /**
   * CONTEXT.md's Role entry: no account can change its own role set. Same
   * rule as the desktop suite's "self-lockout protection" tests, checked here
   * because the Edit modal's layout — and therefore whether the disabled
   * state and its explanation are actually visible without extra scrolling —
   * differs at 390px wide.
   */
  test('own role selector is disabled with an explanation, on the edit dialog opened from a mobile card', async ({
    page,
  }) => {
    await openAdminUsersTab(page)

    const ownCard = page
      .locator('[data-testid^="admin-user-card-"]')
      .filter({
        has: page.locator(
          `[data-testid^="settings-admin-user-email-"]:text-is("${TEST_CREDENTIALS.admin.email}")`,
        ),
      })
    await expect(ownCard).toBeVisible()

    const editButton = ownCard.locator('[data-testid^="settings-admin-edit-button-"]')
    await editButton.click()
    await page.getByTestId('settings-admin-edit-modal').waitFor({ state: 'visible' })

    for (const role of ['admin', 'kassenwart', 'getraenkewart']) {
      await expect(page.getByTestId(`settings-admin-edit-role-checkbox-${role}`)).toBeDisabled()
    }
    await expect(page.getByTestId('settings-admin-edit-role-disabled-reason')).toBeVisible()

    await page.getByTestId('settings-admin-edit-cancel-button').click()
  })
})
