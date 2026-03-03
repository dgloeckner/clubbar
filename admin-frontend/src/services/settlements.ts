/**
 * Settlements API Service
 * Handles settlement list and detail operations (UC-A33, UC-A34)
 */

import { get, post, del, downloadFile } from './api'

export interface Settlement {
  id: string
  settlement_date: string
  execution_date: string | null
  total_amount_cents: number
  total_amount_eur: number
  member_count: number
  is_cancelled: boolean
  exported_at: string | null
  created_at: string
  created_by_admin_id: string | null
  created_by_admin_name: string | null
  transaction_count: number
  transaction_date_min: string | null
  transaction_date_max: string | null
}

export interface SettlementsResponse {
  data: Settlement[]
  pagination: {
    total: number
    per_page: number
    current_page: number
    last_page: number
  }
}

/**
 * Get list of settlements with filtering, sorting, and pagination (UC-A33)
 *
 * @param page Page number (1-indexed, default: 1)
 * @param perPage Items per page (default: 20)
 * @param type Filter by settlement type (DEPRECATED - ignored)
 * @param dateFrom Optional start date (ISO format: YYYY-MM-DD)
 * @param dateTo Optional end date (ISO format: YYYY-MM-DD)
 * @param status Filter by settlement status (all|active|cancelled, default: all)
 * @param sortKey Field to sort by (created_at|created_by, default: created_at)
 * @param sortOrder Sort direction (asc|desc, default: desc)
 * @returns Paginated settlements response
 */
export async function getSettlements(
  page: number = 1,
  perPage: number = 20,
  type?: 'sepa' | 'manual',
  dateFrom?: string,
  dateTo?: string,
  status: 'all' | 'active' | 'cancelled' = 'all',
  sortKey: 'created_at' | 'created_by' = 'created_at',
  sortOrder: 'asc' | 'desc' = 'desc'
): Promise<SettlementsResponse> {
  const params: Record<string, any> = {
    page,
    per_page: perPage,
  }

  if (type) {
    params.type = type
  }
  if (dateFrom) {
    params.date_from = dateFrom
  }
  if (dateTo) {
    params.date_to = dateTo
  }
  if (status !== 'all') {
    params.status = status
  }
  params.sort = sortKey
  params.order = sortOrder

  const apiResponse = await get<any>('/admin/settlements', {
    params,
  })

  // Handle both wrapped and unwrapped responses
  const response = apiResponse as unknown as (SettlementsResponse & { items?: Settlement[]; total?: number })
  if (response && typeof response === 'object') {
    // Format: { data: [...], pagination: { ... } }
    if ('data' in response && Array.isArray(response.data)) {
      return response as SettlementsResponse
    }
    // Format: { items: [...], total: N, limit: N, offset: N }
    if ('items' in response && Array.isArray(response.items)) {
      return {
        data: response.items as Settlement[],
        pagination: {
          total: response.total ?? 0,
          per_page: perPage,
          current_page: page,
          last_page: Math.ceil((response.total ?? 0) / perPage),
        },
      }
    }
  }

  return {
    data: [],
    pagination: {
      total: 0,
      per_page: perPage,
      current_page: page,
      last_page: 0,
    },
  }
}

/**
 * Derive settlement status from fields
 */
export function getSettlementStatus(settlement: Settlement): 'active' | 'cancelled' | 'exported' {
  if (settlement.is_cancelled) return 'cancelled'
  if (settlement.exported_at !== null) return 'exported'
  return 'active'
}

/**
 * Format price in EUR with 2 decimals
 */
export function formatPrice(cents: number): string {
  return `€${(cents / 100).toFixed(2)}`
}

/**
 * Format date from ISO string with time
 */
export function formatDate(dateStr: string): string {
  const date = new Date(dateStr)
  return date.toLocaleDateString('de-DE', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })
}

/**
 * Create a settlement from selected transactions (UC-A30)
 *
 * @param transactionIds Array of transaction UUIDs to include in settlement
 * @param settlementDate Date when settlement was created (ISO format: YYYY-MM-DD)
 * @param executionDate Date when payment will be executed (ISO format: YYYY-MM-DD)
 * @param notes Optional admin notes
 * @returns Created settlement
 */
export async function createSettlement(
  transactionIds: string[],
  settlementDate: string,
  executionDate: string,
  notes?: string
): Promise<Settlement> {
  const payload = {
    transaction_ids: transactionIds,
    settlement_date: settlementDate,
    execution_date: executionDate,
    settlement_type: 'sepa',
    notes,
  }

  const apiResponse = await post<Settlement>('/admin/settlements', payload)

  // Handle both wrapped and unwrapped responses
  // Cast to any to handle both response formats at runtime
  const response = apiResponse as any
  if (response && typeof response === 'object') {
    if ('id' in response && 'settlement_date' in response) {
      return response as Settlement
    }
    if ('data' in response && response.data) {
      return response.data as Settlement
    }
  }

  throw new Error('Invalid response from settlement creation API')
}

export interface SettlementFilterPreview {
  transaction_count: number
  member_count: number
  total_amount_cents: number
}

/**
 * Get aggregate preview stats for all unsettled transactions matching the given filters.
 * Used to populate the "Abrechnung (alle)" confirm modal without fetching transaction IDs.
 */
export async function getSettlementFilterPreview(
  dateFrom?: string,
  dateTo?: string,
  search?: string,
  memberId?: string,
): Promise<SettlementFilterPreview> {
  const params: Record<string, string> = {}
  if (dateFrom) params.date_from = dateFrom
  if (dateTo)   params.date_to   = dateTo
  if (search)   params.search    = search
  if (memberId) params.member_id = memberId

  const apiResponse = await get<SettlementFilterPreview>('/admin/settlements/filter-preview', { params })
  return apiResponse as unknown as SettlementFilterPreview
}

/**
 * Create a settlement for all unsettled transactions matching the given filters.
 * The backend resolves matching transaction IDs server-side.
 */
export async function createSettlementByFilters(
  settlementDate: string,
  executionDate: string,
  dateFrom?: string,
  dateTo?: string,
  search?: string,
  memberId?: string,
  notes?: string,
): Promise<Settlement> {
  const payload: Record<string, string | undefined> = {
    settlement_date: settlementDate,
    execution_date: executionDate,
  }
  if (dateFrom) payload.date_from = dateFrom
  if (dateTo)   payload.date_to   = dateTo
  if (search)   payload.search    = search
  if (memberId) payload.member_id = memberId
  if (notes)    payload.notes     = notes

  const apiResponse = await post<Settlement>('/admin/settlements/settle-filter', payload)
  const response = apiResponse as any
  if (response && typeof response === 'object') {
    if ('id' in response && 'settlement_date' in response) return response as Settlement
    if ('data' in response && response.data) return response.data as Settlement
  }
  throw new Error('Invalid response from settle-filter API')
}

/**
 * Undo a settlement (cancel and unmark transactions) (UC-A33)
 *
 * @param settlementId Settlement UUID to undo
 */
export async function undoSettlement(settlementId: string): Promise<void> {
  await del(`/admin/settlements/${settlementId}`)
}

/**
 * Export detailed transactions CSV for a settlement
 *
 * Downloads CSV with one row per transaction showing member details and amounts.
 * Useful for detailed transaction reconciliation and accounting.
 *
 * @param settlementId Settlement UUID
 */
export async function downloadTransactionsCsv(settlementId: string): Promise<void> {
  await downloadFile(`/admin/settlements/${settlementId}/export-transactions`, `transactions-${settlementId}.csv`)
}
