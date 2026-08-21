import { randomUUID } from 'crypto'
import { test, expect } from '../../fixtures/roleRequests'

/**
 * Somebody is told, and can say they have dealt with it (#622, ADR-0045 §3).
 *
 * M7 made an underage sale a permanent `jugendschutz_violation` audit entry and
 * stopped there. That left the violation **pull-only** — finding one meant
 * opening the audit log and filtering by action, which requires already
 * suspecting it happened — and that log is `admin`-only under ADR-0044, so the
 * **Kassenwart**, the office ADR-0045 names as the recipient, could not reach it
 * at all.
 *
 * Two properties carry this milestone and both are asserted rather than argued:
 *
 * 1. **Acknowledging does not touch the record.** Invariant 4 says a recorded
 *    violation never clears itself. That survives here because the record and
 *    the alert are different objects — the audit entry is immutable, and the
 *    acknowledgement is a row beside it. Only the dashboard goes quiet.
 * 2. **The Kassenwart can do the whole loop.** See the alert, read the list,
 *    acknowledge. An alert they could see but not clear would leave the
 *    dashboard permanently red for the person most likely to be looking at it.
 *
 * E2E Pattern 001: every test mints its own member and product.
 */

const API_BASE = 'http://localhost:8080/api'

const bornToBeAgedOn = (years: number, on: Date): string => {
  const d = new Date(on)
  d.setUTCFullYear(d.getUTCFullYear() - years)
  return d.toISOString().slice(0, 10)
}

test.describe('Jugendschutz violations reach a human', () => {
  const createMember = async (request: any, dateOfBirth: string) => {
    const id = randomUUID().replace(/-/g, '').slice(0, 10)
    const response = await request.post(`${API_BASE}/admin/members`, {
      data: {
        first_name: `Alert${id}`,
        last_name: `Schutz${id}`,
        email: `alert-${id}@example.com`,
        date_of_birth: dateOfBirth,
        preferred_language: 'de',
        iban: 'DE89370400440532013000',
        mandate_signed_at: '2024-01-01',
      },
    })
    expect(response.status(), await response.text()).toBe(201)

    return response.json()
  }

  const createProduct = async (request: any, minAge: number) => {
    const id = randomUUID().replace(/-/g, '').slice(0, 10)
    const category = await request.post(`${API_BASE}/admin/categories`, {
      data: { names: { de: `Kat${id}`, en: `Cat${id}` } },
    })
    const response = await request.post(`${API_BASE}/admin/products`, {
      data: {
        names: { de: `Korn ${id}`, en: `Spirit ${id}` },
        category_id: (await category.json()).id,
        price_cents: 350,
        min_age: minAge,
      },
    })
    expect(response.status(), await response.text()).toBe(201)

    return response.json()
  }

  /** An underage sale, returning the transaction id it was booked under. */
  const sellToAMinor = async (request: any, terminalRequest: any) => {
    const member = await createMember(request, bornToBeAgedOn(15, new Date()))
    const product = await createProduct(request, 18)
    const transactionId = randomUUID()

    const response = await terminalRequest.post(`${API_BASE}/sync/transactions`, {
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
    expect(response.status(), await response.text()).toBe(201)

    return transactionId
  }

  const unacknowledgedFor = async (request: any, transactionId: string) => {
    const response = await request.get(`${API_BASE}/admin/jugendschutz-violations`)
    expect(response.status(), await response.text()).toBe(200)

    return (await response.json()).data.filter(
      (v: { transaction_id: string }) => v.transaction_id === transactionId,
    )
  }

  test('a recorded violation is offered for acknowledgement, and names no member', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const transactionId = await sellToAMinor(authenticatedRequest, authenticatedTerminalRequest)

    const mine = await unacknowledgedFor(authenticatedRequest, transactionId)
    expect(mine, 'the sale must be waiting to be looked at').toHaveLength(1)
    expect(mine[0].min_age).toBe(18)

    // The list renders on a screen that may be open in the clubroom.
    const serialised = JSON.stringify(mine[0])
    expect(serialised).not.toContain('member')
    expect(serialised).not.toContain('15')
  })

  /**
   * The count is a **club-wide** figure, so this asserts a floor rather than an
   * exact delta. `count + 1` looks tighter and is wrong: four workers create
   * and acknowledge violations concurrently, so any absolute claim about a
   * shared counter is a race (E2E Pattern 004). What is genuinely this test's
   * to assert is that a violation it just created leaves the dashboard
   * speaking, at error severity, about somebody it does not name — and the
   * per-transaction assertions elsewhere in this file carry the precision.
   */
  test('the dashboard raises it as an error, naming nobody', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const transactionId = await sellToAMinor(authenticatedRequest, authenticatedTerminalRequest)

    const after = await authenticatedRequest.get(`${API_BASE}/admin/dashboard`)
    const alert = (await after.json()).alerts.jugendschutz_violation

    expect(await unacknowledgedFor(authenticatedRequest, transactionId)).toHaveLength(1)
    expect(alert.count).toBeGreaterThanOrEqual(1)
    // No warning tier: one minor served is an incident, not a trend.
    expect(alert.severity).toBe('error')
    expect(alert.latest_occurred_at).not.toBeNull()
    expect(JSON.stringify(alert)).not.toContain('member')
  })

  test('acknowledging clears it from the alert but leaves the record standing', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const transactionId = await sellToAMinor(authenticatedRequest, authenticatedTerminalRequest)
    const [violation] = await unacknowledgedFor(authenticatedRequest, transactionId)

    const acknowledged = await authenticatedRequest.post(
      `${API_BASE}/admin/jugendschutz-violations/${violation.id}/acknowledge`,
      { data: { note: 'Vorstand informiert' } },
    )
    expect(acknowledged.status(), await acknowledged.text()).toBe(200)
    expect((await acknowledged.json()).newly_acknowledged).toBe(true)

    // Gone from the asking...
    expect(await unacknowledgedFor(authenticatedRequest, transactionId)).toHaveLength(0)

    // ...and still on the record. This is invariant 4: the incident does not
    // become untrue because somebody dealt with it.
    const audit = await authenticatedRequest.get(
      `${API_BASE}/admin/audit-log?action=jugendschutz_violation&entity_id=${transactionId}`,
    )
    expect((await audit.json()).data).toHaveLength(1)
  })

  test('the second admin to press the button is not an error', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const transactionId = await sellToAMinor(authenticatedRequest, authenticatedTerminalRequest)
    const [violation] = await unacknowledgedFor(authenticatedRequest, transactionId)
    const url = `${API_BASE}/admin/jugendschutz-violations/${violation.id}/acknowledge`

    expect((await authenticatedRequest.post(url, { data: {} })).status()).toBe(200)

    // Two people looking at the same dashboard alert is the expected case.
    const second = await authenticatedRequest.post(url, { data: {} })
    expect(second.status()).toBe(200)
    expect((await second.json()).newly_acknowledged).toBe(false)
  })

  /**
   * The key is `audit_log.id`, which every audited action in the system shares.
   * Without the action guard the endpoint would accept a login's id and answer
   * as though it had done something.
   */
  test('an audit entry that is not a violation cannot be acknowledged', async ({
    authenticatedRequest,
  }) => {
    // The non-violation entry is **created here** rather than searched for.
    // Reading one off the log and skipping when the query came back empty
    // would make this test quietly stop testing anything on a fresh database
    // — the ruling on #146, which the repo's own lint rule enforces.
    const id = randomUUID().replace(/-/g, '').slice(0, 10)
    const category = await authenticatedRequest.post(`${API_BASE}/admin/categories`, {
      data: { names: { de: `Nichtverstoß ${id}`, en: `Not a violation ${id}` } },
    })
    expect(category.status(), await category.text()).toBe(201)
    const categoryId = (await category.json()).id

    // Creating it audited a `create` against that id, so there is now exactly
    // one entry that is certainly not a violation.
    const audit = await authenticatedRequest.get(
      `${API_BASE}/admin/audit-log?entity_id=${categoryId}`,
    )
    expect(audit.status(), await audit.text()).toBe(200)
    const entries = (await audit.json()).data
    expect(entries.length, 'creating a category must leave an audit entry').toBeGreaterThan(0)

    const response = await authenticatedRequest.post(
      `${API_BASE}/admin/jugendschutz-violations/${entries[0].id}/acknowledge`,
      { data: {} },
    )
    expect(response.status()).toBe(404)
  })

  /**
   * The gap #622 exists to close. The audit log is `admin`-only, so before this
   * the Kassenwart had no way to learn a violation had happened — and no way to
   * say they had dealt with one.
   */
  test('the Kassenwart can see the alert, read the list and acknowledge', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
    kassenwartRequest,
  }) => {
    const transactionId = await sellToAMinor(authenticatedRequest, authenticatedTerminalRequest)

    const dashboard = await kassenwartRequest.get(`${API_BASE}/admin/dashboard`)
    expect(dashboard.status()).toBe(200)
    expect((await dashboard.json()).alerts.jugendschutz_violation.severity).toBe('error')

    const [violation] = await unacknowledgedFor(kassenwartRequest, transactionId)
    expect(violation, 'the Kassenwart must be able to read the list').toBeTruthy()

    const acknowledged = await kassenwartRequest.post(
      `${API_BASE}/admin/jugendschutz-violations/${violation.id}/acknowledge`,
      { data: {} },
    )
    expect(acknowledged.status(), await acknowledged.text()).toBe(200)

    // And the audit log they still cannot open is untouched by any of it.
    expect((await kassenwartRequest.get(`${API_BASE}/admin/audit-log`)).status()).toBe(403)
  })

  /** The Getränkewart gains nothing here either (ADR-0045 invariant 5). */
  test('the Getränkewart cannot read or clear violations', async ({ getraenkewartRequest }) => {
    expect((await getraenkewartRequest.get(`${API_BASE}/admin/jugendschutz-violations`)).status()).toBe(403)
    expect(
      (await getraenkewartRequest.post(`${API_BASE}/admin/jugendschutz-violations/1/acknowledge`, { data: {} })).status(),
    ).toBe(403)
  })
})
