/**
 * Journal API Service
 * Handles all journal/transaction-related API calls
 */

import { get } from './api'

export interface Transaction {
  id: string
  date: string
  type: 'purchase' | 'correction'
  description: string
  amount_cents: number
  running_total_cents: number
  settlement_id?: string | null
}

export interface Settlement {
  id: string
  date: string
  amount_cents: number
  type: 'sepa' | 'manual'
}

export interface MemberTransactionHistory {
  member_id: string
  current_balance_cents: number
  transactions: Transaction[]
  settlements: Settlement[]
}

/**
 * Get transaction history for a specific member
 */
export async function getMemberTransactionHistory(
  memberId: string,
  filters?: {
    date_from?: string
    date_to?: string
    type?: 'all' | 'purchase' | 'correction'
    include_settled?: boolean
  }
): Promise<MemberTransactionHistory> {
  const params: Record<string, any> = {}

  if (filters) {
    if (filters.date_from) params.date_from = filters.date_from
    if (filters.date_to) params.date_to = filters.date_to
    if (filters.type && filters.type !== 'all') params.type = filters.type
    if (filters.include_settled !== undefined) params.include_settled = filters.include_settled
  }

  const response = await get<MemberTransactionHistory>(`/admin/members/${memberId}/transactions`, {
    params,
  })

  // Backend returns data directly
  if (response && typeof response === 'object') {
    if ('member_id' in response && 'current_balance_cents' in response) {
      return response as MemberTransactionHistory
    }
    if ('data' in response && response.data) {
      return response.data as MemberTransactionHistory
    }
  }

  throw new Error('Invalid response from transaction history API')
}
