/**
 * Statistics Page
 * Dashboard with metrics, analytics, reports, and member rankings
 *
 * Implements:
 * - UC-A80: Dashboard (main view with metrics and alerts)
 * - UC-A50: Reports (transaction analysis and visualization)
 * - UC-A51: Member Ranking (top members by consumption)
 *
 * Uses TDD with E2E tests in e2etests/tests/admin/statistics.spec.ts
 */

import { useEffect, useState } from 'react'
import { get } from '../services/api'
import { theme } from '../styles/design-system'
import {
  tableElementStyles,
  headerRowStyle,
  headerCellBaseStyle,
  tableColors,
  tableSpacing,
} from '../styles/tableTokens'

interface Transaction {
  id: string
  created_at: string
  member_id: string
  member_name: string
  product_id: string
  product_name: string
  amount_cents: number
}

interface DashboardMetrics {
  active_members_count: number
  outstanding_balance_cents: number
  today_revenue_cents: number
  terminal_status: 'online' | 'offline' | 'unknown'
  last_sync_at: string | null
  last_settlement_date: string | null
  sepa_invalid_count: number
}

interface DashboardData {
  metrics: DashboardMetrics
  recent_transactions: Transaction[]
}

type ViewMode = 'dashboard' | 'reports' | 'ranking'

export function StatisticsPage() {
  const [viewMode, setViewMode] = useState<ViewMode>('dashboard')
  const [dashboardData, setDashboardData] = useState<DashboardData | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  // Load dashboard on mount
  useEffect(() => {
    loadDashboard()
    // Optionally auto-refresh every 60 seconds
    const interval = setInterval(loadDashboard, 60000)
    return () => clearInterval(interval)
  }, [])

  const loadDashboard = async () => {
    try {
      setLoading(true)
      setError(null)

      const response = await get<DashboardData>('/admin/dashboard')

      if (response && typeof response === 'object') {
        if ('metrics' in response && 'recent_transactions' in response) {
          setDashboardData(response as DashboardData)
        } else if ('data' in response && typeof response.data === 'object') {
          const data = response.data as unknown as DashboardData
          if (data && 'metrics' in data) {
            setDashboardData(data)
          }
        }
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load dashboard')
    } finally {
      setLoading(false)
    }
  }

  const formatPrice = (cents: number): string => {
    return (cents / 100).toLocaleString('de-DE', {
      style: 'currency',
      currency: 'EUR',
    })
  }

  const formatDateTime = (dateStr: string): string => {
    const date = new Date(dateStr)
    return date.toLocaleString('de-DE')
  }

  const formatDate = (dateStr: string): string => {
    const date = new Date(dateStr)
    return date.toLocaleDateString('de-DE')
  }

  // Dashboard View
  if (viewMode === 'dashboard') {
    return (
      <div data-testid="statistics-page">
        <div data-testid="statistics-dashboard" style={{ padding: theme.spacing.xl }}>
          {/* Header */}
          <div style={{ marginBottom: theme.spacing.xl }}>
            <h1 style={{ margin: 0, marginBottom: theme.spacing.md }}>Statistik</h1>
            <div style={{ display: 'flex', gap: theme.spacing.md }}>
              <button
                data-testid="dashboard-refresh-button"
                onClick={loadDashboard}
                style={{
                  padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                  background: theme.colors.semantic.primary,
                  color: 'white',
                  border: 'none',
                  borderRadius: theme.borderRadius.sm,
                  cursor: 'pointer',
                }}
              >
                Refresh
              </button>
              <button
                data-testid="dashboard-reports-button"
                onClick={() => setViewMode('reports')}
                style={{
                  padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                  background: theme.colors.bg.secondary,
                  color: theme.colors.text.primary,
                  border: `1px solid ${theme.colors.border.light}`,
                  borderRadius: theme.borderRadius.sm,
                  cursor: 'pointer',
                }}
              >
                Reports
              </button>
              <button
                data-testid="dashboard-member-ranking-button"
                onClick={() => setViewMode('ranking')}
                style={{
                  padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                  background: theme.colors.bg.secondary,
                  color: theme.colors.text.primary,
                  border: `1px solid ${theme.colors.border.light}`,
                  borderRadius: theme.borderRadius.sm,
                  cursor: 'pointer',
                }}
              >
                Member Ranking
              </button>
            </div>
          </div>

          {/* Error */}
          {error && (
            <div
              style={{
                padding: theme.spacing.md,
                background: theme.colors.semantic.danger,
                color: 'white',
                borderRadius: theme.borderRadius.md,
                marginBottom: theme.spacing.lg,
              }}
            >
              {error}
            </div>
          )}

          {/* Loading */}
          {loading && <div>Loading dashboard...</div>}

          {/* Metrics Cards */}
          {!loading && dashboardData && (
            <>
              <div data-testid="dashboard-metrics" style={{ marginBottom: theme.spacing.xl }}>
                <div
                  style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(4, 1fr)',
                    gap: theme.spacing.lg,
                  }}
                >
                  {/* Active Members */}
                  <div
                    data-testid="metric-active-members"
                    style={{
                      background: theme.colors.bg.card,
                      border: `1px solid ${theme.colors.border.light}`,
                      borderRadius: theme.borderRadius.lg,
                      padding: theme.spacing.lg,
                    }}
                  >
                    <div style={{ color: theme.colors.text.secondary, fontSize: theme.typography.fontSize.sm, marginBottom: theme.spacing.sm }}>
                      Active Members
                    </div>
                    <div data-testid="metric-value" style={{ fontSize: '28px', fontWeight: 600, color: theme.colors.semantic.primary }}>
                      {dashboardData.metrics.active_members_count}
                    </div>
                  </div>

                  {/* Outstanding Balance */}
                  <div
                    data-testid="metric-outstanding-balance"
                    style={{
                      background: theme.colors.bg.card,
                      border: `1px solid ${theme.colors.border.light}`,
                      borderRadius: theme.borderRadius.lg,
                      padding: theme.spacing.lg,
                    }}
                  >
                    <div style={{ color: theme.colors.text.secondary, fontSize: theme.typography.fontSize.sm, marginBottom: theme.spacing.sm }}>
                      Outstanding Balance
                    </div>
                    <div data-testid="metric-value" style={{ fontSize: '28px', fontWeight: 600, color: theme.colors.semantic.danger }}>
                      {formatPrice(dashboardData.metrics.outstanding_balance_cents)}
                    </div>
                  </div>

                  {/* Today's Revenue */}
                  <div
                    data-testid="metric-today-revenue"
                    style={{
                      background: theme.colors.bg.card,
                      border: `1px solid ${theme.colors.border.light}`,
                      borderRadius: theme.borderRadius.lg,
                      padding: theme.spacing.lg,
                    }}
                  >
                    <div style={{ color: theme.colors.text.secondary, fontSize: theme.typography.fontSize.sm, marginBottom: theme.spacing.sm }}>
                      Today's Revenue
                    </div>
                    <div data-testid="metric-value" style={{ fontSize: '28px', fontWeight: 600, color: theme.colors.semantic.success }}>
                      {formatPrice(dashboardData.metrics.today_revenue_cents)}
                    </div>
                  </div>

                  {/* Terminal Status */}
                  <div
                    data-testid="metric-terminal-status"
                    style={{
                      background: theme.colors.bg.card,
                      border: `1px solid ${theme.colors.border.light}`,
                      borderRadius: theme.borderRadius.lg,
                      padding: theme.spacing.lg,
                    }}
                  >
                    <div style={{ color: theme.colors.text.secondary, fontSize: theme.typography.fontSize.sm, marginBottom: theme.spacing.sm }}>
                      Terminal Status
                    </div>
                    <div
                      style={{
                        fontSize: '14px',
                        fontWeight: 600,
                        color: dashboardData.metrics.terminal_status === 'online' ? theme.colors.semantic.success : theme.colors.semantic.danger,
                      }}
                    >
                      {dashboardData.metrics.terminal_status === 'online' ? '🟢 Online' : '🔴 Offline'}
                    </div>
                  </div>
                </div>
              </div>

              {/* Alerts */}
              {dashboardData.metrics.sepa_invalid_count > 0 && (
                <div data-testid="dashboard-alerts" style={{ marginBottom: theme.spacing.lg }}>
                  <div
                    data-testid="alert-sepa-invalid"
                    style={{
                      padding: theme.spacing.md,
                      background: dashboardData.metrics.sepa_invalid_count >= 6 ? '#FEE2E2' : '#FEF3C7',
                      border: `1px solid ${dashboardData.metrics.sepa_invalid_count >= 6 ? '#FECACA' : '#FCD34D'}`,
                      borderRadius: theme.borderRadius.md,
                      color: dashboardData.metrics.sepa_invalid_count >= 6 ? '#991B1B' : '#92400E',
                    }}
                  >
                    ⚠️ {dashboardData.metrics.sepa_invalid_count} members need SEPA data
                  </div>
                </div>
              )}

              {/* Recent Transactions */}
              <div data-testid="dashboard-recent-transactions" style={{ marginBottom: theme.spacing.lg }}>
                <h3 style={{ marginTop: 0 }}>Recent Transactions</h3>
                {dashboardData.recent_transactions.length === 0 ? (
                  <div data-testid="no-recent-transactions" style={{ color: theme.colors.text.secondary, padding: theme.spacing.lg, textAlign: 'center' }}>
                    No recent transactions
                  </div>
                ) : (
                  <table
                    data-testid="recent-transactions-table"
                    style={tableElementStyles}
                  >
                    <thead>
                      <tr style={headerRowStyle}>
                        <th style={headerCellBaseStyle}>Time</th>
                        <th style={headerCellBaseStyle}>Member</th>
                        <th style={headerCellBaseStyle}>Product</th>
                        <th style={{ ...headerCellBaseStyle, textAlign: 'right' }}>Amount</th>
                      </tr>
                    </thead>
                    <tbody>
                      {dashboardData.recent_transactions.slice(0, 10).map((tx) => (
                        <tr key={tx.id} data-testid="transaction-row" style={{ borderBottom: `1px solid ${tableColors.rowActiveBorder}`, color: tableColors.cellText }}>
                          <td data-testid="transaction-time" style={{ padding: tableSpacing.cellPadding }}>
                            {formatDateTime(tx.created_at)}
                          </td>
                          <td data-testid="transaction-member" style={{ padding: tableSpacing.cellPadding }}>
                            {tx.member_name}
                          </td>
                          <td data-testid="transaction-product" style={{ padding: tableSpacing.cellPadding }}>
                            {tx.product_name}
                          </td>
                          <td data-testid="transaction-amount" style={{ padding: tableSpacing.cellPadding, textAlign: 'right', fontWeight: 500 }}>
                            {formatPrice(tx.amount_cents)}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
              </div>

              {/* Quick Actions */}
              <div data-testid="dashboard-quick-actions" style={{ marginBottom: theme.spacing.lg }}>
                <h3 style={{ marginTop: 0 }}>Quick Actions</h3>
                <div style={{ display: 'flex', gap: theme.spacing.md }}>
                  <button
                    data-testid="quick-action-new-member"
                    style={{
                      padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                      background: theme.colors.semantic.primary,
                      color: 'white',
                      border: 'none',
                      borderRadius: theme.borderRadius.md,
                      cursor: 'pointer',
                      fontWeight: 500,
                    }}
                  >
                    New Member
                  </button>
                  <button
                    data-testid="quick-action-new-settlement"
                    style={{
                      padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                      background: theme.colors.semantic.primary,
                      color: 'white',
                      border: 'none',
                      borderRadius: theme.borderRadius.md,
                      cursor: 'pointer',
                      fontWeight: 500,
                    }}
                  >
                    New Settlement
                  </button>
                  <button
                    data-testid="quick-action-view-reports"
                    onClick={() => setViewMode('reports')}
                    style={{
                      padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                      background: theme.colors.bg.secondary,
                      color: theme.colors.text.primary,
                      border: `1px solid ${theme.colors.border.light}`,
                      borderRadius: theme.borderRadius.md,
                      cursor: 'pointer',
                      fontWeight: 500,
                    }}
                  >
                    View Reports
                  </button>
                </div>
              </div>

              {/* System Status */}
              <div data-testid="dashboard-system-status" style={{ marginBottom: theme.spacing.lg }}>
                <h3 style={{ marginTop: 0 }}>System Status</h3>
                <div
                  style={{
                    background: theme.colors.bg.card,
                    border: `1px solid ${theme.colors.border.light}`,
                    borderRadius: theme.borderRadius.lg,
                    padding: theme.spacing.lg,
                    display: 'grid',
                    gridTemplateColumns: 'repeat(2, 1fr)',
                    gap: theme.spacing.lg,
                  }}
                >
                  <div data-testid="status-terminal-connectivity">
                    <div style={{ color: theme.colors.text.secondary, fontSize: theme.typography.fontSize.sm }}>Terminal Connectivity</div>
                    <div style={{ fontWeight: 500, marginTop: theme.spacing.sm }}>
                      {dashboardData.metrics.last_sync_at ? `Last sync: ${formatDateTime(dashboardData.metrics.last_sync_at)}` : 'Never synced'}
                    </div>
                  </div>
                  {dashboardData.metrics.last_settlement_date && (
                    <div data-testid="status-last-settlement">
                      <div style={{ color: theme.colors.text.secondary, fontSize: theme.typography.fontSize.sm }}>Last Settlement</div>
                      <div style={{ fontWeight: 500, marginTop: theme.spacing.sm }}>{formatDate(dashboardData.metrics.last_settlement_date)}</div>
                    </div>
                  )}
                </div>
              </div>
            </>
          )}
        </div>
      </div>
    )
  }

  // Reports View (placeholder)
  if (viewMode === 'reports') {
    return (
      <div data-testid="reports-view" style={{ padding: theme.spacing.xl }}>
        <button
          onClick={() => setViewMode('dashboard')}
          style={{
            marginBottom: theme.spacing.lg,
            background: 'transparent',
            border: 'none',
            color: theme.colors.semantic.primary,
            cursor: 'pointer',
            fontWeight: 500,
            textDecoration: 'underline',
          }}
        >
          ← Back to Dashboard
        </button>

        <h2>Reports</h2>

        <div data-testid="report-type-selector" style={{ marginBottom: theme.spacing.lg }}>
          <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontWeight: 500 }}>Report Type:</label>
          <div style={{ display: 'flex', gap: theme.spacing.md }}>
            <button
              data-testid="report-type-revenue"
              style={{
                padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                background: theme.colors.semantic.primary,
                color: 'white',
                border: 'none',
                borderRadius: theme.borderRadius.sm,
                cursor: 'pointer',
              }}
            >
              Revenue
            </button>
            <button
              data-testid="report-type-consumption"
              style={{
                padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                background: theme.colors.bg.secondary,
                color: theme.colors.text.primary,
                border: `1px solid ${theme.colors.border.light}`,
                borderRadius: theme.borderRadius.sm,
                cursor: 'pointer',
              }}
            >
              Consumption
            </button>
            <button
              data-testid="report-type-transactions"
              style={{
                padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                background: theme.colors.bg.secondary,
                color: theme.colors.text.primary,
                border: `1px solid ${theme.colors.border.light}`,
                borderRadius: theme.borderRadius.sm,
                cursor: 'pointer',
              }}
            >
              Transactions
            </button>
          </div>
        </div>

        <div data-testid="report-date-range-filter" style={{ marginBottom: theme.spacing.lg }}>
          <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontWeight: 500 }}>Date Range:</label>
          <div style={{ display: 'flex', gap: theme.spacing.md }}>
            <input data-testid="date-from" type="date" placeholder="From" />
            <input data-testid="date-to" type="date" placeholder="To" />
          </div>
        </div>

        <div data-testid="no-report-data" style={{ color: theme.colors.text.secondary, padding: theme.spacing.lg, textAlign: 'center' }}>
          Report data will be displayed here
        </div>
      </div>
    )
  }

  // Member Ranking View (placeholder)
  if (viewMode === 'ranking') {
    return (
      <div data-testid="member-ranking-view" style={{ padding: theme.spacing.xl }}>
        <button
          onClick={() => setViewMode('dashboard')}
          style={{
            marginBottom: theme.spacing.lg,
            background: 'transparent',
            border: 'none',
            color: theme.colors.semantic.primary,
            cursor: 'pointer',
            fontWeight: 500,
            textDecoration: 'underline',
          }}
        >
          ← Back to Dashboard
        </button>

        <h2>Member Ranking</h2>

        <div style={{ marginBottom: theme.spacing.lg, display: 'flex', gap: theme.spacing.lg }}>
          <div>
            <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontWeight: 500 }}>Display Mode:</label>
            <div data-testid="ranking-display-mode-toggle" style={{ display: 'flex', gap: theme.spacing.sm }}>
              <button
                data-testid="ranking-mode-named"
                style={{
                  padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                  background: theme.colors.semantic.primary,
                  color: 'white',
                  border: 'none',
                  borderRadius: theme.borderRadius.sm,
                  cursor: 'pointer',
                }}
              >
                Named
              </button>
              <button
                data-testid="ranking-mode-anonymized"
                style={{
                  padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                  background: theme.colors.bg.secondary,
                  color: theme.colors.text.primary,
                  border: `1px solid ${theme.colors.border.light}`,
                  borderRadius: theme.borderRadius.sm,
                  cursor: 'pointer',
                }}
              >
                Anonymized
              </button>
            </div>
          </div>

          <div>
            <label htmlFor="top-n" style={{ display: 'block', marginBottom: theme.spacing.sm, fontWeight: 500 }}>
              Top N:
            </label>
            <select
              id="top-n"
              data-testid="ranking-top-n-selector"
              style={{
                padding: theme.spacing.sm,
                border: `1px solid ${theme.colors.border.light}`,
                borderRadius: theme.borderRadius.sm,
              }}
            >
              <option value="10">Top 10</option>
              <option value="25">Top 25</option>
              <option value="50">Top 50</option>
              <option value="all">All</option>
            </select>
          </div>
        </div>

        <div data-testid="no-member-ranking-data" style={{ color: theme.colors.text.secondary, padding: theme.spacing.lg, textAlign: 'center' }}>
          Member ranking data will be displayed here
        </div>
      </div>
    )
  }

  return null
}
