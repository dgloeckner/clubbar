/**
 * The announcement chain, end to end (#409, epic #361).
 *
 * Everything before this milestone tested one half. `SettlementMailBuilder`
 * knows what the message should say, `DrainService` knows how to send one, the
 * outbox knows what is owed — and each is asserted against its own half of the
 * seam. What none of them can show is that a treasurer pressing *Abrechnung
 * erstellen* results in a member receiving a mail that carries the creditor
 * identifier, their mandate reference, the amount, an itemised statement and a
 * due date at least seven days out. That is what these do, by reading the
 * message out of a real SMTP server:
 *
 *     finalize (HTTP) → drain (backend/bin/cron.php) → Mailpit (HTTP API)
 *
 * ### Three things this file is deliberate about
 *
 * **The drain is the production entrypoint.** `drainMailQueue()` runs
 * `backend/bin/cron.php` inside the backend container, exactly as a hosting
 * panel's crontab does — the `flock`, the wall-clock budget, the CLI-PHP
 * heartbeat and the pruning included. See `utils/drain.ts` for why the
 * transport is handed to that run rather than wired into the stack.
 *
 * **Idempotency is asserted at Mailpit, not at the database.** "One outbox row"
 * tests our bookkeeping; "one message in the mailbox" tests the thing a member
 * would experience if idempotency broke. So the drain is run twice wherever the
 * claim is that nothing is sent twice.
 *
 * **The seven-day distance is computed from the server's today**, read over the
 * API (`utils/dates.ts`), never from the runner's clock and never from a date
 * written into this file — the backend runs UTC and a developer's machine does
 * not.
 *
 * ### Isolation (Patterns 001, 003, 004)
 *
 * Every test builds its own member, purchase and settlement, and every
 * assertion finds its message by that member's own generated address. The
 * inbox, the outbox and `mail_config` are all global; the addresses are not.
 * `mail_config` is a singleton, so this file writes it once and restores what
 * it found — and the `mail-chain` project runs after the suites that also write
 * it (see playwright.config.ts).
 */

import { test, expect } from '../../fixtures/auth.fixture'
import { assertMailpitReachable, createMailpitClient, MailpitClient, MailpitMessage } from '../../utils/mailpit'
import { drainMailQueue } from '../../utils/drain'
import { minimumExecutionDate, serverToday } from '../../utils/dates'
import { ensureSepaConfigured, FACTORY_IBAN } from '../../utils/settlements'
import type { ApiRequestLike } from '../../utils/request-context'

/**
 * The creditor block, read from the installation rather than written into this
 * file.
 *
 * `sepa_config` is a singleton and other suites edit it — `ensureSepaConfigured()`
 * fills it in only when it is empty, so what is in it is whatever spec wrote
 * last. Asserting a literal here passed alone and failed in a full run, which is
 * the worst way to find that out. What the announcement actually promises is
 * that it names **this installation's** creditor, and that is what is asserted.
 */
let creditorName = ''
/**
 * The creditor identifier, as a pattern rather than a value.
 *
 * The admin API only ever returns it masked (`DE98****9999`) — and the mail
 * must carry it **in full**, because that is the half of the EPC requirement
 * that has to reach the debtor. So the expectation is built from the mask: the
 * ends the panel shows, with the middle the panel hides.
 */
let creditorIdPattern = /$^/

/** Reply-to is the Kassenwart — a pre-notification invites a reply (Ordnung § 4 Abs. 6). */
const KASSENWART = 'kassenwart@mail-chain.test.example'
const SENDER_ADDRESS = 'noreply@mail-chain.test.example'
const SENDER_NAME = 'Club Bar Mail Chain'

/** The last four digits of the IBAN every factory member holds. */
const ACCOUNT_LAST4 = FACTORY_IBAN.slice(-4)

const MAIL_CONFIG = '/api/admin/mail-config'

/** A row of `GET /api/admin/notifications`, as far as these assertions read it. */
interface QueueRow {
  id: string
  member_id: string | null
  recipient: string
  status: string
  attempts: number
  last_error: string | null
  sent_at: string | null
  is_retryable: boolean
}

/** A row of the settlement detail's `announcements[]` — the durable proof (#408). */
interface AnnouncementRow {
  member_id: string
  kind: string
  sent_at: string
}

/** One line of the Abrechnungsübersicht, as the member's own booking produced it. */
interface StatementLine {
  label: string
  amountCents: number
}

interface AnnouncedMember {
  settlementId: string
  memberId: string
  email: string
  mandateReference: string
  executionDate: string
  totalCents: number
  lines: StatementLine[]
}

/**
 * A member whose collection has more than one booking behind it, settled.
 *
 * The settlement factory gives every member exactly one purchase, which cannot
 * show that the statement is *itemised*: with one line, the line and the total
 * are the same number. This seeds two, each against its own product — and the
 * product name is what the line is labelled with. A terminal-synced purchase
 * has no note to fall back on: `notes` is not on the terminal allowlist
 * (#79, ruling #144 §3), so the label is the product's German name or nothing.
 */
async function settleItemisedMember(
  adminRequest: ApiRequestLike,
  terminalRequest: ApiRequestLike,
  categoryId: string,
  amounts: number[]
): Promise<AnnouncedMember> {
  await ensureSepaConfigured(adminRequest)

  const suffix = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 7)}`

  // ASCII on purpose: these strings are compared against the rendered HTML,
  // and an entity-encoded umlaut would fail an assertion about the statement
  // rather than about encoding.
  const lines: StatementLine[] = []
  const productIds: string[] = []
  for (const [index, amountCents] of amounts.entries()) {
    const label = `Mailkette Getraenk ${suffix} ${String.fromCharCode(65 + index)}`
    const product = await adminRequest.post('/api/admin/products', {
      data: {
        names: { de: label, en: label },
        price_cents: Math.abs(amountCents),
        category_id: categoryId,
      },
    })
    expect(product.status(), await product.text()).toBe(201)
    productIds.push((await product.json()).id)
    lines.push({ label, amountCents })
  }

  const memberResponse = await adminRequest.post('/api/admin/members', {
    data: {
      first_name: 'Mail',
      last_name: `Kette${suffix}`,
      email: `mailkette-${suffix}@test.example`,
      preferred_language: 'de',
      iban: FACTORY_IBAN,
      mandate_signed_at: '2024-01-01',
    },
  })
  expect(memberResponse.status(), await memberResponse.text()).toBe(201)
  const member = await memberResponse.json()

  const synced = await terminalRequest.post('/api/sync/transactions', {
    data: {
      transactions: lines.map((line, index) => ({
        id: crypto.randomUUID(),
        member_id: member.id,
        type: 'product',
        product_id: productIds[index],
        quantity: 1,
        unit_price_cents: Math.abs(line.amountCents),
        amount_cents: line.amountCents,
        created_at: new Date(Date.now() - index * 60_000).toISOString(),
      })),
    },
  })
  expect(synced.status(), await synced.text()).toBe(201)

  const executionDate = await minimumExecutionDate(adminRequest)
  const settlement = await adminRequest.post('/api/admin/settlements', {
    data: { method: 'direct_debit', member_ids: [member.id], execution_date: executionDate },
  })
  expect(settlement.status(), await settlement.text()).toBe(201)

  return {
    settlementId: (await settlement.json()).id,
    memberId: member.id,
    email: member.email,
    mandateReference: member.mandate_reference,
    executionDate,
    totalCents: lines.reduce((sum, line) => sum + line.amountCents, 0),
    lines,
  }
}

let mail: MailpitClient
let disposeMailpit: () => Promise<void>

/** `1234` → `12,34 €`, as `MailFormat::money()` renders it in German. */
function germanMoney(cents: number): string {
  return `${(cents / 100).toFixed(2).replace('.', ',')} €`
}

/** `1234` → `EUR 12.34`, the English form. */
function englishMoney(cents: number): string {
  return `EUR ${(cents / 100).toFixed(2)}`
}

/** `2026-08-21` → `21.08.2026`. */
function germanDate(isoDate: string): string {
  const [year, month, day] = isoDate.split('-')
  return `${day}.${month}.${year}`
}

/** `2026-08-21` → `21 August 2026` — English dates are spelled out on purpose. */
function englishDate(isoDate: string): string {
  const months = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
  ]
  const [year, month, day] = isoDate.split('-')
  return `${Number(day)} ${months[Number(month) - 1]} ${year}`
}

/**
 * `DE98****9999` → `/DE98[A-Z0-9]+9999/` — the full value behind a masked one.
 *
 * The ends are the installation's own; the middle is what the panel refuses to
 * show and the mail has to spell out.
 */
function unmaskedPattern(masked: string): RegExp {
  const escape = (part: string) => part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  const [head, tail] = String(masked).split(/\*+/)

  return new RegExp(`${escape(head ?? '')}[A-Z0-9]+${escape(tail ?? '')}`)
}

/** Whole days between two ISO dates, both read as UTC midnights. */
function daysBetween(fromIso: string, toIso: string): number {
  const day = 24 * 60 * 60 * 1000
  return Math.round((Date.parse(`${toIso}T00:00:00Z`) - Date.parse(`${fromIso}T00:00:00Z`)) / day)
}

/** The HTML part as text: entities decoded and tags dropped, so one assertion covers both parts. */
function htmlAsText(html: string): string {
  return html
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&euro;/g, '€')
    .replace(/&#8364;/g, '€')
    .replace(/\s+/g, ' ')
}

/** Both parts of a message, each flattened to comparable text. */
function parts(message: MailpitMessage): { html: string; text: string } {
  return { html: htmlAsText(message.HTML), text: message.Text }
}

test.describe('SEPA pre-notification — finalize, drain, delivered mail', () => {
  let originalMailConfig: Record<string, unknown> | null = null
  /** Owns the products the itemised-statement tests book against. */
  let categoryId = ''

  test.beforeAll(async ({ authenticatedRequest }) => {
    await assertMailpitReachable()
    const client = await createMailpitClient()
    mail = client.mail
    disposeMailpit = client.dispose

    // The drain refuses to send at all without a sender address
    // (`MailConfigDto::isComplete()`), and the reply-to is one of the fields
    // under test. Restored below — this row is a singleton (Pattern 001's
    // singleton form, as in mail-settings.spec.ts).
    originalMailConfig = await (await authenticatedRequest.get(MAIL_CONFIG)).json()
    const patched = await authenticatedRequest.patch(MAIL_CONFIG, {
      data: {
        sender_name: SENDER_NAME,
        sender_address: SENDER_ADDRESS,
        reply_to_address: KASSENWART,
      },
    })
    expect(patched.status()).toBe(200)

    // Products need a category, and a product is what gives a statement line
    // its label. One for the file, named for the run so it cannot be confused
    // with a catalogue another suite is asserting on.
    const category = await authenticatedRequest.post('/api/admin/categories', {
      data: {
        names: {
          de: `Mailkette ${Date.now().toString(36)}`,
          en: `Mail chain ${Date.now().toString(36)}`,
        },
      },
    })
    expect(category.status(), await category.text()).toBe(201)
    categoryId = (await category.json()).id

    // Read after the suites that edit the singleton have finished, and used as
    // the expected value everywhere below.
    await ensureSepaConfigured(authenticatedRequest)
    const sepa = await (await authenticatedRequest.get('/api/admin/sepa-config')).json()
    creditorName = sepa.creditor_name
    creditorIdPattern = unmaskedPattern(sepa.creditor_id)
    expect(creditorName, 'the creditor name is one of the fields the mail must carry').toBeTruthy()
    expect(
      String(sepa.creditor_id),
      'the panel returns the creditor identifier masked — the pattern below is built from that mask, ' +
        'so an unmasked value here would silently turn the assertions into something weaker'
    ).toContain('*')
  })

  test.afterAll(async ({ authenticatedRequest }) => {
    if (originalMailConfig) {
      await authenticatedRequest.patch(MAIL_CONFIG, {
        data: {
          sender_name: originalMailConfig.sender_name,
          sender_address: originalMailConfig.sender_address,
          reply_to_address: originalMailConfig.reply_to_address,
        },
      })
    }
    await disposeMailpit?.()
  })

  /**
   * The acceptance criterion the epic ends on, in one test.
   *
   * Every field here is somebody's commitment rather than a design choice: the
   * creditor identifier and the mandate reference because the EPC rulebook
   * requires them to reach the debtor before the first collection, the amount
   * and the due date because Nutzungsordnung § 7 Abs. 3 promises them seven
   * days ahead, the itemised statement because § 7 Abs. 1 promises an
   * Abrechnungsübersicht, and the reply-to because § 4 Abs. 6 invites a reply.
   */
  test('the delivered announcement carries every field the Ordnung promises', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    // The first real drain in the file pays for every connection this
    // describe block will reuse, and — per DELIVERY_TIMEOUT_MS's comment in
    // utils/mailpit.ts — can legitimately queue behind unrelated CI load on
    // the shared compose stack. Playwright's 30s default has no room left
    // for that once setup and the drain itself are accounted for, so this
    // one wait gets a longer budget than the rest of the file — comfortably
    // under the test's own extended timeout below.
    test.setTimeout(90_000)
    const FIRST_DRAIN_TIMEOUT_MS = 75_000

    const announced = await settleItemisedMember(
      authenticatedRequest,
      authenticatedTerminalRequest,
      categoryId,
      [1500, 2737]
    )
    const today = await serverToday(authenticatedRequest)

    drainMailQueue()

    const message = await mail.waitForMessage(announced.email, FIRST_DRAIN_TIMEOUT_MS)
    const { html } = parts(message)

    // Who is collecting, and under which authority.
    expect(html).toContain(creditorName)
    expect(html).toMatch(creditorIdPattern)
    expect(html).toContain(announced.mandateReference)

    // How much, and when.
    expect(message.Subject).toContain(germanMoney(announced.totalCents))
    expect(html).toContain(germanMoney(announced.totalCents))
    expect(html).toContain(germanDate(announced.executionDate))

    // The promise itself: at least seven days between the day the settlement
    // was finalized and the day the money is taken (SettlementLeadTime::DAYS).
    expect(
      daysBetween(today, announced.executionDate),
      'the announced due date must be at least seven days after the server\'s today'
    ).toBeGreaterThanOrEqual(7)

    // The account, masked — and the rest of the IBAN nowhere in the message.
    expect(html).toContain(`**** ${ACCOUNT_LAST4}`)
    expect(html).not.toContain(FACTORY_IBAN)

    // The Abrechnungsübersicht (§ 7 Abs. 1): both bookings, each by name and by
    // its own amount. Two lines rather than one, because with a single booking
    // the line and the total are the same number and "itemised" is unproven.
    for (const line of announced.lines) {
      expect(html).toContain(line.label)
      expect(html).toContain(germanMoney(line.amountCents))
    }

    // A reply goes to the Kassenwart, not to the mailbox nobody reads.
    expect(message.ReplyTo.map((address) => address.Address)).toContain(KASSENWART)
    expect(message.From?.Address).toBe(SENDER_ADDRESS)
    expect(message.From?.Name).toBe(SENDER_NAME)
    expect(message.To.map((address) => address.Address)).toEqual([announced.email])
  })

  /**
   * A text alternative that says "see the HTML version" is the failure
   * `MailMessage` refuses to be constructed around — and a member reading mail
   * in a text client is entitled to the same facts, not to a stub.
   */
  test('the text part states the same facts as the HTML part', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const announced = await settleItemisedMember(
      authenticatedRequest,
      authenticatedTerminalRequest,
      categoryId,
      [990, 1000]
    )

    drainMailQueue()

    const message = await mail.waitForMessage(announced.email)
    const { text } = parts(message)

    expect(text.length).toBeGreaterThan(200)
    expect(text).toMatch(creditorIdPattern)
    expect(text).toContain(announced.mandateReference)
    expect(text).toContain(germanMoney(announced.totalCents))
    expect(text).toContain(germanDate(announced.executionDate))
    expect(text).toContain(`**** ${ACCOUNT_LAST4}`)
    for (const line of announced.lines) {
      expect(text).toContain(line.label)
      expect(text).toContain(germanMoney(line.amountCents))
    }
    // Text, not markup: a part built by stripping tags would still pass every
    // assertion above.
    expect(text).not.toContain('<td')
    expect(text).not.toContain('<!DOCTYPE')
  })

  test('a member who prefers English is announced to in English', async ({ settlementFactory }) => {
    const settlement = await settlementFactory.create({ amountCents: 3150, preferredLanguage: 'en' })

    drainMailQueue()

    const message = await mail.waitForMessage(settlement.memberEmail)
    const { html } = parts(message)

    expect(message.Subject).toContain('Advance notice')
    expect(html).toContain('Creditor identifier')
    expect(html).toContain('Mandate reference')
    expect(html).toContain('Itemised statement')
    expect(html).toContain(englishMoney(3150))
    expect(html).toContain(englishDate(settlement.executionDate))
    // The German rendering of the same amount must not be in there: a template
    // that fell back per-string would still look translated at a glance.
    expect(html).not.toContain(germanMoney(3150))
  })

  /**
   * French is a language a member may prefer (`SupportedLanguage`) and no
   * announcement exists in (`MailLanguage`). The fallback is German, because a
   * mail in the wrong language is a poor outcome and a mail with blanks where
   * the amount and the due date belong is an unusable one.
   */
  test('a member who prefers French is announced to in German', async ({ settlementFactory }) => {
    const settlement = await settlementFactory.create({ amountCents: 2075, preferredLanguage: 'fr' })

    drainMailQueue()

    const message = await mail.waitForMessage(settlement.memberEmail)
    const { html } = parts(message)

    expect(message.Subject).toContain('Vorabankündigung')
    expect(html).toContain('Mandatsreferenz')
    expect(html).toContain(germanMoney(2075))
    expect(html).toContain(germanDate(settlement.executionDate))
  })

  /**
   * The single most valuable assertion in the epic: a second run must not put a
   * second copy in the member's mailbox. The queue's `UNIQUE (kind, subject_id,
   * member_id)` and the claim are what make it true; this is what would notice
   * if either stopped being.
   */
  test('draining twice still leaves exactly one message per member', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create({ amountCents: 1234, memberCount: 2 })

    drainMailQueue()
    drainMailQueue()

    for (const member of settlement.members) {
      const [message] = await mail.waitForMessages(member.email, 1)
      expect(message.Subject).toContain(germanMoney(1234))
    }

    // And the bookkeeping agrees: one row per collected member, all delivered.
    const rows = (await (
      await authenticatedRequest.get(`/api/admin/notifications?subject_id=${settlement.id}`)
    ).json()).data
    expect(rows).toHaveLength(2)
    for (const row of rows) {
      expect(row.status).toBe('sent')
      expect(row.attempts).toBe(1)
    }
  })

  /**
   * Delivery has to be *visible* (ADR-0038 rule 6): the Kassenwart opens the
   * settlement and sees, per member, that the announcement went out and when.
   * `announcements[]` is the durable half — it outlives the queue row, which is
   * pruned at ninety days (#408).
   */
  test('the settlement detail shows the send per member after the drain', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create({ amountCents: 5000 })

    drainMailQueue()
    await mail.waitForMessage(settlement.memberEmail)

    const detail = await (await authenticatedRequest.get(`/api/admin/settlements/${settlement.id}`)).json()

    const notification = (detail.notifications as QueueRow[]).find(
      (row) => row.member_id === settlement.memberId
    )
    expect(notification?.status).toBe('sent')
    expect(notification?.sent_at).not.toBeNull()
    expect(notification?.recipient).toBe(settlement.memberEmail)

    const announcement = (detail.announcements as AnnouncementRow[]).find(
      (row) => row.member_id === settlement.memberId
    )
    expect(announcement?.kind).toBe('sepa_prenotification')
    expect(announcement?.sent_at).toBe(notification?.sent_at)
  })

  /**
   * Cancelled before anything left the host: there is nothing to retract, so
   * nothing is sent — not the announcement, and not a notice about it.
   * Telling somebody a collection is called off when they were never told it
   * was coming is worse than saying nothing.
   */
  test('a settlement cancelled before the drain sends nothing at all', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create({ amountCents: 2600 })

    const cancelled = await authenticatedRequest.delete(`/api/admin/settlements/${settlement.id}/cancel`, {
      data: { reason: 'Mail chain: cancelled before the first drain' },
    })
    expect(cancelled.status()).toBe(200)

    drainMailQueue()

    const rows = (await (
      await authenticatedRequest.get(`/api/admin/notifications?subject_id=${settlement.id}`)
    ).json()).data
    expect(rows).toHaveLength(1)
    expect(rows[0].status).toBe('superseded')

    await mail.expectNothingFor(settlement.memberEmail)
  })

  /**
   * Cancelled after the announcement went out: exactly one notice, to the
   * member who was told — and it names what was announced, because a retraction
   * that does not identify the collection it retracts is a second puzzle.
   */
  test('a settlement cancelled after the drain sends one cancellation notice', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    const settlement = await settlementFactory.create({ amountCents: 3300, memberCount: 2 })

    drainMailQueue()
    for (const member of settlement.members) {
      await mail.waitForMessages(member.email, 1)
    }

    const cancelled = await authenticatedRequest.delete(`/api/admin/settlements/${settlement.id}/cancel`, {
      data: { reason: 'Mail chain: cancelled after the announcement' },
    })
    expect(cancelled.status()).toBe(200)

    drainMailQueue()
    // A second run: the notice must not be duplicated either.
    drainMailQueue()

    for (const member of settlement.members) {
      const [announcement, notice] = await mail.waitForMessages(member.email, 2)

      expect(announcement.Subject).toContain('Vorabankündigung')
      expect(notice.Subject).toContain('entfällt')

      const { html } = parts(notice)
      expect(html).toContain(germanMoney(3300))
      expect(html).toContain(germanDate(settlement.executionDate))
      // The retraction is not a second collection notice: it carries no
      // mandate reference and no creditor identifier.
      expect(html).not.toContain(member.mandateReference)
      expect(html).not.toMatch(creditorIdPattern)
    }
  })

  /**
   * A refused delivery leaves the settlement alone and the failure on the row —
   * best effort, but visible (ADR-0038 rule 6). Nothing before this milestone
   * could produce that state through the real code: on a stack with no
   * transport the drain claims nothing, so nothing can fail, which is why the
   * retry button's happy path was left to #409 by #407.
   *
   * A **5xx** specifically. A refused connection is transient by design, so it
   * waits out the retry ladder — an hour at the shortest tick — and a test
   * cannot wait for that. A server saying "no" is what puts the row in
   * `failed`, which is the only status the retry button accepts, and the pair
   * is the point: the club can tell a message that needs a human from one that
   * only needs another tick.
   */
  test('a rejected delivery is recorded, and a retry after it delivers', async ({
    authenticatedRequest,
    settlementFactory,
  }) => {
    // Flush first: the refusal below is global to the mail server, and a drain
    // claims the whole queue. Sending whatever the rest of the run has left
    // pending *before* this settlement exists keeps the 550 on this test's own
    // message rather than on a bystander's.
    drainMailQueue()

    const settlement = await settlementFactory.create({ amountCents: 4100 })

    await mail.withRecipientsRefused(550, async () => {
      drainMailQueue()
    })

    const afterFailure = (await (
      await authenticatedRequest.get(`/api/admin/notifications?subject_id=${settlement.id}`)
    ).json()).data
    expect(afterFailure).toHaveLength(1)
    expect(afterFailure[0].status).toBe('failed')
    expect(afterFailure[0].attempts).toBe(1)
    expect(afterFailure[0].last_error).toContain('550')
    // Failed, therefore actionable — the button and the endpoint agree on that
    // because the server computes it (#407).
    expect(afterFailure[0].is_retryable).toBe(true)

    // The settlement itself is untouched: a mail server does not get to decide
    // whether a collection happened.
    const detail = await (await authenticatedRequest.get(`/api/admin/settlements/${settlement.id}`)).json()
    expect(detail.is_cancelled).toBe(false)
    await mail.expectNothingFor(settlement.memberEmail)

    const retried = await authenticatedRequest.post(`/api/admin/notifications/${afterFailure[0].id}/retry`)
    expect(retried.status()).toBe(200)

    drainMailQueue()

    const message = await mail.waitForMessage(settlement.memberEmail)
    expect(message.Subject).toContain(germanMoney(4100))
  })

  /**
   * The other end of the mandatory-scheduler gate (#405).
   *
   * That an installation which has *never* run the drain cannot finalize a
   * direct debit is asserted where it can be asserted without clearing a
   * singleton every worker depends on — `SchedulerGateHttpTest` over a real
   * database, and `scheduler-banner.spec.ts` over the rendered component.
   * What neither can show is that a real `bin/cron.php` run is what lifts it:
   * both sides of those tests are constructed. Here the heartbeat is written by
   * the CLI entrypoint itself, and the panel reads back what the CLI observed —
   * including the CLI's own PHP version, which is the field that exists because
   * on mass hosting it is frequently not the web PHP.
   */
  test('a real cron run is what the panel reports as the verified scheduler', async ({
    authenticatedRequest,
  }) => {
    const before = Date.now()
    drainMailQueue()

    const status = await (await authenticatedRequest.get('/api/admin/scheduler')).json()

    expect(status.verified).toBe(true)
    expect(status.source).toBe('cli')
    expect(status.php_version).toMatch(/^\d+\.\d+/)
    expect(new Date(status.last_run_at).getTime()).toBeGreaterThanOrEqual(before - 60_000)
    // The instructions the banner prints name this installation's own path.
    expect(status.setup.cli_command).toContain('bin/cron.php')
  })
})
