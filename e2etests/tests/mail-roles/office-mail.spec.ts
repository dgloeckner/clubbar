/**
 * Operational mail reaches an office, not an account (#633, ADR-0044).
 *
 *     something happens → bin/cron.php → the mailbox of whoever it is for
 *
 * ### What this file is for
 *
 * `AdminNotifier` used to fan every admin-addressed kind out over **every
 * active account**, whatever office it held. That made mail the one surface
 * ADR-0044's role model did not cover: an account holding only `getraenkewart`
 * — refused the key screen, the terminals page, the admin list and the audit
 * log — was mailed the club's encryption-key fingerprint, which tills had just
 * been issued credentials, and who had just been promoted.
 *
 * The PHPUnit tests pin the mapping, the query and the fan-out. None of them
 * can show that the queue those rows sit in ends with a message in one mailbox
 * and nothing in another: a row that was never written is indistinguishable
 * from a row that was written and never sent, and only one of those is this
 * feature. So every assertion here is made against what Mailpit actually
 * received (Pattern 010), and the queue is drained only through the real
 * `backend/bin/cron.php`.
 *
 * ### The assertions that matter are the negative ones
 *
 * A mailbox that stays empty is the whole feature, and an empty mailbox is also
 * what a broken chain looks like. So each test waits for a **positive**
 * delivery first — the office the notice *is* for — and only then holds the
 * other mailboxes open (`expectNothingFor`). By that point the drain has
 * demonstrably run and delivered, so "nothing arrived" means nothing was ever
 * addressed there.
 *
 * ### Blast radius
 *
 * Three accounts of this file's own, one per office (Patterns 001, 003), all
 * removed in `afterAll` so no later drain fans anything out to them. It runs
 * last in the mail chain for the reason the others run in order: its drains
 * claim the whole queue.
 */

import { randomUUID } from 'crypto'
import { test, expect } from '../../fixtures/auth.fixture'
import {
  assertMailpitReachable,
  createMailpitClient,
  MailpitClient,
  MailpitMessage,
} from '../../utils/mailpit'
import { drainMailQueue } from '../../utils/drain'
import { stepUp } from '../../fixtures/stepUp'

const API_BASE = 'http://localhost:8080/api'
const MAIL_CONFIG = '/api/admin/mail-config'
const ADMIN_USERS = '/api/admin/admin-users'

/** A sender is required before the drain will claim anything at all. */
const SENDER_ADDRESS = 'noreply@office-mail.test.example'

/** The whole queue may be waiting; a run needs room to reach these messages. */
const BUDGET_SECONDS = 55

/**
 * Wait for **this** message, not for a message count.
 *
 * `waitForMessageAbout` asserts a mailbox holds exactly one message matching a
 * subject fragment, which the `admin` office's never does: every account this
 * file creates fires `admin_account_created` at it, and any other suite
 * creating one adds another. Counting would make this file fail on mail it sent
 * itself. So it polls for the message that names what the test is actually
 * about.
 */
async function waitForNoticeMentioning(
  mail: MailpitClient,
  recipient: string,
  subject: RegExp,
  mentions: string,
): Promise<MailpitMessage> {
  let found: MailpitMessage | undefined

  await expect
    .poll(
      async () => {
        for (const summary of await mail.messagesTo(recipient)) {
          if (!subject.test(summary.Subject ?? '')) continue

          const message = await mail.message(summary.ID)
          if (`${message.Text ?? ''}${message.HTML ?? ''}`.includes(mentions)) {
            found = message
            return true
          }
        }

        return false
      },
      { timeout: 30_000, message: `Mailpit should hold a notice for ${recipient} mentioning ${mentions}` },
    )
    .toBe(true)

  return found as MailpitMessage
}

/** A birth date making somebody exactly `years` old today. */
const bornToBeAgedOn = (years: number): string => {
  const d = new Date()
  d.setUTCFullYear(d.getUTCFullYear() - years)
  return d.toISOString().slice(0, 10)
}

test.describe.configure({ mode: 'serial' })

test.describe('a notice reaches the office it is for, and no other', () => {
  const suffix = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 6)}`

  let mail: MailpitClient
  let disposeMailpit: () => Promise<void>

  /** One account per office, so a mailbox names a role rather than a person. */
  const offices: Record<'admin' | 'kassenwart' | 'getraenkewart', { id: string; email: string }> = {
    admin: { id: '', email: '' },
    kassenwart: { id: '', email: '' },
    getraenkewart: { id: '', email: '' },
  }

  test.beforeAll(async ({ authenticatedRequest }) => {
    await assertMailpitReachable()
    const client = await createMailpitClient()
    mail = client.mail
    disposeMailpit = client.dispose

    // The dev stack ships no mail configuration on purpose (a transport wired
    // into the long-running backend would let one suite's drain sweep
    // another's mail), so a sender has to be set before the drain claims
    // anything at all.
    const config = await (await authenticatedRequest.get(MAIL_CONFIG)).json()
    const patched = await authenticatedRequest.patch(MAIL_CONFIG, {
      data: { sender_address: config.sender_address || SENDER_ADDRESS },
    })
    expect(patched.status(), await patched.text()).toBe(200)

    // `admin` last on purpose. Creating an account is itself an `admin`-only
    // notice, so an admin mailbox minted first would collect one per account
    // this file creates — noise the assertions then have to see past.
    for (const role of ['getraenkewart', 'kassenwart', 'admin'] as const) {
      const email = `office-${role}-${suffix}@test.example`
      const created = await authenticatedRequest.post(ADMIN_USERS, {
        data: {
          ...stepUp(),
          email,
          display_name: `Office ${role} ${suffix}`,
          locale: 'de',
          roles: [role],
        },
      })
      expect(created.status(), await created.text()).toBe(201)
      offices[role] = { id: (await created.json()).admin.id, email }
    }
  })

  test.afterAll(async ({ authenticatedRequest }) => {
    // An extra active account puts every later notice in a mailbox nobody
    // reads — and an `admin` one would collect the whole suite's operational
    // mail.
    for (const office of Object.values(offices)) {
      if (office.id) {
        await authenticatedRequest.delete(`${ADMIN_USERS}/${office.id}`)
      }
    }
    await disposeMailpit?.()
  })

  /**
   * The sharpest of the notices #633 lists: it names who gained power. It is
   * also the one ADR-0044 built as an out-of-band control — *"whoever holds one
   * admin's session, password and TOTP code … still cannot reach the other
   * admins' inboxes"* — which was worth rather less while it also reached an
   * office with no business knowing.
   */
  test('an admin lifecycle notice reaches the admin office alone', async ({
    authenticatedRequest,
  }) => {
    const subjectEmail = `office-promoted-${suffix}@test.example`
    const created = await authenticatedRequest.post(ADMIN_USERS, {
      data: {
        ...stepUp(),
        email: subjectEmail,
        display_name: `Office Subject ${suffix}`,
        locale: 'de',
        roles: ['kassenwart'],
      },
    })
    expect(created.status(), await created.text()).toBe(201)
    const subjectId = (await created.json()).admin.id

    try {
      const run = drainMailQueue({ budgetSeconds: BUDGET_SECONDS })
      expect(run, run).toMatch(/claimed=\d+ sent=\d+/)

      // The office this notice is for received it…
      await waitForNoticeMentioning(mail, offices.admin.email, /Admin-Konto/i, subjectEmail)

      // …and the two that are not, did not. The drain has run and delivered by
      // now, so these mailboxes are empty because nothing was addressed to
      // them, not because nothing has been sent yet.
      await mail.expectNothingFor(offices.kassenwart.email)
      await mail.expectNothingFor(offices.getraenkewart.email)
    } finally {
      await authenticatedRequest.delete(`${ADMIN_USERS}/${subjectId}`)
    }
  })

  /**
   * The kind that is not `admin`-only, and the reason the mapping mirrors
   * routes rather than inventing a second table: ADR-0045 names the Kassenwart
   * as the recipient of a Jugendschutz violation, its dashboard alert is
   * `TREASURY`, and the mail carries exactly that set.
   *
   * The Getränkewart is excluded here as everywhere else — they set the number
   * on the product and learn nothing about who bought it (invariant 5).
   */
  test('a Jugendschutz violation reaches the treasury offices and not the bar', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const dateOfBirth = bornToBeAgedOn(15)

    const memberResponse = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: {
        first_name: `Office${suffix}`,
        last_name: `Minor${suffix}`,
        email: `office-minor-${suffix}@example.com`,
        date_of_birth: dateOfBirth,
        preferred_language: 'de',
        iban: 'DE89370400440532013000',
        mandate_signed_at: '2024-01-01',
      },
    })
    expect(memberResponse.status(), await memberResponse.text()).toBe(201)
    const member = await memberResponse.json()

    const category = await authenticatedRequest.post(`${API_BASE}/admin/categories`, {
      data: { names: { de: `Bar${suffix}`, en: `Bar${suffix}` } },
    })
    const productResponse = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
      data: {
        names: { de: `Obstler ${suffix}`, en: `Schnapps ${suffix}` },
        category_id: (await category.json()).id,
        price_cents: 320,
        min_age: 18,
      },
    })
    expect(productResponse.status(), await productResponse.text()).toBe(201)
    const product = await productResponse.json()

    const transactionId = randomUUID()
    const sale = await authenticatedTerminalRequest.post(`${API_BASE}/sync/transactions`, {
      data: {
        transactions: [
          {
            id: transactionId,
            member_id: member.id,
            product_id: product.id,
            amount_cents: 320,
            quantity: 1,
            created_at: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'),
          },
        ],
      },
    })
    // Stored, never refused — the property the whole feature rests on.
    expect(sale.status(), await sale.text()).toBe(201)

    const run = drainMailQueue({ budgetSeconds: BUDGET_SECONDS })
    expect(run, run).toMatch(/claimed=\d+ sent=\d+/)

    // The treasurer is told, which is the half ADR-0045 asks for…
    const delivered = await waitForNoticeMentioning(
      mail,
      offices.kassenwart.email,
      /jugendschutz|youth protection/i,
      transactionId,
    )
    const body = `${delivered.Text ?? ''}${delivered.HTML ?? ''}`
    expect(body).toContain(`Obstler ${suffix}`)
    expect(body).toContain(transactionId)
    // …and rule 6 still holds in the channel that cannot be taken back.
    expect(body).not.toContain(member.first_name)
    expect(body).not.toContain(dateOfBirth)

    // …and the bar is not, whatever it says.
    await mail.expectNothingFor(offices.getraenkewart.email)
  })
})
