/**
 * Reports API Service
 * Handles report data retrieval and CSV export
 * Implements UC-A50 (Revenue/Consumption/Transactions), UC-A51 (Member Ranking), UC-A52 (Terminal Activity)
 */

import { get, downloadFile } from './api'

// ─── Types ───────────────────────────────────────────────────────────────────

export type ReportType = 'revenue' | 'consumption' | 'transactions'
export type GroupBy = 'category' | 'product' | 'member' | 'day' | 'week' | 'month' | 'year'

export interface ReportParams {
  date_from?: string
  date_to?: string
  group_by?: GroupBy
}

export interface ReportRow {
  dimension: string
  revenue_cents: number
  quantity: number
  count: number
  percentage: number
}

export interface ReportMetadata {
  total_revenue_cents: number
  total_quantity: number
  total_count: number
  avg_transaction_cents: number
  date_from: string
  date_to: string
  group_by: GroupBy
  report_type: ReportType
}

export interface ReportResponse {
  metadata: ReportMetadata
  rows: ReportRow[]
}

// ─── Member Ranking ───────────────────────────────────────────────────────────

export interface MemberRankingParams {
  date_from?: string
  date_to?: string
  limit?: number
  anonymize?: boolean
}

export interface MemberRankingRow {
  rank: number
  member_id: string | null
  member_name: string
  total_amount_cents: number
  transaction_count: number
}

export interface MemberRankingResponse {
  rows: MemberRankingRow[]
  metadata: {
    date_from: string
    date_to: string
    limit: number
    anonymized: boolean
    total_members: number
  }
}

// ─── Terminal Activity ────────────────────────────────────────────────────────

export interface TerminalActivityParams {
  date_from: string
  date_to: string
}

export interface TerminalSession {
  date: string
  start_time: string | null
  end_time: string | null
  transaction_count: number
  revenue_cents: number
}

export interface HourlyBucket {
  hour: number
  transaction_count: number
  revenue_cents: number
}

export interface TerminalSummary {
  terminal_id: string
  terminal_name: string
  transaction_count: number
  revenue_cents: number
  last_sync: string | null
}

export interface TerminalActivityResponse {
  sessions: TerminalSession[]
  hourly_distribution: HourlyBucket[]
  terminal_summaries: TerminalSummary[]
  metadata: {
    date_from: string
    date_to: string
    total_transactions: number
    total_revenue_cents: number
  }
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function buildQuery(params: Record<string, string | number | boolean | undefined>): string {
  const parts: string[] = []
  for (const [key, value] of Object.entries(params)) {
    if (value !== undefined && value !== null && value !== '') {
      parts.push(`${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`)
    }
  }
  return parts.length > 0 ? `?${parts.join('&')}` : ''
}

// ─── API Methods ──────────────────────────────────────────────────────────────

/**
 * Get a report by type (revenue, consumption, transactions)
 */
export async function getReport(reportType: ReportType, params: ReportParams = {}): Promise<ReportResponse> {
  const query = buildQuery(params as Record<string, string | number | boolean | undefined>)
  const apiResponse = await get<ReportResponse>(`/admin/reports/${reportType}${query}`)

  // Handle both wrapped and unwrapped response formats
  const data = apiResponse as any
  if (data && 'metadata' in data) return data as ReportResponse
  if (data && 'data' in data && data.data?.metadata) return data.data as ReportResponse
  return data as ReportResponse
}

/**
 * Get member spending ranking (UC-A51)
 */
export async function getMemberRanking(params: MemberRankingParams = {}): Promise<MemberRankingResponse> {
  const query = buildQuery(params as Record<string, string | number | boolean | undefined>)
  const apiResponse = await get<MemberRankingResponse>(`/admin/reports/member-ranking${query}`)

  const data = apiResponse as any
  if (data && 'rows' in data) return data as MemberRankingResponse
  if (data && 'data' in data && data.data?.rows) return data.data as MemberRankingResponse
  return data as MemberRankingResponse
}

/**
 * Get terminal activity report (UC-A52)
 */
export async function getTerminalActivity(params: TerminalActivityParams): Promise<TerminalActivityResponse> {
  const query = buildQuery(params as unknown as Record<string, string | number | boolean | undefined>)
  const apiResponse = await get<TerminalActivityResponse>(`/admin/reports/terminal-activity${query}`)

  const data = apiResponse as any
  if (data && 'sessions' in data) return data as TerminalActivityResponse
  if (data && 'data' in data && data.data?.sessions) return data.data as TerminalActivityResponse
  return data as TerminalActivityResponse
}

/**
 * Export a report as CSV download
 */
export async function exportReport(reportType: ReportType | 'member-ranking' | 'terminal-activity', params: Record<string, string | number | boolean | undefined> = {}): Promise<void> {
  const query = buildQuery({ ...params, format: 'csv' })
  await downloadFile(`/admin/reports/${reportType}${query}`, `report-${reportType}-${new Date().toISOString().slice(0, 10)}.csv`)
}
