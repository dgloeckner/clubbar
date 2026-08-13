/**
 * E2E Tests: Security & Credentials (#394, ADR-0036)
 *
 * IBANs are sealed with a key that expires after a year and that the club — not
 * the server — has to replace. This page is what makes that manageable, so
 * these tests follow the whole path: open the tab, the SPA calls
 * `GET /api/admin/encryption-keys`, the backend answers with each key's state,
 * remaining days and rotation backlog, and the cards render it.
 *
 * The mutations are deliberately not exercised here. Registering or activating
 * a key changes what the *whole installation* seals under, which is not
 * something a spec sharing a database with a parallel suite may do — the full
 * rotation is proved end to end in `tests/api/key-rotation.spec.ts`, which runs
 * alone. What is checked here is that the page reports live server state and
 * that every mutation is gated behind a step-up credential.
 *
 * Pattern 003: keys are found by their id, never by position.
 * Pattern 008: `expect()` for every visibility check, no try-catch.
 */

import { test, expect } from '../../fixtures/pageObjects'

test.describe('Security & Credentials', () => {
  test('lists the installation keys with their lifecycle state', async ({
    authenticatedSettingsPage,
  }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickCredentialsTab()

    const keys = await authenticatedSettingsPage.getEncryptionKeys()

    // A running installation always has at least the key it is sealing under —
    // without one, no member's bank details could be stored at all.
    expect(keys.length).toBeGreaterThan(0)
    for (const key of keys) {
      expect(
        ['pending', 'active', 'retiring', 'retired', 'revoked', 'compromised'],
        key.id,
      ).toContain(key.status)
      expect(['ok', 'info', 'warning', 'critical', 'expired'], key.id).toContain(key.lifecycleState)
    }

    const active = keys.filter((key) => key.status === 'active')
    expect(active, 'exactly one key may be active at a time').toHaveLength(1)
  })

  test('shows how many mandates each key still holds', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickCredentialsTab()

    const keys = await authenticatedSettingsPage.getEncryptionKeys()
    const active = keys.find((key) => key.status === 'active')!

    // The number is what turns the list into a rotation status board: it is the
    // backlog a rotation has to drain before the old key can be retired.
    await expect(
      authenticatedSettingsPage.page.getByTestId(`credentials-key-backlog-${active.id}`),
    ).toBeVisible()
    expect(active.sealedRecordCount).toBeGreaterThanOrEqual(0)
  })

  test('a healthy active key raises no banner', async ({ authenticatedSettingsPage }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickCredentialsTab()

    const active = (await authenticatedSettingsPage.getEncryptionKeys()).find(
      (key) => key.status === 'active',
    )!

    // The dev key is freshly seeded, so the page must be quiet — a warning
    // that is always on is a warning nobody reads.
    const banner = authenticatedSettingsPage.page.getByTestId('credentials-expiry-banner')
    if (active.lifecycleState === 'ok') {
      await expect(banner).toBeHidden()
    } else {
      await expect(banner).toBeVisible()
    }
  })

  test('registering a key asks for a step-up credential first', async ({
    authenticatedSettingsPage,
  }) => {
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickCredentialsTab()

    await authenticatedSettingsPage.clickRegisterKey()

    // Nothing is submitted here: the point is that the public key cannot be
    // registered on the strength of the session alone (ADR-0036 uses step-up
    // where a role system would use a permission).
    await expect(authenticatedSettingsPage.page.getByTestId('credentials-key-identifier')).toBeVisible()
    await expect(authenticatedSettingsPage.page.getByTestId('step-up-password')).toBeVisible()
    await expect(authenticatedSettingsPage.page.getByTestId('confirm-dialog-ok')).toBeDisabled()
  })
})
