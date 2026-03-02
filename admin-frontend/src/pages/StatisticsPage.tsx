/**
 * Statistics Page
 * Monthly statistics dashboard with revenue charts and rankings
 */

import { useEffect, useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { get } from '../services/api'
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

interface DailyRevenue {
  date: string
  revenue_cents: number
  transaction_count: number
}

interface ProductStats {
  id: string
  name: string
  revenue_cents: number
  sold_count: number
}

interface MemberStats {
  id: string
  name: string
  revenue_cents: number
  purchase_count: number
}

interface MonthlyStats {
  month: string // YYYY-MM
  total_revenue_cents: number
  total_sold_items: number
  top_product: { name: string; sold_count: number } | null
  daily_revenue: DailyRevenue[]
  top_products: ProductStats[]
  top_members: MemberStats[]
}

export function StatisticsPage() {
  const { t, i18n } = useTranslation()
  const formatters = useFormatters()
  const breakpoint = useBreakpoint()
  const isMobile = breakpoint === 'mobile'
  const [selectedMonth, setSelectedMonth] = useState(() => {
    const now = new Date()
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
  })
  const [stats, setStats] = useState<MonthlyStats | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  // Generate available months (last 12 months)
  const availableMonths = useMemo(() => {
    const months: { value: string; label: string }[] = []
    const now = new Date()
    const locale = i18n.language === 'de' ? 'de-DE' : 'en-US'
    for (let i = 0; i < 12; i++) {
      const date = new Date(now.getFullYear(), now.getMonth() - i, 1)
      const value = `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
      const label = date.toLocaleDateString(locale, { month: 'long', year: 'numeric' })
      months.push({ value, label })
    }
    return months
  }, [i18n.language])

  useEffect(() => {
    loadStats()
  }, [selectedMonth])

  const loadStats = async () => {
    try {
      setLoading(true)
      setError(null)
      const apiResponse = await get<MonthlyStats>(`/admin/statistics/monthly?month=${selectedMonth}`)
      // Cast to any to handle both response formats at runtime
      const response = apiResponse as any
      if (response && typeof response === 'object') {
        // Handle both wrapped and unwrapped responses
        if ('month' in response) {
          setStats(response as MonthlyStats)
        } else if ('data' in response && response.data) {
          setStats(response.data as MonthlyStats)
        }
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load statistics')
    } finally {
      setLoading(false)
    }
  }

  const formatDay = (dateStr: string): string => {
    const date = new Date(dateStr)
    return date.getDate().toString()
  }

  // Calculate max revenue for chart scaling
  const maxDailyRevenue = useMemo(() => {
    if (!stats?.daily_revenue?.length) return 100
    return Math.max(...stats.daily_revenue.map(d => d.revenue_cents), 100)
  }, [stats])

  const goToPreviousMonth = () => {
    const [year, month] = selectedMonth.split('-').map(Number)
    const newDate = new Date(year, month - 2, 1)
    setSelectedMonth(`${newDate.getFullYear()}-${String(newDate.getMonth() + 1).padStart(2, '0')}`)
  }

  const goToNextMonth = () => {
    const [year, month] = selectedMonth.split('-').map(Number)
    const newDate = new Date(year, month, 1)
    const now = new Date()
    // Don't go beyond current month
    if (newDate <= now) {
      setSelectedMonth(`${newDate.getFullYear()}-${String(newDate.getMonth() + 1).padStart(2, '0')}`)
    }
  }

  const isCurrentMonth = () => {
    const now = new Date()
    const current = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
    return selectedMonth === current
  }

  const selectedMonthLabel = availableMonths.find(m => m.value === selectedMonth)?.label || selectedMonth

  return (
    <div data-testid="statistics-page" style={{ padding: '20px' }}>
      {/* Month Switcher */}
      <div
        data-testid="month-switcher"
        style={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          gap: theme.spacing.lg,
          marginBottom: theme.spacing.xl,
          padding: theme.spacing.lg,
          background: theme.colors.bg.card,
          borderRadius: theme.borderRadius.lg,
          border: `1px solid ${theme.colors.border.light}`,
        }}
      >
        <button
          data-testid="month-prev"
          onClick={goToPreviousMonth}
          style={{
            width: '40px',
            height: '40px',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            background: theme.colors.bg.secondary,
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.md,
            color: theme.colors.text.primary,
            cursor: 'pointer',
            fontSize: '18px',
          }}
        >
          ‹
        </button>
        <h2
          data-testid="month-label"
          style={{
            margin: 0,
            minWidth: '200px',
            textAlign: 'center',
            fontSize: '24px',
            fontWeight: 600,
          }}
        >
          {selectedMonthLabel}
        </h2>
        <button
          data-testid="month-next"
          onClick={goToNextMonth}
          disabled={isCurrentMonth()}
          style={{
            width: '40px',
            height: '40px',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            background: isCurrentMonth() ? theme.colors.bg.tertiary : theme.colors.bg.secondary,
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.md,
            color: isCurrentMonth() ? theme.colors.text.muted : theme.colors.text.primary,
            cursor: isCurrentMonth() ? 'not-allowed' : 'pointer',
            fontSize: '18px',
          }}
        >
          ›
        </button>
      </div>

      {/* Error */}
      {error && (
        <div
          style={{
            padding: theme.spacing.md,
            background: 'rgba(239, 68, 68, 0.1)',
            border: '1px solid rgba(239, 68, 68, 0.3)',
            borderRadius: theme.borderRadius.md,
            color: '#ef4444',
            marginBottom: theme.spacing.lg,
          }}
        >
          {error}
        </div>
      )}

      {/* Loading */}
      {loading && (
        <div style={{ textAlign: 'center', padding: theme.spacing.xl, color: theme.colors.text.secondary }}>
          {t('statistics.loadingStats')}
        </div>
      )}

      {/* Stats Content */}
      {!loading && stats && (
        <>
          {/* Summary Boxes */}
          <div
            data-testid="summary-boxes"
            style={{
              display: 'grid',
              gridTemplateColumns: isMobile ? '1fr' : 'repeat(3, 1fr)',
              gap: theme.spacing.lg,
              marginBottom: theme.spacing.xl,
            }}
          >
            {/* Total Revenue */}
            <div
              data-testid="box-total-revenue"
              style={{
                background: 'linear-gradient(135deg, #1e3a5f 0%, #0d1829 100%)',
                border: `1px solid ${theme.colors.border.light}`,
                borderRadius: theme.borderRadius.xl,
                padding: theme.spacing.xl,
                textAlign: 'center',
              }}
            >
              <div style={{ color: theme.colors.text.secondary, fontSize: theme.typography.fontSize.sm, marginBottom: theme.spacing.sm }}>
                {t('statistics.totalRevenue')}
              </div>
              <div
                data-testid="value-total-revenue"
                style={{
                  fontSize: '36px',
                  fontWeight: 700,
                  fontFamily: 'JetBrains Mono, monospace',
                  color: '#22c55e',
                  textShadow: '0 2px 10px rgba(34, 197, 94, 0.3)',
                }}
              >
                {formatters.formatPrice(stats.total_revenue_cents)}
              </div>
            </div>

            {/* Sold Items */}
            <div
              data-testid="box-sold-items"
              style={{
                background: 'linear-gradient(135deg, #1e3a5f 0%, #0d1829 100%)',
                border: `1px solid ${theme.colors.border.light}`,
                borderRadius: theme.borderRadius.xl,
                padding: theme.spacing.xl,
                textAlign: 'center',
              }}
            >
              <div style={{ color: theme.colors.text.secondary, fontSize: theme.typography.fontSize.sm, marginBottom: theme.spacing.sm }}>
                {t('statistics.soldItems')}
              </div>
              <div
                data-testid="value-sold-items"
                style={{
                  fontSize: '36px',
                  fontWeight: 700,
                  fontFamily: 'JetBrains Mono, monospace',
                  color: '#3b82f6',
                  textShadow: '0 2px 10px rgba(59, 130, 246, 0.3)',
                }}
              >
                {stats.total_sold_items.toLocaleString(i18n.language === 'de' ? 'de-DE' : 'en-US')}
              </div>
            </div>

            {/* Top Product */}
            <div
              data-testid="box-top-product"
              style={{
                background: 'linear-gradient(135deg, #1e3a5f 0%, #0d1829 100%)',
                border: `1px solid ${theme.colors.border.light}`,
                borderRadius: theme.borderRadius.xl,
                padding: theme.spacing.xl,
                textAlign: 'center',
              }}
            >
              <div style={{ color: theme.colors.text.secondary, fontSize: theme.typography.fontSize.sm, marginBottom: theme.spacing.sm }}>
                {t('statistics.topProduct')}
              </div>
              {stats.top_product ? (
                <>
                  <div
                    data-testid="value-top-product-name"
                    style={{
                      fontSize: '24px',
                      fontWeight: 700,
                      color: '#f59e0b',
                      textShadow: '0 2px 10px rgba(245, 158, 11, 0.3)',
                      marginBottom: theme.spacing.xs,
                    }}
                  >
                    {stats.top_product.name}
                  </div>
                  <div
                    data-testid="value-top-product-count"
                    style={{ color: theme.colors.text.secondary, fontSize: theme.typography.fontSize.sm }}
                  >
                    {stats.top_product.sold_count}{t('statistics.timesSold')}
                  </div>
                </>
              ) : (
                <div style={{ color: theme.colors.text.muted, fontSize: '18px' }}>—</div>
              )}
            </div>
          </div>

          {/* Revenue Chart */}
          <div
            data-testid="revenue-chart"
            style={{
              background: theme.colors.bg.card,
              border: `1px solid ${theme.colors.border.light}`,
              borderRadius: theme.borderRadius.lg,
              padding: theme.spacing.xl,
              marginBottom: theme.spacing.xl,
            }}
          >
            <h3 style={{ margin: 0, marginBottom: theme.spacing.lg }}>{t('statistics.revenuePerDay')}</h3>
            {stats.daily_revenue && stats.daily_revenue.length > 0 ? (
              <div
                style={{
                  display: 'flex',
                  alignItems: 'flex-end',
                  gap: '4px',
                  height: '200px',
                  padding: `0 ${theme.spacing.md}`,
                }}
              >
                {stats.daily_revenue.map((day, index) => {
                  const height = maxDailyRevenue > 0 ? (day.revenue_cents / maxDailyRevenue) * 180 : 0
                  return (
                    <div
                      key={day.date}
                      data-testid={`chart-bar-${index}`}
                      style={{
                        flex: 1,
                        display: 'flex',
                        flexDirection: 'column',
                        alignItems: 'center',
                        gap: '4px',
                      }}
                    >
                      <div
                        title={`${formatters.formatPrice(day.revenue_cents)} (${day.transaction_count} ${t('statistics.transactions')})`}
                        style={{
                          width: '100%',
                          maxWidth: '30px',
                          height: `${Math.max(height, 2)}px`,
                          background: 'linear-gradient(180deg, #3b82f6 0%, #1d4ed8 100%)',
                          borderRadius: '4px 4px 0 0',
                          cursor: 'pointer',
                          transition: 'all 0.2s',
                        }}
                        onMouseEnter={(e) => {
                          e.currentTarget.style.background = 'linear-gradient(180deg, #60a5fa 0%, #3b82f6 100%)'
                        }}
                        onMouseLeave={(e) => {
                          e.currentTarget.style.background = 'linear-gradient(180deg, #3b82f6 0%, #1d4ed8 100%)'
                        }}
                      />
                      <span style={{ fontSize: '10px', color: theme.colors.text.muted }}>
                        {formatDay(day.date)}
                      </span>
                    </div>
                  )
                })}
              </div>
            ) : (
              <div style={{ textAlign: 'center', color: theme.colors.text.muted, padding: theme.spacing.xl }}>
                {t('statistics.noDataForMonth')}
              </div>
            )}
          </div>

          {/* Two-column layout for rankings */}
          <div
            style={{
              display: 'grid',
              gridTemplateColumns: isMobile ? '1fr' : 'repeat(2, 1fr)',
              gap: theme.spacing.xl,
            }}
          >
            {/* Top 10 Products */}
            <div
              data-testid="top-products"
              style={{
                background: theme.colors.bg.card,
                border: `1px solid ${theme.colors.border.light}`,
                borderRadius: theme.borderRadius.lg,
                padding: theme.spacing.xl,
              }}
            >
              <h3 style={{ margin: 0, marginBottom: theme.spacing.lg }}>{t('statistics.topProducts')}</h3>
              {stats.top_products && stats.top_products.length > 0 ? (
                <table style={tableElementStyles}>
                  <thead>
                    <tr style={headerRowStyle}>
                      <th style={{ ...headerCellBaseStyle, width: '40px' }}>#</th>
                      <th style={headerCellBaseStyle}>{t('statistics.product')}</th>
                      <th style={{ ...headerCellBaseStyle, textAlign: 'right' }}>{t('statistics.sales')}</th>
                      <th style={{ ...headerCellBaseStyle, textAlign: 'right' }}>{t('statistics.revenue')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {stats.top_products.slice(0, 10).map((product, index) => (
                      <tr
                        key={product.id}
                        data-testid={`product-row-${index}`}
                        style={{
                          borderBottom: `1px solid ${tableColors.rowActiveBorder}`,
                          color: tableColors.cellText,
                        }}
                      >
                        <td style={{ padding: tableSpacing.cellPadding, color: theme.colors.text.muted }}>
                          {index + 1}
                        </td>
                        <td style={{ padding: tableSpacing.cellPadding, fontWeight: index < 3 ? 600 : 400 }}>
                          {product.name}
                        </td>
                        <td style={{ padding: tableSpacing.cellPadding, textAlign: 'right' }}>
                          {product.sold_count.toLocaleString(i18n.language === 'de' ? 'de-DE' : 'en-US')}
                        </td>
                        <td style={{ padding: tableSpacing.cellPadding, textAlign: 'right', fontWeight: 500, fontFamily: 'JetBrains Mono, monospace' }}>
                          {formatters.formatPrice(product.revenue_cents)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              ) : (
                <div style={{ textAlign: 'center', color: theme.colors.text.muted, padding: theme.spacing.lg }}>
                  {t('statistics.noProductData')}
                </div>
              )}
            </div>

            {/* Top 10 Members */}
            <div
              data-testid="top-members"
              style={{
                background: theme.colors.bg.card,
                border: `1px solid ${theme.colors.border.light}`,
                borderRadius: theme.borderRadius.lg,
                padding: theme.spacing.xl,
              }}
            >
              <h3 style={{ margin: 0, marginBottom: theme.spacing.lg }}>{t('statistics.topMembers')}</h3>
              {stats.top_members && stats.top_members.length > 0 ? (
                <table style={tableElementStyles}>
                  <thead>
                    <tr style={headerRowStyle}>
                      <th style={{ ...headerCellBaseStyle, width: '40px' }}>#</th>
                      <th style={headerCellBaseStyle}>{t('statistics.member')}</th>
                      <th style={{ ...headerCellBaseStyle, textAlign: 'right' }}>{t('statistics.purchases')}</th>
                      <th style={{ ...headerCellBaseStyle, textAlign: 'right' }}>{t('statistics.revenue')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {stats.top_members.slice(0, 10).map((member, index) => (
                      <tr
                        key={member.id}
                        data-testid={`member-row-${index}`}
                        style={{
                          borderBottom: `1px solid ${tableColors.rowActiveBorder}`,
                          color: tableColors.cellText,
                        }}
                      >
                        <td style={{ padding: tableSpacing.cellPadding, color: theme.colors.text.muted }}>
                          {index + 1}
                        </td>
                        <td style={{ padding: tableSpacing.cellPadding, fontWeight: index < 3 ? 600 : 400 }}>
                          {member.name}
                        </td>
                        <td style={{ padding: tableSpacing.cellPadding, textAlign: 'right' }}>
                          {member.purchase_count.toLocaleString(i18n.language === 'de' ? 'de-DE' : 'en-US')}
                        </td>
                        <td style={{ padding: tableSpacing.cellPadding, textAlign: 'right', fontWeight: 500, fontFamily: 'JetBrains Mono, monospace' }}>
                          {formatters.formatPrice(member.revenue_cents)}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              ) : (
                <div style={{ textAlign: 'center', color: theme.colors.text.muted, padding: theme.spacing.lg }}>
                  {t('statistics.noMemberData')}
                </div>
              )}
            </div>
          </div>
        </>
      )}
    </div>
  )
}
