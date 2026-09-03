import { randomUUID } from 'crypto'
import { test, expect } from '../../fixtures/auth.fixture'

/**
 * Clearing an optional member field (issue #111).
 *
 * A form has no way to send "absent" — a field the volunteer emptied arrives as
 * `""`. Create normalized a few of those to null inline and update normalized
 * none, so the same body meant different things depending on which button
 * produced it: `account_holder_name` was stored as the empty string, and a
 * cleared `card_uid` was refused outright by its own `min:8` rule
 * — the volunteer could not save a member whose card had been handed back. That
 * rule is also all that keeps an empty `card_uid` off the UNIQUE index it
 * shares, which permits many NULLs but only one empty string.
 *
 * E2E Pattern 001: every test mints its own members, so the file is safe to run
 * against the shared database in parallel.
 */

const API_BASE = 'http://localhost:8080/api'

const uniqueMemberBody = (overrides: Record<string, unknown> = {}) => {
  const id = randomUUID().replace(/-/g, '').slice(0, 10)

  return {
    first_name: `Blank${id}`,
    last_name: `Member${id}`,
    email: `blank-${id}@example.com`,
    // Mandatory since #582 M2 (ADR-0045) — and deliberately not clearable, see
    // member-date-of-birth.spec.ts.
    date_of_birth: '1985-06-15',
    preferred_language: 'de',
    card_uid: id.toUpperCase().replace(/[^0-9A-F]/g, '0'),
    iban: 'DE89370400440532013000',
    account_holder_name: `Blank ${id}`,
    mandate_reference: `MAN${id.toUpperCase()}`,
    mandate_signed_at: '2026-01-15',
    ...overrides,
  }
}

test.describe('Clearing an optional member field', () => {
  // `iban` is deliberately absent: it is sealed and never returned, so blank
  // means "keep" rather than "clear" for that one field. Its own contract is
  // covered below.
  const clearable = ['card_uid', 'account_holder_name', 'mandate_signed_at']

  for (const field of clearable) {
    test(`PATCH /admin/members/{id} clears ${field} to null`, async ({ authenticatedRequest }) => {
      const created = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
        data: uniqueMemberBody(),
      })
      expect(created.status()).toBe(201)
      const member = await created.json()
      expect(member[field]).not.toBeNull()

      const patched = await authenticatedRequest.patch(`${API_BASE}/admin/members/${member.id}`, {
        data: { [field]: '' },
      })

      expect(patched.status()).toBe(200)
      expect((await patched.json())[field]).toBeNull()

      // Re-read: the response must not be reporting a value the row never took.
      const reread = await authenticatedRequest.get(`${API_BASE}/admin/members/${member.id}`)
      expect(reread.status()).toBe(200)
      expect((await reread.json())[field]).toBeNull()
    })
  }

  // Two members, because one cleared card is a NULL and two are what the UNIQUE
  // index would have to hold at once.
  test('clearing the card of two members leaves both of them without one', async ({
    authenticatedRequest,
  }) => {
    const ids: string[] = []
    for (let i = 0; i < 2; i++) {
      const created = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
        data: uniqueMemberBody(),
      })
      expect(created.status()).toBe(201)
      ids.push((await created.json()).id)
    }

    for (const id of ids) {
      const patched = await authenticatedRequest.patch(`${API_BASE}/admin/members/${id}`, {
        data: { card_uid: '' },
      })

      expect(patched.status()).toBe(200)
      expect((await patched.json()).card_uid).toBeNull()
    }
  })

  test('POST /admin/members accepts a body whose optional fields are blank', async ({
    authenticatedRequest,
  }) => {
    const response = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: uniqueMemberBody({
        card_uid: '',
        iban: '',
        account_holder_name: '',
        mandate_signed_at: '',
      }),
    })

    expect(response.status()).toBe(201)

    const member = await response.json()
    for (const field of clearable) {
      expect(member[field], `${field} must be stored as no value, not as ""`).toBeNull()
    }
    // Blank on create still means "no mandate" — there is nothing stored to keep.
    expect(member.iban_last4).toBeNull()
    expect(member.is_sepa_valid).toBe(false)
  })

  /**
   * Email is required at application level for an active member (#362): the
   * pre-notification and settlement statement emails are a contractual
   * promise (Nutzungsordnung § 7). Unlike the fields above, clearing it is
   * refused outright rather than normalized to null — the column itself
   * stays nullable only for GDPR erasure, which anonymizes through a
   * separate endpoint.
   */
  test('PATCH /admin/members/{id} rejects clearing email with a blank string', async ({
    authenticatedRequest,
  }) => {
    const created = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: uniqueMemberBody(),
    })
    expect(created.status()).toBe(201)
    const member = await created.json()

    const patched = await authenticatedRequest.patch(`${API_BASE}/admin/members/${member.id}`, {
      data: { email: '' },
    })

    expect(patched.status()).toBe(422)
    const body = await patched.json()
    expect(body.error).toBe('validation_failed')
    expect(body.messages.email).toBeDefined()

    const reread = await authenticatedRequest.get(`${API_BASE}/admin/members/${member.id}`)
    expect((await reread.json()).email).toBe(member.email)
  })

  test('PATCH /admin/members/{id} rejects clearing email with null', async ({
    authenticatedRequest,
  }) => {
    const created = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: uniqueMemberBody(),
    })
    expect(created.status()).toBe(201)
    const member = await created.json()

    const patched = await authenticatedRequest.patch(`${API_BASE}/admin/members/${member.id}`, {
      data: { email: null },
    })

    expect(patched.status()).toBe(422)
    expect((await patched.json()).messages.email).toBeDefined()
  })

  test('PATCH /admin/members/{id} allows clearing email once the member is inactive', async ({
    authenticatedRequest,
  }) => {
    const created = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: uniqueMemberBody(),
    })
    expect(created.status()).toBe(201)
    const member = await created.json()

    const deactivated = await authenticatedRequest.patch(`${API_BASE}/admin/members/${member.id}`, {
      data: { is_active: false },
    })
    expect(deactivated.status()).toBe(200)

    const patched = await authenticatedRequest.patch(`${API_BASE}/admin/members/${member.id}`, {
      data: { email: '' },
    })

    expect(patched.status()).toBe(200)
    expect((await patched.json()).email).toBeNull()
  })

  test('PATCH /admin/members/{id} allows clearing email while deactivating in the same request', async ({
    authenticatedRequest,
  }) => {
    const created = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: uniqueMemberBody(),
    })
    expect(created.status()).toBe(201)
    const member = await created.json()

    const patched = await authenticatedRequest.patch(`${API_BASE}/admin/members/${member.id}`, {
      data: { is_active: false, email: null },
    })

    expect(patched.status()).toBe(200)
    const body = await patched.json()
    expect(body.email).toBeNull()
    expect(body.is_active).toBe(false)
  })

  /**
   * Overwrite-only IBAN (#392).
   *
   * The IBAN is sealed at rest (ADR-0036) and the API returns only its last four
   * characters, so the edit form cannot prefill it and the field arrives blank on
   * every save that did not deliberately retype it. Blank therefore has to mean
   * "keep the stored account" — the previous "clear it" reading would revoke the
   * mandate of every member whose name an admin corrected.
   */
  test('PATCH /admin/members/{id} with a blank IBAN keeps the stored account', async ({
    authenticatedRequest,
  }) => {
    const created = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: uniqueMemberBody(),
    })
    expect(created.status()).toBe(201)
    const member = await created.json()
    expect(member.iban_last4).toBe('3000')

    const patched = await authenticatedRequest.patch(`${API_BASE}/admin/members/${member.id}`, {
      data: { iban: '', last_name: 'Corrected' },
    })

    expect(patched.status()).toBe(200)
    const updated = await patched.json()
    expect(updated.last_name).toBe('Corrected')
    expect(updated.iban_last4, 'a blank IBAN must not revoke the mandate').toBe('3000')
    expect(updated.mandate_reference).toBe(member.mandate_reference)
    expect(updated.is_sepa_valid).toBe(true)
  })

  test('PATCH /admin/members/{id} with an explicit null IBAN revokes the mandate', async ({
    authenticatedRequest,
  }) => {
    const created = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: uniqueMemberBody(),
    })
    expect(created.status()).toBe(201)
    const member = await created.json()
    expect(member.iban_last4).toBe('3000')

    const patched = await authenticatedRequest.patch(`${API_BASE}/admin/members/${member.id}`, {
      data: { iban: null },
    })

    expect(patched.status()).toBe(200)
    const updated = await patched.json()
    expect(updated.iban_last4).toBeNull()
    expect(updated.is_sepa_valid).toBe(false)
  })

  test('no member response carries a full IBAN', async ({ authenticatedRequest }) => {
    const created = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: uniqueMemberBody(),
    })
    expect(created.status()).toBe(201)
    const member = await created.json()

    for (const body of [
      member,
      await (await authenticatedRequest.get(`${API_BASE}/admin/members/${member.id}`)).json(),
      await (await authenticatedRequest.get(`${API_BASE}/admin/members/${member.id}/export`)).json(),
    ]) {
      expect(JSON.stringify(body)).not.toContain('DE89370400440532013000')
    }
  })
})
