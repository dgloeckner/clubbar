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
 *
 * Backend response format:
 * { metadata: { report_type, generated_at, filters }, summary: { total_revenue_cents, ... }, data: [...rows] }
 *
 * Maps to the ReportResponse shape expected by the UI.
 */
export async function getReport(reportType: ReportType, params: ReportParams = {}): Promise<ReportResponse> {
  const query = buildQuery(params as Record<string, string | number | boolean | undefined>)
  const apiResponse = await get<any>(`/admin/reports/${reportType}${query}`)

  const raw = apiResponse as any

  // Backend returns { metadata, summary, data } — map to ReportResponse shape
  if (raw && 'summary' in raw && 'data' in raw) {
    const mapped: ReportResponse = {
      metadata: {
        total_revenue_cents: raw.summary?.total_revenue_cents ?? 0,
        total_quantity: raw.summary?.total_quantity ?? 0,
        total_count: raw.summary?.transaction_count ?? 0,
        avg_transaction_cents: raw.summary?.avg_transaction_cents ?? 0,
        date_from: raw.metadata?.filters?.date_from ?? params.date_from ?? '',
        date_to: raw.metadata?.filters?.date_to ?? params.date_to ?? '',
        group_by: raw.metadata?.filters?.group_by ?? params.group_by ?? 'month',
        report_type: reportType,
      },
      rows: (raw.data ?? []).map((row: any) => ({
        dimension: row.dimension ?? '',
        revenue_cents: row.revenue_cents ?? 0,
        quantity: row.quantity ?? 0,
        count: row.count ?? 0,
        percentage: row.percent_of_total ?? row.percentage ?? 0,
      })),
    }
    return mapped
  }

  // Fallback: already in correct shape
  return raw as ReportResponse
}

/**
 * Get member spending ranking (UC-A51)
 *
 * Backend response format: { data: [...rows] }
 * Maps to the MemberRankingResponse shape expected by the UI.
 */
export async function getMemberRanking(params: MemberRankingParams = {}): Promise<MemberRankingResponse> {
  const query = buildQuery(params as Record<string, string | number | boolean | undefined>)
  const apiResponse = await get<any>(`/admin/reports/member-ranking${query}`)

  const raw = apiResponse as any

  // Backend returns { data: [...rows] } — map to MemberRankingResponse shape
  if (raw && 'data' in raw && Array.isArray(raw.data)) {
    const mapped: MemberRankingResponse = {
      rows: raw.data.map((row: any) => ({
        rank: row.rank ?? 0,
        member_id: row.member_id ?? null,
        member_name: row.member_name ?? '',
        total_amount_cents: row.total_amount_cents ?? 0,
        transaction_count: row.transaction_count ?? 0,
      })),
      metadata: raw.metadata ?? {
        date_from: params.date_from ?? '',
        date_to: params.date_to ?? '',
        limit: params.limit ?? 25,
        anonymized: params.anonymize ?? false,
        total_members: raw.data.length,
      },
    }
    return mapped
  }

  // Fallback: already correct shape (has rows key)
  if (raw && 'rows' in raw) return raw as MemberRankingResponse

  return raw as MemberRankingResponse
}

/**
 * Get terminal activity report (UC-A52)
 *
 * Backend response format: { sessions, hourly_distribution, terminals }
 * Maps to the TerminalActivityResponse shape expected by the UI.
 */
export async function getTerminalActivity(params: TerminalActivityParams): Promise<TerminalActivityResponse> {
  const query = buildQuery(params as unknown as Record<string, string | number | boolean | undefined>)
  const apiResponse = await get<any>(`/admin/reports/terminal-activity${query}`)

  const raw = apiResponse as any

  // Backend returns { sessions, hourly_distribution, terminals } — map to TerminalActivityResponse
  if (raw && 'sessions' in raw) {
    const mapped: TerminalActivityResponse = {
      sessions: (raw.sessions ?? []).map((s: any) => ({
        date: s.date ?? '',
        start_time: s.start_time ?? null,
        end_time: s.end_time ?? null,
        transaction_count: s.transaction_count ?? 0,
        revenue_cents: s.revenue_cents ?? 0,
      })),
      hourly_distribution: (raw.hourly_distribution ?? []).map((b: any) => ({
        hour: b.hour ?? 0,
        transaction_count: b.transaction_count ?? 0,
        revenue_cents: b.revenue_cents ?? 0,
      })),
      terminal_summaries: (raw.terminals ?? raw.terminal_summaries ?? []).map((t: any) => ({
        terminal_id: t.id ?? t.terminal_id ?? '',
        terminal_name: t.name ?? t.terminal_name ?? '',
        transaction_count: t.transaction_count ?? 0,
        revenue_cents: t.revenue_cents ?? 0,
        last_sync: t.last_sync_at ?? t.last_sync ?? null,
      })),
      metadata: raw.metadata ?? {
        date_from: params.date_from,
        date_to: params.date_to,
        total_transactions: (raw.sessions ?? []).reduce((sum: number, s: any) => sum + (s.transaction_count ?? 0), 0),
        total_revenue_cents: (raw.sessions ?? []).reduce((sum: number, s: any) => sum + (s.revenue_cents ?? 0), 0),
      },
    }
    return mapped
  }

  return raw as TerminalActivityResponse
}

/**
 * Export a report as CSV download
 */
export async function exportReport(reportType: ReportType | 'member-ranking' | 'terminal-activity', params: Record<string, string | number | boolean | undefined> = {}): Promise<void> {
  const query = buildQuery({ ...params, format: 'csv' })
  await downloadFile(`/admin/reports/${reportType}${query}`, `report-${reportType}-${new Date().toISOString().slice(0, 10)}.csv`)
}
