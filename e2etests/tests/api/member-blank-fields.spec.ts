import { randomUUID } from 'crypto'
import { test, expect } from '../../fixtures/auth.fixture'

/**
 * Clearing an optional member field (issue #111).
 *
 * A form has no way to send "absent" — a field the volunteer emptied arrives as
 * `""`. Create normalized a few of those to null inline and update normalized
 * none, so the same body meant different things depending on which button
 * produced it: `phone` and `account_holder_name` were stored as the empty
 * string, and a cleared `card_uid` was refused outright by its own `min:8` rule
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
    preferred_language: 'de',
    phone: '+49 170 1234567',
    card_uid: id.toUpperCase().replace(/[^0-9A-F]/g, '0'),
    iban: 'DE89370400440532013000',
    account_holder_name: `Blank ${id}`,
    mandate_reference: `MAN${id.toUpperCase()}`,
    mandate_signed_at: '2026-01-15',
    ...overrides,
  }
}

test.describe('Clearing an optional member field', () => {
  const clearable = ['phone', 'card_uid', 'iban', 'account_holder_name', 'mandate_signed_at']

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
        phone: '',
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
  })
})
