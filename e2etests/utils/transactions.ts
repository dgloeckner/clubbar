/**
 * Transaction Test Utilities
 *
 * Provides helper functions for creating test transactions, members, and settlements.
 * Implements E2E Testing Pattern 001: Test Data Isolation (via timestamps)
 * Implements E2E Testing Pattern 003: Database-Agnostic Assertions (via test data builders)
 */

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
}

/**
 * Create isolated test member data
 * @param firstName - Base name (timestamp appended for isolation)
 * @param lastName - Last name
 * @param baseEmail - Base email (timestamp appended)
 * @returns Test member data ready for API submission
 */
export const createTestMember = (
  firstName: string = 'TestMember',
  lastName: string = 'Test',
  baseEmail: string = 'member'
): TestMemberData => {
  const timestamp = Date.now()
  return {
    first_name: `${firstName}_${timestamp}`,
    last_name: lastName,
    email: `${baseEmail}-${timestamp}@test.example`,
    preferred_language: 'de',
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
 * @returns Transaction data ready for sync API
 */
export const createSyncTransaction = (
  memberId: string,
  amountCents: number = 2500,
  notes: string = 'Test transaction'
): SyncTransactionData => {
  return {
    id: generateUUID(),
    member_id: memberId,
    type: 'product',
    product_id: generateUUID(), // Placeholder product UUID
    quantity: 1,
    unit_price_cents: amountCents,
    amount_cents: amountCents,
    notes,
    created_at: new Date().toISOString(),
  }
}

/**
 * Test data builder for manual corrections
 * Used by admin to adjust member balances
 */
export interface CorrectionTransactionData {
  reason: 'adjustment' | 'refund' | 'discount'
  amount_cents: number
  notes: string
}

/**
 * Create a correction transaction (simulates admin adjustment)
 * @param amountCents - Amount in cents
 * @param notes - Transaction notes
 * @param reason - Reason for correction
 * @returns Correction data ready for correction API
 */
export const createCorrection = (
  amountCents: number = 1000,
  notes: string = 'Test correction',
  reason: 'adjustment' | 'refund' | 'discount' = 'adjustment'
): CorrectionTransactionData => {
  return {
    reason,
    amount_cents: amountCents,
    notes,
  }
}

/**
 * Settlement data builder for creating settlements via API
 */
export interface SettlementData {
  settlement_type: 'sepa' | 'manual'
  transaction_ids: string[]
  settlement_date: string
  execution_date: string
  period_start: string
  period_end: string
}

/**
 * Create settlement data
 * @param transactionIds - IDs of transactions to settle
 * @param daysFromNow - Days from today for execution date (default: 7)
 * @returns Settlement data ready for settlement API
 */
export const createSettlement = (
  transactionIds: string[],
  daysFromNow: number = 7
): SettlementData => {
  const today = new Date().toISOString().split('T')[0]
  const executionDate = new Date()
  executionDate.setDate(executionDate.getDate() + daysFromNow)
  const executionDateStr = executionDate.toISOString().split('T')[0]

  return {
    settlement_type: 'sepa',
    transaction_ids: transactionIds,
    settlement_date: today,
    execution_date: executionDateStr,
    period_start: today,
    period_end: today,
  }
}
