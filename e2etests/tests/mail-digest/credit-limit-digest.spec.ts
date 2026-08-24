/**
 * The near-limit digest chain, end to end (ADR-0047, migration 053).
 *
 *     cadence switched on → bin/cron.php (scan + enqueue + drain) → Mailpit
 *
 * ### What only this file can show
 *
 * The PHPUnit suites pin the window arithmetic, the report assembly, the
 * refusals and the rendering. None of them can show that switching
 * `credit_limit_digest_cadence` on results in the **Kassenwart receiving one
 * mail naming a member, their Deckel and their limit** — because a row that was
 * never written is indistinguishable from a row that was written and never
 * sent, and only one of those is the feature the treasurer asked for
 * (Pattern 010).
 *
 * So every assertion here is made against what Mailpit actually received, and
 * the queue is drained only through the real `backend/bin/cron.php`.
 *
 * ### Blast radius, and why this file touches no club-wide setting
 *
 * The digest reports on **every** member near their ceiling, so its content
 * depends on the whole database — including whatever the rest of the suite has
 * left lying around. Two decisions keep that from making this file flaky:
 *
 *   - **Per-member overrides, never the club default.** Each fixture member is
 *     given a tight `credit_limit_cents` of their own and a tab just under it.
 *     That puts them in the warning band whatever the club's ceiling happens to
 *     be, and — unlike patching `credit-limit-config` — it cannot pull an
 *     unrelated spec's members onto the list or push its own off.
 *   - **Assertions name this file's own members** (Patterns 001, 003). The
 *     digest will legitimately carry other names; what is asserted is that the
 *     ones this file created are on it, with the right figures, and that the
 *     member deliberately left comfortable is not.
 *
 * The cadence itself is club-wide, so it is switched on in `beforeAll` and back
 * to its original value in `afterAll` — the same containment `mail-statement`
 * uses, and for the same reason: every `bin/cron.php` invocation scans before
 * it drains, so a cadence left on would have an unrelated spec's drain queue a
 * digest and deliver an unexpected message into a mailbox that spec is counting.
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
import { FACTORY_IBAN } from '../../utils/settlements'
import type { ApiRequestLike } from '../../utils/request-context'

const MAIL_CONFIG = '/api/admin/mail-config'
const ADMIN_USERS = '/api/admin/admin-users'

/** A sender is required before the drain will claim anything at all. */
const SENDER_ADDRESS = 'noreply@digest-chain.test.example'

/** The whole queue may be waiting; a run needs room to reach these messages. */
const BUDGET_SECONDS = 55

/**
 * The fixture members' own ceiling, and the tab put against it.
 *
 * 19,00 € of 20,00 € is 95 % — comfortably inside any warning band a club could
 * configure, including the 100 % that would mean "warn only when it is full".
 * The comfortable member sits at 5 % of the same ceiling, which is below every
 * band this system permits.
 */
const OVERRIDE_CENTS = 2_000
const NEAR_LIMIT_CENTS = 1_900
const COMFORTABLE_CENTS = 100

interface DigestMember {
  id: string
  name: string
  email: string
}

let mail: MailpitClient
let disposeMailpit: () => Promise<void>

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

/** `1234` → `12,34 €`, as `MailFormat::money()` writes it in German. */
function germanMoney(cents: number): string {
  return `${(cents / 100).toFixed(2).replace('.', ',')} €`
}

test.describe('Near-limit digest — cadence, cron, delivered list', () => {
  let originalCadence: string | null = null
  let categoryId = ''
  let suffix = ''

  const offices: Record<string, { id: string; email: string }> = {}

  test.beforeAll(async ({ authenticatedRequest }) => {
    await assertMailpitReachable()
    const client = await createMailpitClient()
    mail = client.mail
    disposeMailpit = client.dispose

    suffix = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`

    const config = await (await authenticatedRequest.get(MAIL_CONFIG)).json()
    originalCadence = config.credit_limit_digest_cadence ?? 'off'

    // `mail_config` is a singleton, so only the fields this file needs are
    // patched. `daily` rather than `weekly` so the window is today — a weekly
    // window would be shared with any other run in the same ISO week, and the
    // unique index would then refuse the second run's digest for a recipient
    // that already had one.
    const patch: Record<string, unknown> = { credit_limit_digest_cadence: 'daily' }
    if (!config.sender_address) {
      patch.sender_address = SENDER_ADDRESS
    }

    const patched = await authenticatedRequest.patch(MAIL_CONFIG, { data: patch })
    expect(patched.status(), await patched.text()).toBe(200)
    expect((await patched.json()).credit_limit_digest_cadence).toBe('daily')

    const category = await authenticatedRequest.post('/api/admin/categories', {
      data: { names: { de: `Deckel-Uebersicht ${suffix}`, en: `Digest ${suffix}` } },
    })
    expect(category.status(), await category.text()).toBe(201)
    categoryId = (await category.json()).id

    // One account per office the mapping distinguishes. The Kassenwart is who
    // the digest is for; the Getränkewart is the negative assertion, and the
    // one #633 is about.
    for (const role of ['kassenwart', 'getraenkewart'] as const) {
      const email = `digest-${role}-${suffix}@test.example`
      const created = await authenticatedRequest.post(ADMIN_USERS, {
        data: {
          ...stepUp(),
          email,
          display_name: `Digest ${role} ${suffix}`,
          locale: 'de',
          roles: [role],
        },
      })
      expect(created.status(), await created.text()).toBe(201)
      offices[role] = { id: (await created.json()).admin.id, email }
    }
  })

  test.afterAll(async ({ authenticatedRequest }) => {
    // Off again as soon as this project is done — see the file header.
    await authenticatedRequest.patch(MAIL_CONFIG, {
      data: { credit_limit_digest_cadence: originalCadence ?? 'off' },
    })

    // An extra active account puts every later notice in a mailbox nobody
    // reads, and a Kassenwart one would collect the rest of the suite's
    // treasury mail.
    for (const office of Object.values(offices)) {
      if (office.id) {
        await authenticatedRequest.delete(`${ADMIN_USERS}/${office.id}`)
      }
    }

    await disposeMailpit?.()
  })

  /**
   * A member with a ceiling of their own and a tab measured against it.
   *
   * The override is what keeps this file independent of the club's setting —
   * see the header. The purchase goes through the terminal sync rather than
   * straight into the database, so the balance the digest reports is the same
   * unsettled sum the member's own page reports.
   */
  async function seedMember(
    adminRequest: ApiRequestLike,
    terminalRequest: ApiRequestLike,
    amountCents: number,
    label: string,
  ): Promise<DigestMember> {
    const memberSuffix = `${label}${suffix}`

    const product = await adminRequest.post('/api/admin/products', {
      data: {
        // ASCII on purpose: this is compared against rendered HTML, and an
        // entity-encoded umlaut would fail an assertion about the digest rather
        // than about encoding.
        names: { de: `Digest Getraenk ${memberSuffix}`, en: `Digest drink ${memberSuffix}` },
        price_cents: amountCents,
        category_id: categoryId,
      },
    })
    expect(product.status(), await product.text()).toBe(201)
    const productId = (await product.json()).id

    const lastName = `Deckel${memberSuffix}`
    const memberResponse = await adminRequest.post('/api/admin/members', {
      data: {
        first_name: 'Digest',
        last_name: lastName,
        email: `digest-${memberSuffix}@test.example`,
        date_of_birth: '1985-06-15',
        preferred_language: 'de',
        iban: FACTORY_IBAN,
        mandate_signed_at: '2024-01-01',
        credit_limit_cents: OVERRIDE_CENTS,
      },
    })
    expect(memberResponse.status(), await memberResponse.text()).toBe(201)
    const member = await memberResponse.json()

    const synced = await terminalRequest.post('/api/sync/transactions', {
      data: {
        transactions: [
          {
            id: crypto.randomUUID(),
            member_id: member.id,
            type: 'product',
            product_id: productId,
            quantity: 1,
            unit_price_cents: amountCents,
            amount_cents: amountCents,
            created_at: new Date().toISOString(),
          },
        ],
      },
    })
    expect(synced.status(), await synced.text()).toBe(201)

    return { id: member.id, name: `Digest ${lastName}`, email: member.email }
  }

  /**
   * The acceptance criterion, in one flow (Pattern 009).
   *
   * Everything the request asked for in a single delivered message — the
   * members, their current Deckel and their limits, aggregated into one mail —
   * followed immediately by the run that must not produce a second one.
   * Splitting those would mean seeding twice and asserting idempotency against
   * a mailbox some other test filled.
   */
  test('the Kassenwart receives one aggregate mail naming each member, their Deckel and their limit', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const nearLimit = await seedMember(
      authenticatedRequest,
      authenticatedTerminalRequest,
      NEAR_LIMIT_CENTS,
      'near',
    )
    const comfortable = await seedMember(
      authenticatedRequest,
      authenticatedTerminalRequest,
      COMFORTABLE_CENTS,
      'ok',
    )

    const run = drainMailQueue({ budgetSeconds: BUDGET_SECONDS })
    expect(run, run).toMatch(/claimed=\d+ sent=\d+/)

    const message = await mail.waitForMessage(offices.kassenwart.email)
    const { html, text } = parts(message)

    for (const part of [html, text]) {
      // The three figures the treasurer asked for, together: who, what they
      // owe, and the limit it is measured against.
      expect(part).toContain(nearLimit.name)
      expect(part).toContain(germanMoney(NEAR_LIMIT_CENTS))
      expect(part).toContain(germanMoney(OVERRIDE_CENTS))

      // The member who is nowhere near their ceiling is not on the list. This
      // is what makes the digest worth opening: it is the members who need
      // attention, not a roster.
      expect(part).not.toContain(comfortable.name)
    }

    // One mail, not one per member — the aggregate the request asked for.
    expect(await mail.messagesTo(offices.kassenwart.email)).toHaveLength(1)

    // A second run inside the same window sends nothing further. Asserted at
    // the mailbox rather than at the outbox: "one row" tests our bookkeeping,
    // "one message" tests what the Kassenwart would experience if
    // `UNIQUE (kind, subject_id, dedup_key)` ever stopped doing its job.
    drainMailQueue({ budgetSeconds: BUDGET_SECONDS })
    expect(await mail.messagesTo(offices.kassenwart.email)).toHaveLength(1)
  })

  /**
   * The digest reaches the treasury office and not the bar stock — the same
   * rule #633 established for every other operational notice, applied to the
   * one kind that carries member balances.
   *
   * The positive delivery is waited for first, so the empty mailbox below means
   * nothing was ever addressed there rather than that nothing has been sent
   * yet.
   */
  test('the digest reaches the Kassenwart and not the Getränkewart', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    await seedMember(authenticatedRequest, authenticatedTerminalRequest, NEAR_LIMIT_CENTS, 'roles')

    drainMailQueue({ budgetSeconds: BUDGET_SECONDS })

    await mail.waitForMessage(offices.kassenwart.email)
    await mail.expectNothingFor(offices.getraenkewart.email)
  })
})
