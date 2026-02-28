/**
 * SEPA Validation Tests for Transaction Creation
 *
 * Tests to verify that transaction creation (both terminal sync and admin corrections)
 * enforces SEPA mandate validity (IBAN + mandate_reference required).
 *
 * Implements ADR-0020: SEPA Mandate Requirements
 * Implements UC-A21: Manual Booking
 * Implements UC-T10: Terminal Sync (with validation)
 *
 * Test Patterns:
 * - Pattern 001: Test Data Isolation (unique test data per test)
 * - Pattern 002: Authentication Isolation (use fixtures for auth)
 * - Pattern 003: Database-Agnostic Assertions (search by ID, not position)
 * - Pattern 004: Parallel Execution Safety (no shared state)
 * - Pattern 008: Playwright Assertions (use expect() instead of try-catch)
 *
 * E2E Integration:
 * - Create members with/without SEPA data via API
 * - Attempt transaction creation (both sync and corrections)
 * - Verify validation errors are returned correctly
 * - Verify transactions are rejected for SEPA-invalid members
 */

import { test, expect } from '../../fixtures/auth.fixture'
import { generateUUID, createTestMember, createSepaInvalidMember } from '../../utils/transactions'
import { TEST_CREDENTIALS } from '../../config/test-credentials'

test.describe('Transaction Creation - SEPA Validation', () => {
  /**
   * Test 1: Admin Corrections - Reject for Member Without IBAN
   *
   * Verifies that corrections API rejects members who have mandate but no IBAN
   */
  test('should reject correction for member without IBAN', async ({ authenticatedRequest }) => {
    // === ARRANGE ===
    const testData = createSepaInvalidMember('NoIban', 'Test', 'iban')

    // Create member without IBAN
    const createResponse = await authenticatedRequest.post(`http://localhost:8080/api/admin/members`, {
      data: testData,
    })

    expect(createResponse.status()).toBe(201)
    const memberJson = await createResponse.json()
    const memberId = memberJson.id

    // Verify member is SEPA invalid
    expect(memberJson.is_sepa_valid).toBeFalsy()

    // === ACT & ASSERT ===
    // Attempt to create correction
    const correctionResponse = await authenticatedRequest.post(
      `http://localhost:8080/api/admin/members/${memberId}/transactions/correct`,
      {
        data: {
          amount_cents: 1000,
          reason: 'Test correction',
        },
      }
    )

    // Verify rejection
    expect(correctionResponse.status()).toBe(422)
    const error = await correctionResponse.json()
    expect(error.error).toBe('sepa_invalid')
    expect(error.message).toContain('SEPA mandate')
  })

  /**
   * Test 2: Admin Corrections - Reject for Member Without Mandate Reference
   *
   * Verifies that corrections API rejects members who have IBAN but no mandate_reference
   */
  test('should reject correction for member without mandate reference', async ({ authenticatedRequest }) => {
    // === ARRANGE ===
    const testData = createSepaInvalidMember('NoMandate', 'Test', 'mandate')

    // Create member without mandate_reference
    const createResponse = await authenticatedRequest.post(`http://localhost:8080/api/admin/members`, {
      data: testData,
    })

    expect(createResponse.status()).toBe(201)
    const memberJson = await createResponse.json()
    const memberId = memberJson.id

    // Verify member is SEPA invalid
    expect(memberJson.is_sepa_valid).toBeFalsy()

    // === ACT & ASSERT ===
    // Attempt to create correction
    const correctionResponse = await authenticatedRequest.post(
      `http://localhost:8080/api/admin/members/${memberId}/transactions/correct`,
      {
        data: {
          amount_cents: 1500,
          reason: 'Test correction without mandate',
        },
      }
    )

    // Verify rejection
    expect(correctionResponse.status()).toBe(422)
    const error = await correctionResponse.json()
    expect(error.error).toBe('sepa_invalid')
    expect(error.message).toContain('SEPA mandate')
  })

  /**
   * Test 3: Terminal Sync - Reject Transaction for SEPA-Invalid Member
   *
   * Verifies that terminal sync API rejects transactions for members without SEPA data
   */
  test('should reject sync transaction for member without SEPA data', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    // === ARRANGE ===
    const testData = createSepaInvalidMember('NoSepa', 'Test', 'both')

    // Create member without SEPA data
    const createResponse = await authenticatedRequest.post(`http://localhost:8080/api/admin/members`, {
      data: testData,
    })

    expect(createResponse.status()).toBe(201)
    const memberJson = await createResponse.json()
    const memberId = memberJson.id

    // Verify member is SEPA invalid
    expect(memberJson.is_sepa_valid).toBeFalsy()

    // === ACT ===
    // Create sync transaction for SEPA-invalid member
    const txnId = generateUUID()
    const syncData = {
      transactions: [
        {
          id: txnId,
          member_id: memberId,
          type: 'product',
          product_id: generateUUID(),
          quantity: 1,
          unit_price_cents: 2500,
          amount_cents: 2500,
          notes: 'Test transaction',
          created_at: new Date().toISOString(),
        },
      ],
    }

    const response = await authenticatedTerminalRequest.post('http://localhost:8080/api/sync/transactions', {
      data: syncData,
    })

    // === ASSERT ===
    // Verify batch response (should be 201 even with errors)
    expect(response.status()).toBe(201)
    const result = await response.json()

    // Verify transaction was rejected
    expect(result.rejected.count).toBe(1)
    expect(result.accepted_ids).toHaveLength(0)
    expect(result.rejected.errors).toHaveLength(1)

    const error = result.rejected.errors[0]
    expect(error.error).toBe('sepa_invalid')
    expect(error.transaction_id).toBe(txnId)
    expect(error.message).toContain('SEPA mandate')
  })

  /**
   * Test 4: Happy Path - Allow Correction for Member with Valid SEPA Data
   *
   * Verifies that corrections API accepts members with valid SEPA data
   */
  test('should allow correction for member with valid SEPA data', async ({ authenticatedRequest }) => {
    // === ARRANGE ===
    const testData = createTestMember('ValidSepa', 'Test', 'validsepa')

    // Create member with valid SEPA
    const createResponse = await authenticatedRequest.post(`http://localhost:8080/api/admin/members`, {
      data: testData,
    })

    expect(createResponse.status()).toBe(201)
    const memberJson = await createResponse.json()
    const memberId = memberJson.id

    // Verify member is SEPA valid
    expect(memberJson.is_sepa_valid).toBeTruthy()

    // === ACT ===
    // Create correction
    const correctionResponse = await authenticatedRequest.post(
      `http://localhost:8080/api/admin/members/${memberId}/transactions/correct`,
      {
        data: {
          amount_cents: 2000,
          reason: 'Test correction for valid member',
        },
      }
    )

    // === ASSERT ===
    // Verify success
    expect(correctionResponse.status()).toBe(201)
    const correction = await correctionResponse.json()
    expect(correction.id).toBeTruthy()
    expect(correction.member_id).toBe(memberId)
    expect(correction.amount_cents).toBe(2000)
  })

  /**
   * Test 5: Happy Path - Allow Sync Transaction for Member with Valid SEPA Data
   *
   * Verifies that terminal sync API accepts transactions for members with valid SEPA data
   */
  test('should allow sync transaction for member with valid SEPA data', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    // === ARRANGE ===
    const testData = createTestMember('SyncSepaValid', 'Test', 'syncvalid')

    // Create member with valid SEPA
    const createResponse = await authenticatedRequest.post(`http://localhost:8080/api/admin/members`, {
      data: testData,
    })

    expect(createResponse.status()).toBe(201)
    const memberJson = await createResponse.json()
    const memberId = memberJson.id

    // Verify member is SEPA valid
    expect(memberJson.is_sepa_valid).toBeTruthy()

    // === ACT ===
    // Sync transaction for SEPA-valid member
    const txnId = generateUUID()
    const syncData = {
      transactions: [
        {
          id: txnId,
          member_id: memberId,
          type: 'product',
          product_id: generateUUID(),
          quantity: 1,
          unit_price_cents: 3500,
          amount_cents: 3500,
          notes: 'Test sync transaction',
          created_at: new Date().toISOString(),
        },
      ],
    }

    const response = await authenticatedTerminalRequest.post('http://localhost:8080/api/sync/transactions', {
      data: syncData,
    })

    // === ASSERT ===
    // Verify success
    expect(response.status()).toBe(201)
    const result = await response.json()

    // Verify transaction was accepted
    expect(result.accepted_ids).toContain(txnId)
    expect(result.rejected.count).toBe(0)
    expect(result.rejected.errors).toHaveLength(0)
  })

  /**
   * Test 6: Batch Processing - Mixed Valid and Invalid Members
   *
   * Verifies that terminal sync API correctly handles batch with both valid and invalid members
   */
  test('should handle batch with mixed valid and invalid SEPA members', async ({ authenticatedRequest, authenticatedTerminalRequest }) => {
    // === ARRANGE ===
    // Create two members: one with valid SEPA, one without
    const validData = createTestMember('MixedValid', 'Test', 'mixedvalid')
    const invalidData = createSepaInvalidMember('MixedInvalid', 'Test', 'both')

    const validResponse = await authenticatedRequest.post(`http://localhost:8080/api/admin/members`, {
      data: validData,
    })
    const validMember = await validResponse.json()

    const invalidResponse = await authenticatedRequest.post(`http://localhost:8080/api/admin/members`, {
      data: invalidData,
    })
    const invalidMember = await invalidResponse.json()

    // === ACT ===
    // Sync two transactions: one for valid, one for invalid
    const validTxnId = generateUUID()
    const invalidTxnId = generateUUID()

    const syncData = {
      transactions: [
        {
          id: validTxnId,
          member_id: validMember.id,
          type: 'product',
          product_id: generateUUID(),
          quantity: 1,
          unit_price_cents: 1500,
          amount_cents: 1500,
          notes: 'Valid transaction',
          created_at: new Date().toISOString(),
        },
        {
          id: invalidTxnId,
          member_id: invalidMember.id,
          type: 'product',
          product_id: generateUUID(),
          quantity: 1,
          unit_price_cents: 2500,
          amount_cents: 2500,
          notes: 'Invalid transaction',
          created_at: new Date().toISOString(),
        },
      ],
    }

    const response = await authenticatedTerminalRequest.post('http://localhost:8080/api/sync/transactions', {
      data: syncData,
    })

    // === ASSERT ===
    expect(response.status()).toBe(201)
    const result = await response.json()

    // Verify one accepted, one rejected
    expect(result.accepted_ids).toContain(validTxnId)
    expect(result.accepted_ids).not.toContain(invalidTxnId)
    expect(result.rejected.count).toBe(1)
    expect(result.rejected.errors).toHaveLength(1)

    const error = result.rejected.errors[0]
    expect(error.transaction_id).toBe(invalidTxnId)
    expect(error.error).toBe('sepa_invalid')
  })
})
