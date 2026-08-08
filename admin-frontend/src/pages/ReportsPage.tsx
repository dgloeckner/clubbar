/**
 * Reports Page
 * Advanced reporting dashboard for revenue, consumption, transactions,
 * member ranking, and terminal activity.
 * Implements UC-A50, UC-A51, UC-A52
 */

import { useEffect, useState, useCallback, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import {
  BarChart,
  Bar,
  XAxis,
  YAxis,
  Tooltip,
  ResponsiveContainer,
  CartesianGrid,
} from 'recharts'
import { getReports } from '../api/generated/reports/reports'
import type { GetReportGroupBy } from '../api/generated'
import { downloadFile } from '../api/client'
import { toIsoDate } from '../utils/dates'

// ─── Local Types (UI-facing, mapped from generated API types) ─────────────────

type ReportType = 'revenue' | 'consumption' | 'transactions'
type GroupBy = GetReportGroupBy

interface ReportParams {
  date_from?: string
  date_to?: string
  group_by?: GroupBy
}

interface ReportRow {
  dimension: string
  revenue_cents: number
  count: number
  percentage: number
}

interface ReportMetadata {
  total_revenue_cents: number
  unique_member_count: number
  total_count: number
  avg_transaction_cents: number
  date_from: string
  date_to: string
  group_by: GroupBy
  report_type: ReportType
}

interface ReportResponse {
  metadata: ReportMetadata
  rows: ReportRow[]
}

interface MemberRankingParams {
  date_from?: string
  date_to?: string
  limit?: number
  anonymize?: boolean
}

interface MemberRankingRow {
  rank: number
  member_name: string
  total_amount_cents: number
  transaction_count: number
}

interface MemberRankingResponse {
  rows: MemberRankingRow[]
  metadata: {
    date_from: string
    date_to: string
    limit: number
    anonymized: boolean
    total_members: number
  }
}

interface TerminalActivityParams {
  date_from: string
  date_to: string
}

interface TerminalSession {
  date: string
  start_time: string | null
  end_time: string | null
  transaction_count: number
  revenue_cents: number
}

interface HourlyBucket {
  hour: number
  transaction_count: number
}

interface TerminalSummary {
  terminal_id: string
  terminal_name: string
  transaction_count: number
  // Not computed by the backend's terminal-activity aggregation — always 0.
  revenue_cents: number
  last_sync: string | null
}

interface TerminalActivityResponse {
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

// ─── API Wrappers (map generated client responses to local types) ─────────────

async function getReport(reportType: ReportType, params: ReportParams = {}, signal?: AbortSignal): Promise<ReportResponse> {
  const raw = await getReports().getReport(reportType, params, { signal })
  return {
    metadata: {
      total_revenue_cents: raw.summary?.total_revenue_cents ?? 0,
      unique_member_count: raw.summary?.unique_member_count ?? 0,
      total_count: raw.summary?.transaction_count ?? 0,
      avg_transaction_cents: raw.summary?.avg_transaction_cents ?? 0,
      date_from: raw.metadata?.filters?.date_from ?? params.date_from ?? '',
      date_to: raw.metadata?.filters?.date_to ?? params.date_to ?? '',
      group_by: raw.metadata?.filters?.group_by ?? params.group_by ?? 'month',
      report_type: reportType,
    },
    rows: (raw.data ?? []).map((row) => ({
      dimension: row.dimension ?? '',
      revenue_cents: row.revenue_cents ?? 0,
      count: row.count ?? 0,
      percentage: row.percent_of_total ?? 0,
    })),
  }
}

async function getMemberRanking(params: MemberRankingParams = {}, signal?: AbortSignal): Promise<MemberRankingResponse> {
  const raw = await getReports().getMemberRanking(params, { signal })
  const rows = raw.data ?? []
  return {
    rows: rows.map((row) => ({
      rank: row.rank ?? 0,
      member_name: row.member_name ?? '',
      total_amount_cents: row.total_amount_cents ?? 0,
      transaction_count: row.transaction_count ?? 0,
    })),
    // The backend doesn't echo metadata for this endpoint — derive it from the request.
    metadata: {
      date_from: params.date_from ?? '',
      date_to: params.date_to ?? '',
      limit: params.limit ?? 25,
      anonymized: params.anonymize ?? false,
      total_members: rows.length,
    },
  }
}

async function getTerminalActivity(params: TerminalActivityParams, signal?: AbortSignal): Promise<TerminalActivityResponse> {
  const raw = await getReports().getTerminalActivity(params, { signal })
  const sessions = raw.sessions ?? []
  return {
    sessions: sessions.map((s) => ({
      date: s.date ?? '',
      start_time: s.start_time ?? null,
      end_time: s.end_time ?? null,
      transaction_count: s.transaction_count ?? 0,
      revenue_cents: s.revenue_cents ?? 0,
    })),
    hourly_distribution: (raw.hourly_distribution ?? []).map((b) => ({
      hour: b.hour ?? 0,
      transaction_count: b.transaction_count ?? 0,
    })),
    terminal_summaries: (raw.terminals ?? []).map((t) => ({
      terminal_id: t.id ?? '',
      terminal_name: t.name ?? '',
      transaction_count: t.transaction_count ?? 0,
      // Not computed by the backend's terminal-activity aggregation — always 0.
      revenue_cents: 0,
      last_sync: t.last_sync_at ?? null,
    })),
    // The backend doesn't echo metadata for this endpoint — derive it from the sessions.
    metadata: {
      date_from: params.date_from,
      date_to: params.date_to,
      total_transactions: sessions.reduce((sum, s) => sum + (s.transaction_count ?? 0), 0),
      total_revenue_cents: sessions.reduce((sum, s) => sum + (s.revenue_cents ?? 0), 0),
    },
  }
}

type ExportableReport = ReportType | 'member-ranking' | 'terminal-activity'

/**
 * Every report has a `/export` sibling that answers with CSV; the list endpoints
 * only ever answer with JSON. Going through downloadFile keeps session auth, the
 * global loading indicator and the 401 redirect intact, honours the filename the
 * backend puts in Content-Disposition, and — unlike a bare anchor click — rejects
 * on failure so the caller can tell the user.
 */
async function exportReport(
  reportType: ExportableReport,
  params: Record<string, string | number | boolean | undefined> = {}
): Promise<void> {
  const query = Object.entries(params)
    .filter(([, v]) => v !== undefined && v !== '')
    .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(String(v))}`)
    .join('&')
  // The fallback name only applies if the backend omits Content-Disposition;
  // it still stamps the admin's calendar day, not Greenwich's (#95).
  await downloadFile(
    `/admin/reports/${reportType}/export${query ? `?${query}` : ''}`,
    `report-${reportType}-${toIsoDate(new Date())}.csv`
  )
}
import { theme } from '../styles/design-system'
import { useFormatters } from '../hooks/useFormatters'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { useLatestRequest } from '../hooks/useLatestRequest'
import {
  tableElementStyles,
  headerRowStyle,
  headerCellBaseStyle,
  tableColors,
  tableSpacing,
} from '../styles/tableTokens'
import { Toggle } from '../components/forms/Toggle'

// ─── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Seed range for the report filters: the last 30 local calendar days.
 *
 * Built with `toIsoDate`, not `toISOString()` — the latter converts to UTC
 * first, so a German admin opening this page before 02:00 local would get
 * `date_to = yesterday` and silently lose today's transactions (#95).
 */
function defaultDateRange(): { date_from: string; date_to: string } {
  const to = new Date()
  const from = new Date()
  from.setDate(from.getDate() - 30)
  return {
    date_from: toIsoDate(from),
    date_to: toIsoDate(to),
  }
}

// ─── Sub-components ───────────────────────────────────────────────────────────

interface SummaryCardProps {
  label: string
  value: string
  color: string
  testId: string
}

interface SummaryCardMobileProps extends SummaryCardProps {
  isMobile?: boolean
}

function SummaryCard({ label, value, color, testId, isMobile }: SummaryCardMobileProps) {
  return (
    <div
      data-testid={testId}
      style={{
        background: 'linear-gradient(135deg, #1e3a5f 0%, #0d1829 100%)',
        border: `1px solid ${theme.colors.border.light}`,
        borderRadius: theme.borderRadius.xl,
        padding: isMobile ? theme.spacing.md : theme.spacing.xl,
        textAlign: 'center',
      }}
    >
      <div
        style={{
          color: theme.colors.text.secondary,
          fontSize: isMobile ? theme.typography.fontSize.xs : theme.typography.fontSize.sm,
          marginBottom: theme.spacing.sm,
        }}
      >
        {label}
      </div>
      <div
        style={{
          fontSize: isMobile ? '20px' : '28px',
          fontWeight: 700,
          fontFamily: 'JetBrains Mono, monospace',
          color,
          textShadow: `0 2px 10px ${color}44`,
        }}
      >
        {value}
      </div>
    </div>
  )
}

// ─── LimitSelect (custom dropdown matching design system) ─────────────────────

interface FilterSelectOption {
  value: string
  label: string
}

interface FilterSelectProps {
  value: string
  onChange: (value: string) => void
  options: FilterSelectOption[]
  testId: string
  label: string
  minWidth?: number
}

function FilterSelect({ value, onChange, options, testId, label, minWidth = 80 }: FilterSelectProps) {
  const [isOpen, setIsOpen] = useState(false)
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setIsOpen(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  const selectedLabel = options.find((o) => o.value === value)?.label ?? value

  return (
    <div style={{ position: 'relative' }} ref={ref}>
      <label
        style={{
          fontSize: theme.typography.fontSize.xs,
          color: theme.colors.text.secondary,
          marginBottom: '4px',
          display: 'block',
        }}
      >
        {label}
      </label>
      <button
        data-testid={`${testId}-trigger`}
        onClick={() => setIsOpen(!isOpen)}
        type="button"
        style={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          gap: 8,
          padding: `${theme.spacing.sm} ${theme.spacing.md}`,
          minWidth,
          background: theme.colors.bg.secondary,
          border: `1px solid ${isOpen ? 'rgba(59,130,246,0.5)' : theme.colors.border.light}`,
          borderRadius: theme.borderRadius.md,
          color: theme.colors.text.primary,
          fontSize: theme.typography.fontSize.sm,
          cursor: 'pointer',
          transition: 'all 0.15s',
          fontFamily: 'inherit',
        }}
      >
        <span>{selectedLabel}</span>
        <svg
          width="14"
          height="14"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="2"
          strokeLinecap="round"
          strokeLinejoin="round"
          style={{
            color: theme.colors.text.muted,
            transform: isOpen ? 'rotate(180deg)' : '',
            transition: 'transform 0.2s',
            flexShrink: 0,
          }}
        >
          <polyline points="6 9 12 15 18 9" />
        </svg>
      </button>

      {isOpen && (
        <div
          data-testid={`${testId}-dropdown`}
          style={{
            position: 'absolute',
            top: '100%',
            left: 0,
            marginTop: 4,
            background: theme.colors.bg.card,
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.md,
            boxShadow: '0 10px 40px rgba(0,0,0,0.4)',
            zIndex: 1000,
            minWidth: '100%',
          }}
        >
          <div style={{ padding: 4 }}>
            {options.map((opt) => (
              <button
                key={opt.value}
                data-testid={`${testId}-option-${opt.value}`}
                onClick={() => {
                  onChange(opt.value)
                  setIsOpen(false)
                }}
                type="button"
                style={{
                  width: '100%',
                  display: 'flex',
                  alignItems: 'center',
                  gap: 8,
                  padding: '8px 12px',
                  background: value === opt.value ? 'rgba(59,130,246,0.15)' : 'transparent',
                  border: 'none',
                  borderRadius: 8,
                  color: value === opt.value ? theme.colors.semantic.primary : theme.colors.text.primary,
                  fontSize: theme.typography.fontSize.sm,
                  cursor: 'pointer',
                  textAlign: 'left',
                  transition: 'all 0.15s',
                  fontFamily: 'inherit',
                  whiteSpace: 'nowrap',
                }}
              >
                <span
                  style={{
                    color: value === opt.value ? theme.colors.semantic.primary : theme.colors.text.muted,
                    fontWeight: 500,
                    fontSize: 8,
                  }}
                >
                  ●
                </span>
                <span>{opt.label}</span>
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

// ─── Main Component ───────────────────────────────────────────────────────────

type TabId = 'revenue' | 'consumption' | 'member-ranking' | 'terminal-activity'

export function ReportsPage() {
  const { t, i18n } = useTranslation()
  const formatters = useFormatters()
  const breakpoint = useBreakpoint()
  const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'

  // One per tab: each tab owns its own in-flight request, so switching tabs or
  // re-applying a filter can only ever discard the tab's own stale answer.
  const reportRequest = useLatestRequest()
  const rankingRequest = useLatestRequest()
  const terminalRequest = useLatestRequest()

  const [activeTab, setActiveTab] = useState<TabId>('revenue')

  // ── Date range shared across most tabs ──
  const defaultRange = defaultDateRange()
  const [dateFrom, setDateFrom] = useState(defaultRange.date_from)
  const [dateTo, setDateTo] = useState(defaultRange.date_to)

  // ── Standard report (tabs 1-3) state ──
  const [groupBy, setGroupBy] = useState<GroupBy>('month')
  const [reportData, setReportData] = useState<ReportResponse | null>(null)
  const [reportLoading, setReportLoading] = useState(false)
  const [reportError, setReportError] = useState<string | null>(null)

  // ── Member ranking (tab 4) state ──
  const [rankingAnonymize, setRankingAnonymize] = useState(false)
  const [rankingLimit, setRankingLimit] = useState<number>(25)
  const [rankingData, setRankingData] = useState<MemberRankingResponse | null>(null)
  const [rankingLoading, setRankingLoading] = useState(false)
  const [rankingError, setRankingError] = useState<string | null>(null)

  // ── Terminal activity (tab 5) state ──
  const [terminalData, setTerminalData] = useState<TerminalActivityResponse | null>(null)
  const [terminalLoading, setTerminalLoading] = useState(false)
  const [terminalError, setTerminalError] = useState<string | null>(null)

  // ── CSV export state (shared by every tab's export button) ──
  const [exportError, setExportError] = useState<string | null>(null)

  // ─── Load data ───────────────────────────────────────────────────────────

  // Switching revenue -> consumption fires a second getReport; without the
  // signal a slow revenue response would overwrite the consumption tab.
  const loadReport = useCallback(async () => {
    if (activeTab !== 'revenue' && activeTab !== 'consumption') return
    const signal = reportRequest.next()
    setReportLoading(true)
    setReportError(null)
    try {
      const params: ReportParams = { date_from: dateFrom, date_to: dateTo, group_by: groupBy }
      const data = await getReport(activeTab as ReportType, params, signal)
      if (signal.aborted) return
      setReportData(data)
    } catch (err) {
      if (signal.aborted) return
      setReportError(err instanceof Error ? err.message : t('errors.generic'))
    } finally {
      if (!signal.aborted) setReportLoading(false)
    }
  }, [activeTab, dateFrom, dateTo, groupBy, reportRequest, t])

  const loadRanking = useCallback(async () => {
    const signal = rankingRequest.next()
    setRankingLoading(true)
    setRankingError(null)
    try {
      const params: MemberRankingParams = {
        date_from: dateFrom,
        date_to: dateTo,
        limit: rankingLimit,
        anonymize: rankingAnonymize,
      }
      const data = await getMemberRanking(params, signal)
      if (signal.aborted) return
      setRankingData(data)
    } catch (err) {
      if (signal.aborted) return
      setRankingError(err instanceof Error ? err.message : t('errors.generic'))
    } finally {
      if (!signal.aborted) setRankingLoading(false)
    }
  }, [dateFrom, dateTo, rankingLimit, rankingAnonymize, rankingRequest, t])

  const loadTerminalActivity = useCallback(async () => {
    const signal = terminalRequest.next()
    setTerminalLoading(true)
    setTerminalError(null)
    try {
      const params: TerminalActivityParams = { date_from: dateFrom, date_to: dateTo }
      const data = await getTerminalActivity(params, signal)
      if (signal.aborted) return
      setTerminalData(data)
    } catch (err) {
      if (signal.aborted) return
      setTerminalError(err instanceof Error ? err.message : t('errors.generic'))
    } finally {
      if (!signal.aborted) setTerminalLoading(false)
    }
  }, [dateFrom, dateTo, terminalRequest, t])

  // Load data when tab changes. Leaving a tab abandons its request, so a slow
  // answer cannot land on the tab the admin switched to.
  useEffect(() => {
    setExportError(null)
    if (activeTab === 'revenue' || activeTab === 'consumption') {
      loadReport()
      return () => reportRequest.abort()
    }
    if (activeTab === 'member-ranking') {
      loadRanking()
      return () => rankingRequest.abort()
    }
    if (activeTab === 'terminal-activity') {
      loadTerminalActivity()
      return () => terminalRequest.abort()
    }
  }, [activeTab]) // eslint-disable-line react-hooks/exhaustive-deps

  // ─── Event handlers ───────────────────────────────────────────────────────

  const handleApplyFilter = () => {
    if (activeTab === 'revenue' || activeTab === 'consumption') {
      loadReport()
    } else if (activeTab === 'member-ranking') {
      loadRanking()
    } else if (activeTab === 'terminal-activity') {
      loadTerminalActivity()
    }
  }

  const handleExportCsv = async () => {
    setExportError(null)
    const params: Record<string, string | number | boolean | undefined> = {
      date_from: dateFrom,
      date_to: dateTo,
    }
    if (activeTab === 'revenue' || activeTab === 'consumption') {
      params.group_by = groupBy
    } else if (activeTab === 'member-ranking') {
      params.limit = rankingLimit
      params.anonymize = rankingAnonymize
    }
    try {
      await exportReport(activeTab, params)
    } catch (err) {
      setExportError(err instanceof Error ? err.message : t('errors.generic'))
    }
  }

  // ─── Tab styles ───────────────────────────────────────────────────────────

  const tabStyle = (id: TabId): React.CSSProperties => ({
    padding: `${theme.spacing.sm} ${isMobile ? theme.spacing.sm : theme.spacing.lg}`,
    border: 'none',
    borderBottom: activeTab === id ? `2px solid ${theme.colors.semantic.primary}` : '2px solid transparent',
    background: 'transparent',
    color: activeTab === id ? theme.colors.semantic.primary : theme.colors.text.secondary,
    fontWeight: activeTab === id ? 600 : 400,
    fontSize: isMobile ? theme.typography.fontSize.xs : theme.typography.fontSize.sm,
    cursor: 'pointer',
    whiteSpace: 'nowrap',
    transition: `all ${theme.transitions.default}`,
  })

  const inputStyle: React.CSSProperties = {
    padding: `${theme.spacing.sm} ${theme.spacing.md}`,
    background: theme.colors.bg.secondary,
    border: `1px solid ${theme.colors.border.light}`,
    borderRadius: theme.borderRadius.md,
    color: theme.colors.text.primary,
    fontSize: theme.typography.fontSize.sm,
  }

  const labelStyle: React.CSSProperties = {
    fontSize: theme.typography.fontSize.xs,
    color: theme.colors.text.secondary,
    marginBottom: '4px',
    display: 'block',
  }

  const filterGroupStyle: React.CSSProperties = {
    display: 'flex',
    flexDirection: isMobile ? 'column' : 'row',
    flexWrap: isMobile ? undefined : 'wrap',
    gap: theme.spacing.md,
    alignItems: isMobile ? 'stretch' : 'flex-end',
    marginBottom: theme.spacing.xl,
    padding: isMobile ? theme.spacing.md : theme.spacing.lg,
    background: theme.colors.bg.card,
    border: `1px solid ${theme.colors.border.light}`,
    borderRadius: theme.borderRadius.lg,
  }

  const applyBtnStyle: React.CSSProperties = {
    padding: `${theme.spacing.sm} ${theme.spacing.lg}`,
    background: theme.colors.semantic.primary,
    border: 'none',
    borderRadius: theme.borderRadius.md,
    color: '#fff',
    fontWeight: 600,
    fontSize: theme.typography.fontSize.sm,
    cursor: 'pointer',
    ...(isMobile ? { width: '100%' } : {}),
  }

  const exportBtnStyle: React.CSSProperties = {
    padding: `${theme.spacing.sm} ${theme.spacing.lg}`,
    background: 'rgba(34, 197, 94, 0.15)',
    border: '1px solid rgba(34, 197, 94, 0.4)',
    borderRadius: theme.borderRadius.md,
    color: '#22c55e',
    fontWeight: 600,
    fontSize: theme.typography.fontSize.sm,
    cursor: 'pointer',
    marginBottom: theme.spacing.lg,
    ...(isMobile ? { width: '100%' } : {}),
  }

  const errorStyle: React.CSSProperties = {
    padding: theme.spacing.md,
    background: 'rgba(239, 68, 68, 0.1)',
    border: '1px solid rgba(239, 68, 68, 0.3)',
    borderRadius: theme.borderRadius.md,
    color: '#ef4444',
    marginBottom: theme.spacing.lg,
  }

  const cardStyle: React.CSSProperties = {
    background: theme.colors.bg.card,
    border: `1px solid ${theme.colors.border.light}`,
    borderRadius: theme.borderRadius.lg,
    padding: isMobile ? theme.spacing.md : theme.spacing.xl,
    marginBottom: theme.spacing.xl,
  }

  // Every tab renders the same export control, and a failed export has to say so
  // rather than leaving the button looking like it worked.
  const exportSection = (
    <>
      <button data-testid="report-export-csv" onClick={handleExportCsv} style={exportBtnStyle}>
        {t('reports.exportCsv')}
      </button>
      {exportError && (
        <div data-testid="report-export-error" style={errorStyle}>
          {t('reports.exportFailed', { message: exportError })}
        </div>
      )}
    </>
  )

  // ─── Render: Standard Report Tabs (1-3) ──────────────────────────────────

  const renderStandardReport = () => {
    const locale = i18n.language === 'de' ? 'de-DE' : 'en-US'
    const isConsumption = activeTab === 'consumption'
    const chartData =
      reportData?.rows.map((row) => ({
        label: row.dimension,
        revenue: row.revenue_cents / 100,
        count: row.count,
      })) ?? []

    return (
      <>
        {/* Filters */}
        <div style={filterGroupStyle}>
          {/* Date row: side-by-side on mobile, inline on desktop */}
          <div style={isMobile ? { display: 'flex', gap: theme.spacing.sm } : { display: 'contents' }}>
            <div style={isMobile ? { flex: 1, minWidth: 0 } : {}}>
              <label style={labelStyle}>{t('reports.dateFrom')}</label>
              <input
                type="date"
                data-testid="report-filter-date-from"
                value={dateFrom}
                onChange={(e) => setDateFrom(e.target.value)}
                style={{ ...inputStyle, width: isMobile ? '100%' : undefined, boxSizing: 'border-box' }}
              />
            </div>
            <div style={isMobile ? { flex: 1, minWidth: 0 } : {}}>
              <label style={labelStyle}>{t('reports.dateTo')}</label>
              <input
                type="date"
                data-testid="report-filter-date-to"
                value={dateTo}
                onChange={(e) => setDateTo(e.target.value)}
                style={{ ...inputStyle, width: isMobile ? '100%' : undefined, boxSizing: 'border-box' }}
              />
            </div>
          </div>
          <FilterSelect
            value={groupBy}
            onChange={(v) => setGroupBy(v as GroupBy)}
            testId="report-filter-group-by"
            label={t('reports.groupBy')}
            minWidth={120}
            options={[
              { value: 'category', label: t('reports.groupByCategory') },
              { value: 'product', label: t('reports.groupByProduct') },
              { value: 'member', label: t('reports.groupByMember') },
              { value: 'day', label: t('reports.groupByDay') },
              { value: 'week', label: t('reports.groupByWeek') },
              { value: 'month', label: t('reports.groupByMonth') },
              { value: 'year', label: t('reports.groupByYear') },
            ]}
          />
          <button
            data-testid="report-apply-filter"
            onClick={handleApplyFilter}
            style={applyBtnStyle}
          >
            {t('reports.applyFilter')}
          </button>
        </div>

        {/* Error */}
        {reportError && <div style={errorStyle}>{reportError}</div>}

        {/* Loading */}
        {reportLoading && (
          <div style={{ textAlign: 'center', color: theme.colors.text.secondary, padding: theme.spacing.xl }}>
            {t('common.loading')}
          </div>
        )}

        {!reportLoading && reportData && (
          <>
            {/* Summary Cards */}
            <div
              style={{
                display: 'grid',
                gridTemplateColumns: isMobile ? 'repeat(2, 1fr)' : 'repeat(4, 1fr)',
                gap: theme.spacing.lg,
                marginBottom: theme.spacing.xl,
              }}
            >
              <SummaryCard
                testId="report-summary-revenue"
                label={t('reports.summaryRevenue')}
                value={formatters.formatPrice(reportData.metadata.total_revenue_cents)}
                color="#22c55e"
                isMobile={isMobile}
              />
              <SummaryCard
                testId="report-summary-unique-members"
                label={t('reports.summaryUniqueMembers')}
                value={reportData.metadata.unique_member_count.toLocaleString(locale)}
                color="#3b82f6"
                isMobile={isMobile}
              />
              <SummaryCard
                testId="report-summary-count"
                label={t('reports.summaryCount')}
                value={reportData.metadata.total_count.toLocaleString(locale)}
                color="#a855f7"
                isMobile={isMobile}
              />
              <SummaryCard
                testId="report-summary-avg"
                label={t('reports.summaryAvg')}
                value={formatters.formatPrice(reportData.metadata.avg_transaction_cents)}
                color="#f59e0b"
                isMobile={isMobile}
              />
            </div>

            {/* Chart */}
            <div data-testid="report-chart" style={cardStyle}>
              <h3 style={{ margin: 0, marginBottom: theme.spacing.lg }}>
                {isConsumption ? t('reports.chartTitleConsumption') : t('reports.chartTitle')}
              </h3>
              {chartData.length > 0 ? (
                <ResponsiveContainer width="100%" height={isMobile ? 160 : 220}>
                  <BarChart data={chartData} margin={{ top: 5, right: 10, left: 10, bottom: 5 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke={theme.colors.border.light} />
                    <XAxis
                      dataKey="label"
                      tick={{ fill: theme.colors.text.muted, fontSize: 10 }}
                      axisLine={{ stroke: theme.colors.border.light }}
                      tickLine={false}
                    />
                    <YAxis
                      tick={{ fill: theme.colors.text.muted, fontSize: 10 }}
                      axisLine={false}
                      tickLine={false}
                      tickFormatter={isConsumption ? (v: number) => String(v) : (v: number) => formatters.formatPrice(v * 100)}
                      width={isConsumption ? 40 : 80}
                    />
                    <Tooltip
                      contentStyle={{
                        background: theme.colors.bg.secondary,
                        border: `1px solid ${theme.colors.border.light}`,
                        borderRadius: theme.borderRadius.md,
                        color: theme.colors.text.primary,
                      }}
                      formatter={(value?: number, name?: string) => {
                        if (name === 'revenue' && value != null)
                          return [formatters.formatPrice(value * 100), t('reports.summaryRevenue')]
                        if (name === 'count' && value != null)
                          return [value, t('reports.summaryCount')]
                        return [value ?? 0, name ?? '']
                      }}
                    />
                    <Bar dataKey={isConsumption ? 'count' : 'revenue'} fill="#3b82f6" radius={[4, 4, 0, 0]} maxBarSize={40} />
                  </BarChart>
                </ResponsiveContainer>
              ) : (
                <div style={{ textAlign: 'center', color: theme.colors.text.muted, padding: theme.spacing.xl }}>
                  {t('reports.noData')}
                </div>
              )}
            </div>

            {/* Export Button */}
            {exportSection}

            {/* Data Table */}
            <div data-testid="report-table" style={cardStyle}>
              <h3 style={{ margin: 0, marginBottom: theme.spacing.lg }}>{t('reports.tableTitle')}</h3>
              {reportData.rows.length > 0 ? (
                <div style={{ overflowX: 'auto' }}>
                  <table style={tableElementStyles}>
                    <thead>
                      <tr style={headerRowStyle}>
                        <th style={headerCellBaseStyle}>{t('reports.colDimension')}</th>
                        <th style={{ ...headerCellBaseStyle, textAlign: 'right' }}>{t('reports.colRevenue')}</th>
                        <th style={{ ...headerCellBaseStyle, textAlign: 'right' }}>{t('reports.colCount')}</th>
                        <th style={{ ...headerCellBaseStyle, textAlign: 'right' }}>{t('reports.colPercentage')}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {reportData.rows.map((row, index) => (
                        <tr
                          key={`${row.dimension}-${index}`}
                          data-testid={`report-row-${index}`}
                          style={{
                            borderBottom: `1px solid ${tableColors.rowActiveBorder}`,
                            color: tableColors.cellText,
                          }}
                        >
                          <td style={{ padding: tableSpacing.cellPadding, fontWeight: 500 }}>{row.dimension}</td>
                          <td
                            style={{
                              padding: tableSpacing.cellPadding,
                              textAlign: 'right',
                              fontFamily: 'JetBrains Mono, monospace',
                            }}
                          >
                            {formatters.formatPrice(row.revenue_cents)}
                          </td>
                          <td style={{ padding: tableSpacing.cellPadding, textAlign: 'right' }}>
                            {row.count.toLocaleString(locale)}
                          </td>
                          <td style={{ padding: tableSpacing.cellPadding, textAlign: 'right' }}>
                            {row.percentage.toFixed(1)}%
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <div style={{ textAlign: 'center', color: theme.colors.text.muted, padding: theme.spacing.lg }}>
                  {t('reports.noData')}
                </div>
              )}
            </div>
          </>
        )}
      </>
    )
  }

  // ─── Render: Member Ranking (tab 4) ──────────────────────────────────────

  const renderMemberRanking = () => {
    const locale = i18n.language === 'de' ? 'de-DE' : 'en-US'

    return (
      <>
        {/* Filters */}
        <div style={filterGroupStyle}>
          {/* Date row: side-by-side on mobile, inline on desktop */}
          <div style={isMobile ? { display: 'flex', gap: theme.spacing.sm } : { display: 'contents' }}>
            <div style={isMobile ? { flex: 1, minWidth: 0 } : {}}>
              <label style={labelStyle}>{t('reports.dateFrom')}</label>
              <input
                type="date"
                data-testid="report-filter-date-from"
                value={dateFrom}
                onChange={(e) => setDateFrom(e.target.value)}
                style={{ ...inputStyle, width: isMobile ? '100%' : undefined, boxSizing: 'border-box' }}
              />
            </div>
            <div style={isMobile ? { flex: 1, minWidth: 0 } : {}}>
              <label style={labelStyle}>{t('reports.dateTo')}</label>
              <input
                type="date"
                data-testid="report-filter-date-to"
                value={dateTo}
                onChange={(e) => setDateTo(e.target.value)}
                style={{ ...inputStyle, width: isMobile ? '100%' : undefined, boxSizing: 'border-box' }}
              />
            </div>
          </div>
          <FilterSelect
            value={String(rankingLimit)}
            onChange={(v) => setRankingLimit(Number(v))}
            testId="ranking-limit"
            label={t('reports.limit')}
            options={[
              { value: '10', label: '10' },
              { value: '25', label: '25' },
              { value: '50', label: '50' },
              { value: '100', label: '100' },
            ]}
          />
          <div style={{ display: 'flex', flexDirection: 'column', justifyContent: 'flex-end' }}>
            <label
              style={{
                fontSize: theme.typography.fontSize.xs,
                color: theme.colors.text.secondary,
                marginBottom: '4px',
                display: 'block',
              }}
            >
              {t('reports.anonymize')}
            </label>
            <div style={{ display: 'flex', alignItems: 'center', height: '34px' }}>
              <Toggle
                enabled={rankingAnonymize}
                onChange={setRankingAnonymize}
                size="small"
                testId="ranking-anonymize"
              />
            </div>
          </div>
          <button
            data-testid="report-apply-filter"
            onClick={handleApplyFilter}
            style={applyBtnStyle}
          >
            {t('reports.applyFilter')}
          </button>
        </div>

        {/* Error */}
        {rankingError && <div style={errorStyle}>{rankingError}</div>}

        {/* Loading */}
        {rankingLoading && (
          <div style={{ textAlign: 'center', color: theme.colors.text.secondary, padding: theme.spacing.xl }}>
            {t('common.loading')}
          </div>
        )}

        {/* Export + Table */}
        {!rankingLoading && rankingData && (
          <>
            {exportSection}

            <div data-testid="ranking-table" style={cardStyle}>
              <h3 style={{ margin: 0, marginBottom: theme.spacing.lg }}>{t('reports.memberRankingTitle')}</h3>
              {rankingData.rows.length > 0 ? (
                <div style={{ overflowX: 'auto' }}>
                  <table style={tableElementStyles}>
                    <thead>
                      <tr style={headerRowStyle}>
                        <th style={{ ...headerCellBaseStyle, width: '50px' }}>{t('reports.colRank')}</th>
                        <th style={headerCellBaseStyle}>{t('reports.colMemberName')}</th>
                        <th style={{ ...headerCellBaseStyle, textAlign: 'right' }}>{t('reports.colTotalAmount')}</th>
                        <th style={{ ...headerCellBaseStyle, textAlign: 'right' }}>{t('reports.colTransactionCount')}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {rankingData.rows.map((row, index) => (
                        <tr
                          key={`ranking-${row.rank}-${index}`}
                          data-testid={`report-row-${index}`}
                          style={{
                            borderBottom: `1px solid ${tableColors.rowActiveBorder}`,
                            color: tableColors.cellText,
                          }}
                        >
                          <td style={{ padding: tableSpacing.cellPadding, color: theme.colors.text.muted }}>
                            {row.rank}
                          </td>
                          <td style={{ padding: tableSpacing.cellPadding, fontWeight: index < 3 ? 600 : 400 }}>
                            {row.member_name}
                          </td>
                          <td
                            style={{
                              padding: tableSpacing.cellPadding,
                              textAlign: 'right',
                              fontFamily: 'JetBrains Mono, monospace',
                            }}
                          >
                            {formatters.formatPrice(row.total_amount_cents)}
                          </td>
                          <td style={{ padding: tableSpacing.cellPadding, textAlign: 'right' }}>
                            {row.transaction_count.toLocaleString(locale)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <div style={{ textAlign: 'center', color: theme.colors.text.muted, padding: theme.spacing.lg }}>
                  {t('reports.noData')}
                </div>
              )}
            </div>
          </>
        )}
      </>
    )
  }

  // ─── Render: Terminal Activity (tab 5) ───────────────────────────────────

  const renderTerminalActivity = () => {
    const locale = i18n.language === 'de' ? 'de-DE' : 'en-US'
    const hourlyChartData =
      terminalData?.hourly_distribution.map((b) => ({
        hour: `${String(b.hour).padStart(2, '0')}:00`,
        count: b.transaction_count,
      })) ?? []

    return (
      <>
        {/* Filters */}
        <div style={filterGroupStyle}>
          {/* Date row: side-by-side on mobile, inline on desktop */}
          <div style={isMobile ? { display: 'flex', gap: theme.spacing.sm } : { display: 'contents' }}>
            <div style={isMobile ? { flex: 1, minWidth: 0 } : {}}>
              <label style={labelStyle}>{t('reports.dateFrom')}</label>
              <input
                type="date"
                data-testid="report-filter-date-from"
                value={dateFrom}
                onChange={(e) => setDateFrom(e.target.value)}
                style={{ ...inputStyle, width: isMobile ? '100%' : undefined, boxSizing: 'border-box' }}
              />
            </div>
            <div style={isMobile ? { flex: 1, minWidth: 0 } : {}}>
              <label style={labelStyle}>{t('reports.dateTo')}</label>
              <input
                type="date"
                data-testid="report-filter-date-to"
                value={dateTo}
                onChange={(e) => setDateTo(e.target.value)}
                style={{ ...inputStyle, width: isMobile ? '100%' : undefined, boxSizing: 'border-box' }}
              />
            </div>
          </div>
          <button
            data-testid="report-apply-filter"
            onClick={handleApplyFilter}
            style={applyBtnStyle}
          >
            {t('reports.applyFilter')}
          </button>
        </div>

        {/* Error */}
        {terminalError && <div style={errorStyle}>{terminalError}</div>}

        {/* Loading */}
        {terminalLoading && (
          <div style={{ textAlign: 'center', color: theme.colors.text.secondary, padding: theme.spacing.xl }}>
            {t('common.loading')}
          </div>
        )}

        {!terminalLoading && terminalData && (
          <>
            {/* Export */}
            {exportSection}

            {/* Sessions Table */}
            <div data-testid="terminal-sessions" style={cardStyle}>
              <h3 style={{ margin: 0, marginBottom: theme.spacing.lg }}>{t('reports.sessionsTitle')}</h3>
              {terminalData.sessions.length > 0 ? (
                <div style={{ overflowX: 'auto' }}>
                  <table style={tableElementStyles}>
                    <thead>
                      <tr style={headerRowStyle}>
                        <th style={headerCellBaseStyle}>{t('reports.colDate')}</th>
                        <th style={headerCellBaseStyle}>{t('reports.colStart')}</th>
                        <th style={headerCellBaseStyle}>{t('reports.colEnd')}</th>
                        <th style={{ ...headerCellBaseStyle, textAlign: 'right' }}>{t('reports.colTransactions')}</th>
                        <th style={{ ...headerCellBaseStyle, textAlign: 'right' }}>{t('reports.colRevenue')}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {terminalData.sessions.map((session, index) => (
                        <tr
                          key={`session-${index}`}
                          data-testid={`report-row-${index}`}
                          style={{
                            borderBottom: `1px solid ${tableColors.rowActiveBorder}`,
                            color: tableColors.cellText,
                          }}
                        >
                          <td style={{ padding: tableSpacing.cellPadding }}>{session.date}</td>
                          <td style={{ padding: tableSpacing.cellPadding }}>{session.start_time ?? '—'}</td>
                          <td style={{ padding: tableSpacing.cellPadding }}>{session.end_time ?? '—'}</td>
                          <td style={{ padding: tableSpacing.cellPadding, textAlign: 'right' }}>
                            {session.transaction_count.toLocaleString(locale)}
                          </td>
                          <td
                            style={{
                              padding: tableSpacing.cellPadding,
                              textAlign: 'right',
                              fontFamily: 'JetBrains Mono, monospace',
                            }}
                          >
                            {formatters.formatPrice(session.revenue_cents)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <div style={{ textAlign: 'center', color: theme.colors.text.muted, padding: theme.spacing.lg }}>
                  {t('reports.noData')}
                </div>
              )}
            </div>

            {/* Hourly Distribution Chart */}
            <div data-testid="terminal-hourly-chart" style={cardStyle}>
              <h3 style={{ margin: 0, marginBottom: theme.spacing.lg }}>{t('reports.hourlyDistribution')}</h3>
              {hourlyChartData.length > 0 ? (
                <ResponsiveContainer width="100%" height={200}>
                  <BarChart data={hourlyChartData} margin={{ top: 5, right: 10, left: 10, bottom: 5 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke={theme.colors.border.light} />
                    <XAxis
                      dataKey="hour"
                      tick={{ fill: theme.colors.text.muted, fontSize: 9 }}
                      axisLine={{ stroke: theme.colors.border.light }}
                      tickLine={false}
                    />
                    <YAxis
                      tick={{ fill: theme.colors.text.muted, fontSize: 10 }}
                      axisLine={false}
                      tickLine={false}
                    />
                    <Tooltip
                      contentStyle={{
                        background: theme.colors.bg.secondary,
                        border: `1px solid ${theme.colors.border.light}`,
                        borderRadius: theme.borderRadius.md,
                        color: theme.colors.text.primary,
                      }}
                    />
                    <Bar dataKey="count" fill="#a855f7" radius={[4, 4, 0, 0]} maxBarSize={20} />
                  </BarChart>
                </ResponsiveContainer>
              ) : (
                <div style={{ textAlign: 'center', color: theme.colors.text.muted, padding: theme.spacing.xl }}>
                  {t('reports.noData')}
                </div>
              )}
            </div>

            {/* Terminal Summary */}
            <div data-testid="terminal-list" style={cardStyle}>
              <h3 style={{ margin: 0, marginBottom: theme.spacing.lg }}>{t('reports.terminalSummary')}</h3>
              {terminalData.terminal_summaries.length > 0 ? (
                <div style={{ overflowX: 'auto' }}>
                  <table style={tableElementStyles}>
                    <thead>
                      <tr style={headerRowStyle}>
                        <th style={headerCellBaseStyle}>{t('reports.colTerminalName')}</th>
                        <th style={{ ...headerCellBaseStyle, textAlign: 'right' }}>{t('reports.colTransactions')}</th>
                        <th style={{ ...headerCellBaseStyle, textAlign: 'right' }}>{t('reports.colRevenue')}</th>
                        <th style={headerCellBaseStyle}>{t('reports.colLastSync')}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {terminalData.terminal_summaries.map((ts, index) => (
                        <tr
                          key={ts.terminal_id}
                          data-testid={`report-row-${index}`}
                          style={{
                            borderBottom: `1px solid ${tableColors.rowActiveBorder}`,
                            color: tableColors.cellText,
                          }}
                        >
                          <td style={{ padding: tableSpacing.cellPadding, fontWeight: 500 }}>
                            {ts.terminal_name}
                          </td>
                          <td style={{ padding: tableSpacing.cellPadding, textAlign: 'right' }}>
                            {ts.transaction_count.toLocaleString(locale)}
                          </td>
                          <td
                            style={{
                              padding: tableSpacing.cellPadding,
                              textAlign: 'right',
                              fontFamily: 'JetBrains Mono, monospace',
                            }}
                          >
                            {formatters.formatPrice(ts.revenue_cents)}
                          </td>
                          <td style={{ padding: tableSpacing.cellPadding, color: theme.colors.text.secondary }}>
                            {ts.last_sync ?? '—'}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <div style={{ textAlign: 'center', color: theme.colors.text.muted, padding: theme.spacing.lg }}>
                  {t('reports.noData')}
                </div>
              )}
            </div>
          </>
        )}
      </>
    )
  }

  // ─── Main render ──────────────────────────────────────────────────────────

  return (
    <div data-testid="reports-page" style={{ padding: isMobile ? '12px' : '20px' }}>
      <h2 style={{ margin: 0, marginBottom: theme.spacing.xl, fontSize: isMobile ? '20px' : undefined }}>{t('reports.title')}</h2>

      {/* Tab Bar */}
      <div
        data-testid="report-tabs"
        style={{
          display: 'flex',
          borderBottom: `1px solid ${theme.colors.border.light}`,
          marginBottom: theme.spacing.xl,
          overflowX: 'auto',
          scrollbarWidth: 'none',
          // eslint-disable-next-line @typescript-eslint/no-explicit-any
          ...(isMobile ? { WebkitOverflowScrolling: 'touch' as any } : {}),
        }}
      >
        <button
          data-testid="report-tab-revenue"
          onClick={() => setActiveTab('revenue')}
          style={tabStyle('revenue')}
        >
          {t('reports.tabRevenue')}
        </button>
        <button
          data-testid="report-tab-consumption"
          onClick={() => setActiveTab('consumption')}
          style={tabStyle('consumption')}
        >
          {t('reports.tabConsumption')}
        </button>
        <button
          data-testid="report-tab-member-ranking"
          onClick={() => setActiveTab('member-ranking')}
          style={tabStyle('member-ranking')}
        >
          {t('reports.tabMemberRanking')}
        </button>
        <button
          data-testid="report-tab-terminal-activity"
          onClick={() => setActiveTab('terminal-activity')}
          style={tabStyle('terminal-activity')}
        >
          {t('reports.tabTerminalActivity')}
        </button>
      </div>

      {/* Tab Content */}
      {(activeTab === 'revenue' || activeTab === 'consumption') && renderStandardReport()}
      {activeTab === 'member-ranking' && renderMemberRanking()}
      {activeTab === 'terminal-activity' && renderTerminalActivity()}
    </div>
  )
}
