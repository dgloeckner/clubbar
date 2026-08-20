import { randomUUID } from 'crypto'
import { test, expect } from '../../fixtures/auth.fixture'

/**
 * What the terminal is given to enforce JuSchG § 9 with (epic #582 M4, ADR-0045).
 *
 * The check happens at an offline kiosk, so both halves of it have to be *in*
 * the sync payload — there is no second call at checkout and no server to ask.
 * This file asserts the wire contract those two fields form:
 *
 * - `members.date_of_birth` — the **raw date**, never an age in years. Age
 *   changes on a day the server cannot predict a sync for, so anything derived
 *   is silently wrong from the member's next birthday until the next sync
 *   (ADR-0045 decision 1). The terminal computes the age itself.
 * - `products.min_age` — the limit, already carried by the shared `ProductDto`
 *   since M3.
 *
 * And the property that makes the privacy trade-off survivable: **erasure
 * propagates through this same delta sync**. A birth date now lives on every
 * kiosk, and the only thing that takes it back off again is an anonymized row
 * arriving on the ordinary cursor with the field nulled.
 */

const API_BASE = 'http://localhost:8080/api'

test.describe('Jugendschutz over the terminal sync', () => {
  const uniqueMemberBody = (overrides: Record<string, unknown> = {}) => {
    const id = randomUUID().replace(/-/g, '').slice(0, 10)

    return {
      first_name: `Sync${id}`,
      last_name: `Jugend${id}`,
      email: `sync-jugend-${id}@example.com`,
      date_of_birth: '1985-06-15',
      preferred_language: 'de',
      iban: 'DE89370400440532013000',
      mandate_signed_at: '2024-01-01',
      ...overrides,
    }
  }

  /** The member as the terminal sees it, from a delta that certainly contains it. */
  const syncedMember = async (terminalRequest: any, memberId: string, since = 0) => {
    const response = await terminalRequest.get(`${API_BASE}/sync/members`, { params: { since } })
    expect(response.status(), await response.text()).toBe(200)

    const body = await response.json()

    return body.members.find((m: { id: string }) => m.id === memberId)
  }

  test('a member reaches the terminal with the raw birth date', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const created = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: uniqueMemberBody({ date_of_birth: '2009-03-04' }),
    })
    expect(created.status(), await created.text()).toBe(201)
    const member = await created.json()

    const synced = await syncedMember(authenticatedTerminalRequest, member.id)
    expect(synced, 'the new member must be in the delta').toBeTruthy()

    // The date itself, in the wire format — not an age, not a boolean, and not
    // shifted by a timezone on the way out.
    expect(synced.date_of_birth).toBe('2009-03-04')
  })

  /**
   * The payload still carries no contact and no banking data. Widening the
   * kiosk cache by one field is the trade ADR-0045 made; widening it by more
   * than that is not, and this is where such a change would first show up.
   */
  test('and nothing else was widened along with it', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const created = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: uniqueMemberBody(),
    })
    const member = await created.json()

    const synced = await syncedMember(authenticatedTerminalRequest, member.id)

    expect(Object.keys(synced).sort()).toEqual(
      [
        'card_uid',
        'created_at',
        'date_of_birth',
        'deleted_at',
        'first_name',
        'id',
        'is_active',
        'is_sepa_valid',
        'last_name',
        'preferred_language',
        'updated_at',
      ].sort(),
    )
  })

  /**
   * The compensating control for decision 1. Without this the kiosk keeps a
   * birth date the server has already erased, and the "erasure propagates
   * through the same delta sync" claim in ADR-0045 would be false.
   */
  test('an erasure takes the birth date back off the kiosks', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const created = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: uniqueMemberBody({ date_of_birth: '1990-05-04' }),
    })
    const member = await created.json()
    expect((await syncedMember(authenticatedTerminalRequest, member.id)).date_of_birth).toBe('1990-05-04')

    const since = Math.floor(Date.now() / 1000) - 1
    const anonymized = await authenticatedRequest.post(`${API_BASE}/admin/members/${member.id}/anonymize`)
    expect(anonymized.status(), await anonymized.text()).toBe(200)

    // On the ordinary cursor, with no new mechanism and no cache-busting step.
    const synced = await syncedMember(authenticatedTerminalRequest, member.id, since * 1000)
    expect(synced, 'the erasure must ride the ordinary delta').toBeTruthy()
    expect(synced.date_of_birth).toBeNull()
    expect(synced.deleted_at).not.toBeNull()
  })

  test('a restricted product reaches the terminal with its minimum age', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const id = randomUUID().replace(/-/g, '').slice(0, 10)
    const category = await authenticatedRequest.post(`${API_BASE}/admin/categories`, {
      data: { names: { de: `Kat${id}`, en: `Cat${id}` } },
    })
    const categoryId = (await category.json()).id

    const created = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
      data: {
        names: { de: `Schnaps ${id}`, en: `Spirit ${id}` },
        category_id: categoryId,
        price_cents: 250,
        min_age: 18,
      },
    })
    expect(created.status(), await created.text()).toBe(201)
    const product = await created.json()

    const response = await authenticatedTerminalRequest.get(`${API_BASE}/sync/products`, {
      params: { since: 0 },
    })
    expect(response.status()).toBe(200)

    const synced = (await response.json()).products.find((p: { id: string }) => p.id === product.id)
    expect(synced, 'the new product must be in the delta').toBeTruthy()
    expect(synced.min_age).toBe(18)
  })

  test('an unrestricted product reaches it with an explicit null', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const id = randomUUID().replace(/-/g, '').slice(0, 10)
    const category = await authenticatedRequest.post(`${API_BASE}/admin/categories`, {
      data: { names: { de: `Kat${id}`, en: `Cat${id}` } },
    })
    const categoryId = (await category.json()).id

    const created = await authenticatedRequest.post(`${API_BASE}/admin/products`, {
      data: {
        names: { de: `Schorle ${id}`, en: `Spritzer ${id}` },
        category_id: categoryId,
        price_cents: 220,
      },
    })
    const product = await created.json()

    const response = await authenticatedTerminalRequest.get(`${API_BASE}/sync/products`, {
      params: { since: 0 },
    })
    const synced = (await response.json()).products.find((p: { id: string }) => p.id === product.id)

    // Present and null, rather than absent: a terminal reading a missing key
    // and a terminal reading `null` must not have to agree by accident.
    expect(synced).toHaveProperty('min_age')
    expect(synced.min_age).toBeNull()
  })

  /**
   * The seeded fixtures are what the manual proof in the plan is run against,
   * and what M6's terminal tests will read. If they lost their ages the whole
   * kiosk-side check would silently have nothing to enforce.
   */
  test('the seeded drinks list carries both JuSchG thresholds', async ({
    authenticatedTerminalRequest,
  }) => {
    const response = await authenticatedTerminalRequest.get(`${API_BASE}/sync/products`, {
      params: { since: 0 },
    })
    const products = (await response.json()).products

    const ages = new Set(products.map((p: { min_age: number | null }) => p.min_age))
    expect(ages.has(16), 'a beer at 16').toBe(true)
    expect(ages.has(18), 'a spirit at 18').toBe(true)
    expect(ages.has(null), 'something unrestricted').toBe(true)
  })
})
