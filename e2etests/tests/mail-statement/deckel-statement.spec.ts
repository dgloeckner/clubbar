/**
 * The Deckelauszug chain, end to end (#463, ADR-0039).
 *
 *     cadence switched on → bin/cron.php (enqueue + drain) → Mailpit
 *
 * The unit and feature tests already pin what a statement *says* and which
 * transactions belong in it. What none of them can show is that switching
 * `statement_cadence` to `monthly` results in a member receiving one mail, in
 * their language, stating their tab at the period boundary — and, on the second
 * run, receiving nothing further.
 *
 * ### The two things this file is careful about
 *
 * **Idempotency is asserted at Mailpit, not at the outbox.** "One row" tests our
 * bookkeeping; "one message in the mailbox" tests the thing a member would
 * experience if `UNIQUE (kind, subject_id, dedup_key)` ever stopped doing its
 * job. So every claim of the form "nothing is sent twice" is made after running
 * the cron twice.
 *
 * **Bookings are backdated behind the boundary.** A statement dated 1 August
 * states the tab as it stood *then*, so a purchase written at test time — some
 * day in the middle of the month — is correctly absent from it. The fixtures
 * therefore sync their purchases a few days before the boundary, which is also
 * the only way this file could ever have a non-empty itemised list. Terminal
 * sync stores `created_at` as sent (`TerminalTransactionAllowlist`), which is
 * what makes that possible without touching the database.
 *
 * ### Blast radius
 *
 * A statement goes to **every** member in scope, not to this file's fixtures —
 * that is the feature. So the cadence is switched on in `beforeAll`, switched
 * back to `off` in `afterAll`, and the runs here use a raised budget so they
 * clear the whole queue rather than leaving a tail for the next spec's drain
 * to work through. Every assertion still finds its message by an address this
 * file generated (Patterns 001, 003).
 */

import { test, expect } from '../../fixtures/auth.fixture'
import { assertMailpitReachable, createMailpitClient, MailpitClient, MailpitMessage } from '../../utils/mailpit'
import { drainMailQueue } from '../../utils/drain'
import { serverToday } from '../../utils/dates'
import { FACTORY_IBAN } from '../../utils/settlements'
import type { ApiRequestLike } from '../../utils/request-context'

const MAIL_CONFIG = '/api/admin/mail-config'

/** A sender is required before the drain will send anything at all. */
const SENDER_ADDRESS = 'noreply@deckel-chain.test.example'
const KASSENWART = 'kassenwart@deckel-chain.test.example'

/**
 * A run has the whole membership's statements to clear, and the suite has
 * created a member per test before this project starts. The default 50 seconds
 * is sized for one settlement's announcements.
 */
const BUDGET_SECONDS = 55

/** A row of `GET /api/admin/notifications`, as far as these assertions read it. */
interface QueueRow {
  member_id: string | null
  kind: string
  recipient: string
  status: string
  sent_at: string | null
}

interface StatementMember {
  id: string
  email: string
  /** What the statement should total, in cents. */
  totalCents: number
  /** Product names booked before the boundary, in order. */
  labels: string[]
}

let mail: MailpitClient
let disposeMailpit: () => Promise<void>

/** The period the cron is asked for, and the date its statements are dated at. */
let period = ''
let boundary = ''

/** `1234` → `12,34 €`, as `MailFormat::money()` writes it in German. */
function germanMoney(cents: number): string {
  return `${(cents / 100).toFixed(2).replace('.', ',')} €`
}

/** `1234` → `EUR 12.34`. */
function englishMoney(cents: number): string {
  return `EUR ${(cents / 100).toFixed(2)}`
}

/** `2026-08-01` → `01.08.2026`. */
function germanDate(isoDate: string): string {
  const [year, month, day] = isoDate.split('-')
  return `${day}.${month}.${year}`
}

/** `2026-08-01` → `1 August 2026` — English dates are spelled out on purpose. */
function englishDate(isoDate: string): string {
  const months = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
  ]
  const [year, month, day] = isoDate.split('-')
  return `${Number(day)} ${months[Number(month) - 1]} ${year}`
}

/** `$daysBefore` days before the boundary, as an ISO instant the sync accepts. */
function beforeBoundary(daysBefore: number): string {
  return new Date(Date.parse(`${boundary}T20:00:00Z`) - daysBefore * 24 * 60 * 60 * 1000).toISOString()
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

test.describe('Deckelauszug — cadence, cron, delivered statement', () => {
  let originalCadence: string | null = null
  let categoryId = ''

  test.beforeAll(async ({ authenticatedRequest }) => {
    await assertMailpitReachable()
    const client = await createMailpitClient()
    mail = client.mail
    disposeMailpit = client.dispose

    const today = await serverToday(authenticatedRequest)
    period = today.slice(0, 7)
    boundary = `${period}-01`

    const config = await (await authenticatedRequest.get(MAIL_CONFIG)).json()
    originalCadence = config.statement_cadence ?? 'off'

    // `mail_config` is a singleton, so only the fields this file needs are
    // patched rather than the whole row round-tripped. The cadence is restored
    // below; the sender is **not**, and deliberately:
    //
    //   - It is only filled in at all when the installation has none, because
    //     the drain refuses to send without one (`MailConfigDto::isComplete()`),
    //     and running `--no-deps` against a fresh dev stack is exactly that
    //     case.
    //   - Putting an empty string back would be refused by the `email` rule,
    //     so the "restore" would silently do nothing anyway — and leaving a
    //     working sender behind is the harmless direction to be wrong in.
    const patch: Record<string, unknown> = { statement_cadence: 'monthly' }
    if (!config.sender_address) {
      patch.sender_address = SENDER_ADDRESS
      patch.reply_to_address = KASSENWART
    }

    const patched = await authenticatedRequest.patch(MAIL_CONFIG, { data: patch })
    expect(patched.status(), await patched.text()).toBe(200)
    expect((await patched.json()).statement_cadence).toBe('monthly')

    const category = await authenticatedRequest.post('/api/admin/categories', {
      data: {
        names: {
          de: `Deckelauszug ${Date.now().toString(36)}`,
          en: `Statement ${Date.now().toString(36)}`,
        },
      },
    })
    expect(category.status(), await category.text()).toBe(201)
    categoryId = (await category.json()).id
  })

  test.afterAll(async ({ authenticatedRequest }) => {
    // Off again as soon as this project is done: every `bin/cron.php`
    // invocation enqueues before it drains, so a cadence left on would make an
    // unrelated spec's drain queue a statement for the whole membership — and
    // deliver a second, unexpected message to a member that spec is counting.
    await authenticatedRequest.patch(MAIL_CONFIG, {
      data: { statement_cadence: originalCadence ?? 'off' },
    })
    await disposeMailpit?.()
  })

  /**
   * A member with a tab, backdated behind the boundary so it is on the
   * statement the cron will queue.
   */
  async function seedMember(
    adminRequest: ApiRequestLike,
    terminalRequest: ApiRequestLike,
    amounts: number[],
    language: 'de' | 'en' = 'de',
  ): Promise<StatementMember> {
    // Short: the local part is bounded at 64 characters by RFC 5321, and this
    // suite has been bitten by that before.
    const suffix = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`

    // ASCII on purpose: these labels are compared against the rendered HTML,
    // and an entity-encoded umlaut would fail an assertion about the statement
    // rather than about encoding.
    const labels: string[] = []
    const productIds: string[] = []
    for (const [index, amountCents] of amounts.entries()) {
      const label = `Deckel Getraenk ${suffix} ${String.fromCharCode(65 + index)}`
      const product = await adminRequest.post('/api/admin/products', {
        data: { names: { de: label, en: label }, price_cents: amountCents, category_id: categoryId },
      })
      expect(product.status(), await product.text()).toBe(201)
      productIds.push((await product.json()).id)
      labels.push(label)
    }

    const memberResponse = await adminRequest.post('/api/admin/members', {
      data: {
        first_name: 'Deckel',
        last_name: `Auszug${suffix}`,
        email: `deckel-${suffix}@test.example`,
        preferred_language: language,
        iban: FACTORY_IBAN,
        mandate_signed_at: '2024-01-01',
      },
    })
    expect(memberResponse.status(), await memberResponse.text()).toBe(201)
    const member = await memberResponse.json()

    if (productIds.length > 0) {
      const synced = await terminalRequest.post('/api/sync/transactions', {
        data: {
          transactions: productIds.map((productId, index) => ({
            id: crypto.randomUUID(),
            member_id: member.id,
            type: 'product',
            product_id: productId,
            quantity: 1,
            unit_price_cents: amounts[index],
            amount_cents: amounts[index],
            // Behind the boundary, so the statement has something to itemise.
            created_at: beforeBoundary(index + 2),
          })),
        },
      })
      expect(synced.status(), await synced.text()).toBe(201)
    }

    return {
      id: member.id,
      email: member.email,
      totalCents: amounts.reduce((sum, amount) => sum + amount, 0),
      labels,
    }
  }

  /** The queue rows this member's statements produced. */
  async function statementRows(adminRequest: ApiRequestLike, memberId: string): Promise<QueueRow[]> {
    const response = await adminRequest.get(`/api/admin/notifications?subject_id=${memberId}`)
    expect(response.status(), await response.text()).toBe(200)

    return ((await response.json()).data as QueueRow[]).filter((row) => row.kind === 'deckel_statement')
  }

  /**
   * The acceptance criterion, in one flow (Pattern 009).
   *
   * Everything a member is promised by ADR-0039 in a single delivered message —
   * the Stand, the itemised lines, the total — followed immediately by the run
   * that must not produce a second one. Splitting those into two tests would
   * mean seeding the member twice and asserting idempotency against a mailbox
   * some other test filled.
   */
  test('a member with an unsettled tab receives one statement per period', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const member = await seedMember(authenticatedRequest, authenticatedTerminalRequest, [250, 180])

    drainMailQueue({ period, budgetSeconds: BUDGET_SECONDS })

    const message = await mail.waitForMessage(member.email)
    const { html, text } = parts(message)

    // The subject names the period, never the send day — the whole reason a
    // member can read the number without checking it against the terminal.
    expect(message.Subject).toContain(germanDate(boundary))

    for (const part of [html, text]) {
      expect(part, 'the tab is the statement').toContain(germanMoney(member.totalCents))
      expect(part).toContain(germanDate(boundary))
      for (const label of member.labels) {
        expect(part, 'the statement is itemised').toContain(label)
      }
    }

    // Nothing from the Vorabankündigung. Every one of these is available to the
    // renderer a field away, and a statement carrying them reads as a demand
    // for money — the confusion CONTEXT.md defines the two terms apart to stop.
    for (const part of [html, text, message.Subject]) {
      expect(part).not.toContain(FACTORY_IBAN)
      expect(part).not.toContain(FACTORY_IBAN.slice(-4))
      expect(part).not.toContain('Mandatsreferenz')
      expect(part).not.toContain('Gläubiger')
      expect(part).not.toContain('Fälligkeit')
    }

    const [row] = await statementRows(authenticatedRequest, member.id)
    expect(row.status).toBe('sent')
    expect(row.recipient).toBe(member.email)
    expect(row.sent_at).not.toBeNull()

    // The test that matters. A second tick re-runs the same enqueue over the
    // same period, and the unique index — not a lookup the service performs —
    // is what stops it becoming a second mail.
    drainMailQueue({ period, budgetSeconds: BUDGET_SECONDS })

    const afterSecondRun = await mail.messagesTo(member.email)
    expect(
      afterSecondRun.map((m) => m.Subject),
      'two ticks inside one period deliver one statement'
    ).toHaveLength(1)
    expect(await statementRows(authenticatedRequest, member.id)).toHaveLength(1)
  })

  /**
   * The scope ruling that looks like a mistake and is not: a member who owes
   * nothing gets one anyway. A statement that only arrives when you owe
   * something is a nudge wearing a statement's clothes.
   */
  test('a member with a clear tab receives a statement that says so', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const member = await seedMember(authenticatedRequest, authenticatedTerminalRequest, [])

    drainMailQueue({ period, budgetSeconds: BUDGET_SECONDS })

    const { html, text } = parts(await mail.waitForMessage(member.email))

    for (const part of [html, text]) {
      expect(part).toContain(germanMoney(0))
      expect(part, 'a sentence, not an empty table').toContain('keine offenen Buchungen')
    }
  })

  /**
   * The member's own language, frozen onto the row at enqueue (ADR-0002).
   *
   * Asserted through the chain rather than in the renderer's own test because
   * the language has to survive three hops to get here: the member record, the
   * `mail_outbox.language` column the enqueue writes, and the builder that
   * reads it back at send time.
   */
  test('an English-speaking member receives the English statement', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const member = await seedMember(authenticatedRequest, authenticatedTerminalRequest, [325], 'en')

    drainMailQueue({ period, budgetSeconds: BUDGET_SECONDS })

    const message = await mail.waitForMessage(member.email)
    const { html, text } = parts(message)

    expect(message.Subject).toContain(englishDate(boundary))

    for (const part of [html, text]) {
      expect(part).toContain(englishMoney(member.totalCents))
      expect(part).toContain(englishDate(boundary))
      expect(part, 'the English statement is not the German one').not.toContain('Dein Deckel')
    }
  })
})
