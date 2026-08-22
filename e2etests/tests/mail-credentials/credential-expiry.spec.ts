/**
 * The credential expiry chain, end to end (#438, ADR-0036).
 *
 *     token near expiry → bin/cron.php (scan + drain) → an admin's mailbox
 *
 * ### What this file is for
 *
 * #438's acceptance criterion is about a person, not a row: *"an admin who does
 * not open the admin panel still receives at least one warning per tier before
 * an encryption key or terminal token expires."* The PHPUnit tests pin the tier
 * arithmetic, the idempotency and the wording. None of them can show that the
 * scheduler a hosting panel actually runs puts that warning in a mailbox — and
 * a feature whose entire purpose is *"somebody is told without logging in"* is
 * not demonstrated by a queue row saying it meant to.
 *
 * So every assertion here is made against the message Mailpit received
 * (Pattern 010), and the scan is reached only through the real
 * `backend/bin/cron.php`.
 *
 * ### The one thing that is not driven through the API
 *
 * A terminal token's lifetime comes from `AppConfig::$tokenTtlDays`, not from
 * the create request, so no sequence of HTTP calls produces a credential seven
 * days from expiry. `setTerminalTokenExpiry()` moves the date directly; see
 * `utils/sql.ts` for why that exception is drawn here and nowhere else.
 *
 * ### Blast radius
 *
 * An expiry warning goes to **every active admin**, so this file creates one of
 * its own and asserts only against that address (Patterns 001, 003) — the
 * seeded admin receives copies, which is the feature working. The terminal is
 * deactivated in `afterAll` so a later drain cannot warn about it again, and
 * the project runs last for the same reason `mail-statement` does: its runs
 * drain the whole queue.
 */

import { test, expect } from '../../fixtures/auth.fixture'
import {
  assertMailpitReachable,
  createMailpitClient,
  MailpitClient,
  MailpitMessage,
} from '../../utils/mailpit'
import { drainMailQueue } from '../../utils/drain'
import { setTerminalTokenExpiry } from '../../utils/sql'
import { clubDate } from '../../utils/dates'
import { stepUp } from '../../fixtures/stepUp'

const MAIL_CONFIG = '/api/admin/mail-config'

/** A sender is required before the drain will send anything at all. */
const SENDER_ADDRESS = 'noreply@credential-chain.test.example'

/**
 * What an expiry warning's subject says at every tier, and no other kind's
 * does (`credential_expiry.subject.*` in `MailStrings`).
 *
 * This file no longer owns the whole mailbox. Since ADR-0043 every terminal
 * enrolment and rotation queues a notice to every active admin — including the
 * enrolment in `beforeAll` that stands up the terminal this file then ages
 * towards expiry — so an admin here holds two classes of message and only one
 * of them is under test. Narrowing on the subject keeps the "exactly one"
 * counting rule that catches a duplicate warning, without claiming messages
 * another feature is responsible for.
 */
const EXPIRY_SUBJECT = 'läuft in'

/** The whole queue may be waiting; a run needs room to reach these messages. */
const BUDGET_SECONDS = 55

let mail: MailpitClient
let disposeMailpit: () => Promise<void>

/** The HTML part as comparable text, so one assertion can cover both parts. */
function htmlAsText(html: string): string {
  return html
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&#8222;|&#8220;/g, '„')
    .replace(/&#8221;|&#8216;|&#8217;/g, '“')
    .replace(/\s+/g, ' ')
}

/** Both parts of a message, each flattened to comparable text. */
function parts(message: MailpitMessage): { html: string; text: string } {
  return { html: htmlAsText(message.HTML), text: message.Text }
}

test.describe('Credential expiry warnings — scan, cron, delivered mail', () => {
  const suffix = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`

  /** The admin this file asserts for. Every other active admin gets a copy. */
  const adminEmail = `expiry-${suffix}@test.example`

  let adminUserId = ''
  let terminalId = ''
  let terminalName = ''
  let issuedToken = ''

  test.beforeAll(async ({ authenticatedRequest }) => {
    await assertMailpitReachable()
    const client = await createMailpitClient()
    mail = client.mail
    disposeMailpit = client.dispose

    // The scan declines entirely on an installation that cannot send — that is
    // the "stays fully optional" half of the acceptance criterion — so a sender
    // has to exist before anything here can be observed. Filled in only when
    // the installation has none, exactly as the Deckelauszug chain does.
    const config = await (await authenticatedRequest.get(MAIL_CONFIG)).json()
    if (!config.sender_address) {
      const patched = await authenticatedRequest.patch(MAIL_CONFIG, {
        data: { sender_address: SENDER_ADDRESS },
      })
      expect(patched.status(), await patched.text()).toBe(200)
    }

    const admin = await authenticatedRequest.post('/api/admin/admin-users', {
      data: { ...stepUp(), email: adminEmail, display_name: `Expiry Kassenwart ${suffix}`, locale: 'de' },
    })
    expect(admin.status(), await admin.text()).toBe(201)
    adminUserId = (await admin.json()).id

    terminalName = `Theke ${suffix}`
    // Issuing a terminal token is a step-up operation (#395): the credential it
    // mints can read the membership list.
    const terminal = await authenticatedRequest.post('/api/admin/terminals', {
      data: { name: terminalName, device_id: `expiry-${suffix}`, ...stepUp() },
    })
    expect(terminal.status(), await terminal.text()).toBe(201)
    const created = await terminal.json()
    terminalId = created.terminal.id
    // Kept so the "carries no token material" test can look for the real thing
    // rather than for a pattern that resembles it.
    issuedToken = created.api_token
  })

  test.afterAll(async ({ authenticatedRequest }) => {
    // Both cleanups matter to *other* files, not to this one: an active
    // terminal left inside a warning tier would have every later drain queue a
    // warning about it, and an extra active admin would have every later
    // warning fan out to an address nobody is reading.
    if (terminalId) {
      await authenticatedRequest.delete(`/api/admin/terminals/${terminalId}`)
    }
    if (adminUserId) {
      await authenticatedRequest.delete(`/api/admin/admin-users/${adminUserId}`)
    }
    await disposeMailpit?.()
  })

  /**
   * The acceptance criterion itself, in one flow (Pattern 009): a token inside
   * the seven-day tier, a scheduler run nobody logged in for, and a mail that
   * says which credential, by when, and what to do about it.
   */
  test('warns an admin who never opened the panel, once per tier', async () => {
    const expiresIn = 5
    // The deadline the database actually wrote, not `Date.now() + 5 days`: the
    // interval is added to the database's clock, and the date the mail prints
    // is that instant in the *club's* zone. Recomputing it from the runner's
    // clock agreed with the mail for 22 hours a day and disagreed for the two
    // before midnight UTC, which is when this ran and went red (#637).
    const deadline = setTerminalTokenExpiry(terminalId, expiresIn)

    // The run's own output, asserted before the mailbox: a tick that never
    // reached the scan would otherwise surface as "expected 1 message,
    // received 0" — the same misdiagnosis `drainMailQueue`'s ABORTED check
    // exists to prevent.
    const run = drainMailQueue({ budgetSeconds: BUDGET_SECONDS })
    expect(run, run).toContain('Credential expiry scan: examined=')
    expect(run, run).not.toContain('warning_window=0')

    const message = await mail.waitForMessageAbout(adminEmail, EXPIRY_SUBJECT)

    // Which credential, and how urgent — the seven-day subject carries the
    // urgency because at that point nobody opens a mail to find out.
    expect(message.Subject).toContain(terminalName)
    expect(message.Subject).toContain('Dringend')

    const { html, text } = parts(message)
    for (const part of [html, text]) {
      // …by when…
      expect(part).toContain(clubDate(deadline))
      // …what happens if nobody acts…
      expect(part).toContain('kann sich das Terminal nicht mehr anmelden')
      // …and what to do, named as the admin panel labels it.
      expect(part).toContain('Token rotieren')
    }

    // A second tick finds the same token in the same tier. The queue's unique
    // index is what stops that being a second mail — asserted here at the
    // mailbox rather than at the outbox, because a duplicate row is our
    // bookkeeping and a duplicate message is what the admin would live with.
    drainMailQueue({ budgetSeconds: BUDGET_SECONDS })
    const stillOne = await mail.waitForMessagesAbout(adminEmail, EXPIRY_SUBJECT, 1)
    expect(stillOne).toHaveLength(1)
  })

  /**
   * The message is about a secret and must be worth nothing to whoever
   * intercepts it. Asserted against the delivered bytes rather than the
   * template, because that is the artefact that leaves the building.
   */
  test('the delivered warning carries no token material', async () => {
    const message = await mail.waitForMessageAbout(adminEmail, EXPIRY_SUBJECT)
    const { html, text } = parts(message)

    for (const part of [html, text]) {
      // The token this terminal was actually issued, and its hash — the two
      // things that would matter if this message were intercepted.
      expect(issuedToken).toHaveLength(64)
      expect(part).not.toContain(issuedToken)
      expect(part).not.toMatch(/\b[0-9a-f]{64}\b/)
      expect(part).not.toContain('api_token')
      expect(part).toContain('keinerlei Schlüssel- oder Zugangsdaten')
    }
  })

  /**
   * Past the deadline there is nothing left to warn *in advance* of: expiry is
   * enforced where it bites and shown as an error on the dashboard, so a fourth
   * message would be the least urgent of the places already saying so.
   */
  test('an already-expired token produces no further warning', async ({ authenticatedRequest }) => {
    const otherName = `Lager ${suffix}`
    const created = await authenticatedRequest.post('/api/admin/terminals', {
      data: { name: otherName, device_id: `expired-${suffix}`, ...stepUp() },
    })
    expect(created.status(), await created.text()).toBe(201)
    const expiredId = (await created.json()).terminal.id

    try {
      setTerminalTokenExpiry(expiredId, -3)
      drainMailQueue({ budgetSeconds: BUDGET_SECONDS })

      // The first terminal's warning is still the only expiry warning here.
      const messages = await mail.waitForMessagesAbout(adminEmail, EXPIRY_SUBJECT, 1)
      expect(messages[0].Subject).toContain(terminalName)
      expect(messages[0].Subject).not.toContain(otherName)
    } finally {
      await authenticatedRequest.delete(`/api/admin/terminals/${expiredId}`)
    }
  })
})
