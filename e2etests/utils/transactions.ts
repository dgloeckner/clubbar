/**
 * Transaction Test Utilities
 *
 * Provides helper functions for creating test transactions, members, and settlements.
 * Implements E2E Testing Pattern 001: Test Data Isolation (via timestamps)
 * Implements E2E Testing Pattern 003: Database-Agnostic Assertions (via test data builders)
 */

import { toIsoDate } from './dates'

/**
 * Generate a UUID v4 string
 * Used for transaction IDs in sync API calls
 */
export const generateUUID = (): string => {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
    const r = Math.random() * 16 | 0
    const v = c === 'x' ? r : (r & 0x3 | 0x8)
    return v.toString(16)
  })
}

/**
 * Test data builder for creating members
 * Ensures isolation via timestamps
 */
export interface TestMemberData {
  first_name: string
  last_name: string
  email: string
  preferred_language: 'de' | 'en'
  iban: string
  mandate_reference: string
  mandate_signed_at: string
}

/**
 * Create isolated test member data with valid SEPA by default
 * @param firstName - Base name (timestamp appended for isolation)
 * @param lastName - Last name
 * @param baseEmail - Base email (timestamp appended)
 * @returns Test member data ready for API submission (SEPA-valid by default)
 */
export const createTestMember = (
  firstName: string = 'TestMember',
  lastName: string = 'Test',
  baseEmail: string = 'member'
): TestMemberData => {
  const timestamp = Date.now()
  const uuid = generateUUID()

  return {
    first_name: `${firstName}_${timestamp}`,
    last_name: lastName,
    email: `${baseEmail}-${timestamp}@test.example`,
    preferred_language: 'de',
    // SEPA data (valid by default)
    iban: 'DE89370400440532013000',
    mandate_reference: uuid.replace(/-/g, '').toUpperCase().substring(0, 35),
    mandate_signed_at: '2024-01-01',
  }
}

/**
 * Create a test member without valid SEPA data (for negative testing)
 * @param firstName - Base name (timestamp appended for isolation)
 * @param lastName - Last name
 * @param missingField - Which field to omit: 'iban', 'mandate', or 'both'
 * @returns Test member data with missing SEPA fields
 */
export const createSepaInvalidMember = (
  firstName: string = 'InvalidMember',
  lastName: string = 'Test',
  missingField: 'iban' | 'mandate' | 'both' = 'both'
): TestMemberData => {
  const timestamp = Date.now()
  const uuid = generateUUID()

  return {
    first_name: `${firstName}_${timestamp}`,
    last_name: lastName,
    email: `invalid-${timestamp}@test.example`,
    preferred_language: 'de',
    // Conditionally include SEPA fields
    iban: missingField === 'iban' || missingField === 'both' ? '' : 'DE89370400440532013000',
    mandate_reference: missingField === 'mandate' || missingField === 'both' ? '' : uuid.replace(/-/g, '').substring(0, 35),
    mandate_signed_at: '',
  }
}

/**
 * Test data builder for sync API transactions
 * Used by terminal app to sync product purchases
 */
export interface SyncTransactionData {
  id: string
  member_id: string
  type: 'product'
  product_id: string
  quantity: number
  unit_price_cents: number
  amount_cents: number
  notes: string
  created_at: string
}

/**
 * Create a sync transaction (simulates terminal transaction)
 * @param memberId - Member UUID to charge
 * @param amountCents - Amount in cents (e.g., 2500 for €25.00)
 * @param notes - Transaction notes/description
 * @param productId - Optional real product UUID (if omitted, generates placeholder)
 * @returns Transaction data ready for sync API
 */
export const createSyncTransaction = (
  memberId: string,
  amountCents: number = 2500,
  notes: string = 'Test transaction',
  productId?: string
): SyncTransactionData => {
  return {
    id: generateUUID(),
    member_id: memberId,
    type: 'product',
    product_id: productId ?? generateUUID(),
    quantity: 1,
    unit_price_cents: amountCents,
    amount_cents: amountCents,
    notes,
    created_at: new Date().toISOString(),
  }
}

/**
 * Test data builder for stornos
 *
 * (#169) POST /admin/transactions/{transactionId}/storno takes exactly one
 * field. There is no `amount_cents` any more — the amount is derived as the
 * exact negation of the transaction named in the URL, and no `notes` field
 * exists separately from `reason`: the reason string IS what lands in the
 * storno row's `notes` column. Passing an amount or notes here would just be
 * silently ignored by the backend, so the type no longer offers them.
 */
export interface StornoTransactionData {
  reason: string
}

/**
 * Build the request body for the storno endpoint.
 * @param reason - Why the booking is being reversed (required, 1-500 chars). Recorded verbatim as the storno row's notes.
 * @returns Storno data ready for POST /admin/transactions/{transactionId}/storno
 */
export const createStorno = (
  reason: string = 'Test storno'
): StornoTransactionData => {
  return { reason }
}

/**
 * Settlement data builder for creating settlements via API
 */
export interface SettlementData {
  method: 'direct_debit' | 'bank_transfer' | 'write_off'
  transaction_ids: string[]
  settlement_date: string
  execution_date: string
  period_start: string
  period_end: string
}

/**
 * Create settlement data
 *
 * The execution date must be supplied by the caller rather than derived from
 * `today + 7`: it has to be a TARGET2 business day, and a computed one lands on
 * a weekend or closing day often enough to make tests fail by day of week
 * (issue #11). Use `minimumExecutionDate()` from `utils/dates` to get a valid
 * value from the backend.
 *
 * `settlementDate` is supplied for the same reason: it must come from the same
 * clock as the execution date, or the server's own lead-time rule rejects the
 * pair. Use `serverToday()` from `utils/dates`.
 *
 * @param transactionIds - IDs of transactions to settle
 * @param executionDate - Valid execution date (YYYY-MM-DD)
 * @param settlementDate - Server's today (YYYY-MM-DD)
 * @returns Settlement data ready for settlement API
 */
export const createSettlement = (
  transactionIds: string[],
  executionDate: string,
  settlementDate: string
): SettlementData => {
  const today = settlementDate

  return {
    method: 'direct_debit',
    transaction_ids: transactionIds,
    settlement_date: today,
    execution_date: executionDate,
    period_start: today,
    period_end: today,
  }
}
