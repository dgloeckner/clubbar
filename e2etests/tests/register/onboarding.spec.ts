import { expect } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import {
  CLUB_DOCUMENT_URL,
  clearRegistrationAttempts,
  configureSelfRegistration,
  countPendingRegistrations,
  restoreClubDocumentUrl,
  serveClubDocument,
  stopServingClubDocument,
} from '../../utils/sql'
import { lockSelfRegistration, unlockSelfRegistration } from '../../utils/registrationLock'
import { test } from '../../fixtures/roleRequests'

/**
 * The page an applicant's phone actually renders (#781, UC-P01).
 *
 * Served as static files from the backend's own document root — no build, no
 * framework, no relationship to the admin SPA — so this is where its behaviour
 * is asserted. There is no unit-test seam to reach for and there should not be
 * one: what is worth checking is what happens on a 375px screen with a thumb.
 *
 * ### Serial, for the same reason the API specs are
 *
 * `self_registration_config` is a single row by design — one club, one poster,
 * one switch — so parallel workers overwrite each other's secret mid-flight and
 * a valid secret comes back as the uniform "this link no longer works". Pattern
 * 001's answer, unique data per test, cannot apply to a row the schema allows
 * exactly one of.
 */

const PAGE = '/register/'
const TEST_IBAN = 'DE89370400440532013000'

test.describe('Public onboarding page', () => {
  test.describe.configure({ mode: 'serial' })

  /**
   * One writer at a time on `self_registration_config` (#784).
   *
   * `mode: 'serial'` orders this file's tests and says nothing about the three
   * other spec files that write the same singleton row. Without the lock, a
   * concurrent worker overwrites the secret between the write and the request
   * presenting it, and the valid secret comes back as the uniform 404 — a
   * failure reported by whichever file lost the race rather than the one that
   * caused it. Held for the whole test, because the window spans the write and
   * the round trip that presents what was written.
   */
  test.beforeEach(() => {
    lockSelfRegistration()
  })

  test.afterEach(() => {
    unlockSelfRegistration()
  })

  test.beforeAll(() => {
    // A real Anmeldung the backend can fetch, so the success screen's document
    // is the genuine article rather than a stand-in.
    serveClubDocument()
  })

  test.afterAll(() => {
    stopServingClubDocument()
  })

  test.beforeEach(() => {
    clearRegistrationAttempts()
  })

  test.afterEach(() => {
    restoreClubDocumentUrl()
  })

  const uniqueSecret = () => `secret-${randomUUID()}`

  /** Open the page the way a QR code does: the secret in the fragment. */
  const openWith = async (page: any, secret: string) => {
    await page.goto(`${PAGE}#${encodeURIComponent(secret)}`)
  }

  const fillForm = async (page: any, overrides: Record<string, string> = {}) => {
    const unique = randomUUID().slice(0, 8)
    const values = {
      'field-first-name': 'Lena',
      'field-last-name': `Brandt-${unique}`,
      'field-date-of-birth': '23111979',
      'field-email': `lena-${unique}@example.org`,
      'field-iban': TEST_IBAN,
      ...overrides,
    }

    for (const [testId, value] of Object.entries(values)) {
      await page.getByTestId(testId).fill(value)
    }

    return values
  }

  // ── entry states ───────────────────────────────────────────────────────

  test('an open club shows the form, with the notice reachable before any field', async ({ page }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret)

    await openWith(page, secret)

    // One language configured pair means the choice screen appears; both are
    // enabled here, so pick German and carry on.
    await expect(page.getByTestId('screen-language')).toBeVisible()
    await page.getByTestId('language-de').click()

    await expect(page.getByTestId('screen-form')).toBeVisible()

    // Art. 13 informs *before* collection, so the link is on the page the
    // moment the first field is — not behind a submit button.
    const link = page.getByTestId('document-link')
    await expect(link).toBeVisible()
    await expect(link).toHaveAttribute('href', CLUB_DOCUMENT_URL)

    // And no checkbox is attached to it: informing is a duty, not a consent.
    await expect(page.locator('#screen-form input[type="checkbox"]')).toHaveCount(0)
  })

  test("a paused club shows its own reason, not an error", async ({ page }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret, { enabled: false, disabledReason: 'Beta-Phase schon voll' })

    await openWith(page, secret)

    await expect(page.getByTestId('screen-paused')).toBeVisible()
    await expect(page.getByTestId('paused-reason')).toHaveText('Beta-Phase schon voll')
    await expect(page.getByTestId('screen-form')).toBeHidden()
  })

  /**
   * The reason is written by an admin, so it is user input on a page an
   * anonymous visitor loads. It renders as text or it is a stored XSS.
   */
  test('markup inside the club’s reason renders inert', async ({ page }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret, {
      enabled: false,
      disabledReason: '<img src=x onerror="window.__pwned = true">Pause',
    })

    await openWith(page, secret)

    await expect(page.getByTestId('paused-reason')).toContainText('<img src=x')
    await expect(page.locator('#paused-reason img')).toHaveCount(0)
    expect(await page.evaluate(() => (window as any).__pwned)).toBeUndefined()
  })

  test('a rotated-away poster gets the generic screen, and no hint that a secret exists', async ({
    page,
  }) => {
    configureSelfRegistration(uniqueSecret())

    await openWith(page, 'a-secret-from-last-years-poster')

    await expect(page.getByTestId('screen-unknown')).toBeVisible()
    await expect(page.getByTestId('screen-form')).toBeHidden()
    await expect(page.getByTestId('screen-paused')).toBeHidden()
  })

  test('opening the page with no secret at all is the same screen', async ({ page }) => {
    configureSelfRegistration(uniqueSecret())

    await page.goto(PAGE)

    await expect(page.getByTestId('screen-unknown')).toBeVisible()
  })

  /**
   * The fail-closed condition, answered before a field is rendered. A form that
   * collects a name, a birth date and an IBAN and only then discovers nobody
   * could have been shown a notice has already had it typed into a phone.
   */
  test('a club with no document configured never renders the form', async ({ page }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret, { documentUrl: null })

    await openWith(page, secret)

    await expect(page.getByTestId('screen-paused')).toBeVisible()
    await expect(page.getByTestId('screen-form')).toBeHidden()
  })

  // ── the form ───────────────────────────────────────────────────────────

  const openForm = async (page: any) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret)
    await openWith(page, secret)
    await page.getByTestId('language-de').click()
    await expect(page.getByTestId('screen-form')).toBeVisible()

    return secret
  }

  /**
   * Typed entry, because `<input type="date">` is banned in the admin app and
   * the reason applies here more, not less: on a phone it opens a spinner
   * starting at today, and a 1979 birth date is dozens of swipes away.
   */
  test('the birth date is typed, not spun, and the wire carries ISO', async ({ page }) => {
    await openForm(page)

    const field = page.getByTestId('field-date-of-birth')
    await expect(field).toHaveAttribute('type', 'text')
    await expect(field).toHaveAttribute('inputmode', 'numeric')
    await expect(page.locator('#screen-form input[type="date"]')).toHaveCount(0)

    await field.fill('23111979')
    await expect(field).toHaveValue('23.11.1979')

    await fillForm(page)
    await page.getByTestId('submit-button').click()

    // Asserted on the hidden ISO value, never on the locale-formatted display —
    // the rule the admin date-field pattern sets.
    await expect(page.getByTestId('field-date-of-birth-value')).toHaveValue('1979-11-23')
  })

  test('a mistyped IBAN is caught before anything is submitted', async ({ page }) => {
    await openForm(page)
    const before = countPendingRegistrations()

    await fillForm(page, { 'field-iban': 'DE89370400440532013001' })
    await page.getByTestId('submit-button').click()

    await expect(page.locator('[data-error-for="iban"]')).not.toBeEmpty()
    await expect(page.getByTestId('screen-review')).toBeHidden()
    // The server never heard about it.
    expect(countPendingRegistrations()).toBe(before)
  })

  test('the IBAN is grouped in fours as it is typed', async ({ page }) => {
    await openForm(page)

    await page.getByTestId('field-iban').fill(TEST_IBAN)

    await expect(page.getByTestId('field-iban')).toHaveValue('DE89 3704 0044 0532 0130 00')
  })

  test('an impossible date is refused with words', async ({ page }) => {
    await openForm(page)

    await fillForm(page, { 'field-date-of-birth': '31021979' })
    await page.getByTestId('submit-button').click()

    await expect(page.locator('[data-error-for="date_of_birth"]')).not.toBeEmpty()
    await expect(page.getByTestId('screen-review')).toBeHidden()
  })

  test('every field and button is at least 44px tall on a 375px screen', async ({ page }) => {
    await openForm(page)

    expect(page.viewportSize()?.width).toBeLessThanOrEqual(430)

    const targets = page.locator('#screen-form input:not([type="hidden"]):visible, #screen-form button')
    const count = await targets.count()
    expect(count).toBeGreaterThan(0)

    for (let i = 0; i < count; i++) {
      const box = await targets.nth(i).boundingBox()
      expect(box, `target ${i} should be laid out`).not.toBeNull()
      expect(box!.height, `target ${i} is ${box!.height}px tall`).toBeGreaterThanOrEqual(44)
    }
  })

  /** Nothing about this flow may outlive the tab it happened in. */
  test('nothing personal is written to browser storage or the URL', async ({ page }) => {
    await openForm(page)
    const values = await fillForm(page)
    await page.getByTestId('submit-button').click()
    await expect(page.getByTestId('screen-review')).toBeVisible()

    const stored = await page.evaluate(() => ({
      local: JSON.stringify(window.localStorage),
      session: JSON.stringify(window.sessionStorage),
      cookie: document.cookie,
      url: window.location.href,
    }))

    for (const haystack of [stored.local, stored.session, stored.cookie]) {
      expect(haystack).not.toContain(values['field-email'])
      expect(haystack).not.toContain('DE89')
      expect(haystack).not.toContain(values['field-last-name'])
    }
    // The URL carries the poster secret in its fragment and nothing else — no
    // name, no IBAN, nothing a shared screenshot would leak.
    expect(stored.url).not.toContain(values['field-email'])
    expect(stored.url).not.toContain('DE89')
  })

  // ── review and submit ──────────────────────────────────────────────────

  test('the review step shows what will be sent, and can be gone back on', async ({ page }) => {
    await openForm(page)
    const values = await fillForm(page)
    await page.getByTestId('submit-button').click()

    const summary = page.getByTestId('review-summary')
    await expect(summary).toContainText(values['field-last-name'])
    await expect(summary).toContainText('DE89 3704 0044 0532 0130 00')
    await expect(summary).toContainText('23.11.1979')

    await page.getByTestId('back-button').click()
    await expect(page.getByTestId('screen-form')).toBeVisible()
    // The form still holds what was typed — going back to fix one field must
    // not mean typing an IBAN again.
    await expect(page.getByTestId('field-email')).toHaveValue(values['field-email'])
  })

  test('a submission reaches the backend and the success screen explains what happens next', async ({
    page,
  }) => {
    await openForm(page)
    const before = countPendingRegistrations()

    await fillForm(page)
    await page.getByTestId('submit-button').click()
    await page.getByTestId('confirm-button').click()

    await expect(page.getByTestId('screen-done')).toBeVisible()
    expect(countPendingRegistrations()).toBe(before + 1)

    // The one thing a member must take away: the account does not work yet.
    await expect(page.getByTestId('screen-done')).toContainText('Kassenwart')
    await expect(page.getByTestId('mandate-reference')).not.toBeEmpty()
  })

  test('the filled document downloads from the response, and cannot be re-fetched', async ({
    page,
  }) => {
    await openForm(page)
    await fillForm(page)
    await page.getByTestId('submit-button').click()
    await page.getByTestId('confirm-button').click()
    await expect(page.getByTestId('screen-done')).toBeVisible()

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.getByTestId('download-button').click(),
    ])
    expect(download.suggestedFilename()).toBe('anmeldung.pdf')

    // One chance, and the page is honest about it: the plaintext IBAN it was
    // rendered from existed only for the length of the submission request, so
    // a reload has nothing left to render from and no endpoint to ask.
    await page.reload()
    await expect(page.getByTestId('screen-done')).toBeHidden()
  })

  /**
   * An admin switching the club off while somebody is mid-form. The page shows
   * the same paused screen it would have shown on load, from the same reason
   * code — one rendering path, whichever moment the club's decision arrived in.
   */
  test('a club switched off mid-form lands on the same paused screen', async ({ page }) => {
    const secret = await openForm(page)
    await fillForm(page)
    await page.getByTestId('submit-button').click()
    await expect(page.getByTestId('screen-review')).toBeVisible()

    configureSelfRegistration(secret, { enabled: false, disabledReason: 'Gerade Inventur' })

    await page.getByTestId('confirm-button').click()

    await expect(page.getByTestId('screen-paused')).toBeVisible()
    await expect(page.getByTestId('paused-reason')).toHaveText('Gerade Inventur')
  })

  // ── language ───────────────────────────────────────────────────────────

  test('choosing English carries the whole flow through in English', async ({ page }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret)
    await openWith(page, secret)

    await page.getByTestId('language-en').click()

    await expect(page.getByTestId('screen-form')).toContainText('First name')
    await expect(page.locator('html')).toHaveAttribute('lang', 'en')

    await fillForm(page, { 'field-iban': 'DE89370400440532013001' })
    await page.getByTestId('submit-button').click()

    // Even the refusal is in the member's language — never the backend's
    // English, and never a raw code.
    await expect(page.locator('[data-error-for="iban"]')).toContainText('IBAN')
    await expect(page.locator('[data-error-for="iban"]')).not.toContainText('mod')
  })
})
