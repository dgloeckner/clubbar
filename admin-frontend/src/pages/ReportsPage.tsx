/**
 * Reports Page
 * Advanced reporting dashboard for revenue, consumption, transactions,
 * member ranking, and terminal activity.
 * Implements UC-A50, UC-A51, UC-A52
 */

import { useEffect, useState, useCallback } from 'react'
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
import {
  getReport,
  getMemberRanking,
  getTerminalActivity,
  exportReport,
  type ReportType,
  type GroupBy,
  type ReportResponse,
  type MemberRankingResponse,
  type TerminalActivityResponse,
  type ReportParams,
  type MemberRankingParams,
  type TerminalActivityParams,
} from '../services/reports'
import { theme } from '../styles/design-system'
import { useFormatters } from '../hooks/useFormatters'
import { useBreakpoint } from '../hooks/useBreakpoint'
import {
  tableElementStyles,
  headerRowStyle,
  headerCellBaseStyle,
  tableColors,
  tableSpacing,
} from '../styles/tableTokens'

// ─── Helpers ──────────────────────────────────────────────────────────────────

function defaultDateRange(): { date_from: string; date_to: string } {
  const to = new Date()
  const from = new Date()
  from.setDate(from.getDate() - 30)
  return {
    date_from: from.toISOString().slice(0, 10),
    date_to: to.toISOString().slice(0, 10),
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

// ─── Main Component ───────────────────────────────────────────────────────────

type TabId = 'revenue' | 'consumption' | 'transactions' | 'member-ranking' | 'terminal-activity'

export function ReportsPage() {
  const { t, i18n } = useTranslation()
  const formatters = useFormatters()
  const breakpoint = useBreakpoint()
  const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'

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

  // ─── Load data ───────────────────────────────────────────────────────────

  const loadReport = useCallback(async () => {
    if (activeTab !== 'revenue' && activeTab !== 'consumption' && activeTab !== 'transactions') return
    setReportLoading(true)
    setReportError(null)
    try {
      const params: ReportParams = { date_from: dateFrom, date_to: dateTo, group_by: groupBy }
      const data = await getReport(activeTab as ReportType, params)
      setReportData(data)
    } catch (err) {
      setReportError(err instanceof Error ? err.message : t('errors.generic'))
    } finally {
      setReportLoading(false)
    }
  }, [activeTab, dateFrom, dateTo, groupBy, t])

  const loadRanking = useCallback(async () => {
    setRankingLoading(true)
    setRankingError(null)
    try {
      const params: MemberRankingParams = {
        date_from: dateFrom,
        date_to: dateTo,
        limit: rankingLimit,
        anonymize: rankingAnonymize,
      }
      const data = await getMemberRanking(params)
      setRankingData(data)
    } catch (err) {
      setRankingError(err instanceof Error ? err.message : t('errors.generic'))
    } finally {
      setRankingLoading(false)
    }
  }, [dateFrom, dateTo, rankingLimit, rankingAnonymize, t])

  const loadTerminalActivity = useCallback(async () => {
    setTerminalLoading(true)
    setTerminalError(null)
    try {
      const params: TerminalActivityParams = { date_from: dateFrom, date_to: dateTo }
      const data = await getTerminalActivity(params)
      setTerminalData(data)
    } catch (err) {
      setTerminalError(err instanceof Error ? err.message : t('errors.generic'))
    } finally {
      setTerminalLoading(false)
    }
  }, [dateFrom, dateTo, t])

  // Load data when tab changes
  useEffect(() => {
    if (activeTab === 'revenue' || activeTab === 'consumption' || activeTab === 'transactions') {
      loadReport()
    } else if (activeTab === 'member-ranking') {
      loadRanking()
    } else if (activeTab === 'terminal-activity') {
      loadTerminalActivity()
    }
  }, [activeTab]) // eslint-disable-line react-hooks/exhaustive-deps

  // ─── Event handlers ───────────────────────────────────────────────────────

  const handleApplyFilter = () => {
    if (activeTab === 'revenue' || activeTab === 'consumption' || activeTab === 'transactions') {
      loadReport()
    } else if (activeTab === 'member-ranking') {
      loadRanking()
    } else if (activeTab === 'terminal-activity') {
      loadTerminalActivity()
    }
  }

  const handleExportCsv = async () => {
    try {
      const params: Record<string, string | number | boolean | undefined> = {
        date_from: dateFrom,
        date_to: dateTo,
      }
      if (activeTab === 'revenue' || activeTab === 'consumption' || activeTab === 'transactions') {
        params.group_by = groupBy
        await exportReport(activeTab as ReportType, params)
      } else if (activeTab === 'member-ranking') {
        params.limit = rankingLimit
        params.anonymize = rankingAnonymize
        await exportReport('member-ranking', params)
      } else if (activeTab === 'terminal-activity') {
        await exportReport('terminal-activity', params)
      }
    } catch (err) {
      // Silently ignore export errors (file download may be unavailable)
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

  // ─── Render: Standard Report Tabs (1-3) ──────────────────────────────────

  const renderStandardReport = () => {
    const locale = i18n.language === 'de' ? 'de-DE' : 'en-US'
    const chartData =
      reportData?.rows.map((row) => ({
        label: row.dimension,
        revenue: row.revenue_cents / 100,
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
          <div>
            <label style={labelStyle}>{t('reports.groupBy')}</label>
            <select
              data-testid="report-filter-group-by"
              value={groupBy}
              onChange={(e) => setGroupBy(e.target.value as GroupBy)}
              style={{ ...inputStyle, width: isMobile ? '100%' : undefined, boxSizing: 'border-box' }}
            >
              <option value="category">{t('reports.groupByCategory')}</option>
              <option value="product">{t('reports.groupByProduct')}</option>
              <option value="member">{t('reports.groupByMember')}</option>
              <option value="day">{t('reports.groupByDay')}</option>
              <option value="week">{t('reports.groupByWeek')}</option>
              <option value="month">{t('reports.groupByMonth')}</option>
              <option value="year">{t('reports.groupByYear')}</option>
            </select>
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
                testId="report-summary-quantity"
                label={t('reports.summaryQuantity')}
                value={reportData.metadata.total_quantity.toLocaleString(locale)}
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
              <h3 style={{ margin: 0, marginBottom: theme.spacing.lg }}>{t('reports.chartTitle')}</h3>
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
                      tickFormatter={(v: number) => formatters.formatPrice(v * 100)}
                      width={80}
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
                        return [value ?? 0, name ?? '']
                      }}
                    />
                    <Bar dataKey="revenue" fill="#3b82f6" radius={[4, 4, 0, 0]} maxBarSize={40} />
                  </BarChart>
                </ResponsiveContainer>
              ) : (
                <div style={{ textAlign: 'center', color: theme.colors.text.muted, padding: theme.spacing.xl }}>
                  {t('reports.noData')}
                </div>
              )}
            </div>

            {/* Export Button */}
            <button
              data-testid="report-export-csv"
              onClick={handleExportCsv}
              style={exportBtnStyle}
            >
              {t('reports.exportCsv')}
            </button>

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
                        <th style={{ ...headerCellBaseStyle, textAlign: 'right' }}>{t('reports.colQuantity')}</th>
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
                            {row.quantity.toLocaleString(locale)}
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
          <div>
            <label style={labelStyle}>{t('reports.limit')}</label>
            <select
              data-testid="ranking-limit"
              value={rankingLimit}
              onChange={(e) => setRankingLimit(Number(e.target.value))}
              style={{ ...inputStyle, width: isMobile ? '100%' : undefined, boxSizing: 'border-box' }}
            >
              <option value={10}>10</option>
              <option value={25}>25</option>
              <option value={50}>50</option>
              <option value={100}>100</option>
            </select>
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: theme.spacing.sm }}>
            <input
              type="checkbox"
              id="ranking-anonymize"
              data-testid="ranking-anonymize"
              checked={rankingAnonymize}
              onChange={(e) => setRankingAnonymize(e.target.checked)}
              style={{ width: '16px', height: '16px', cursor: 'pointer' }}
            />
            <label
              htmlFor="ranking-anonymize"
              style={{ ...labelStyle, marginBottom: 0, cursor: 'pointer' }}
            >
              {t('reports.anonymize')}
            </label>
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
            <button
              data-testid="report-export-csv"
              onClick={handleExportCsv}
              style={exportBtnStyle}
            >
              {t('reports.exportCsv')}
            </button>

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
            <button
              data-testid="report-export-csv"
              onClick={handleExportCsv}
              style={exportBtnStyle}
            >
              {t('reports.exportCsv')}
            </button>

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
          data-testid="report-tab-transactions"
          onClick={() => setActiveTab('transactions')}
          style={tabStyle('transactions')}
        >
          {t('reports.tabTransactions')}
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
      {(activeTab === 'revenue' || activeTab === 'consumption' || activeTab === 'transactions') &&
        renderStandardReport()}
      {activeTab === 'member-ranking' && renderMemberRanking()}
      {activeTab === 'terminal-activity' && renderTerminalActivity()}
    </div>
  )
}
