/**
 * The Anmeldelink chain, end to end (#821, ADR-0053, UC-A70).
 *
 *     a Kassenwart types an address → bin/cron.php → a stranger's mailbox
 *                                                  → the registration form
 *                                                  → the review inbox
 *
 * ### What only this file can show
 *
 * The unit tests pin what the message says and the API suite pins the row it
 * leaves behind. Neither can show the claim the whole feature rests on: that
 * **the link a stranger receives is the poster's own**, and opening it in a
 * browser holding no session reaches the same form the QR code opens and
 * produces a registration the club can review.
 *
 * So the link is not asserted against a string this file built — it is read out
 * of the delivered message, opened in a fresh browser context, filled in, and
 * the resulting row is found in the admin inbox. If the mailed URL ever drifted
 * from the poster's, every step after the first would fail.
 *
 * The address travels the whole way too (#823): the Kassenwart types it here,
 * the link carries it in its fragment, the form arrives holding it, and it is
 * the address the inbox row is finally found by — never typed by the applicant
 * at any point.
 *
 * ### Nothing is faked
 *
 * The poster secret is the exception, and it is the same exception
 * `self-registration.spec.ts` documents: the secret is minted server-side and
 * shown once, so no API this product will ever have hands a *known* one back.
 * The suite writes the hash it wants to test against. Everything else — the
 * send, the drain, the delivery, the submission, the review — goes through the
 * real surface.
 *
 * ### Blast radius
 *
 * Every message here is addressed to an address this file generated, so it
 * cannot collide with the admin-mailbox counts the chains before it assert on.
 * It is in the chain anyway, and last, for the reason they all are: its drains
 * claim the **whole queue**, so running it beside another sending project would
 * sweep messages that project is waiting to assert are still pending.
 *
 * It also writes `self_registration_config`, the singleton row four other spec
 * files contend over — so it takes the same cross-file lock they do (#784).
 */

import { randomUUID } from 'node:crypto'

// `roleRequests` extends `auth.fixture`, so this file gets the seeded admin's
// session *and* the per-worker Kassenwart the role assertions need (Pattern 011).
import { test, expect } from '../../fixtures/roleRequests'
import { drainMailQueue } from '../../utils/drain'
import {
  assertMailpitReachable,
  createMailpitClient,
  MailpitClient,
  MailpitMessage,
} from '../../utils/mailpit'
import { lockSelfRegistration, unlockSelfRegistration } from '../../utils/registrationLock'
import {
  clearRegistrationAttempts,
  configureSelfRegistration,
  countPendingRegistrations,
  serveClubDocument,
  stopServingClubDocument,
} from '../../utils/sql'

const MAIL_CONFIG = '/api/admin/mail-config'
const SEND_LINK = '/api/admin/registrations/link'

/** A sender is required before the drain will send anything at all. */
const SENDER_ADDRESS = 'noreply@anmeldelink-chain.test.example'

/** The origin the backend serves the registration page from. */
const APP_URL = process.env.API_URL || 'http://localhost:8080'

const TEST_IBAN = 'DE89370400440532013000'

let mail: MailpitClient
let disposeMailpit: () => Promise<void>

/**
 * Short local parts: RFC 5321 bounds one at 64 characters, and Pattern 010
 * records that this suite has been bitten by that before.
 */
function prospectAddress(tag: string): string {
  return `anm-${tag}-${randomUUID().replace(/-/g, '').slice(0, 10)}@test.example`
}

function htmlAsText(html: string): string {
  return html.replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ')
}

function parts(message: MailpitMessage): { html: string; text: string } {
  return { html: htmlAsText(message.HTML), text: message.Text }
}

/**
 * The registration URL as it was actually delivered.
 *
 * Read out of the **plain-text** part, where it stands alone on its own line —
 * the HTML part carries the same URL inside an `href`, and pulling it out of
 * markup would be asserting on the template rather than on the link.
 */
function deliveredLink(message: MailpitMessage): string {
  const match = message.Text.match(/https?:\/\/\S*\/register#\S+/)
  expect(match, `no registration link in the delivered message:\n${message.Text}`).toBeTruthy()

  return (match as RegExpMatchArray)[0]
}

test.describe('Anmeldelink — send, cron, delivered mail, and back through the form', () => {
  test.describe.configure({ mode: 'serial' })

  test.beforeAll(async ({ authenticatedRequest }) => {
    await assertMailpitReachable()
    const client = await createMailpitClient()
    mail = client.mail
    disposeMailpit = client.dispose

    // A drain with no sender configured claims nothing at all, so every
    // assertion below would pass vacuously.
    const current = await authenticatedRequest.get(MAIL_CONFIG)
    expect(current.status(), await current.text()).toBe(200)
    if (!(await current.json()).sender_address) {
      const patched = await authenticatedRequest.patch(MAIL_CONFIG, {
        data: { sender_address: SENDER_ADDRESS },
      })
      expect(patched.status(), await patched.text()).toBe(200)
    }

    // The submission leg needs a fetchable club document: without one the
    // public endpoint refuses, which would read here as a broken link.
    serveClubDocument()
  })

  test.afterAll(async () => {
    stopServingClubDocument()
    await disposeMailpit?.()
  })

  /**
   * One writer at a time on `self_registration_config` (#784). Held for the
   * whole test, because the window spans the write, the send, the drain and
   * the submission that presents what was written.
   */
  test.beforeEach(() => {
    lockSelfRegistration()
    // The public endpoint's rate-limit meter is per source address, and every
    // spec in the run arrives from the same one.
    clearRegistrationAttempts()
  })

  test.afterEach(() => {
    unlockSelfRegistration()
  })

  /**
   * Acceptance 1 and 2, as one flow (Pattern 009): the second is only
   * meaningful given the first, and splitting them would mean sending the same
   * message twice to assert two halves of one claim.
   */
  test('a Kassenwart sends a link that opens the real form and produces a reviewable registration', async ({
    kassenwartRequest,
    authenticatedRequest,
    browser,
  }) => {
    const secret = `secret-${randomUUID()}`
    configureSelfRegistration(secret)

    const email = prospectAddress('flow')

    // ── the send, by the office decision 5 names ───────────────────────
    const sent = await kassenwartRequest.post(SEND_LINK, { data: { email } })
    expect(sent.status(), await sent.text()).toBe(202)

    // ── the real scheduler, the real transport ─────────────────────────
    drainMailQueue()

    const message = await mail.waitForMessage(email)
    const both = parts(message)

    // German, because there is no club-level default to render an unknown
    // reader's message in and inventing one belongs to #820 (decision 6).
    expect(message.Subject).toContain('Mitglied werden')

    // The biggest surprise in this flow, and the reason the body says it: a
    // poster-scanner is standing in the clubhouse and learns in a minute that
    // filling the form is not joining. Somebody reading a link at home does not.
    for (const part of [both.html, both.text]) {
      expect(part).toMatch(/ausdrucken/i)
      expect(part).toMatch(/unterschreib/i)
    }

    // ── the link is the poster's, and this is how that is proved ───────
    const link = deliveredLink(message)
    // The poster's URL, plus the address this message was sent to (#823). It
    // rides after the `#`, so no access log in front of the installation ever
    // sees it — the same rule that puts the secret there.
    expect(link).toBe(`${APP_URL}/register#${secret}&email=${encodeURIComponent(email)}`)

    // A context of its own: no session, no cookies, nothing this run's admin
    // fixtures left behind. This is a stranger on a phone.
    const context = await browser.newContext()
    const page = await context.newPage()

    try {
      const before = countPendingRegistrations()

      // Opened verbatim, exactly as a mail client hands it to a browser.
      await page.goto(link)

      await expect(page.getByTestId('screen-language')).toBeVisible()
      await page.getByTestId('language-de').click()
      await expect(page.getByTestId('screen-form')).toBeVisible()

      // The one field the reader does not have to type, because the club
      // already knows it (#823) — filled in from the link they arrived on.
      // Left untouched below, so what lands in the inbox proves it end to end.
      const applicantEmail = email
      await expect(page.getByTestId('field-email')).toHaveValue(applicantEmail)

      const lastName = `Brandt-${randomUUID().slice(0, 8)}`

      for (const [testId, value] of Object.entries({
        'field-first-name': 'Lena',
        'field-last-name': lastName,
        'field-date-of-birth': '23111979',
        'field-iban': TEST_IBAN,
      })) {
        await page.getByTestId(testId).fill(value)
      }

      await page.getByTestId('submit-button').click()
      await page.getByTestId('confirm-button').click()
      await expect(page.getByTestId('screen-done')).toBeVisible()

      expect(countPendingRegistrations()).toBe(before + 1)

      // ── and it lands where a Kassenwart will find it ─────────────────
      const inbox = await authenticatedRequest.get(
        `/api/admin/registrations?search=${encodeURIComponent(applicantEmail)}&per_page=25`,
      )
      expect(inbox.status(), await inbox.text()).toBe(200)

      // Searched by the address this test generated rather than read off
      // position 0 (Pattern 003): other workers submit registrations too.
      const rows = ((await inbox.json()).data ?? []).filter(
        (row: { email?: string }) => row.email === applicantEmail,
      )
      expect(rows).toHaveLength(1)
      expect(rows[0].last_name).toBe(lastName)
    } finally {
      await context.close()
    }
  })

  /**
   * Acceptance 3, at the mailbox rather than at the queue.
   *
   * The API suite already asserts two rows; this asserts two *messages*, which
   * is the claim that matters — the whole reason `dedup_key` carries a nonce is
   * that the second send is the one answering "I never got it", and a reader
   * who gets nothing has been failed by a design nobody meant.
   */
  test('sending twice to one address delivers twice', async ({ authenticatedRequest }) => {
    configureSelfRegistration(`secret-${randomUUID()}`)
    const email = prospectAddress('twice')

    for (const _ of [1, 2]) {
      const sent = await authenticatedRequest.post(SEND_LINK, { data: { email } })
      expect(sent.status(), await sent.text()).toBe(202)
    }

    drainMailQueue()

    const messages = await mail.waitForMessages(email, 2)
    expect(messages).toHaveLength(2)
  })

  /**
   * Acceptance 4, proved over a window rather than by looking once.
   *
   * "Nothing was sent" asserted before a drain would pass for the wrong reason,
   * so the drain runs first and `expectNothingFor` watches afterwards.
   */
  test('a club with self-registration switched off sends nothing at all', async ({
    authenticatedRequest,
  }) => {
    configureSelfRegistration(`secret-${randomUUID()}`, {
      enabled: false,
      disabledReason: 'Beta-Phase schon voll',
    })
    const email = prospectAddress('off')

    const refused = await authenticatedRequest.post(SEND_LINK, { data: { email } })
    expect(refused.status()).toBe(409)
    expect((await refused.json()).reason).toBe('registration_disabled')

    drainMailQueue()
    await mail.expectNothingFor(email)
  })
})
