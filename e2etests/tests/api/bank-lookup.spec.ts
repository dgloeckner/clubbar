import { test } from '../../fixtures/auth.fixture'
import { expect } from '@playwright/test'

/**
 * E2E Test: Bank Lookup API
 *
 * Verifies the GET /api/admin/bank-lookup?iban= endpoint returns
 * correct bank information from the Bundesbank BLZ lookup table.
 *
 * Prerequisites: bank_codes table seeded with test data (see seed.sql).
 */

test.describe('Bank Lookup API', () => {
  const API_BASE = 'http://localhost:8080/api'

  test('returns bank name for valid German IBAN', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/bank-lookup?iban=DE89370400440532013000`,
    )
    expect(response.ok()).toBeTruthy()

    const data = await response.json()
    expect(data.bank_code).toBe('37040044')
    expect(data.bank_name).toContain('Commerzbank')
    expect(data.bic).toBe('COBADEFFXXX')
  })

  test('returns null fields for non-German IBAN', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/bank-lookup?iban=AT611904300234573201`,
    )
    expect(response.ok()).toBeTruthy()

    const data = await response.json()
    expect(data.bank_name).toBeNull()
    expect(data.bic).toBeNull()
  })

  test('returns 400 for missing iban parameter', async ({ authenticatedRequest }) => {
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/bank-lookup`,
    )
    expect(response.status()).toBe(400)
  })

  test('returns null bank_name for unknown BLZ', async ({ authenticatedRequest }) => {
    // Valid IBAN checksum, but BLZ 99999999 is never assigned by the
    // Bundesbank — stays unknown even with the full BLZ file imported
    // (a real BLZ like 20050550 resolves once the import has run).
    const response = await authenticatedRequest.get(
      `${API_BASE}/admin/bank-lookup?iban=DE66999999991234567890`,
    )
    expect(response.ok()).toBeTruthy()

    const data = await response.json()
    expect(data.bank_code).toBe('99999999')
    expect(data.bank_name).toBeNull()
  })

  test('member response includes bank_name field', async ({ authenticatedRequest }) => {
    // Create member with known IBAN
    const ts = Date.now()
    const createResponse = await authenticatedRequest.post(`${API_BASE}/admin/members`, {
      data: {
        first_name: `BankTest${ts}`,
        last_name: `Last${ts}`,
        email: `banktest-${ts}@example.com`,
        preferred_language: 'de',
        iban: 'DE89370400440532013000',
        mandate_signed_at: '2025-01-01',
      },
    })
    expect(createResponse.status()).toBe(201)

    const member = await createResponse.json()
    expect(member.bank_name).toContain('Commerzbank')
  })
})
