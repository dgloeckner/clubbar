/**
 * The member lifecycle chain, end to end (ADR-0051).
 *
 *     card assigned / address changed → bin/cron.php → Mailpit
 *
 * The unit tests pin what each message *says* and the feature tests pin which
 * one a given record change produces. What neither can show is that a
 * Kassenwart typing a card UID into the edit form results in a member holding a
 * welcome in their inbox — through the real API, the real queue and the one
 * real sending path.
 *
 * ### The three things this file is careful about
 *
 * **Every negative is proved over a window, not by looking once.** Half the
 * claims here are of the form "nothing was sent", and a `messagesTo()` that
 * runs before the drain would pass for the wrong reason. So the drain runs
 * first and `expectNothingFor()` watches for two seconds afterwards.
 *
 * **The two ends of an address change are two mailboxes.** The whole point of
 * the pair is that the old address gets one message and the new address gets a
 * different one — asserting that at Mailpit is the only place the split is
 * visible as a member would experience it.
 *
 * **Idempotency is asserted after a second real drain.** "One row" tests our
 * bookkeeping; "one message" tests what a member would receive if
 * `UNIQUE (kind, subject_id, dedup_key)` ever stopped doing its job.
 *
 * ### Blast radius
 *
 * Small, and deliberately so: every message here is addressed to a member this
 * file created, at an address it generated. Nothing in this project writes to
 * an admin mailbox, so unlike the chains around it, it cannot collide with
 * their counts — but it still runs serially in the chain, because its drains
 * claim the whole queue like everybody else's.
 */

import { test, expect } from '../../fixtures/auth.fixture'
import { assertMailpitReachable, createMailpitClient, MailpitClient, MailpitMessage } from '../../utils/mailpit'
import { drainMailQueue } from '../../utils/drain'
import { FACTORY_IBAN } from '../../utils/settlements'
import type { ApiRequestLike } from '../../utils/request-context'

const MAIL_CONFIG = '/api/admin/mail-config'

/** A sender is required before the drain will send anything at all. */
const SENDER_ADDRESS = 'noreply@member-chain.test.example'
const KASSENWART = 'kassenwart@member-chain.test.example'

let mail: MailpitClient
let disposeMailpit: () => Promise<void>

/**
 * Short local parts: RFC 5321 bounds one at 64 characters and this suite has
 * been bitten by that before (Pattern 010).
 */
function uniqueSuffix(): string {
  return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`
}

function address(tag: string): string {
  return `mem-${tag}-${uniqueSuffix()}@test.example`
}

/**
 * A card UID the format rules accept: 8–20 uppercase hex (ADR-0021), unique
 * across members because the column carries a UNIQUE index.
 */
function cardUid(): string {
  return `${Date.now().toString(16)}${Math.random().toString(16).slice(2, 8)}`
    .toUpperCase()
    .replace(/[^0-9A-F]/g, '0')
    .slice(0, 16)
}

function htmlAsText(html: string): string {
  return html.replace(/<[^>]*>/g, ' ').replace(/&nbsp;/g, ' ').replace(/\s+/g, ' ')
}

function parts(message: MailpitMessage): { html: string; text: string } {
  return { html: htmlAsText(message.HTML), text: message.Text }
}

test.describe('Member lifecycle mail — card, address, cron, delivered message', () => {
  test.beforeAll(async ({ authenticatedRequest }) => {
    await assertMailpitReachable()
    const client = await createMailpitClient()
    mail = client.mail
    disposeMailpit = client.dispose

    // A drain with no sender configured claims nothing at all, so every
    // assertion below would pass vacuously.
    const current = await authenticatedRequest.get(MAIL_CONFIG)
    expect(current.status(), await current.text()).toBe(200)
    const config = (await current.json()).mail_config ?? (await (await authenticatedRequest.get(MAIL_CONFIG)).json())

    if (!config.sender_address) {
      const patched = await authenticatedRequest.patch(MAIL_CONFIG, {
        data: { sender_address: SENDER_ADDRESS, reply_to_address: KASSENWART },
      })
      expect(patched.status(), await patched.text()).toBe(200)
    }
  })

  test.afterAll(async () => {
    await disposeMailpit?.()
  })

  /** Create a member through the real endpoint. `cardUid: null` leaves them uncarded. */
  async function createMember(
    request: ApiRequestLike,
    options: { email: string; card?: string | null; language?: 'de' | 'en' },
  ): Promise<string> {
    const response = await request.post('/api/admin/members', {
      data: {
        first_name: 'Lifecycle',
        last_name: `Member${uniqueSuffix()}`,
        email: options.email,
        date_of_birth: '1985-06-15',
        preferred_language: options.language ?? 'de',
        card_uid: options.card ?? null,
        iban: FACTORY_IBAN,
        mandate_signed_at: '2024-01-01',
      },
    })
    expect(response.status(), await response.text()).toBe(201)

    return (await response.json()).id
  }

  async function patchMember(
    request: ApiRequestLike,
    memberId: string,
    data: Record<string, unknown>,
  ): Promise<void> {
    const response = await request.patch(`/api/admin/members/${memberId}`, { data })
    expect(response.status(), await response.text()).toBe(200)
  }

  /**
   * Assigning the first card is the onboarding, and creating the record is not.
   *
   * Both halves are asserted in one flow (Pattern 009), because the second is
   * only meaningful given the first: "no mail yet" proves nothing unless the
   * same address demonstrably receives one a moment later.
   */
  test('a member is welcomed when their card arrives, not when their record is created', async ({
    authenticatedRequest,
  }) => {
    const email = address('welcome')
    const memberId = await createMember(authenticatedRequest, { email, card: null })

    // A drain first, so "nothing was sent" is a claim about a queue that has
    // actually been given the chance to send.
    drainMailQueue()
    await mail.expectNothingFor(email)

    await patchMember(authenticatedRequest, memberId, { card_uid: cardUid() })
    drainMailQueue()

    const message = await mail.waitForMessage(email)
    expect(message.Subject).toContain('Willkommen')

    for (const [where, body] of Object.entries(parts(message))) {
      expect(body, `${where} says the card works`).toContain('freigeschaltet')
      // The paragraph that earns the mail its keep: a member told this cannot
      // be surprised by the first collection.
      expect(body, `${where} sets up the Vorabankündigung`).toContain('Vorabankündigung')
      expect(body, `${where} explains the tab`).toContain('Deckel')
      // The Kassenwart types the UID in while preparing the onboarding, so this
      // mail routinely lands before the plastic does.
      expect(body, `${where} allows for the card not having arrived`).toContain('noch gar nicht bekommen')
      // The registration form promises these arrive with the first
      // Vorabankündigung, not here (ADR-0051 §5).
      expect(body, `${where} must not carry the mandate reference`).not.toContain('Mandatsreferenz')
      expect(body, `${where} must not carry the creditor id`).not.toContain('Gläubiger')
    }

    // A second real drain: one welcome, still.
    drainMailQueue()
    expect(await mail.messagesTo(email)).toHaveLength(1)
  })

  /** A later card is a replacement, and it says so rather than greeting again. */
  test('a replacement card retires the old one without a second welcome', async ({
    authenticatedRequest,
  }) => {
    const email = address('card')
    const memberId = await createMember(authenticatedRequest, { email, card: cardUid() })

    drainMailQueue()
    const welcome = await mail.waitForMessage(email)
    expect(welcome.Subject).toContain('Willkommen')

    await patchMember(authenticatedRequest, memberId, { card_uid: cardUid() })
    drainMailQueue()

    const [, replacement] = await mail.waitForMessages(email, 2)
    expect(replacement.Subject).toContain('neue Karte')

    for (const [where, body] of Object.entries(parts(replacement))) {
      expect(body, `${where} retires the old card`).toContain('funktioniert nicht mehr')
      expect(body, `${where} reassures about the tab`).toContain('unverändert')
      // Assigning a new UID stops the old card immediately, so a member who has
      // not been handed the replacement yet genuinely cannot pay. Being told
      // beforehand is the difference between a warned-about gap and a card that
      // mysteriously stopped working at the bar.
      expect(body, `${where} warns about the gap`).toContain('nicht bezahlen')
    }

    // Two messages, and the second is not a greeting.
    expect(replacement.Subject).not.toContain('Willkommen')
  })

  /**
   * The pair, and the only place the split is visible as a member sees it: the
   * old mailbox gets one message, the new mailbox a different one, and neither
   * names the other address.
   */
  test('an address change writes to both mailboxes and neither names the other', async ({
    authenticatedRequest,
  }) => {
    const before = address('old')
    const after = address('new')
    const memberId = await createMember(authenticatedRequest, { email: before, card: cardUid() })

    drainMailQueue()
    await mail.waitForMessage(before) // the welcome

    await patchMember(authenticatedRequest, memberId, { email: after })
    drainMailQueue()

    const [, changed] = await mail.waitForMessages(before, 2)
    const activated = await mail.waitForMessage(after)

    expect(changed.Subject).toContain('geändert')
    expect(activated.Subject).toContain('Vereinsadresse')

    for (const [where, body] of Object.entries(parts(changed))) {
      expect(body, `${where} names the address it arrived at`).toContain(before)
      // ADR-0051 §4 — the load-bearing one.
      expect(body, `${where} must not name the new address`).not.toContain(after)
      expect(body, `${where} says it is the last one`).toContain('letzte Nachricht')
    }

    for (const [where, body] of Object.entries(parts(activated))) {
      expect(body, `${where} names the address it arrived at`).toContain(after)
      expect(body, `${where} must not name the old address`).not.toContain(before)
      // Not a verification: nothing to click, nothing to confirm.
      expect(body, `${where} asks for no confirmation`).toContain('nichts bestätigen')
    }
  })

  /**
   * The gate the whole feature rests on: a member the club has never written to
   * is told nothing, so an address change cannot arrive out of context from a
   * sender they do not recognise.
   */
  test('a member with no card hears nothing when their address changes', async ({
    authenticatedRequest,
  }) => {
    const before = address('nocard')
    const after = address('nocard2')
    const memberId = await createMember(authenticatedRequest, { email: before, card: null })

    await patchMember(authenticatedRequest, memberId, { email: after })
    drainMailQueue()

    await mail.expectNothingFor(before)
    await mail.expectNothingFor(after)

    // And the gate lifts exactly when the card arrives — otherwise this test
    // would also pass against a feature that never sends anything at all.
    await patchMember(authenticatedRequest, memberId, { card_uid: cardUid() })
    drainMailQueue()

    const welcome = await mail.waitForMessage(after)
    expect(welcome.Subject).toContain('Willkommen')
    await mail.expectNothingFor(before)
  })

  /** A member who prefers English is written to in English (ADR-0002). */
  test('the welcome arrives in the language the member prefers', async ({ authenticatedRequest }) => {
    const email = address('en')
    await createMember(authenticatedRequest, { email, card: cardUid(), language: 'en' })

    drainMailQueue()

    const message = await mail.waitForMessage(email)
    expect(message.Subject).toContain('your card is active')
    expect(message.Text).toContain('your membership card is now active')
    expect(message.Text).not.toContain('freigeschaltet')
  })
})
