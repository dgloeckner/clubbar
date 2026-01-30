/**
 * Audit Log API Service
 * Handles fetching audit log entries from backend
 */

import { get } from './api'

export interface AuditLogEntry {
  id: number
  admin_user_id: string | null
  admin_user_email: string | null
  action: string
  entity_type: string
  entity_id: string | null
  old_values: Record<string, any> | null
  new_values: Record<string, any> | null
  ip_address: string | null
  user_agent: string | null
  created_at: string
}

export interface AuditLogListResponse {
  items: AuditLogEntry[]
  total: number
  page: number
  per_page: number
}

export interface AuditLogFilters {
  page?: number
  per_page?: number
  date_from?: string // ISO date: YYYY-MM-DD
  date_to?: string   // ISO date: YYYY-MM-DD
  admin_user_id?: string // UUID
  action?: string
  entity_type?: string
  search?: string
}

/**
 * Get paginated audit log entries with filtering and sorting
 * @param filters - Query filters for the audit log
 * @returns Promise with paginated audit log entries
 */
export async function getAuditLogs(filters: AuditLogFilters = {}): Promise<AuditLogListResponse> {
  const params = new URLSearchParams()

  if (filters.page) params.append('page', filters.page.toString())
  if (filters.per_page) params.append('per_page', filters.per_page.toString())
  if (filters.date_from) params.append('date_from', filters.date_from)
  if (filters.date_to) params.append('date_to', filters.date_to)
  if (filters.admin_user_id) params.append('admin_user_id', filters.admin_user_id)
  if (filters.action) params.append('action', filters.action)
  if (filters.entity_type) params.append('entity_type', filters.entity_type)
  if (filters.search) params.append('search', filters.search)

  const queryString = params.toString()
  const url = queryString ? `/admin/audit-log?${queryString}` : '/admin/audit-log'

  const response = await get<AuditLogListResponse>(url)

  if (response && typeof response === 'object') {
    if ('items' in response && Array.isArray(response.items)) {
      return {
        items: response.items as AuditLogEntry[],
        total: (response as any).total || 0,
        page: (response as any).page || 1,
        per_page: (response as any).per_page || 50,
      }
    }
    if ('data' in response && response.data) {
      return (response.data as any) as AuditLogListResponse
    }
  }

  throw new Error('Invalid response from audit log API')
}

/**
 * Get list of available actions for filtering
 * (These should come from backend schema or be hardcoded based on ADR-0013)
 */
export function getAvailableActions(): string[] {
  return [
    'create',
    'update',
    'delete',
    'anonymize',
    'login',
    'logout',
    'login_failed',
    'export',
    'settlement_create',
    'settlement_cancel',
    'settlement_export',
  ]
}

/**
 * Get list of available entity types for filtering
 */
export function getAvailableEntityTypes(): string[] {
  return [
    'member',
    'product',
    'admin_user',
    'terminal',
    'settlement',
    'sepa_config',
    'category',
  ]
}
