import { useState, useEffect, useCallback, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../styles/design-system'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { useLoading } from '../context/LoadingContext'
import { useFormatters } from '../hooks/useFormatters'
import { getDashboardMetrics, DashboardResponse } from '../services/dashboard'
import { StatCard } from '../components/common/StatCard'
import { UsersIcon, ReceiptIcon, BookIcon } from '../components/icons'
import { HomeIcon } from '../components/icons/HomeIcon'

const AUTO_REFRESH_INTERVAL = 60_000 // 60 seconds per UC-A80

export function DashboardPage() {
  const { t } = useTranslation()
  const breakpoint = useBreakpoint()
  const { setIsLoading } = useLoading()
  const { formatPrice, formatDateTime, formatRelativeDate } = useFormatters()

  const [data, setData] = useState<DashboardResponse | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const intervalRef = useRef<ReturnType<typeof setInterval> | null>(null)

  const fetchDashboard = useCallback(async (showGlobalLoading = true) => {
    try {
      if (showGlobalLoading) {
        setLoading(true)
        setIsLoading(true)
      }
      const response = await getDashboardMetrics()
      setData(response)
      setError(null)
    } catch (err) {
      setError(t('errors.generic'))
    } finally {
      setLoading(false)
      setIsLoading(false)
    }
  }, [setIsLoading, t])

  // Initial load + auto-refresh
  useEffect(() => {
    fetchDashboard()
    intervalRef.current = setInterval(() => fetchDashboard(false), AUTO_REFRESH_INTERVAL)
    return () => {
      if (intervalRef.current) clearInterval(intervalRef.current)
    }
  }, [fetchDashboard])

  const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'

  if (loading && !data) {
    return (
      <div data-testid="dashboard-page" style={{ padding: theme.spacing['2xl'] }}>
        <div data-testid="dashboard-loading" style={{ color: theme.colors.text.secondary }}>
          {t('common.loading')}
        </div>
      </div>
    )
  }

  if (error && !data) {
    return (
      <div data-testid="dashboard-page" style={{ padding: theme.spacing['2xl'] }}>
        <div data-testid="dashboard-error" style={{ color: theme.colors.semantic.danger }}>
          {error}
        </div>
      </div>
    )
  }

  if (!data) return null

  const { metrics, recent_transactions, terminal_status, system_status, alerts } = data

  const statusColor = (status: string) => {
    switch (status) {
      case 'online': return theme.colors.semantic.success
      case 'offline': return theme.colors.semantic.warning
      case 'disabled': return theme.colors.text.muted
      default: return theme.colors.text.secondary
    }
  }

  const severityColor = (severity: string) => {
    switch (severity) {
      case 'error': return theme.colors.semantic.danger
      case 'warning': return theme.colors.semantic.warning
      default: return theme.colors.semantic.success
    }
  }

  return (
    <div data-testid="dashboard-page" style={{ padding: isMobile ? `${theme.spacing.sm} 0` : theme.spacing['2xl'], maxWidth: '1200px' }}>
      {/* Header */}
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: theme.spacing.xl }}>
        <h1 data-testid="dashboard-title" style={{
          fontSize: theme.typography.fontSize['2xl'],
          fontWeight: theme.typography.fontWeight.bold,
          color: theme.colors.text.primary,
          margin: 0,
        }}>
          {t('dashboard.title')}
        </h1>
        <button
          data-testid="dashboard-refresh-button"
          onClick={() => fetchDashboard()}
          style={{
            background: theme.colors.bg.card,
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.sm,
            padding: `${theme.spacing.sm} ${theme.spacing.lg}`,
            color: theme.colors.text.secondary,
            cursor: 'pointer',
            fontSize: theme.typography.fontSize.sm,
          }}
        >
          {t('dashboard.refreshNow')}
        </button>
      </div>

      {/* Metrics Row */}
      <div data-testid="dashboard-metrics" style={{
        display: 'grid',
        gridTemplateColumns: isMobile ? '1fr' : 'repeat(4, 1fr)',
        gap: theme.spacing.lg,
        marginBottom: theme.spacing['2xl'],
      }}>
        <StatCard
          icon={<UsersIcon />}
          label={t('dashboard.activeMembers')}
          value={metrics.active_members}
          color="blue"
        />
        <StatCard
          icon={<ReceiptIcon />}
          label={t('dashboard.outstandingBalance')}
          value={formatPrice(metrics.outstanding_balance_cents)}
          color={metrics.outstanding_balance_cents > 0 ? 'orange' : 'green'}
        />
        <StatCard
          icon={<BookIcon />}
          label={t('dashboard.todaysRevenue')}
          value={formatPrice(metrics.todays_revenue_cents)}
          color="green"
        />
        <StatCard
          icon={<HomeIcon />}
          label={t('dashboard.terminals')}
          value={`${metrics.active_terminals}/${metrics.terminal_count}`}
          color="blue"
        />
      </div>

      {/* Two-column layout: Transactions + Sidebar */}
      <div style={{
        display: 'grid',
        gridTemplateColumns: isMobile ? 'minmax(0, 1fr)' : '2fr 1fr',
        gap: theme.spacing.xl,
      }}>
        {/* Recent Transactions */}
        <div data-testid="dashboard-recent-transactions" style={{
          background: theme.colors.bg.card,
          border: `1px solid ${theme.colors.border.light}`,
          borderRadius: theme.borderRadius.lg,
          padding: isMobile ? theme.spacing.md : theme.spacing.xl,
        }}>
          <h2 style={{
            fontSize: theme.typography.fontSize.lg,
            fontWeight: theme.typography.fontWeight.semibold,
            color: theme.colors.text.primary,
            margin: `0 0 ${theme.spacing.lg} 0`,
          }}>
            {t('dashboard.recentTransactions')}
          </h2>

          {recent_transactions.length === 0 ? (
            <div data-testid="dashboard-no-transactions" style={{ color: theme.colors.text.muted, fontSize: theme.typography.fontSize.sm }}>
              {t('dashboard.noRecentTransactions')}
            </div>
          ) : (
            <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.sm }}>
              {recent_transactions.map((tx: any) => (
                <div key={tx.id} data-testid={`dashboard-transaction-${tx.id}`} style={{
                  display: 'flex',
                  justifyContent: 'space-between',
                  alignItems: 'center',
                  padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                  borderRadius: theme.borderRadius.sm,
                  background: theme.colors.bg.secondary,
                }}>
                  <div style={{ flex: 1, minWidth: 0 }}>
                    <div style={{ fontSize: theme.typography.fontSize.sm, color: theme.colors.text.primary, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                      {tx.member_name}
                    </div>
                    <div style={{ fontSize: theme.typography.fontSize.xs, color: theme.colors.text.muted }}>
                      {tx.product_name || t(`dashboard.${tx.type}`)} · {formatDateTime(tx.timestamp)}
                    </div>
                  </div>
                  <div style={{
                    fontSize: theme.typography.fontSize.sm,
                    fontWeight: theme.typography.fontWeight.semibold,
                    fontFamily: 'JetBrains Mono, monospace',
                    color: tx.amount_cents < 0 ? theme.colors.semantic.success : theme.colors.text.primary,
                    flexShrink: 0,
                    whiteSpace: 'nowrap',
                    marginLeft: theme.spacing.sm,
                  }}>
                    {formatPrice(tx.amount_cents)}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        {/* Sidebar: Terminals + Alerts + System Status */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.xl }}>
          {/* Terminal Status */}
          <div data-testid="dashboard-terminal-status" style={{
            background: theme.colors.bg.card,
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.lg,
            padding: isMobile ? theme.spacing.md : theme.spacing.xl,
          }}>
            <h2 style={{
              fontSize: theme.typography.fontSize.lg,
              fontWeight: theme.typography.fontWeight.semibold,
              color: theme.colors.text.primary,
              margin: `0 0 ${theme.spacing.lg} 0`,
            }}>
              {t('dashboard.terminalStatus')}
            </h2>

            {terminal_status.length === 0 ? (
              <div data-testid="dashboard-no-terminals" style={{ color: theme.colors.text.muted, fontSize: theme.typography.fontSize.sm }}>
                {t('dashboard.noTerminals')}
              </div>
            ) : (
              <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.sm }}>
                {terminal_status.map((term: any) => (
                  <div key={term.id} data-testid={`dashboard-terminal-${term.id}`} style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'center',
                    padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                    borderRadius: theme.borderRadius.sm,
                    background: theme.colors.bg.secondary,
                  }}>
                    <span style={{ fontSize: theme.typography.fontSize.sm, color: theme.colors.text.primary, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', minWidth: 0 }}>
                      {term.name}
                    </span>
                    <span data-testid={`dashboard-terminal-status-${term.id}`} style={{
                      fontSize: theme.typography.fontSize.xs,
                      fontWeight: theme.typography.fontWeight.semibold,
                      color: statusColor(term.status),
                      textTransform: 'uppercase',
                      flexShrink: 0,
                      whiteSpace: 'nowrap',
                    }}>
                      {t(`dashboard.${term.status}`)}
                    </span>
                  </div>
                ))}
              </div>
            )}
          </div>

          {/* Alerts */}
          <div data-testid="dashboard-alerts" style={{
            background: theme.colors.bg.card,
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.lg,
            padding: isMobile ? theme.spacing.md : theme.spacing.xl,
          }}>
            <h2 style={{
              fontSize: theme.typography.fontSize.lg,
              fontWeight: theme.typography.fontWeight.semibold,
              color: theme.colors.text.primary,
              margin: `0 0 ${theme.spacing.lg} 0`,
            }}>
              {t('dashboard.alerts')}
            </h2>

            {/* SEPA Issues Alert */}
            <div data-testid="dashboard-sepa-alert" style={{
              display: 'flex',
              alignItems: 'center',
              gap: theme.spacing.md,
              padding: theme.spacing.md,
              borderRadius: theme.borderRadius.sm,
              background: alerts.sepa_issues.severity === 'none'
                ? 'rgba(34, 197, 94, 0.1)'
                : alerts.sepa_issues.severity === 'warning'
                  ? 'rgba(249, 115, 22, 0.1)'
                  : 'rgba(239, 68, 68, 0.1)',
            }}>
              <div style={{
                width: '8px',
                height: '8px',
                borderRadius: '50%',
                background: severityColor(alerts.sepa_issues.severity),
                flexShrink: 0,
              }} />
              <span data-testid="dashboard-sepa-alert-message" style={{
                fontSize: theme.typography.fontSize.sm,
                color: theme.colors.text.primary,
              }}>
                {alerts.sepa_issues.count > 0
                  ? t('dashboard.membersNeedSepaData', { count: alerts.sepa_issues.count })
                  : t('dashboard.allSepaValid')
                }
              </span>
            </div>
          </div>

          {/* System Status */}
          <div data-testid="dashboard-system-status" style={{
            background: theme.colors.bg.card,
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.lg,
            padding: isMobile ? theme.spacing.md : theme.spacing.xl,
          }}>
            <h2 style={{
              fontSize: theme.typography.fontSize.lg,
              fontWeight: theme.typography.fontWeight.semibold,
              color: theme.colors.text.primary,
              margin: `0 0 ${theme.spacing.lg} 0`,
            }}>
              {t('dashboard.systemStatus')}
            </h2>

            <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.sm }}>
              {[
                { label: t('dashboard.lastSettlement'), value: system_status.last_settlement_date ? formatRelativeDate(system_status.last_settlement_date) : '–', testId: 'last-settlement' },
                { label: t('dashboard.pendingSettlements'), value: system_status.pending_settlement_count, testId: 'pending-settlements' },
                { label: t('dashboard.totalMembers'), value: system_status.total_members, testId: 'total-members' },
                { label: t('dashboard.totalTransactions'), value: system_status.total_transactions, testId: 'total-transactions' },
                { label: t('dashboard.databaseHealth'), value: system_status.database_health, testId: 'database-health' },
              ].map(({ label, value, testId }) => (
                <div key={testId} data-testid={`dashboard-system-${testId}`} style={{
                  display: 'flex',
                  justifyContent: 'space-between',
                  padding: `${theme.spacing.xs} 0`,
                  borderBottom: `1px solid ${theme.colors.border.dark}`,
                }}>
                  <span style={{ fontSize: theme.typography.fontSize.sm, color: theme.colors.text.secondary, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', minWidth: 0 }}>{label}</span>
                  <span style={{
                    fontSize: theme.typography.fontSize.sm,
                    fontWeight: theme.typography.fontWeight.medium,
                    color: theme.colors.text.primary,
                    fontFamily: 'JetBrains Mono, monospace',
                    flexShrink: 0,
                    whiteSpace: 'nowrap',
                  }}>
                    {value}
                  </span>
                </div>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  )
}
