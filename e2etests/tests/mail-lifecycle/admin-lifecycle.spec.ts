/**
 * The admin lifecycle chain, end to end (ADR-0044 rule 3, #521).
 *
 *     create an account / move its roles → bin/cron.php → the club's mailbox
 *
 * ### What this file is for
 *
 * ADR-0044's claim is about a *witness*. With exactly one admin, a notice about
 * account creation travels from the person who acted, to the same person, about
 * something they just did — and is silent in precisely the case where that one
 * account is the compromised one. The club-level address is the second pair of
 * eyes, and it is only worth anything if mail actually arrives there.
 *
 * The PHPUnit tests pin the enqueue, the recipients and the rendering. None of
 * them can show that the scheduler a hosting panel really runs puts the notice
 * in a mailbox, and a control whose entire value is being *out of band* is not
 * demonstrated by an outbox row saying it meant to be. So every assertion here
 * is made against what Mailpit received (Pattern 010), and the queue is drained
 * only through the real `backend/bin/cron.php`.
 *
 * ### What is asserted here, and what is asserted in PHPUnit
 *
 * The zero-other-admins case — an installation whose only admin is the actor —
 * belongs to `AdminLifecycleNoticeTest`, because standing it up means
 * deactivating every admin including the one this suite authenticates as. What
 * this file proves is the half that only a real delivery can: the club address
 * receives a message, and that message says which account and which roles.
 *
 * ### Blast radius
 *
 * These notices go to **every active admin** as well as the club address, so
 * this file creates admins of its own and asserts against an address of its
 * own (Patterns 001, 003). It runs after the other mail projects for the reason
 * they run in order: its drains claim the whole queue. Its accounts are removed
 * and the club address is restored in `afterAll`, so a later drain has nothing
 * of this file's left to talk about.
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
import { tokenFromInvitationUrl, INVITED_ADMIN_PASSWORD } from '../../utils/adminInvitation'

const MAIL_CONFIG = '/api/admin/mail-config'

/** A sender is required before the drain will send anything at all. */
const SENDER_ADDRESS = 'noreply@lifecycle-chain.test.example'

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
    .replace(/\s+/g, ' ')
}

/** Both parts of a message, each flattened to comparable text. */
function parts(message: MailpitMessage): { html: string; text: string } {
  return { html: htmlAsText(message.HTML), text: message.Text }
}

test.describe('Admin lifecycle — account, roles, cron, delivered mail', () => {
  const suffix = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`

  /** The club-level address this file asserts for. It belongs to no account. */
  const clubAddress = `vorstand-${suffix}@test.example`

  /** The account the notices are *about*. */
  const targetEmail = `lifecycle-${suffix}@test.example`

  let targetId = ''
  let previousClubAddress: string | null = null
  /**
   * The link the API handed back at creation, kept so the delivered message can
   * be compared against it — and so the club's copy can be checked for *not*
   * containing it.
   */
  let issuedInvitationUrl = ''

  test.beforeAll(async ({ authenticatedRequest }) => {
    await assertMailpitReachable()
    const client = await createMailpitClient()
    mail = client.mail
    disposeMailpit = client.dispose

    const config = await (await authenticatedRequest.get(MAIL_CONFIG)).json()
    previousClubAddress = config.club_notification_address ?? null

    const patched = await authenticatedRequest.patch(MAIL_CONFIG, {
      data: {
        sender_address: config.sender_address || SENDER_ADDRESS,
        club_notification_address: clubAddress,
      },
    })
    expect(patched.status(), await patched.text()).toBe(200)
  })

  test.afterAll(async ({ authenticatedRequest }) => {
    // An extra active admin fans every later notice out to an address nobody
    // reads, and a club address left set would put this file's mailbox on
    // every later lifecycle event.
    if (targetId) {
      await authenticatedRequest.post(`/api/admin/admin-users/${targetId}/deactivate`)
    }
    await authenticatedRequest.patch(MAIL_CONFIG, {
      data: { club_notification_address: previousClubAddress },
    })
    await disposeMailpit?.()
  })

  /**
   * The loud path. ADR-0044 calls account creation the event that already fires
   * lifecycle mail — it was loud only in intention until this chain existed.
   */
  test('the club is told when an admin account is created', async ({ authenticatedRequest }) => {
    const created = await authenticatedRequest.post('/api/admin/admin-users', {
      data: {
        ...stepUp(),
        email: targetEmail,
        display_name: `Lifecycle Kassenwart ${suffix}`,
        locale: 'de',
        roles: ['kassenwart'],
      },
    })
    expect(created.status(), await created.text()).toBe(201)
    const body = await created.json()
    targetId = body.admin.id
    // Kept so the assertions below can look for the real credential rather than
    // for a pattern that resembles one. Since migration 058 that credential is
    // the invitation link; there is no password to leak any more.
    issuedInvitationUrl = body.invitation.url

    // The run's own output, asserted before the mailbox: a tick that never
    // reached the drain would otherwise surface as "expected a message,
    // received none", which reads as a missing notice rather than a missing run.
    const run = drainMailQueue({ budgetSeconds: BUDGET_SECONDS })
    expect(run, run).toMatch(/claimed=\d+ sent=\d+/)

    // Keyed on the subject, because that is what `waitForMessageAbout`
    // filters on — the account's address lives in the body, and is asserted
    // there.
    const message = await mail.waitForMessageAbout(clubAddress, 'neues Admin-Konto')

    const { html, text } = parts(message)
    for (const part of [html, text]) {
      // …which account…
      expect(part).toContain(targetEmail)
      // …what it can do, in the word the club uses…
      expect(part).toContain('Kassenwart')
      // …and where to look if this was not expected.
      expect(part).toContain('Audit-Log')

      // The property the whole message is built around: it reaches a wider
      // audience than the person being onboarded, so it must not carry their
      // credential. Before migration 058 that meant the generated password;
      // now it means the invitation link, which is the same risk in a new
      // shape — a working way into the new account, on a list.
      expect(part).not.toContain(issuedInvitationUrl)
      expect(part).not.toContain('/invite#')
    }
  })

  /**
   * The chain the invitation actually depends on (migration 058, UC-A68):
   *
   *     create an account → bin/cron.php → the *invitee's* mailbox → a working link
   *
   * This is the assertion no unit test can make. The link is rendered by the
   * drain from a token stored only as a hash plus a sealed copy, so everything
   * between "an invitation exists" and "somebody can use it" — the dedup key
   * fitting its column, the cipher round-tripping, the URL surviving an HTML
   * mail — is only exercised here. An earlier revision of this feature queued
   * a `dedup_key` two characters too long for its column; every unit test
   * passed, every API test passed, and no invitation was ever mailed.
   */
  test('the invitee is mailed a link that actually works', async ({
    authenticatedRequest,
    request,
  }) => {
    const run = drainMailQueue({ budgetSeconds: BUDGET_SECONDS })
    expect(run, run).toMatch(/claimed=\d+ sent=\d+/)

    // To the account being onboarded — not to the club, and not to the other
    // admins. It is the one message here with a single recipient.
    // "Dein Zugang zu <club>" — deliberately not "Admin-Zugang", since the
    // account may be a Getränkewart's; the role is a row inside the message.
    const message = await mail.waitForMessageAbout(targetEmail, 'Dein Zugang')

    const { html, text } = parts(message)
    for (const part of [html, text]) {
      // The same link the API returned: the drain rebuilt it from the sealed
      // token rather than inventing one.
      expect(part).toContain(issuedInvitationUrl)
      // Said before it happens, so the authenticator prompt reads as the next
      // step rather than as an obstacle.
      expect(part).toContain('Zwei-Faktor')
      // The account's own role, named — this one is a Kassenwart's.
      expect(part).toContain('Kassenwart')
    }

    // And the message does not call it an admin account. It is not one, and
    // telling a Kassenwart otherwise is the failure this assertion pins: the
    // first draft opened "Dein Admin-Zugang für …" whatever the role behind it.
    expect(message.Subject).not.toContain('Admin-Zugang')

    // And it is a link, not a picture of one.
    const token = tokenFromInvitationUrl(issuedInvitationUrl)
    const accepted = await request.post('/api/invitations/accept', {
      data: {
        token,
        password: INVITED_ADMIN_PASSWORD,
        password_confirmation: INVITED_ADMIN_PASSWORD,
      },
    })
    expect(accepted.status(), await accepted.text()).toBe(200)
  })

  /**
   * The quiet path, which this epic exists to make loud: promoting an account
   * costs a live session and, until #520, produced no signal at all.
   */
  test('the club is told when an account gains a role', async ({ authenticatedRequest }) => {
    const promoted = await authenticatedRequest.patch(`/api/admin/admin-users/${targetId}`, {
      data: { ...stepUp(), roles: ['kassenwart', 'getraenkewart'] },
    })
    expect(promoted.status(), await promoted.text()).toBe(200)

    const run = drainMailQueue({ budgetSeconds: BUDGET_SECONDS })
    expect(run, run).toMatch(/claimed=\d+ sent=\d+/)

    const message = await mail.waitForMessageAbout(clubAddress, 'Rollen')

    const { html, text } = parts(message)
    for (const part of [html, text]) {
      expect(part).toContain(targetEmail)
      // The role set as it stands *now*, which is what the message reports —
      // the delta lives in the audit log, and a message rendered after a second
      // change must not claim a state that has been superseded.
      expect(part).toContain('Kassenwart')
      expect(part).toContain('Getränkewart')
      expect(part).not.toContain('/invite#')
    }
  })
})
