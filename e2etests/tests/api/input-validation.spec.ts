import { randomUUID } from 'crypto'
import { test, expect } from '../../fixtures/auth.fixture'
import { stepUp } from '../../fixtures/stepUp'

/**
 * Input-validation hardening (issue #117).
 *
 * Every case below used to reach the database as-is and come back as an opaque
 * 500: an over-long member field or a malformed date hit MariaDB in strict
 * mode, a duplicate own-profile address hit the `admin_users.email` UNIQUE
 * constraint, and a negative terminal `limit`/`offset` went straight into
 * LIMIT/OFFSET. They must all be answered by the API instead — with a status
 * that names the problem, and without the request changing anything.
 *
 * E2E Pattern 001: every test mints its own member/admin, so the file is safe
 * to run against the shared database in parallel.
 */

const API_BASE = 'http://localhost:8080/api'

const uniqueMemberBody = (overrides: Record<string, unknown> = {}) => {
  const id = randomUUID().slice(0, 8)

  return {
    first_name: `Valid${id}`,
    last_name: `Member${id}`,
    email: `valid-${id}@example.com`,
    date_of_birth: '1985-06-15',
    preferred_language: 'de',
    ...overrides,
  }
}

test.describe('Member field validation', () => {
  const rejected: Array<{ label: string; field: string; value: string }> = [
    { label: 'an email past VARCHAR(255)', field: 'email', value: `${'a'.repeat(250)}@example.com` },
    { label: 'a mandate reference past VARCHAR(35)', field: 'mandate_reference', value: 'R'.repeat(36) },
    { label: 'a mandate date written as prose', field: 'mandate_signed_at', value: 'next tuesday' },
    { label: 'a mandate date that is not on the calendar', field: 'mandate_signed_at', value: '2026-02-30' },
  ]

  for (const { label, field, value } of rejected) {
    test(`POST /admin/members answers 422 naming the field for ${label}`, async ({
      authenticatedRequest,
    }) => {
      const response = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
        data: uniqueMemberBody({ [field]: value }),
      })

      expect(response.status()).toBe(422)

      const body = await response.json()
      expect(body.error).toBe('validation_failed')
      expect(Object.keys(body.messages)).toContain(field)
    })

    test(`PATCH /admin/members/{id} answers 422 naming the field for ${label}`, async ({
      authenticatedRequest,
    }) => {
      const created = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
        data: uniqueMemberBody(),
      })
      expect(created.status()).toBe(201)
      const member = await created.json()

      const response = await authenticatedRequest.patch(`${API_BASE}/admin/members/${member.id}`, {
        data: { [field]: value },
      })

      expect(response.status()).toBe(422)
      expect(Object.keys((await response.json()).messages)).toContain(field)

      // The refused request must have changed nothing.
      const after = await authenticatedRequest.get(`${API_BASE}/admin/members/${member.id}`)
      expect((await after.json())[field] ?? null).toBe(member[field] ?? null)
    })
  }

  test('a body that fits every column is still accepted and persisted', async ({
    authenticatedRequest,
  }) => {
    // With an IBAN, so the mandate the signature date lives on actually
    // exists: `mandate_signed_at` is a `mandates` column, and a member created
    // without bank details has no row for it to persist into.
    const body = uniqueMemberBody({
      iban: 'DE89370400440532013000',
      mandate_signed_at: '2026-01-15',
    })

    const created = await authenticatedRequest.post(`${API_BASE}/admin/members`, { data: body })
    expect(created.status()).toBe(201)

    const member = await created.json()
    const fetched = await authenticatedRequest.get(`${API_BASE}/admin/members/${member.id}`)

    expect(fetched.status()).toBe(200)
    expect((await fetched.json()).mandate_signed_at).toBe('2026-01-15')
  })
})

test.describe('Admin user update applies the whole body', () => {
  const createAdmin = async (authenticatedRequest: any) => {
    const id = randomUUID().slice(0, 8)
    const response = await authenticatedRequest.post(`${API_BASE}/admin/admin-users`, {
      data: {
        ...stepUp(),
        email: `issue117-${id}@test.example.com`,
        display_name: 'Original Name',
        locale: 'de',
      },
    })
    expect(response.status()).toBe(201)

    return (await response.json()).admin
  }

  test('PATCH carrying is_active alongside other fields applies all of them', async ({
    authenticatedRequest,
  }) => {
    const admin = await createAdmin(authenticatedRequest)

    const response = await authenticatedRequest.patch(`${API_BASE}/admin/admin-users/${admin.id}`, {
      data: { is_active: false, display_name: 'Renamed Name', locale: 'en' },
    })

    expect(response.status()).toBe(200)

    const fetched = await authenticatedRequest.get(`${API_BASE}/admin/admin-users/${admin.id}`)
    const saved = (await fetched.json()).admin

    expect(saved.is_active).toBe(false)
    expect(saved.display_name).toBe('Renamed Name')
    expect(saved.locale).toBe('en')
  })

  test('reactivation alongside other fields applies all of them too', async ({
    authenticatedRequest,
  }) => {
    const admin = await createAdmin(authenticatedRequest)

    await authenticatedRequest.patch(`${API_BASE}/admin/admin-users/${admin.id}`, {
      data: { is_active: false },
    })

    const response = await authenticatedRequest.patch(`${API_BASE}/admin/admin-users/${admin.id}`, {
      data: { is_active: true, display_name: 'Back Again' },
    })

    expect(response.status()).toBe(200)

    const fetched = await authenticatedRequest.get(`${API_BASE}/admin/admin-users/${admin.id}`)
    const saved = (await fetched.json()).admin

    expect(saved.is_active).toBe(true)
    expect(saved.display_name).toBe('Back Again')
  })
})

test.describe('Own-profile email change', () => {
  test('PATCH /auth/profile answers 422 for an address another admin already holds', async ({
    authenticatedRequest,
  }) => {
    const id = randomUUID().slice(0, 8)
    const taken = `issue117-taken-${id}@test.example.com`

    const created = await authenticatedRequest.post(`${API_BASE}/admin/admin-users`, {
      data: { ...stepUp(), email: taken, display_name: 'Holder', locale: 'de' },
    })
    expect(created.status()).toBe(201)

    const before = await authenticatedRequest.get(`${API_BASE}/auth/profile`)
    const myEmail = (await before.json()).admin.email

    const response = await authenticatedRequest.patch(`${API_BASE}/auth/profile`, {
      data: { email: taken },
    })

    expect(response.status()).toBe(422)

    const body = await response.json()
    expect(body.error).toBe('validation_failed')
    expect(Object.keys(body.messages)).toContain('email')

    const after = await authenticatedRequest.get(`${API_BASE}/auth/profile`)
    expect((await after.json()).admin.email).toBe(myEmail)
  })

  test('PATCH /auth/profile still accepts your own address unchanged', async ({
    authenticatedRequest,
  }) => {
    const before = await authenticatedRequest.get(`${API_BASE}/auth/profile`)
    const myEmail = (await before.json()).admin.email

    const response = await authenticatedRequest.patch(`${API_BASE}/auth/profile`, {
      data: { email: myEmail },
    })

    expect(response.status()).toBe(200)
    expect((await response.json()).admin.email).toBe(myEmail)
  })
})

test.describe('Terminal transaction history pagination', () => {
  test('a negative limit or offset is clamped rather than answered with a 500', async ({
    authenticatedRequest,
    authenticatedTerminalRequest,
  }) => {
    const created = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: uniqueMemberBody(),
    })
    expect(created.status()).toBe(201)
    const member = await created.json()

    for (const query of ['limit=-1', 'offset=-5', 'limit=0', 'limit=-1&offset=-5']) {
      const response = await authenticatedTerminalRequest.get(
        `/api/terminal/transactions/${member.id}?${query}`,
      )

      expect(response.status(), `?${query} must not be a server error`).toBe(200)
      expect(Array.isArray((await response.json()).transactions)).toBe(true)
    }
  })
})
