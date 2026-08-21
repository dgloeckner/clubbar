import { randomUUID } from 'crypto'
import { test, expect } from '../../fixtures/auth.fixture'

/**
 * The server records the sale and raises a violation (epic #582 M7, ADR-0045 §3).
 *
 * The terminal is the only layer that can *prevent* an underage sale, because
 * it is the only one present when the drink is poured. By the time a row
 * reaches the server the glass is empty, so the server's job is **detection**,
 * and ADR-0020's amendment (#162) settles what it may do about it: store the
 * transaction, raise the alarm, never drop the row. Rejecting would not
 * un-serve the minor — it would only delete the club's knowledge that it
 * happened, and trade a youth-protection incident for a § 146 AO bookkeeping
 * violation.
 *
 * Two properties carry the whole design, and both are asserted here rather than
 * argued:
 *
 * 1. **The row is always stored.** Every test that raises a violation also
 *    asserts the transaction was accepted. The risk of any flag is it quietly
 *    becoming a refusal.
 * 2. **The age is judged at the transaction's own timestamp** (rule 2), not at
 *    ingest. A terminal may sync a fortnight late, and the question is whether
 *    the sale was lawful *when it happened*.
 *
 * E2E Pattern 001: every test mints its own member and product.
 */

const API_BASE = 'http://localhost:8080/api'

/** A date that makes somebody exactly `years` old on `on`. */
const bornToBeAgedOn = (years: number, on: Date): string => {
  const d = new Date(on)
  d.setUTCFullYear(d.getUTCFullYear() - years)
  return d.toISOString().slice(0, 10)
}

const daysAgo = (days: number): Date => {
  const d = new Date()
  d.setUTCDate(d.getUTCDate() - days)
  return d
}

test.describe('Jugendschutz violations at sync', () => {
  const createMember = async (request: any, dateOfBirth: string) => {
    const id = randomUUID().replace(/-/g, '').slice(0, 10)
    const response = await request.post(`${API_BASE}/admin/members`, {
      data: {
        first_name: `Juv${id}`,
        last_name: `Schutz${id}`,
        email: `juschg-${id}@example.com`,
        date_of_birth: dateOfBirth,
        preferred_language: 'de',
        iban: 'DE89370400440532013000',
        mandate_signed_at: '2024-01-01',
      },
    })
    expect(response.status(), await response.text()).toBe(201)

    return response.json()
  }

  const createProduct = async (request: any, minAge: number | null) => {
    const id = randomUUID().replace(/-/g, '').slice(0, 10)
    const category = await request.post(`${API_BASE}/admin/categories`, {
      data: { names: { de: `Kat${id}`, en: `Cat${id}` } },
    })
    expect(category.status()).toBe(201)

    const response = await request.post(`${API_BASE}/admin/products`, {
      data: {
        names: { de: `Korn ${id}`, en: `Spirit ${id}` },
        category_id: (await category.json()).id,
        price_cents: 350,
        ...(minAge === null ? {} : { min_age: minAge }),
      },
    })
    expect(response.status(), await response.text()).toBe(201)

    return response.json()
  }

  const sell = async (
    terminalRequest: any,
    memberId: string,
    productId: string,
    occurredAt: Date = new Date(),
  ) => {
    const transactionId = randomUUID()
    const response = await terminalRequest.post(`${API_BASE}/sync/transactions`, {
      data: {
        transactions: [
          {
            id: transactionId,
            member_id: memberId,
            product_id: productId,
            amount_cents: 350,
            quantity: 1,
            created_at: occurredAt.toISOString().replace(/\.\d{3}Z$/, 'Z'),
          },
        ],
      },
    })

    return { transactionId, response }
  }

  /** Violations filed under one transaction. Append-only, so never empty twice. */
  const violationsFor = async (request: any, transactionId: string) => {
    const response = await request.get(
      `${API_BASE}/admin/audit-log?action=jugendschutz_violation&entity_id=${transactionId}`,
    )
    expect(response.status(), await response.text()).toBe(200)

    return (await response.json()).data
  }

  test('an underage sale is stored, and raises a violation naming what was breached', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const member = await createMember(authenticatedRequest, bornToBeAgedOn(15, new Date()))
    const product = await createProduct(authenticatedRequest, 18)

    const { transactionId, response } = await sell(authenticatedTerminalRequest, member.id, product.id)

    // Stored, never rejected. This assertion is the point of the test: the whole
    // risk of the flag is it quietly becoming a refusal, which #162 already
    // built once and removed.
    expect(response.status(), await response.text()).toBe(201)
    const body = await response.json()
    expect(body.accepted_ids).toContain(transactionId)
    expect(body.rejected.count).toBe(0)

    const violations = await violationsFor(authenticatedRequest, transactionId)
    expect(violations.length, 'the sale must have raised exactly one violation').toBe(1)

    const recorded = violations[0].new_values
    expect(recorded.member_id).toBe(member.id)
    expect(recorded.product_id).toBe(product.id)
    expect(recorded.min_age).toBe(18)
    expect(recorded.age_at_sale).toBe(15)

    // No actor: a terminal synced this, no admin acted.
    expect(violations[0].admin_user_id).toBeNull()
  })

  test('an of-age sale of the same restricted drink raises nothing', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const member = await createMember(authenticatedRequest, bornToBeAgedOn(40, new Date()))
    const product = await createProduct(authenticatedRequest, 18)

    const { transactionId, response } = await sell(authenticatedTerminalRequest, member.id, product.id)
    expect(response.status()).toBe(201)

    expect(await violationsFor(authenticatedRequest, transactionId)).toHaveLength(0)
  })

  test('an unrestricted drink raises nothing, whatever the member’s age', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const member = await createMember(authenticatedRequest, bornToBeAgedOn(14, new Date()))
    const product = await createProduct(authenticatedRequest, null)

    const { transactionId, response } = await sell(authenticatedTerminalRequest, member.id, product.id)
    expect(response.status()).toBe(201)

    // A minor buying an Apfelschorle is not an incident. Blocking terminal
    // access on age is an explicit non-goal of ADR-0045.
    expect(await violationsFor(authenticatedRequest, transactionId)).toHaveLength(0)
  })

  /**
   * Rule 2, and the reason the check cannot simply read the clock.
   *
   * The member turned 16 yesterday. The sale happened three days ago, when they
   * were 15. A server that judged "is this member old enough *now*" would find
   * nothing wrong and the incident would vanish — which is exactly the case a
   * late-syncing terminal produces.
   */
  test('the age is judged when the sale happened, not when the batch arrives', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const member = await createMember(authenticatedRequest, bornToBeAgedOn(16, daysAgo(1)))
    const product = await createProduct(authenticatedRequest, 16)

    const { transactionId, response } = await sell(
      authenticatedTerminalRequest,
      member.id,
      product.id,
      daysAgo(3),
    )
    expect(response.status(), await response.text()).toBe(201)

    const violations = await violationsFor(authenticatedRequest, transactionId)
    expect(violations.length, 'a sale that was unlawful when it happened stays unlawful').toBe(1)
    expect(violations[0].new_values.age_at_sale).toBe(15)
  })

  /** The mirror of the above: of age on the day, so nothing is raised. */
  test('a sale on the very birthday is lawful', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const soldAt = daysAgo(2)
    const member = await createMember(authenticatedRequest, bornToBeAgedOn(18, soldAt))
    const product = await createProduct(authenticatedRequest, 18)

    const { transactionId, response } = await sell(
      authenticatedTerminalRequest,
      member.id,
      product.id,
      soldAt,
    )
    expect(response.status()).toBe(201)

    expect(
      await violationsFor(authenticatedRequest, transactionId),
      'somebody who turns 18 on the day may buy on that day',
    ).toHaveLength(0)
  })

  /**
   * ADR-0045 invariant 4. Unlike ADR-0020's derived `ineligible_members`
   * bucket — which is recomputed and disappears the moment a mandate is
   * recorded — a past sale to a minor stays true no matter what changes
   * afterwards. Un-restricting the drink tomorrow does not un-serve it.
   */
  test('a recorded violation does not clear when the product is later un-restricted', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const member = await createMember(authenticatedRequest, bornToBeAgedOn(15, new Date()))
    const product = await createProduct(authenticatedRequest, 18)

    const { transactionId } = await sell(authenticatedTerminalRequest, member.id, product.id)
    expect(await violationsFor(authenticatedRequest, transactionId)).toHaveLength(1)

    const cleared = await authenticatedRequest.patch(`${API_BASE}/admin/products/${product.id}`, {
      data: { min_age: null },
    })
    expect(cleared.status(), await cleared.text()).toBe(200)
    expect((await cleared.json()).min_age).toBeNull()

    const after = await violationsFor(authenticatedRequest, transactionId)
    expect(after, 'the record of a sale that happened is not editable by later config').toHaveLength(1)
    expect(after[0].new_values.min_age, 'and it still says what the limit was at the time').toBe(18)
  })

  /**
   * A replayed batch must not multiply the record.
   *
   * The terminal retries a batch it did not see acknowledged (ADR-0004 makes
   * that safe), and the flag runs only for a row this call actually inserted —
   * so the second attempt finds the booking stored and skips straight past.
   */
  test('replaying the batch leaves one violation, not two', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const member = await createMember(authenticatedRequest, bornToBeAgedOn(15, new Date()))
    const product = await createProduct(authenticatedRequest, 18)

    const occurredAt = new Date()
    const transactionId = randomUUID()
    const payload = {
      transactions: [
        {
          id: transactionId,
          member_id: member.id,
          product_id: product.id,
          amount_cents: 350,
          quantity: 1,
          created_at: occurredAt.toISOString().replace(/\.\d{3}Z$/, 'Z'),
        },
      ],
    }

    const first = await authenticatedTerminalRequest.post(`${API_BASE}/sync/transactions`, { data: payload })
    expect(first.status()).toBe(201)
    const replay = await authenticatedTerminalRequest.post(`${API_BASE}/sync/transactions`, { data: payload })
    expect(replay.status()).toBe(201)
    expect((await replay.json()).accepted_ids).toContain(transactionId)

    expect(await violationsFor(authenticatedRequest, transactionId)).toHaveLength(1)
  })

  /**
   * The violation carries ids and numbers, never the member's name or birth
   * date. It is filed under the *transaction*, so the anonymization scrub —
   * which keys on the member's own entity_id — cannot reach inside it
   * (ADR-0013 principle 8), and an erased member must not leave their birth
   * date behind in an audit payload.
   */
  test('the recorded payload carries no personal data', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const dateOfBirth = bornToBeAgedOn(15, new Date())
    const member = await createMember(authenticatedRequest, dateOfBirth)
    const product = await createProduct(authenticatedRequest, 18)

    const { transactionId } = await sell(authenticatedTerminalRequest, member.id, product.id)

    const recorded = (await violationsFor(authenticatedRequest, transactionId))[0]
    const serialised = JSON.stringify(recorded.new_values)

    expect(serialised).not.toContain(dateOfBirth)
    expect(serialised).not.toContain(member.first_name)
    expect(serialised).not.toContain(member.last_name)
    expect(serialised).not.toContain(member.email)
  })
})
