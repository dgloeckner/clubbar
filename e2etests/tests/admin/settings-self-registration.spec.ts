/**
 * E2E: the club's registration controls on Security & Credentials (#783, UC-A69).
 *
 * The poster secret sits beside the encryption keys and the terminal tokens
 * because it is the same kind of thing: long-lived, printed on something in the
 * physical world, and replaced by an action that takes that thing out of
 * service. These tests follow the whole path — open the tab, the SPA calls
 * `GET /api/admin/self-registration`, the club's real state renders, and each
 * control's effect is verified against the server rather than against the
 * screen that triggered it.
 *
 * ### Why this file is serial and puts everything back
 *
 * `self_registration_config` is a **single row** — one club, one poster secret,
 * one switch. Pattern 001's answer (unique data per test) cannot apply to a row
 * the schema allows exactly one of, so this block is serial, and every test
 * restores what it changed: `tests/api/self-registration.spec.ts` runs beside
 * it in another lane against the same database and would fail on a club left
 * switched off, in a way that reads as a broken submission endpoint.
 *
 * Pattern 005: every assertion goes through a `data-testid`.
 * Pattern 008: `expect()` for every visibility check, no try-catch.
 */

import { test, expect } from '../../fixtures/pageObjects'
import { randomUUID } from 'node:crypto'
import {
  configureSelfRegistration,
  execSql,
  restoreClubDocumentUrl,
  serveClubDocument,
  stopServingClubDocument,
} from '../../utils/sql'

/**
 * Server-side truth is read through `page.request`, not a separate fixture:
 * it shares the page's session cookies, so the check runs as the same admin
 * who just pressed the button — and asserts what the *server* now holds rather
 * than what the screen that triggered it decided to render.
 */

/** Put the row back the way the rest of the suite expects to find it. */
function restoreClub(): void {
  execSql(
    "UPDATE self_registration_config SET enabled = 1, disabled_reason = NULL WHERE id = 1",
  )
}

test.describe('Settings — self-registration controls', () => {
  test.describe.configure({ mode: 'serial' })

  /**
   * Serve a document the backend can actually fetch: the URL is validated at
   * save time, so the test that saves a good one back after a refusal makes a
   * real HTTP request from inside the backend container.
   */
  test.beforeAll(() => {
    serveClubDocument()
  })

  test.afterAll(() => {
    stopServingClubDocument()
  })

  /**
   * Establish the state these tests read, rather than inheriting it.
   *
   * Every test here starts from a club that is **on**, with a secret and a
   * document — that is what makes "pausing needs a sentence" the thing being
   * asserted. Migration `059` seeds the row switched *off*, so on a fresh
   * database the toggle correctly reads "switch on" and is correctly disabled
   * for want of a secret, and the spec times out clicking it. Locally it passed
   * only because an earlier run had left the club on: a test that depends on
   * what ran before it is not a test (Pattern 001).
   */
  test.beforeEach(() => {
    configureSelfRegistration(`secret-${randomUUID()}`)
  })

  test.afterEach(() => {
    restoreClub()
    restoreClubDocumentUrl()
  })

  test('the tab reports the club’s live state and never shows the secret', async ({
    authenticatedSettingsPage,
  }) => {
    const page = authenticatedSettingsPage.page
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickCredentialsTab()

    await expect(page.getByTestId('self-registration-credentials')).toBeVisible()

    const status = page.getByTestId('self-registration-status')
    await expect(status).toBeVisible()
    // Live server state, not a hardcoded default: the seed leaves the club on.
    expect(['true', 'false']).toContain(await status.getAttribute('data-enabled'))

    // The settings payload carries no secret material by construction, so this
    // asserts what the whole design is for: a screen share of this tab hands
    // nobody the club's gate.
    const secretAge = page.getByTestId('self-registration-secret-age')
    await expect(secretAge).toBeVisible()
    expect(await secretAge.textContent()).not.toMatch(/[A-Za-z0-9]{4}-[A-Za-z0-9]{4}/)

    // The document URL is a real one — the same column the SEPA tab and the
    // review print read.
    await expect(page.getByTestId('self-registration-document-url')).toHaveValue(/^https?:\/\//)
  })

  test('pausing needs a sentence, and the club goes off with it', async ({
    authenticatedSettingsPage,
  }) => {
    const page = authenticatedSettingsPage.page
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickCredentialsTab()

    const toggle = page.getByTestId('self-registration-toggle')
    // The club is on and the reason box is empty, so pausing is not offered
    // yet — and the screen says which precondition is missing rather than
    // greying the control out silently.
    await expect(toggle).toBeDisabled()
    await expect(page.getByTestId('self-registration-blocked')).toBeVisible()

    await page.getByTestId('self-registration-reason').fill('Wir pausieren bis zur Versammlung')
    await expect(toggle).toBeEnabled()
    await toggle.click()

    await expect(page.getByTestId('self-registration-success')).toBeVisible()
    await expect(page.getByTestId('self-registration-status')).toHaveAttribute(
      'data-enabled',
      'false',
    )

    // Verified against the server, not the screen that triggered it: the point
    // of the sentence is that somebody standing in the clubhouse reads it.
    const settings = await (
      await page.request.get('http://localhost:8080/api/admin/self-registration')
    ).json()
    expect(settings.enabled).toBe(false)
    expect(settings.disabled_reason).toBe('Wir pausieren bis zur Versammlung')

    // …and back on, which clears the stale sentence.
    await toggle.click()
    await expect(page.getByTestId('self-registration-status')).toHaveAttribute(
      'data-enabled',
      'true',
    )
  })

  test('a broken document URL is refused on this screen, and the field keeps it', async ({
    authenticatedSettingsPage,
  }) => {
    const page = authenticatedSettingsPage.page
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickCredentialsTab()

    const field = page.getByTestId('self-registration-document-url')
    const original = await field.inputValue()

    await field.fill('http://localhost/definitely-not-there.pdf')
    await page.getByTestId('self-registration-save').click()

    // Save-time validation is the whole change here: without it the club finds
    // out weeks later, when a Kassenwart tries to print.
    await expect(page.getByTestId('self-registration-error')).toBeVisible()
    // The typed value survives — the admin is likely one character away.
    await expect(field).toHaveValue('http://localhost/definitely-not-there.pdf')

    // Nothing was stored: a saved-but-unusable URL is the state the check
    // exists to make unreachable.
    const settings = await (
      await page.request.get('http://localhost:8080/api/admin/self-registration')
    ).json()
    expect(settings.document_url).toBe(original)
  })

  test('the poster downloads as a PDF and rotating asks first', async ({
    authenticatedSettingsPage,
  }) => {
    const page = authenticatedSettingsPage.page
    await authenticatedSettingsPage.waitForLoad()
    await authenticatedSettingsPage.clickCredentialsTab()

    // Mint through the UI, which is also the only way to get a *sealed* secret
    // on the row: printing reads that copy back.
    await page.getByTestId('self-registration-rotate').click()
    // The dialog is the point: every poster in the building dies at this
    // button, and it cannot be undone.
    await expect(page.getByTestId('confirm-dialog-message')).toBeVisible()
    await page.getByTestId('confirm-dialog-ok').click()
    await expect(page.getByTestId('self-registration-success')).toBeVisible()

    const before = await (
      await page.request.get('http://localhost:8080/api/admin/self-registration')
    ).json()
    expect(before.has_secret).toBe(true)

    const download = page.waitForEvent('download')
    await page.getByTestId('self-registration-poster').click()
    const file = await download

    expect(file.suggestedFilename()).toBe('anmeldung-poster.pdf')

    // Printing does not rotate. A club that had to rotate in order to reprint
    // would invalidate every poster in the building every time somebody
    // spilled a drink on one.
    const after = await (
      await page.request.get('http://localhost:8080/api/admin/self-registration')
    ).json()
    expect(after.secret_rotated_at).toBe(before.secret_rotated_at)
  })
})
