/**
 * Dashboard API Service
 * Handles dashboard metrics and system status
 */

import { get } from './api'

export interface MetricsDto {
  active_members: number
  inactive_members: number
  outstanding_balance_cents: number
  todays_revenue_cents: number
  terminal_count: number
  active_terminals: number
  settled_members: number
  sepa_issue_count: number
}

export interface SystemStatusDto {
  last_settlement_date: string | null
  pending_settlement_count: number
  total_members: number
  total_transactions: number
  database_health: string
}

export interface DashboardResponse {
  metrics: MetricsDto
  recent_transactions: Array<any>
  terminal_status: Array<any>
  system_status: SystemStatusDto
  alerts: any
}

/**
 * Get aggregated dashboard metrics
 */
export async function getDashboardMetrics(): Promise<DashboardResponse> {
  const response = await get<DashboardResponse>('/admin/dashboard')

  // Backend returns data directly
  if (response && typeof response === 'object') {
    if ('metrics' in response && response.metrics) {
      return response as DashboardResponse
    }
    if ('data' in response && response.data) {
      return response.data as DashboardResponse
    }
  }

  throw new Error('Invalid response from dashboard API')
}
