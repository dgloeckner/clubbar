/**
 * The whole flow, across both surfaces (#784, epic #776).
 *
 * Every other spec in this feature exercises one end of it: the API suite
 * proves the endpoints, `tests/register/onboarding.spec.ts` proves the public
 * page, `registrations-inbox.spec.ts` proves the panel. This one is the claim
 * none of them makes on its own — **that a stranger's phone and a treasurer's
 * browser meet correctly**, over one applicant, one sealed IBAN and one mandate
 * reference, all the way from a QR scan to a member the till can serve.
 *
 * It is deliberately a single flow test rather than six assertions in six tests
 * (Pattern 009): the steps are not independent, and a "member appears in the
 * list" test that seeded its own row would prove nothing about the submission
 * that was supposed to produce it.
 *
 * ### Two browser contexts, on purpose
 *
 * The applicant is a phone that has never signed in; the Kassenwart is a
 * desktop session. Running both in one context would let the admin's session
 * cookie ride along on the public request, which is precisely the confusion
 * Pattern 002 exists to prevent — and would quietly hide an authorisation bug
 * on the public surface.
 *
 * Lane: `ui` (`admin-chromium`). It shares the singleton
 * `self_registration_config` row with three other spec files, so it takes the
 * cross-file lock (`utils/registrationLock.ts`) for the length of each test.
 */

import { expect, devices } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { test } from '../../fixtures/roleRequests'
import {
  clearRegistrationAttempts,
  configureSelfRegistration,
  execSql,
  restoreClubDocumentUrl,
  serveClubDocument,
  stopServingClubDocument,
} from '../../utils/sql'
import { lockSelfRegistration, unlockSelfRegistration } from '../../utils/registrationLock'

const API = 'http://localhost:8080/api'
const PUBLIC_PAGE = 'http://localhost:8080/register/'
const TEST_IBAN = 'DE89370400440532013000'

test.describe('Self-registration — the whole flow', () => {
  test.describe.configure({ mode: 'serial' })

  test.beforeEach(() => {
    lockSelfRegistration()
  })

  test.afterEach(() => {
    unlockSelfRegistration()
  })

  test.beforeAll(() => {
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
   * Switch the club back on unconditionally, even when the test that turned it
   * off failed half way (#784 acceptance criterion 2).
   *
   * A leaked disabled state is the worst kind of shared-state failure: it fails
   * *other* specs, with a refusal that is entirely correct for a club that is
   * off, so the report accuses whichever file ran next. Written in SQL rather
   * than through the API because the API needs a reason to switch off and this
   * has to work from any state, including one a failed test left half-applied.
   */
  test.afterEach(() => {
    execSql('UPDATE self_registration_config SET enabled = 1, disabled_reason = NULL WHERE id = 1')
  })

  test('a QR scan becomes a member the terminal can serve', async ({
    browser,
    page,
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const secret = `secret-${randomUUID()}`
    configureSelfRegistration(secret)

    const unique = randomUUID().slice(0, 8)
    const applicant = {
      firstName: 'Lena',
      lastName: `Brandt-${unique}`,
      email: `lena-${unique}@example.org`,
    }

    // ── 1. the phone in the clubhouse ──────────────────────────────────────
    //
    // A context of its own, with no admin cookie in it: the public surface is
    // reached by somebody who has never signed in, and a session riding along
    // would hide exactly the bug this asserts the absence of.
    const phone = await browser.newContext({
      ...devices['iPhone 14'],
      baseURL: 'http://localhost:8080',
    })
    const applicantPage = await phone.newPage()

    let mandateReference = ''
    try {
      await applicantPage.goto(`${PUBLIC_PAGE}#${encodeURIComponent(secret)}`)

      await expect(applicantPage.getByTestId('screen-language')).toBeVisible()
      await applicantPage.getByTestId('language-de').click()
      await expect(applicantPage.getByTestId('screen-form')).toBeVisible()

      // The club's own Anmeldung is reachable before a single field is typed —
      // Art. 13 is a precondition of collecting the data, not a footnote under
      // it (ADR-0052 decision 6).
      await expect(applicantPage.getByTestId('document-link')).toBeVisible()

      await applicantPage.getByTestId('field-first-name').fill(applicant.firstName)
      await applicantPage.getByTestId('field-last-name').fill(applicant.lastName)
      await applicantPage.getByTestId('field-date-of-birth').fill('23111979')
      await applicantPage.getByTestId('field-email').fill(applicant.email)
      await applicantPage.getByTestId('field-iban').fill(TEST_IBAN)

      await applicantPage.getByTestId('submit-button').click()
      await expect(applicantPage.getByTestId('screen-review')).toBeVisible()
      await applicantPage.getByTestId('confirm-button').click()

      await expect(applicantPage.getByTestId('screen-done')).toBeVisible()
      mandateReference = (await applicantPage.getByTestId('mandate-reference').textContent()) ?? ''
      expect(mandateReference).not.toBe('')

      // Their own copy, carrying the IBAN they typed — the one moment in the
      // system's life where a plaintext IBAN exists, and it is on the
      // applicant's own device.
      const [download] = await Promise.all([
        applicantPage.waitForEvent('download'),
        applicantPage.getByTestId('download-button').click(),
      ])
      expect(download.suggestedFilename()).toBe('anmeldung.pdf')
    } finally {
      await phone.close()
    }

    // ── 2. the terminal, before anybody approved anything ──────────────────
    //
    // The claim the whole epic rests on, asserted at exactly the moment it
    // could fail: the submission exists, and the till has no idea this person
    // is in the world (ADR-0052 decision 9).
    const beforeApproval = await authenticatedTerminalRequest.get(`${API}/sync/members?since=0`)
    expect(
      ((await beforeApproval.json()).members ?? []).filter(
        (member: { last_name: string }) => member.last_name === applicant.lastName,
      ),
    ).toHaveLength(0)

    // ── 3. the Kassenwart's browser ────────────────────────────────────────
    //
    // Watch every response the panel receives, for the whole review. The
    // strongest privacy claim this feature makes is that the full IBAN does not
    // exist on the server in a readable form (ADR-0036), so it cannot be in any
    // of these bodies — and an interception is how that is checked rather than
    // asserted.
    const bodies: string[] = []
    page.on('response', async (response) => {
      if (!response.url().includes('/api/admin/')) return
      if (!(response.headers()['content-type'] ?? '').includes('json')) return
      bodies.push(await response.text().catch(() => ''))
    })

    // The id comes from the queue endpoint rather than from the DOM: the row
    // is keyed by it, so scraping it out of a test id to look the row up by
    // would be circular (Pattern 003 — find by identity, not by position).
    const queue = await authenticatedRequest.get(`${API}/admin/registrations?per_page=100`)
    const mine = ((await queue.json()).data ?? []).filter(
      (entry: { email: string }) => entry.email === applicant.email,
    )
    expect(mine, 'the phone’s submission must be in the queue').toHaveLength(1)
    const registrationId = mine[0].id as string

    await page.goto('/registrations')
    await expect(page.getByTestId('registrations-page')).toBeVisible()
    await expect(page.getByTestId(`registration-row-${registrationId}`)).toBeVisible()

    await page.getByTestId(`registration-open-${registrationId}`).click()
    await expect(page.getByTestId('registration-panel')).toBeVisible()
    // Masked, and it can only ever be masked: nothing on the server can read
    // the number back.
    await expect(page.getByTestId('panel-iban')).toHaveText('****3000')

    // The print the Kassenwart takes to the signing — the club's own document,
    // filled, with the IBAN line left blank for a hand-written number.
    const [print] = await Promise.all([
      page.waitForEvent('download'),
      page.getByTestId('panel-print').click(),
    ])
    expect(print.suggestedFilename()).toContain('.pdf')

    // ── 4. the attestation ─────────────────────────────────────────────────
    await page.getByTestId('panel-approve').click()
    // Not a formality and not pre-tickable: the admin is stating that the
    // signed paper is in hand and that the IBAN on it matches ****3000.
    await expect(page.getByTestId('approve-confirm')).toBeDisabled()
    await page.getByTestId('approve-signed-at').fill('30.08.2026')
    await page.getByTestId('approve-attestation').check()
    await expect(page.getByTestId('approve-confirm')).toBeEnabled()
    await page.getByTestId('approve-confirm').click()

    // The panel navigates to the new member, which is the answer to "and then
    // what" — the queue is not where a member lives.
    await page.waitForURL(/\/members/)
    await expect(page.getByText(applicant.lastName).first()).toBeVisible()

    // ── 5. what the server now holds ───────────────────────────────────────
    const gone = await authenticatedRequest.get(`${API}/admin/registrations/${registrationId}`)
    expect(gone.status(), 'the submission is consumed, not archived').toBe(404)

    const members = await authenticatedRequest.get(
      `${API}/admin/members?search=${encodeURIComponent(applicant.email)}`,
    )
    const found = (await members.json()).data ?? []
    expect(found).toHaveLength(1)

    const member = await (
      await authenticatedRequest.get(`${API}/admin/members/${found[0].id}`)
    ).json()
    // The reference minted on the phone is the one the bank will see: it was
    // printed on the paper this member signed, so it cannot be re-minted at
    // approval (ADR-0006).
    expect(member.mandate_reference).toBe(mandateReference)
    expect(member.iban_masked).toBe('****3000')

    // ── 6. the terminal, after ─────────────────────────────────────────────
    const afterApproval = await authenticatedTerminalRequest.get(`${API}/sync/members?since=0`)
    const visible = ((await afterApproval.json()).members ?? []).filter(
      (member: { last_name: string }) => member.last_name === applicant.lastName,
    )
    expect(visible).toHaveLength(1)
    expect(visible[0].is_sepa_valid).toBe(true)

    // ── 7. and nothing in the panel ever carried the number ────────────────
    expect(bodies.length, 'the interception must have seen the review traffic').toBeGreaterThan(0)
    for (const body of bodies) {
      expect(body).not.toContain(TEST_IBAN)
      // The unspaced form is what a leak would most likely look like; the
      // spaced one is what a helpful formatter would produce.
      expect(body).not.toContain('DE89 3704 0044 0532 0130 00')
    }
  })

  /**
   * The switch, from both sides of the same wall (epic decision 1).
   *
   * The admin's sentence and the applicant's screen are the two halves of one
   * promise, and each is asserted elsewhere against a fixture. Here they are
   * the same sentence, travelling.
   */
  test('an admin pausing the club is what the next scan reads', async ({
    browser,
    authenticatedRequest,
  }) => {
    const secret = `secret-${randomUUID()}`
    configureSelfRegistration(secret)

    // Markup, deliberately: the reason is club-authored free text rendered on a
    // page with no framework escaping it for us.
    const reason = 'Pause bis <img src=x onerror=alert(1)> zur Versammlung'

    const paused = await authenticatedRequest.patch(`${API}/admin/self-registration`, {
      data: { enabled: false, disabled_reason: reason },
    })
    expect(paused.status()).toBe(200)

    const phone = await browser.newContext({ ...devices['iPhone 14'] })
    const applicantPage = await phone.newPage()
    try {
      const alerts: string[] = []
      applicantPage.on('dialog', async (dialog) => {
        alerts.push(dialog.message())
        await dialog.dismiss()
      })

      await applicantPage.goto(`${PUBLIC_PAGE}#${encodeURIComponent(secret)}`)

      await expect(applicantPage.getByTestId('screen-paused')).toBeVisible()
      await expect(applicantPage.getByTestId('screen-form')).toBeHidden()
      // The club's own words, rendered as words. An `<img>` that executed would
      // mean the club could XSS its own members through a settings field.
      await expect(applicantPage.getByTestId('paused-reason')).toContainText('<img src=x')
      expect(alerts, 'markup in the reason must render inert').toEqual([])
      await expect(applicantPage.locator('[data-testid="paused-reason"] img')).toHaveCount(0)

      // Re-enabling puts the form back, on the same secret — the poster on the
      // wall did not change, and must not have to.
      const resumed = await authenticatedRequest.patch(`${API}/admin/self-registration`, {
        data: { enabled: true },
      })
      expect(resumed.status()).toBe(200)

      await applicantPage.reload()
      await expect(applicantPage.getByTestId('screen-language')).toBeVisible()
      await applicantPage.getByTestId('language-de').click()
      await expect(applicantPage.getByTestId('screen-form')).toBeVisible()
      // …and the stale sentence is gone, rather than sitting under a live form.
      await expect(applicantPage.getByTestId('screen-paused')).toBeHidden()
    } finally {
      await phone.close()
    }
  })
})
