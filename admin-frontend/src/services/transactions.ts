/**
 * Transactions API Service
 * Handles member transaction history (UC-A20)
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
 * Get transaction history for a specific member (UC-A20)
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

  // Backend returns data directly or wrapped in 'data'
  if (response && typeof response === 'object') {
    if ('member_id' in response && 'current_balance_cents' in response && 'transactions' in response) {
      return response as unknown as MemberTransactionHistory
    }
    if ('data' in response && typeof response.data === 'object' && response.data !== null) {
      const data = response.data as unknown
      if (data && typeof data === 'object' && 'member_id' in data) {
        return data as MemberTransactionHistory
      }
    }
  }

  throw new Error('Invalid response from transaction history API')
}

/**
 * Global transaction from API (for journal view)
 */
export interface GlobalTransaction {
  id: string
  member_id: string
  member_name: string
  type: 'purchase' | 'correction'
  amount_cents: number
  description: string
  product_id: string | null
  product_name: string | null
  created_at: string
  created_by_admin_id: string | null
  created_by_terminal_id: string | null
  settlement_id: string | null
}

/**
 * Paginated global transactions response from backend
 */
export interface TransactionsListResponse {
  items: GlobalTransaction[]
  total: number
  page: number
  per_page: number
}

/**
 * Get global transactions list with filtering and pagination (UC-A20 derivative)
 * Used by: JournalPage for global transaction journal view
 *
 * @param page Page number (1-indexed, default: 1)
 * @param perPage Items per page (1-100, default: 20)
 * @param dateFrom Optional start date (ISO format: YYYY-MM-DD)
 * @param dateTo Optional end date (ISO format: YYYY-MM-DD)
 * @param type Transaction type filter (all|purchase|correction, default: all)
 * @param memberId Optional member UUID to filter by
 * @param search Optional search string (searches member name or notes)
 * @param sortKey Field to sort by (created_at|amount|type|member, default: created_at)
 * @param sortOrder Sort direction (asc|desc, default: desc)
 * @returns Paginated transactions response
 */
export async function getTransactions(
  page: number = 1,
  perPage: number = 20,
  dateFrom?: string,
  dateTo?: string,
  type: 'all' | 'purchase' | 'correction' = 'all',
  memberId?: string,
  search?: string,
  sortKey: 'created_at' | 'amount' | 'type' | 'member' = 'created_at',
  sortOrder: 'asc' | 'desc' = 'desc'
): Promise<TransactionsListResponse> {
  // Build query parameters
  const params: Record<string, any> = {
    page,
    per_page: perPage,
  }

  if (dateFrom) {
    params.date_from = dateFrom
  }
  if (dateTo) {
    params.date_to = dateTo
  }
  if (type !== 'all') {
    params.type = type
  }
  if (memberId) {
    params.member_id = memberId
  }
  if (search) {
    params.search = search
  }
  params.sort = sortKey
  params.order = sortOrder

  // Make API call
  const response = await get<TransactionsListResponse>('/admin/transactions', {
    params,
  })

  // Backend returns data directly or wrapped in data property
  // Check if response has items (direct return) or data property (wrapped)
  if (response && typeof response === 'object') {
    if ('items' in response && Array.isArray(response.items)) {
      return response as TransactionsListResponse
    }
    if ('data' in response && response.data && typeof response.data === 'object' && 'items' in response.data) {
      return response.data as TransactionsListResponse
    }
  }

  // Fallback response
  return {
    items: [],
    total: 0,
    page,
    per_page: perPage,
  }
}

/**
 * Format transaction type for display
 *
 * @param type Transaction type
 * @returns Display label
 */
export function formatTransactionType(type: 'purchase' | 'correction'): string {
  const labels: Record<'purchase' | 'correction', string> = {
    purchase: 'Purchase',
    correction: 'Correction',
  }
  return labels[type] || type
}

/**
 * Get badge color for transaction type
 *
 * @param type Transaction type
 * @returns Tailwind color class
 */
export function getTransactionTypeColor(type: 'purchase' | 'correction'): string {
  const colors: Record<'purchase' | 'correction', string> = {
    purchase: 'bg-blue-100 text-blue-800',
    correction: 'bg-orange-100 text-orange-800',
  }
  return colors[type] || 'bg-gray-100 text-gray-800'
}

/**
 * Get amount color based on sign
 *
 * @param amountCents Amount in cents
 * @returns Tailwind color class
 */
export function getAmountColor(amountCents: number): string {
  if (amountCents > 0) {
    return 'text-red-600' // Positive = charge (red)
  } else if (amountCents < 0) {
    return 'text-green-600' // Negative = credit (green)
  }
  return 'text-gray-600'
}
