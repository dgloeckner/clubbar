import { expect } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import {
  CLUB_DOCUMENT_URL,
  clearRegistrationAttempts,
  clubInstanceName,
  configureSelfRegistration,
  countPendingRegistrations,
  execSql,
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

  /**
   * Leave the club switched on, whatever this test did to it (#784).
   *
   * A leaked disabled state fails *other* specs, with a refusal that is
   * entirely correct for a club that is off — so the report accuses whichever
   * file ran next. In SQL rather than through the API because switching off
   * needs a reason and this has to work from any state, including one a failed
   * test left half-applied.
   */
  test.afterEach(() => {
    execSql(
      'UPDATE self_registration_config SET enabled = 1, disabled_reason = NULL, ' +
        'retention_days = 30 WHERE id = 1',
    )
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

  // ── branding ───────────────────────────────────────────────────────────

  /**
   * The page says whose form it is, in the club's own name (#781).
   *
   * A form that asks a stranger for a birth date and an IBAN without naming the
   * club it belongs to is indistinguishable from a phishing page — and "type
   * your IBAN in here" is exactly the sentence a phishing page opens with. End
   * to end: the name is read from `instance_config`, travels on the context
   * answer, and is rendered in the masthead the club's mail also wears.
   */
  test('the club is named in the masthead and the footer', async ({ page }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret)
    const club = clubInstanceName()

    await openWith(page, secret)
    await page.getByTestId('language-de').click()
    await expect(page.getByTestId('screen-form')).toBeVisible()

    await expect(page.getByTestId('brand-name')).toHaveText(club)
    await expect(page.getByTestId('colophon-name')).toHaveText(club)
  })

  /** The paused screen is the club speaking, so it is the club's header. */
  test('a paused club is still the club, header and all', async ({ page }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret, { enabled: false, disabledReason: 'Beta-Phase schon voll' })

    await openWith(page, secret)

    await expect(page.getByTestId('screen-paused')).toBeVisible()
    await expect(page.getByTestId('brand-name')).toHaveText(clubInstanceName())
  })

  /**
   * A guessed URL learns nothing — not even who the club is.
   *
   * The refusal is the uniform 404 with no body at all, so the masthead has
   * nothing to render and falls back to the neutral word. Everything the gate
   * protects is protected here too.
   */
  test('an unknown link is not branded', async ({ page }) => {
    configureSelfRegistration(uniqueSecret())

    await openWith(page, 'definitely-not-the-secret')

    await expect(page.getByTestId('screen-unknown')).toBeVisible()
    await expect(page.getByTestId('brand-name')).toHaveText('Anmeldung')
    await expect(page.getByTestId('colophon')).toBeHidden()
  })

  /**
   * The club's mark, when it has one.
   *
   * Stubbed at the network boundary rather than written to `mail_config`: that
   * row is a global singleton the mail specs read, restore and assert delivered
   * HTML against, so a logo written here would surface in somebody else's mail
   * on another worker. What is under test is what the *page* does with a logo
   * the backend already narrowed to a fetchable URL — the narrowing itself has
   * its own unit tests (`PublicBrandingTest`), and the field's presence on the
   * wire is asserted in `tests/api/self-registration.spec.ts`.
   */
  const answerContextWith = (page: any, branding: Record<string, string | null>) =>
    page.route('**/api/public/registrations/context', async (route: any) => {
      const original = await route.fetch()
      await route.fulfill({
        response: original,
        json: { ...(await original.json()), ...branding },
      })
    })

  test('a configured mark is shown in the masthead', async ({ page }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret)

    await answerContextWith(page, { logo_url: '/register/e2e-logo.svg' })
    await openWith(page, secret)
    await page.getByTestId('language-de').click()

    await expect(page.getByTestId('screen-form')).toBeVisible()
    await expect(page.getByTestId('brand-logo')).toHaveAttribute('src', '/register/e2e-logo.svg')
  })

  test('a club with no mark shows no broken image', async ({ page }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret)

    await answerContextWith(page, { logo_url: null })
    await openWith(page, secret)
    await page.getByTestId('language-de').click()

    // The club's name carries the masthead on its own, exactly as it does in
    // the mail when a client blocks the image.
    await expect(page.getByTestId('screen-form')).toBeVisible()
    await expect(page.getByTestId('brand-logo')).toBeHidden()
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

  const browserState = (page: any) =>
    page.evaluate(() => ({
      local: JSON.stringify(window.localStorage),
      session: JSON.stringify(window.sessionStorage),
      cookie: document.cookie,
      url: window.location.href,
    }))

  /** Nothing about this flow may outlive the tab it happened in. */
  test('nothing personal is written to browser storage or the URL', async ({ page }) => {
    await openForm(page)
    const values = await fillForm(page)
    await page.getByTestId('submit-button').click()
    await expect(page.getByTestId('screen-review')).toBeVisible()

    // Before the submission there is nothing to keep, so nothing is kept —
    // `sessionStorage` included.
    const stored = await browserState(page)
    expect(stored.session).toBe('{}')

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

  /**
   * After the submission, exactly one thing is kept and exactly one place keeps
   * it (#804).
   *
   * The filled document arrives once and cannot be asked for again, and phones
   * reload backgrounded tabs — so it lives in this tab's `sessionStorage` until
   * the applicant says they have it. `localStorage`, cookies and the URL are
   * unchanged by that: they never carry anything about the applicant, before or
   * after, and the stored copy leaves on the tap that says it was saved.
   */
  test('after submitting, only sessionStorage holds the document — and only until the clear tap', async ({
    page,
  }) => {
    const values = await submitRegistration(page)

    const afterSubmit = await browserState(page)
    const kept = JSON.parse(JSON.parse(afterSubmit.session)['clubbar.registration'])
    expect(kept.document.length).toBeGreaterThan(0)

    // Everywhere else is still empty of them — the name, the address and the
    // IBAN they typed are in none of it, at any point.
    for (const haystack of [afterSubmit.local, afterSubmit.cookie, afterSubmit.url]) {
      expect(haystack).not.toContain(values['field-email'])
      expect(haystack).not.toContain('DE89')
      expect(haystack).not.toContain(values['field-last-name'])
    }

    await page.getByTestId('clear-button').click()

    const afterClear = await browserState(page)
    expect(afterClear.session).toBe('{}')
    await expect(page.getByTestId('clear-button')).toBeHidden()
    await expect(page.getByTestId('cleared-note')).toBeVisible()
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

  // ── saving the filled document (#804) ──────────────────────────────────
  //
  // The document arrives once, in the submission response, and by design can
  // never be asked for again — the plaintext IBAN it was rendered from existed
  // only for the length of that request. Everything below is about that one
  // copy surviving a phone: an automatic save, a share sheet, a reload, and a
  // blob URL that outlives an iOS confirmation dialog.

  /**
   * Fill, review and submit, and wait for the automatic save on the way (#804).
   *
   * The download event is consumed here rather than ignored: it fires without a
   * tap, so a test that did not expect it would race its own later one.
   */
  const submitRegistration = async (page: any, overrides: Record<string, string> = {}) => {
    await openForm(page)
    const values = await fillForm(page, overrides)
    await page.getByTestId('submit-button').click()

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.getByTestId('confirm-button').click(),
    ])
    expect(download.suggestedFilename()).toBe('anmeldung.pdf')

    await expect(page.getByTestId('screen-done')).toBeVisible()

    return values
  }

  /**
   * The commonest way to lose the document is never tapping a second button, so
   * there is no second button to tap: the save is attempted inside the user
   * activation of the „Absenden“ tap itself. The button stays, saying what it
   * now does.
   */
  test('the filled document saves itself, and the button then saves it again', async ({ page }) => {
    await submitRegistration(page)

    await expect(page.getByTestId('download-button')).toHaveText('Erneut speichern')

    const [again] = await Promise.all([
      page.waitForEvent('download'),
      page.getByTestId('download-button').click(),
    ])
    expect(again.suggestedFilename()).toBe('anmeldung.pdf')
  })

  /**
   * The reload rescue (#804), which is the actual answer to "was interrupted".
   *
   * iOS Safari reloads a backgrounded tab after a phone call and Android Chrome
   * discards tabs under memory pressure. Before this, that put the applicant
   * back at an empty form with their Anmeldung gone. It is still never
   * re-fetched — the screen is rebuilt from what this tab kept, and the tab is
   * the only place that copy exists.
   */
  test('a reload of the done screen brings the same document back', async ({ page }) => {
    await submitRegistration(page)
    const reference = await page.getByTestId('mandate-reference').textContent()
    expect(reference).not.toBe('')

    await page.reload()

    await expect(page.getByTestId('screen-done')).toBeVisible()
    await expect(page.getByTestId('mandate-reference')).toHaveText(reference!)

    const [download] = await Promise.all([
      page.waitForEvent('download'),
      page.getByTestId('download-button').click(),
    ])
    expect(download.suggestedFilename()).toBe('anmeldung.pdf')
  })

  /** And once the applicant says they have it, the tab lets go of it for good. */
  test('after the clear tap a reload lands on the form, not the done screen', async ({ page }) => {
    await submitRegistration(page)

    await page.getByTestId('clear-button').click()
    await page.reload()

    // Back at the start of the flow the poster's secret opens — the language
    // choice, then the form — and not at a done screen with nothing behind it.
    await expect(page.getByTestId('screen-done')).toBeHidden()
    await expect(page.getByTestId('screen-language')).toBeVisible()
    await page.getByTestId('language-de').click()
    await expect(page.getByTestId('screen-form')).toBeVisible()
  })

  /**
   * The share sheet is what an iPhone actually offers: „In Dateien sichern“,
   * „Drucken“ over AirPrint, and „Mail“ — the last of which covers the case a
   * download cannot, no printer at home but one at work.
   *
   * `navigator.share` is stubbed because no headless browser has one. What is
   * under test is what the page hands it and what it does with the answer: one
   * PDF file under the right name, and the kept copy released once the sheet
   * reports that something took it.
   */
  const stubShare = (page: any) =>
    page.addInitScript(() => {
      const win = window as any
      win.__shared = []
      Object.defineProperty(navigator, 'canShare', {
        configurable: true,
        value: (data: any) => Array.isArray(data?.files) && data.files.length > 0,
      })
      Object.defineProperty(navigator, 'share', {
        configurable: true,
        value: (data: any) => {
          win.__shared.push(
            (data.files || []).map((file: File) => ({
              name: file.name,
              type: file.type,
              size: file.size,
            })),
          )
          return Promise.resolve()
        },
      })
    })

  test('the share sheet gets one PDF, and a successful share releases the kept copy', async ({
    page,
  }) => {
    await stubShare(page)
    await submitRegistration(page)

    await expect(page.getByTestId('share-button')).toBeVisible()
    await page.getByTestId('share-button').click()

    await expect
      .poll(() => page.evaluate(() => (window as any).__shared.length))
      .toBeGreaterThan(0)

    expect(await page.evaluate(() => (window as any).__shared[0])).toEqual([
      { name: 'anmeldung.pdf', type: 'application/pdf', size: expect.any(Number) },
    ])

    // A resolved share is a save. Nothing is left in the browser to clean up,
    // and a reload from here lands on the form.
    await expect(page.getByTestId('cleared-note')).toBeVisible()
    expect(await page.evaluate(() => JSON.stringify(window.sessionStorage))).toBe('{}')
  })

  test('a browser with no share sheet is offered the download alone', async ({ page }) => {
    await page.addInitScript(() => {
      // Chromium on Linux has neither, but saying so explicitly keeps this test
      // honest on a runner whose browser grew one. `defineProperty` rather than
      // `delete`: both live on `Navigator.prototype`, where deleting an
      // instance property does nothing.
      Object.defineProperty(navigator, 'share', { configurable: true, value: undefined })
      Object.defineProperty(navigator, 'canShare', { configurable: true, value: undefined })
    })

    await submitRegistration(page)

    await expect(page.getByTestId('share-button')).toBeHidden()
    await expect(page.getByTestId('download-button')).toBeVisible()
  })

  /**
   * The blob URL outlives the tap that made it (#804).
   *
   * iOS shows a confirmation dialog before it saves a download, and the old
   * 60-second timer meant answering that dialog late produced an empty file.
   * Asserted by counting revocations rather than by waiting 61 seconds: nothing
   * is released while the page lives, and everything is released when it goes.
   */
  test('the document’s blob URL is released on pagehide, not on a timer', async ({ page }) => {
    await page.addInitScript(() => {
      const win = window as any
      win.__revoked = 0
      const revoke = URL.revokeObjectURL.bind(URL)
      URL.revokeObjectURL = (url: string) => {
        win.__revoked += 1
        revoke(url)
      }
    })

    await submitRegistration(page)

    expect(await page.evaluate(() => (window as any).__revoked)).toBe(0)

    await page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pagehide')))
    expect(await page.evaluate(() => (window as any).__revoked)).toBeGreaterThan(0)
  })

  // ── what the done screen says ──────────────────────────────────────────

  /**
   * The applicant leaves knowing three things they could not know before
   * (#804): save this now, an email is coming when the card is assigned, and
   * this registration is deleted if nobody confirms it — with the club's own
   * number, read from the context answer rather than typed into the page.
   */
  test('the done screen saves first, then says what happens next', async ({ page }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret, { retentionDays: 45 })

    await openWith(page, secret)
    await page.getByTestId('language-de').click()
    await expect(page.getByTestId('screen-form')).toBeVisible()
    await fillForm(page)
    await page.getByTestId('submit-button').click()
    await Promise.all([
      page.waitForEvent('download'),
      page.getByTestId('confirm-button').click(),
    ])
    await expect(page.getByTestId('screen-done')).toBeVisible()

    // Saving is above the explanation of what to do with what was saved.
    const saveTop = (await page.getByTestId('save-block').boundingBox())!.y
    const stepsTop = (await page.locator('#screen-done .steps').boundingBox())!.y
    expect(saveTop).toBeLessThan(stepsTop)

    await expect(page.getByTestId('done-card')).toContainText('E-Mail')
    await expect(page.getByTestId('done-expiry')).toContainText('45 Tagen')
  })

  test('the done screen is English end to end when English was chosen', async ({ page }) => {
    const secret = uniqueSecret()
    configureSelfRegistration(secret)

    await openWith(page, secret)
    await page.getByTestId('language-en').click()
    await expect(page.getByTestId('screen-form')).toBeVisible()
    await fillForm(page)
    await page.getByTestId('submit-button').click()
    await Promise.all([
      page.waitForEvent('download'),
      page.getByTestId('confirm-button').click(),
    ])

    await expect(page.getByTestId('screen-done')).toBeVisible()
    await expect(page.getByTestId('done-card')).toContainText('email')
    await expect(page.getByTestId('done-expiry')).toContainText('30 days')
    await expect(page.getByTestId('platform-hint')).toContainText('iPhone')
    await expect(page.getByTestId('download-button')).toHaveText('Save again')

    // …and it is still English after the reload rescue, which is rebuilt from
    // what the tab kept rather than from a fresh context answer.
    await page.reload()
    await expect(page.getByTestId('done-expiry')).toContainText('30 days')
  })

  /**
   * Where the file went is a different sentence on every platform, and a wrong
   * one is worse than none: on an iPhone a downloaded file is behind the share
   * sheet, on Android it is in Downloads.
   */
  test('the platform hint follows the user agent', async ({ page }) => {
    // The project runs iPhone 14 metrics, so this is the phone the page thinks
    // it is talking to.
    await submitRegistration(page)
    await expect(page.getByTestId('platform-hint')).toContainText('iPhone')
  })

  test('an Android phone is told where its Downloads are', async ({ browser }) => {
    const context = await browser.newContext({
      userAgent:
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36',
      viewport: { width: 375, height: 812 },
      baseURL: 'http://localhost:8080',
    })
    const page = await context.newPage()

    try {
      await submitRegistration(page)
      await expect(page.getByTestId('platform-hint')).toContainText('Android')
      await expect(page.getByTestId('platform-hint')).not.toContainText('iPhone')
    } finally {
      await context.close()
    }
  })

  /** The 44px floor is not only the form's: the done screen is thumbs too. */
  test('every button on the done screen is at least 44px tall', async ({ page }) => {
    await stubShare(page)
    await submitRegistration(page)

    const buttons = page.locator('#screen-done button:visible')
    const count = await buttons.count()
    expect(count).toBeGreaterThanOrEqual(3)

    for (let i = 0; i < count; i++) {
      const box = await buttons.nth(i).boundingBox()
      expect(box, `button ${i} should be laid out`).not.toBeNull()
      expect(box!.height, `button ${i} is ${box!.height}px tall`).toBeGreaterThanOrEqual(44)
    }
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
