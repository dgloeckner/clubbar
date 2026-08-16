/**
 * Reading what the club actually sent (#409).
 *
 * Mailpit is an SMTP sink with an HTTP API, running as the `mailpit` service in
 * `docker-compose.yml`. It is the only place in this suite where an assertion
 * can be made against a *message* rather than against our own bookkeeping: an
 * outbox row that says `sent` is what we recorded; a message here is what a
 * member would have received.
 *
 * ### Two rules, both of them Pattern 003
 *
 * 1. **Never index into a message list.** Every lookup here is a search by
 *    recipient, and every recipient in this suite is generated per test. The
 *    inbox is global — other tests, and any drain a developer triggered by
 *    hand, are writing to it at the same time — so "the first message" means
 *    nothing and "the message to this address" means everything.
 * 2. **Never delete.** A `DELETE /api/v1/messages` would be a shared-state
 *    write against a mailbox other workers are reading, which is exactly the
 *    failure Patterns 001 and 004 exist to prevent. Uniqueness of the address
 *    is the isolation; there is nothing to clean up.
 *
 * ### Waiting
 *
 * Delivery is not instantaneous: the drain returns when SMTP has accepted the
 * message, and Mailpit indexes it a moment later. Everything that expects a
 * message therefore polls (`expect.poll`, Pattern 008) rather than asserting
 * once and hoping.
 *
 * @see https://mailpit.axllent.org/docs/api-v1/
 */

import { expect, request as playwrightRequest, type APIRequestContext } from '@playwright/test'

/** Mailpit's HTTP API, as exposed by the compose stack. */
export const MAILPIT_URL = process.env.MAILPIT_URL || 'http://localhost:8025'

/** How long a message may take to appear after the drain reports it sent. */
const DELIVERY_TIMEOUT_MS = 15_000

export interface MailpitAddress {
  Name: string
  Address: string
}

/** A message as the search endpoint summarises it. */
export interface MailpitSummary {
  ID: string
  Subject: string
  From: MailpitAddress | null
  To: MailpitAddress[]
  Created: string
}

/** A message as the single-message endpoint returns it, both parts included. */
export interface MailpitMessage {
  ID: string
  Subject: string
  From: MailpitAddress | null
  To: MailpitAddress[]
  ReplyTo: MailpitAddress[]
  Date: string
  /** The `text/plain` alternative. */
  Text: string
  /** The `text/html` part. */
  HTML: string
}

export class MailpitClient {
  constructor(private readonly request: APIRequestContext) {}

  /**
   * Every message addressed to `recipient`, newest first.
   *
   * The address is quoted in the query so that a local part containing a dash
   * or a dot cannot be read as Mailpit's own search syntax.
   */
  async messagesTo(recipient: string): Promise<MailpitSummary[]> {
    const response = await this.request.get('/api/v1/search', {
      params: { query: `to:"${recipient}"`, limit: 50 },
    })

    if (!response.ok()) {
      throw new Error(
        `Mailpit search for ${recipient} failed (${response.status()}): ${await response.text()}`
      )
    }

    return (await response.json()).messages ?? []
  }

  /** The full message — both body parts and the headers a summary leaves out. */
  async message(id: string): Promise<MailpitMessage> {
    const response = await this.request.get(`/api/v1/message/${id}`)

    if (!response.ok()) {
      throw new Error(`Mailpit could not return message ${id} (${response.status()})`)
    }

    return await response.json()
  }

  /**
   * Wait until `recipient` holds exactly `count` messages, then return them
   * in the order they arrived, bodies and all.
   *
   * `toBe` rather than `toBeGreaterThanOrEqual`: a duplicate is the failure
   * this whole milestone is here to catch, so an extra message must fail the
   * wait rather than satisfy it early.
   */
  async waitForMessages(recipient: string, count: number): Promise<MailpitMessage[]> {
    await expect
      .poll(async () => (await this.messagesTo(recipient)).length, {
        timeout: DELIVERY_TIMEOUT_MS,
        message: `Mailpit should hold exactly ${count} message(s) for ${recipient}`,
      })
      .toBe(count)

    const summaries = await this.messagesTo(recipient)
    // Mailpit answers newest first; the assertions read better in the order the
    // member's mailbox received them.
    const oldestFirst = [...summaries].reverse()

    return Promise.all(oldestFirst.map((summary) => this.message(summary.ID)))
  }

  /** Wait for the one message this recipient is expected to have. */
  async waitForMessage(recipient: string): Promise<MailpitMessage> {
    const [message] = await this.waitForMessages(recipient, 1)

    return message
  }

  /**
   * The same wait, narrowed to the messages whose subject contains `fragment`.
   *
   * Needed because an admin mailbox holds more than one *class* of message.
   * Since ADR-0043 every terminal enrolment and rotation queues a notice to
   * every active admin, so a file asserting on expiry warnings can no longer
   * claim the whole mailbox — and the terminal it stands up to produce an
   * expiry warning is itself an issuance.
   *
   * The counting rule survives the narrowing: `toBe` within the matched set, so
   * a duplicate of the class under test still fails the wait rather than
   * satisfying it early. What it gives up is duplicates of *other* classes,
   * which is the correct trade — those belong to the file that asserts them.
   *
   * Filtered on the summaries rather than through Mailpit's `subject:` search,
   * because the subjects worth matching contain typographic quotes and umlauts
   * and the search syntax is one more thing that would have to be escaped
   * correctly for a test to be honest.
   */
  async waitForMessagesAbout(
    recipient: string,
    fragment: string,
    count: number
  ): Promise<MailpitMessage[]> {
    const matching = async () =>
      (await this.messagesTo(recipient)).filter((message) => message.Subject.includes(fragment))

    await expect
      .poll(async () => (await matching()).length, {
        timeout: DELIVERY_TIMEOUT_MS,
        message: `Mailpit should hold exactly ${count} message(s) for ${recipient} about "${fragment}"`,
      })
      .toBe(count)

    const oldestFirst = [...(await matching())].reverse()

    return Promise.all(oldestFirst.map((summary) => this.message(summary.ID)))
  }

  /** Wait for the one message of this class the recipient is expected to have. */
  async waitForMessageAbout(recipient: string, fragment: string): Promise<MailpitMessage> {
    const [message] = await this.waitForMessagesAbout(recipient, fragment, 1)

    return message
  }

  /**
   * Make this server refuse every recipient with `code`, run `body`, and put
   * it back however that goes.
   *
   * Mailpit's chaos API (`MP_ENABLE_CHAOS` in docker-compose.yml) is the only
   * way to get a **permanent** delivery failure out of the real code path: a
   * refused connection is transient by design — `SymfonyMailerTransport` reads
   * the first digit of the SMTP reply, and an absent code counts as "not now" —
   * so a message that fails that way waits out the retry ladder rather than
   * landing in `failed`. A 5xx is the receiving server saying no.
   *
   * Global to the server, which is safe here for the same reason the DSN is
   * handed to one drain at a time: nothing else in the suite sends. It is
   * restored in a `finally`, so a failing assertion inside `body` cannot leave
   * a mail server refusing everything for the rest of the run.
   */
  async withRecipientsRefused<T>(code: number, body: () => Promise<T>): Promise<T> {
    await this.setChaos({ Recipient: { ErrorCode: code, Probability: 100 } })

    try {
      return await body()
    } finally {
      await this.setChaos({ Recipient: { ErrorCode: code, Probability: 0 } })
    }
  }

  private async setChaos(triggers: Record<string, { ErrorCode: number; Probability: number }>): Promise<void> {
    const response = await this.request.put('/api/v1/chaos', { data: triggers })

    if (!response.ok()) {
      throw new Error(
        `Mailpit refused the chaos configuration (${response.status()}): ${await response.text()}. ` +
          'The service needs MP_ENABLE_CHAOS=1 — see docker-compose.yml.'
      )
    }
  }

  /**
   * Assert that nothing was delivered to `recipient`.
   *
   * Deliberately not a one-shot read: an announcement that is on its way would
   * pass an immediate "no messages" check. This holds the claim open for a
   * window instead, so a message that should never have been sent has time to
   * arrive and fail the test.
   */
  async expectNothingFor(recipient: string, windowMs = 2_000): Promise<void> {
    const deadline = Date.now() + windowMs

    do {
      const messages = await this.messagesTo(recipient)
      expect(
        messages.map((m) => m.Subject),
        `nothing may be delivered to ${recipient}`
      ).toEqual([])
      await new Promise((resolve) => setTimeout(resolve, 250))
    } while (Date.now() < deadline)
  }
}

/**
 * A client over its own request context.
 *
 * Its own, rather than the test's `request` fixture, because that one is
 * pointed at the API and carries the admin session — neither of which Mailpit
 * has any business seeing. Dispose it in `afterAll`.
 */
export async function createMailpitClient(): Promise<{ mail: MailpitClient; dispose: () => Promise<void> }> {
  const context = await playwrightRequest.newContext({ baseURL: MAILPIT_URL })

  return {
    mail: new MailpitClient(context),
    dispose: () => context.dispose(),
  }
}

/**
 * Fail early, and say what is missing.
 *
 * Without this, a stack started before #409 added the service fails every
 * assertion in the chain suite with a connection error attached to whichever
 * test polled first — which reads as a product bug and is a missing container.
 */
export async function assertMailpitReachable(): Promise<void> {
  const context = await playwrightRequest.newContext({ baseURL: MAILPIT_URL })

  try {
    const response = await context.get('/readyz')
    if (!response.ok()) {
      throw new Error(`Mailpit answered ${response.status()} on ${MAILPIT_URL}/readyz`)
    }
  } catch (error) {
    throw new Error(
      `Mailpit is not reachable on ${MAILPIT_URL}. Start it with ` +
        `\`docker compose up -d mailpit\` (or run scripts/dev-setup.sh). Cause: ${String(error)}`
    )
  } finally {
    await context.dispose()
  }
}
