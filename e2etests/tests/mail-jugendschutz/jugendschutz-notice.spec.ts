/**
 * The Jugendschutz chain, end to end (#622, ADR-0045 §3).
 *
 *     an underage sale at sync → bin/cron.php → an admin's mailbox
 *
 * ### What this file is for
 *
 * M7 made the violation a permanent audit entry and told nobody. The record was
 * pull-only — finding one meant already suspecting it — and the audit log is
 * `admin`-only, so the Kassenwart could not reach it at all. The dashboard alert
 * closes that for anybody who opens the panel; this closes it for anybody who
 * does not.
 *
 * The PHPUnit tests pin the enqueue, the recipients and the rendering. None of
 * them can show that the scheduler a hosting panel really runs puts the notice
 * in a mailbox, and a control whose whole value is reaching somebody who is not
 * looking at a dashboard is not demonstrated by an outbox row saying it meant
 * to (Pattern 010). So every assertion here is made against what Mailpit
 * received, and the queue is drained only through the real
 * `backend/bin/cron.php`.
 *
 * ### The assertion that matters most is a negative one
 *
 * `AdminNotifier` fans out to **every active admin account regardless of role**,
 * which includes the Getränkewart — and ADR-0045 invariant 5 says they gain no
 * member data. A message that named the member would leak it to an office that
 * may not see one, in the one channel nobody can take back. So this file checks
 * what the delivered bytes do *not* contain as carefully as what they do.
 *
 * ### Blast radius
 *
 * The notice goes to every active admin, so this file mints an admin of its own
 * and asserts against that address (Patterns 001, 003). It runs after the other
 * mail projects for the reason they run in order: its drain claims the whole
 * queue.
 */

import { randomUUID } from 'crypto'
import { test, expect } from '../../fixtures/auth.fixture'
import {
  assertMailpitReachable,
  createMailpitClient,
  MailpitClient,
} from '../../utils/mailpit'
import { drainMailQueue } from '../../utils/drain'
import { createIsolatedAdmin } from '../../utils/isolatedAdmin'

const API_BASE = 'http://localhost:8080/api'
const MAIL_CONFIG = '/api/admin/mail-config'

/** A sender is required before the drain will claim anything at all. */
const SENDER_ADDRESS = 'noreply@jugendschutz-chain.test.example'

/**
 * The whole queue may be waiting, and one violation fans out to every active
 * admin — a run needs room to reach this file's message.
 */
const BUDGET_SECONDS = 55

/**
 * Wait for **this** notice, not for a message count.
 *
 * `waitForMessages(recipient, 1)` asserts a mailbox holds exactly one message,
 * which this recipient's never does: creating the admin fires
 * `admin_account_created` to every active admin including itself, and any other
 * suite creating one adds another. Counting would make this file fail on mail
 * it did not send. So it polls for the message it is actually about.
 */
async function waitForTheNotice(mail: MailpitClient, recipient: string) {
  let id: string | undefined

  await expect
    .poll(
      async () => {
        const summaries = await mail.messagesTo(recipient)
        id = summaries.find((s) => /jugendschutz|youth protection/i.test(s.Subject ?? ''))?.ID

        return id !== undefined
      },
      { timeout: 30_000, message: `Mailpit should hold a Jugendschutz notice for ${recipient}` },
    )
    .toBe(true)

  return mail.message(id as string)
}

/** A birth date making somebody exactly `years` old today. */
const bornToBeAgedOn = (years: number): string => {
  const d = new Date()
  d.setUTCFullYear(d.getUTCFullYear() - years)
  return d.toISOString().slice(0, 10)
}

test.describe.configure({ mode: 'serial' })

test.describe('a violation reaches a mailbox', () => {
  let mail: MailpitClient
  let dispose: () => Promise<void>
  let recipient: string

  test.beforeAll(async ({ authenticatedRequest }) => {
    await assertMailpitReachable()
    const client = await createMailpitClient()
    mail = client.mail
    dispose = client.dispose

    // The dev stack ships no mail configuration on purpose (a transport wired
    // into the long-running backend would let one suite's drain sweep another's
    // announcements), so a sender has to be set before the drain claims
    // anything at all.
    const config = await (await authenticatedRequest.get(MAIL_CONFIG)).json()
    const patched = await authenticatedRequest.patch(MAIL_CONFIG, {
      data: { sender_address: config.sender_address || SENDER_ADDRESS },
    })
    expect(patched.status(), await patched.text()).toBe(200)
  })

  test.afterAll(async () => {
    await dispose?.()
  })

  test('an underage sale is delivered to an admin, naming the drink and not the member', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    playwright,
  }) => {
    // An admin of this file's own, so the assertions below are about a mailbox
    // no other suite writes to.
    const isolated = await createIsolatedAdmin(playwright, 'jugendschutz-mail', ['admin'])
    recipient = isolated.email

    const suffix = randomUUID().replace(/-/g, '').slice(0, 10)
    const dateOfBirth = bornToBeAgedOn(15)

    const memberResponse = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: {
        first_name: `Mailtest${suffix}`,
        last_name: `Jugend${suffix}`,
        email: `mail-jugend-${suffix}@example.com`,
        date_of_birth: dateOfBirth,
        preferred_language: 'de',
        iban: 'DE89370400440532013000',
        mandate_signed_at: '2024-01-01',
      },
    })
    expect(memberResponse.status(), await memberResponse.text()).toBe(201)
    const member = await memberResponse.json()

    const category = await authenticatedRequest.post(`${API_BASE}/admin/categories`, {
      data: { names: { de: `Kat${suffix}`, en: `Cat${suffix}` } },
    })
    const productResponse = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
      data: {
        names: { de: `Kräuterlikör ${suffix}`, en: `Herbal ${suffix}` },
        category_id: (await category.json()).id,
        price_cents: 350,
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
            amount_cents: 350,
            quantity: 1,
            created_at: new Date().toISOString().replace(/\.\d{3}Z$/, 'Z'),
          },
        ],
      },
    })
    // Stored, never refused — the property the whole feature rests on.
    expect(sale.status(), await sale.text()).toBe(201)

    drainMailQueue({ budgetSeconds: BUDGET_SECONDS })

    const delivered = await waitForTheNotice(mail, recipient)

    const body = `${delivered.Text ?? ''}${delivered.HTML ?? ''}`
    const subject = delivered.Subject ?? ''

    // What it must say: which drink, and the age it required.
    expect(subject.toLowerCase()).toContain('jugendschutz')
    expect(body).toContain(`Kräuterlikör ${suffix}`)
    expect(body).toContain('18')
    expect(body).toContain(transactionId)

    // What it must never say. This is invariant 5 and rule 6 in the one channel
    // that cannot be taken back once sent.
    expect(body).not.toContain(dateOfBirth)
    expect(body).not.toContain(member.first_name)
    expect(body).not.toContain(member.last_name)
    expect(body).not.toContain(member.id)
    expect(delivered.Text ?? '').not.toContain('15')
  })
})
