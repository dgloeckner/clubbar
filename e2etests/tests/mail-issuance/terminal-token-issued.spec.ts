/**
 * The issuance chain, end to end (ADR-0043).
 *
 *     mint a credential → bin/cron.php → every active admin's mailbox
 *
 * ### What this file is for
 *
 * ADR-0043's claim is about a person, not a row: an admin who did not perform
 * the minting, and who never opens the panel, learns that a terminal credential
 * came into existence. The PHPUnit tests pin the occasion format, the rendering
 * and the best-effort contract. None of them can show that the scheduler a
 * hosting panel actually runs puts the notice in a mailbox — and a control whose
 * entire value is *being out of band* is not demonstrated by an outbox row
 * saying it meant to be.
 *
 * So every assertion here is made against the message Mailpit received
 * (Pattern 010), and the queue is drained only through the real
 * `backend/bin/cron.php`.
 *
 * ### Nothing is faked
 *
 * Unlike the expiry chain, this one needs no SQL: issuance is something an admin
 * *does*, so the whole chain is reachable through the API. Both mint paths are
 * driven — enrolling a device and rotating an existing terminal's token — because
 * ADR-0043 covers both deliberately, and because the rotation is the one that
 * has to find a generation the enrolment did not leave behind.
 *
 * ### Blast radius
 *
 * An issuance notice goes to **every active admin**, so this file creates one of
 * its own and asserts only against that address (Patterns 001, 003) — the seeded
 * admin receives copies, which is the feature working. It runs last of the mail
 * projects for the reason the two before it do: its drains claim the whole
 * queue. Its own terminal and admin are cleaned up in `afterAll` so a later
 * drain has nothing of this file's left to talk about.
 */

import { test, expect } from '../../fixtures/auth.fixture'
import {
  assertMailpitReachable,
  createMailpitClient,
  MailpitClient,
  MailpitMessage,
} from '../../utils/mailpit'
import { drainMailQueue } from '../../utils/drain'
import { stepUp } from '../../fixtures/stepUp'

const MAIL_CONFIG = '/api/admin/mail-config'

/** A sender is required before the drain will send anything at all. */
const SENDER_ADDRESS = 'noreply@issuance-chain.test.example'

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

test.describe('Terminal credential issuance — mint, cron, delivered mail', () => {
  const suffix = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`

  /** The admin this file asserts for. They mint nothing; they are only told. */
  const adminEmail = `issuance-${suffix}@test.example`

  let adminUserId = ''
  let terminalId = ''
  let terminalName = ''
  let enrolledToken = ''
  let rotatedToken = ''

  test.beforeAll(async ({ authenticatedRequest }) => {
    await assertMailpitReachable()
    const client = await createMailpitClient()
    mail = client.mail
    disposeMailpit = client.dispose

    const config = await (await authenticatedRequest.get(MAIL_CONFIG)).json()
    if (!config.sender_address) {
      const patched = await authenticatedRequest.patch(MAIL_CONFIG, {
        data: { sender_address: SENDER_ADDRESS },
      })
      expect(patched.status(), await patched.text()).toBe(200)
    }

    // Created **before** the terminal, and that ordering is the test: the fan-out
    // happens at enqueue time, so an admin who exists only afterwards is not a
    // recipient. This one is on the list before anything is minted, exactly as a
    // colleague who has been an admin all along would be.
    const admin = await authenticatedRequest.post('/api/admin/admin-users', {
      data: { email: adminEmail, display_name: `Issuance Kassenwart ${suffix}`, locale: 'de' },
    })
    expect(admin.status(), await admin.text()).toBe(201)
    adminUserId = (await admin.json()).id

    // The minting itself, here rather than in the first test, because state
    // assigned *inside* a test does not survive one: Playwright discards the
    // worker after a failure and re-imports the file for the next test, so a
    // terminal id set in test one is empty in test two and every later
    // assertion fails for a reason that has nothing to do with what it checks.
    terminalName = `Theke ${suffix}`
    // Minting is a step-up operation (#395): the credential it issues can read
    // the membership list.
    const created = await authenticatedRequest.post('/api/admin/terminals', {
      data: { name: terminalName, device_id: `issued-${suffix}`, ...stepUp() },
    })
    expect(created.status(), await created.text()).toBe(201)
    const body = await created.json()
    terminalId = body.terminal.id
    // Kept so the "carries no token material" assertions can look for the real
    // thing rather than for a pattern that resembles it.
    enrolledToken = body.api_token
  })

  test.afterAll(async ({ authenticatedRequest }) => {
    // Both cleanups matter to *other* files: a terminal left active is one more
    // credential later drains can warn about, and an extra active admin fans
    // every later notice out to an address nobody reads.
    if (terminalId) {
      await authenticatedRequest.delete(`/api/admin/terminals/${terminalId}`)
    }
    if (adminUserId) {
      await authenticatedRequest.delete(`/api/admin/admin-users/${adminUserId}`)
    }
    await disposeMailpit?.()
  })

  /**
   * The claim itself, in one flow (Pattern 009): a credential minted by somebody
   * else, a scheduler run nobody logged in for, and a message that says which
   * till, when, and what to do if it was not us.
   */
  test('tells an admin who minted nothing that a credential was issued', async () => {
    // The run's own output, asserted before the mailbox: a tick that never
    // reached the drain would otherwise surface as "expected 1 message,
    // received 0", which reads as a missing notice rather than a missing run.
    const run = drainMailQueue({ budgetSeconds: BUDGET_SECONDS })
    expect(run, run).toMatch(/claimed=\d+ sent=\d+/)

    const message = await mail.waitForMessageAbout(adminEmail, terminalName)

    // Which till, and that it was an enrolment rather than a rotation.
    expect(message.Subject).toContain('Neues Terminal')

    const { html, text } = parts(message)
    for (const part of [html, text]) {
      // …which device…
      expect(part).toContain(`issued-${suffix}`)
      // …what to do if this was not the club, named as the panel labels it…
      expect(part).toContain('Zugang sperren')
      // …where the actor is recorded, since the message does not guess…
      expect(part).toContain('Protokoll')
      // …and why it reached an admin who did nothing.
      expect(part).toContain('alle aktiven Administratorinnen')
    }

    // A second tick has nothing new to say. The outbox's unique index is what
    // makes that true — asserted at the mailbox rather than at the queue,
    // because a duplicate row is our bookkeeping and a duplicate message is
    // what the admin would live with.
    drainMailQueue({ budgetSeconds: BUDGET_SECONDS })
    const stillOne = await mail.waitForMessagesAbout(adminEmail, terminalName, 1)
    expect(stillOne).toHaveLength(1)
  })

  /**
   * Rotation is the other mint path, and the one whose message has to find a
   * credential that is not yet the terminal's active one. Two notices about one
   * till is the intended outcome: the generation is what makes them distinct,
   * so a second issuance is a second message rather than one the unique index
   * swallows.
   */
  test('a rotation is announced as its own event', async ({ authenticatedRequest }) => {
    const rotated = await authenticatedRequest.post(
      `/api/admin/terminals/${terminalId}/rotate-token`,
      { data: { ...stepUp() } }
    )
    expect(rotated.status(), await rotated.text()).toBe(200)
    rotatedToken = (await rotated.json()).api_token

    drainMailQueue({ budgetSeconds: BUDGET_SECONDS })

    const messages = await mail.waitForMessagesAbout(adminEmail, terminalName, 2)
    const [enrolment, rotation] = messages

    expect(enrolment.Subject).toContain('Neues Terminal')
    expect(rotation.Subject).toContain('Neuer Zugang')

    const { html, text } = parts(rotation)
    for (const part of [html, text]) {
      expect(part).toContain('Token rotiert')
      // The old credential keeps working until the terminal presents the new
      // one (#395), and the message says so rather than implying an outage.
      expect(part).toContain('bis der neue am Terminal zum ersten Mal verwendet wird')
    }
  })

  /**
   * The message exists because a secret was created, so it must be worth
   * nothing to whoever intercepts it. Asserted against the delivered bytes
   * rather than the template, because that is the artefact that leaves the
   * building — and against both tokens, because both were minted while this
   * file was watching.
   */
  test('the delivered notices carry no token material', async () => {
    const messages = await mail.waitForMessagesAbout(adminEmail, terminalName, 2)

    expect(enrolledToken).toHaveLength(64)
    expect(rotatedToken).toHaveLength(64)

    for (const message of messages) {
      const { html, text } = parts(message)
      for (const part of [html, text]) {
        expect(part).not.toContain(enrolledToken)
        expect(part).not.toContain(rotatedToken)
        expect(part).not.toMatch(/\b[0-9a-f]{64}\b/)
        expect(part).not.toContain('api_token')
        expect(part).toContain('keinerlei Zugangsdaten')
      }
    }
  })

  /**
   * Revocation is the reader's next step, and it must not itself become a
   * notice: nothing is minted, so there is nothing to announce. Asserted
   * because the opposite — a message per credential *event* rather than per
   * credential *created* — is the easy mistake, and it would train admins to
   * skim exactly the mail this feature needs them to read.
   */
  test('revoking announces nothing', async ({ authenticatedRequest }) => {
    const revoked = await authenticatedRequest.post(`/api/admin/terminals/${terminalId}/revoke`)
    expect(revoked.status(), await revoked.text()).toBe(200)

    drainMailQueue({ budgetSeconds: BUDGET_SECONDS })

    const messages = await mail.waitForMessagesAbout(adminEmail, terminalName, 2)
    expect(messages).toHaveLength(2)
  })
})
