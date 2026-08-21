import { randomUUID } from 'crypto'
import { test, expect } from '../../fixtures/auth.fixture'

/**
 * A member carries a date of birth (epic #582 M2, ADR-0045).
 *
 * The field is the member half of the Jugendschutz check: the terminal syncs
 * the **raw date** and computes the age itself at checkout, because age changes
 * on a day the server cannot predict a sync for and an offline kiosk has to be
 * right anyway.
 *
 * Two properties are asserted here rather than assumed, because the whole
 * design rests on them:
 *
 * 1. **Mandatory on create.** Without it there would have to be an "unknown
 *    age, allow anyway" branch at the terminal — the one branch ADR-0045
 *    decision 2 exists to remove.
 * 2. **NULL only ever means anonymized.** The column is nullable purely so
 *    erasure can clear it (ADR-0029, OLG Dresden 4 U 1278/21), so nothing else
 *    in the API may produce a NULL: not a create, not an update, not a blank
 *    field from a form.
 *
 * E2E Pattern 001: every test mints its own member, so the file is safe under
 * parallel workers.
 */

const API_BASE = 'http://localhost:8080/api'

const uniqueMemberBody = (overrides: Record<string, unknown> = {}) => {
  const id = randomUUID().replace(/-/g, '').slice(0, 10)

  return {
    first_name: `Dob${id}`,
    last_name: `Member${id}`,
    email: `dob-${id}@example.com`,
    date_of_birth: '1985-06-15',
    preferred_language: 'de',
    ...overrides,
  }
}

/** A date `days` from the server's own today, in the wire format. */
const daysFromToday = (days: number): string => {
  const d = new Date()
  d.setUTCDate(d.getUTCDate() + days)
  return d.toISOString().slice(0, 10)
}

test.describe('Member date of birth', () => {
  test('is stored on create and read back on the member', async ({ authenticatedRequest }) => {
    const created = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: uniqueMemberBody({ date_of_birth: '2008-02-29' }),
    })

    expect(created.status(), await created.text()).toBe(201)
    const member = await created.json()
    expect(member.date_of_birth).toBe('2008-02-29')

    // A DATE-only field must survive the round trip unshifted — the leap day is
    // the value a timezone bug moves first.
    const fetched = await authenticatedRequest.get(`${API_BASE}/admin/members/${member.id}`)
    expect(fetched.status()).toBe(200)
    expect((await fetched.json()).date_of_birth).toBe('2008-02-29')
  })

  test('is required — a member cannot be created without one', async ({ authenticatedRequest }) => {
    const body = uniqueMemberBody()
    delete (body as Record<string, unknown>).date_of_birth

    const response = await authenticatedRequest.post(`${API_BASE}/admin/members`, { data: body })

    expect(response.status()).toBe(422)
    const error = await response.json()
    expect(error.error).toBe('validation_failed')
    expect(error.messages).toHaveProperty('date_of_birth')
  })

  test('rejects a blank one on create, exactly like an absent one', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: uniqueMemberBody({ date_of_birth: '' }),
    })

    expect(response.status()).toBe(422)
    expect((await response.json()).messages).toHaveProperty('date_of_birth')
  })

  test('rejects a birth date in the future', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: uniqueMemberBody({ date_of_birth: daysFromToday(1) }),
    })

    expect(response.status()).toBe(422)
    expect((await response.json()).messages.date_of_birth).toContain(
      'date_of_birth must not be in the future',
    )
  })

  test('rejects a malformed birth date', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: uniqueMemberBody({ date_of_birth: 'sometime in the eighties' }),
    })

    expect(response.status()).toBe(422)
    expect((await response.json()).messages).toHaveProperty('date_of_birth')
  })

  test('can be corrected on an existing member', async ({ authenticatedRequest }) => {
    const created = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: uniqueMemberBody({ date_of_birth: '1990-05-04' }),
    })
    const member = await created.json()

    const patched = await authenticatedRequest.patch(`${API_BASE}/admin/members/${member.id}`, {
      data: { date_of_birth: '1990-04-05' },
    })

    expect(patched.status(), await patched.text()).toBe(200)
    expect((await patched.json()).date_of_birth).toBe('1990-04-05')
  })

  /**
   * The edit form cannot erase a birth date. Erasure is the anonymize endpoint's
   * job alone — irreversible, and `admin`-only under ADR-0044. A blank that
   * normalized to NULL here would let an admin produce, by accident, the state
   * the terminal reads as "anonymized member" on someone who is still active.
   */
  test('cannot be cleared through an update', async ({ authenticatedRequest }) => {
    const created = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: uniqueMemberBody({ date_of_birth: '1990-05-04' }),
    })
    const member = await created.json()

    const blanked = await authenticatedRequest.patch(`${API_BASE}/admin/members/${member.id}`, {
      data: { date_of_birth: '' },
    })
    expect(blanked.status()).toBe(422)

    const nulled = await authenticatedRequest.patch(`${API_BASE}/admin/members/${member.id}`, {
      data: { date_of_birth: null },
    })
    expect(nulled.status()).toBe(422)

    const fetched = await authenticatedRequest.get(`${API_BASE}/admin/members/${member.id}`)
    expect((await fetched.json()).date_of_birth).toBe('1990-05-04')
  })

  test('a PATCH of another field leaves it alone and is not rejected for its absence', async ({
    authenticatedRequest,
  }) => {
    const created = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: uniqueMemberBody({ date_of_birth: '1990-05-04' }),
    })
    const member = await created.json()

    const patched = await authenticatedRequest.patch(`${API_BASE}/admin/members/${member.id}`, {
      data: { phone: '+49 170 7654321' },
    })

    expect(patched.status(), await patched.text()).toBe(200)
    expect((await patched.json()).date_of_birth).toBe('1990-05-04')
  })

  /**
   * The roster does not carry birth dates.
   *
   * The list is what an admin scrolls; it needs names, balances and SEPA state.
   * The two consumers that need the date read one member at a time — the edit
   * form loads the member by id before it opens, and the terminal gets it
   * through the sync — so keeping it off the list costs nothing.
   */
  test('is absent from the member list', async ({ authenticatedRequest }) => {
    const created = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: uniqueMemberBody({ date_of_birth: '1990-05-04' }),
    })
    const member = await created.json()

    const list = await authenticatedRequest.get(`${API_BASE}/admin/members`, {
      params: { search: member.last_name, per_page: 20 },
    })
    expect(list.status()).toBe(200)

    const rows = (await list.json()).data
    const row = rows.find((r: { id: string }) => r.id === member.id)
    expect(row, 'the member the search was for must be in the page').toBeTruthy()
    expect(row).not.toHaveProperty('date_of_birth')
  })

  /**
   * `mandate_signed_at` gains the same `past_date` rule in this change. It
   * carried only `nullable|date`, so a mandate could be recorded as signed next
   * month — on the field whose date decides whether a collection is covered.
   */
  test('a mandate cannot be recorded as signed in the future', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: uniqueMemberBody({
        iban: 'DE89370400440532013000',
        mandate_signed_at: daysFromToday(30),
      }),
    })

    expect(response.status()).toBe(422)
    expect((await response.json()).messages).toHaveProperty('mandate_signed_at')
  })
})
