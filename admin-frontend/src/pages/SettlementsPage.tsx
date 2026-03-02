/**
 * Settlements Page
 * Settlement history viewing and management
 *
 * Implements:
 * - UC-A33: Settlement History (list view with table, filtering, sorting, pagination)
 * - Bulk actions: Export SEPA, Export CSV, Export Transactions, Undo Settlement
 *
 * Workflow:
 * Single page list view with filtering, sorting, and pagination.
 * All actions (exports, undo) accessible directly from list table.
 *
 * Pattern: Table implementation with filtering, sorting, and pagination
 * Uses TDD with E2E tests in e2etests/tests/admin/settlements.spec.ts
 *
 * Note: Settlement creation is handled in the Journal page (UC-A30, UC-A35).
 * Settlement details view was removed - no additional value beyond list view.
 */

import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../styles/design-system'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { MobileToolbar } from '../components/layout/MobileToolbar'
import { ConfirmDialog } from '../components/modals/ConfirmDialog'
import { useFormatters } from '../hooks/useFormatters'
import { PeriodPicker } from '../components/forms/PeriodPicker'
import { StatusFilter } from '../components/forms/StatusFilter'
import { PaginationToolbar } from '../components/tables/PaginationToolbar'
import {
  tableWrapperStyles,
  tableElementStyles,
  headerRowStyle,
  headerCellBaseStyle,
  tableColors,
  tableSpacing,
  getRowStyle,
} from '../styles/tableTokens'
import {
  getSettlements,
  getSettlementStatus,
  undoSettlement,
  downloadTransactionsCsv,
  Settlement,
} from '../services/settlements'


/**
 * Format a transaction date range for display.
 * Abbreviates the first date's year when both dates share the same year.
 * Examples: "15.01. – 28.02.2026" (same year), "15.12.2025 – 03.01.2026" (different year)
 */
function formatDateRange(minStr: string | null, maxStr: string | null): string | null {
  if (!minStr || !maxStr) return null
  const min = new Date(minStr)
  const max = new Date(maxStr)
  if (isNaN(min.getTime()) || isNaN(max.getTime())) return null

  const pad = (n: number) => String(n).padStart(2, '0')
  const dMin = pad(min.getDate())
  const mMin = pad(min.getMonth() + 1)
  const yMin = min.getFullYear()
  const dMax = pad(max.getDate())
  const mMax = pad(max.getMonth() + 1)
  const yMax = max.getFullYear()

  if (yMin === yMax) {
    return `${dMin}.${mMin}. – ${dMax}.${mMax}.${yMax}`
  }
  return `${dMin}.${mMin}.${yMin} – ${dMax}.${mMax}.${yMax}`
}

const defaultPageSize = 20

export function SettlementsPage() {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const breakpoint = useBreakpoint()
  const [settlements, setSettlements] = useState<Settlement[]>([])
  const [totalItems, setTotalItems] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  // Pagination
  const [currentPage, setCurrentPage] = useState(1)
  const [pageSize, setPageSize] = useState(defaultPageSize)

  // Filters
  const [period, setPeriod] = useState('3m')
  const [dateFrom, setDateFrom] = useState<string | undefined>(undefined)
  const [dateTo, setDateTo] = useState<string | undefined>(undefined)
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'cancelled'>('all')

  // Sorting
  const [sortKey, setSortKey] = useState<'created_at' | 'created_by'>('created_at')
  const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>('desc')

  // Undo confirmation dialog
  const [undoConfirm, setUndoConfirm] = useState<string | null>(null)

  // Mobile responsive
  const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'
  const [showMobileFilters, setShowMobileFilters] = useState(false)

  const mobileFilterCount = [
    period !== '3m' ? 1 : 0,
    statusFilter !== 'all' ? 1 : 0,
  ].reduce((a, b) => a + b, 0)

  const mobileSortOptions = [
    { value: 'created_at_desc', label: t('settlements.sortNewest', 'Newest first'), direction: 'desc' as const },
    { value: 'created_at_asc', label: t('settlements.sortOldest', 'Oldest first'), direction: 'asc' as const },
  ]

  const mobileSortValue = `${sortKey}_${sortOrder}`

  const handleMobileSortChange = (value: string) => {
    const lastUnderscore = value.lastIndexOf('_')
    const key = value.substring(0, lastUnderscore) as typeof sortKey
    const dir = value.substring(lastUnderscore + 1) as 'asc' | 'desc'
    setSortKey(key)
    setSortOrder(dir)
    setCurrentPage(1)
  }

  // Load settlements when filters, sorting, or pagination changes
  useEffect(() => {
    loadSettlements()
  }, [currentPage, pageSize, dateFrom, dateTo, statusFilter, sortKey, sortOrder])

  const loadSettlements = async () => {
    try {
      setLoading(true)
      setError(null)

      const response = await getSettlements(
        currentPage,
        pageSize,
        undefined,
        dateFrom,
        dateTo,
        statusFilter,
        sortKey,
        sortOrder
      )

      setSettlements(response.data)
      setTotalItems(response.pagination.total)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load settlements')
      setSettlements([])
      setTotalItems(0)
    } finally {
      setLoading(false)
    }
  }


  // Handle period change from PeriodPicker
  const handlePeriodChange = (from: string | undefined, to: string | undefined, periodKey: string) => {
    setPeriod(periodKey)
    setDateFrom(from)
    setDateTo(to)
    setCurrentPage(1)
  }

  const handleExportSepa = async (settlementId: string) => {
    try {
      // Trigger SEPA XML download (backend marks settlement as exported)
      const url = `/api/admin/settlements/${settlementId}/export-sepa`
      window.open(url, '_blank')
      // Reload list after short delay so status updates to "Exported"
      setTimeout(() => loadSettlements(), 1000)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to export SEPA XML')
    }
  }

  const handleExportCsv = async (settlementId: string) => {
    try {
      // Trigger CSV download
      const url = `/api/admin/settlements/${settlementId}/export-csv`
      window.open(url, '_blank')
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to export CSV')
    }
  }

  const handleExportTransactionsCsv = (settlementId: string) => {
    try {
      downloadTransactionsCsv(settlementId)
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to export transactions CSV')
    }
  }

  const handleUndoSettlement = (settlementId: string) => {
    setUndoConfirm(settlementId)
  }

  const handleUndoSettlementConfirmed = async () => {
    if (!undoConfirm) return
    const settlementId = undoConfirm
    setUndoConfirm(null)
    try {
      setLoading(true)
      setError(null)
      await undoSettlement(settlementId)
      await loadSettlements()
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to undo settlement')
    } finally {
      setLoading(false)
    }
  }

  // Calculate total pages
  const totalPages = Math.ceil(totalItems / pageSize)

  const status = (settlement: Settlement) => getSettlementStatus(settlement as any)

    return (
      <div data-testid="settlements-page">
        <h1 style={{ margin: '0 0 20px 0' }}>{t('settlements.title')}</h1>

          {/* Error state */}
          {error && (
            <div
              data-testid="settlements-error-message"
              style={{
                padding: tableSpacing.cellPadding,
                backgroundColor: '#7f1d1d',
                color: '#fca5a5',
                borderRadius: 6,
                margin: tableSpacing.cellPadding,
              }}
            >
              Error: {error}
            </div>
          )}

        {isMobile ? (
          <>
            <MobileToolbar
              testId="settlements-mobile-toolbar"
              sort={{
                options: mobileSortOptions,
                value: mobileSortValue,
                onChange: handleMobileSortChange,
              }}
              filterCount={mobileFilterCount}
              onFilterToggle={() => setShowMobileFilters(!showMobileFilters)}
              showFilters={showMobileFilters}
              filterContent={
                <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
                  <PeriodPicker
                    value={period}
                    onPeriodChange={handlePeriodChange}
                    testId="settlements-period-picker"
                  />
                  <StatusFilter
                    value={statusFilter}
                    onChange={(newStatus) => {
                      setStatusFilter(newStatus)
                      setCurrentPage(1)
                    }}
                    testId="settlements-status-filter"
                  />
                </div>
              }
            />

            {/* Mobile card list */}
            {loading ? (
              <div style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
                {t('common.loading')}
              </div>
            ) : settlements.length === 0 ? (
              <div data-testid="settlements-empty-state" style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
                {t('settlements.noSettlements')}
              </div>
            ) : (
              <div data-testid="settlements-mobile-cards" style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                {settlements.map((settlement) => (
                  <div
                    key={settlement.id}
                    data-testid={`settlement-card-${settlement.id}`}
                    style={{
                      background: 'rgba(255,255,255,0.03)',
                      border: '1px solid rgba(255,255,255,0.06)',
                      borderRadius: '10px',
                      padding: '14px 16px',
                    }}
                  >
                    {/* Row 1: date + status badge */}
                    <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '6px' }}>
                      <span style={{ fontWeight: 600, color: theme.colors.text.primary, fontSize: '14px' }}>
                        {formatters.formatDate(settlement.created_at)}
                      </span>
                      <span
                        data-testid={`settlements-badge-status-${settlement.id}`}
                        style={{
                          padding: '3px 8px',
                          borderRadius: 4,
                          fontSize: 11,
                          fontWeight: 500,
                          backgroundColor: settlement.is_cancelled ? '#ef4444' : '#10b981',
                          color: '#ffffff',
                        }}
                      >
                        {settlement.is_cancelled ? t('settlements.cancelled') : t('settlements.active')}
                      </span>
                    </div>

                    {/* Row 2: admin user */}
                    <div style={{ fontSize: '12px', color: theme.colors.text.secondary, marginBottom: '4px' }}>
                      {settlement.created_by_admin_name || '\u2014'}
                    </div>

                    {/* Row 3: summary */}
                    <div style={{ fontSize: '12px', color: theme.colors.text.muted, marginBottom: '8px' }}>
                      {settlement.member_count} {t('settlements.memberCount')} &middot; {settlement.transaction_count} {t('settlements.transactionCount')}
                    </div>

                    {/* Row 4: total amount */}
                    <div
                      data-testid={`settlements-price-${settlement.id}`}
                      style={{
                        fontWeight: 700,
                        fontFamily: 'JetBrains Mono, monospace',
                        fontSize: '18px',
                        color: theme.colors.text.primary,
                        marginBottom: '10px',
                      }}
                    >
                      {formatters.formatPrice(settlement.total_amount_cents)}
                    </div>

                    {/* Row 5: action buttons */}
                    <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap' }}>
                      <button
                        data-testid={`settlements-export-sepa-btn-${settlement.id}`}
                        onClick={() => handleExportSepa(settlement.id)}
                        disabled={settlement.is_cancelled}
                        style={{
                          padding: '5px 10px',
                          backgroundColor: settlement.is_cancelled ? '#6b7280' : '#3b82f6',
                          color: '#ffffff',
                          border: 'none',
                          borderRadius: 4,
                          fontSize: 11,
                          fontWeight: 500,
                          cursor: settlement.is_cancelled ? 'not-allowed' : 'pointer',
                        }}
                      >
                        SEPA
                      </button>
                      <button
                        data-testid={`settlements-export-csv-btn-${settlement.id}`}
                        onClick={() => handleExportCsv(settlement.id)}
                        disabled={settlement.is_cancelled}
                        style={{
                          padding: '5px 10px',
                          backgroundColor: settlement.is_cancelled ? '#6b7280' : '#10b981',
                          color: '#ffffff',
                          border: 'none',
                          borderRadius: 4,
                          fontSize: 11,
                          fontWeight: 500,
                          cursor: settlement.is_cancelled ? 'not-allowed' : 'pointer',
                        }}
                      >
                        CSV
                      </button>
                      <button
                        data-testid={`settlements-export-transactions-btn-${settlement.id}`}
                        onClick={() => handleExportTransactionsCsv(settlement.id)}
                        disabled={settlement.is_cancelled}
                        style={{
                          padding: '5px 10px',
                          backgroundColor: settlement.is_cancelled ? '#6b7280' : '#8b5cf6',
                          color: '#ffffff',
                          border: 'none',
                          borderRadius: 4,
                          fontSize: 11,
                          fontWeight: 500,
                          cursor: settlement.is_cancelled ? 'not-allowed' : 'pointer',
                        }}
                      >
                        TXN
                      </button>
                      <button
                        data-testid={`settlements-undo-btn-${settlement.id}`}
                        onClick={() => handleUndoSettlement(settlement.id)}
                        disabled={settlement.is_cancelled}
                        style={{
                          padding: '5px 10px',
                          backgroundColor: settlement.is_cancelled ? '#6b7280' : '#ef4444',
                          color: '#ffffff',
                          border: 'none',
                          borderRadius: 4,
                          fontSize: 11,
                          fontWeight: 500,
                          cursor: settlement.is_cancelled ? 'not-allowed' : 'pointer',
                        }}
                      >
                        {t('common.undo')}
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            )}

            {/* Pagination (mobile) */}
            {!loading && settlements.length > 0 && (
              <PaginationToolbar
                currentPage={currentPage}
                totalPages={totalPages}
                totalItems={totalItems}
                pageSize={pageSize}
                onPageChange={setCurrentPage}
                onPageSizeChange={() => {}}
                showPageSize={false}
                showInfo={true}
                testId="settlements"
              />
            )}
          </>
        ) : (
          <>
          {/* Desktop Toolbar */}
          <div
            data-testid="settlements-toolbar"
            style={{
              padding: tableSpacing.cellPadding,
              borderBottom: `1px solid ${tableColors.rowActiveBorder}`,
              display: 'flex',
              flexWrap: 'wrap',
              gap: tableSpacing.actionButtonGap,
              alignItems: 'center',
              justifyContent: 'space-between',
            }}
          >
            {/* Left: Count summary */}
            <div
              data-testid="settlements-count-summary"
              style={{
                fontSize: 14,
                color: tableColors.cellSecondaryText,
              }}
            >
              {totalItems} {t('settlements.title')} {t('common.found')}
            </div>

            {/* Right: Period picker + Status filter */}
            <div style={{ display: 'flex', gap: tableSpacing.actionButtonGap, alignItems: 'center' }}>
              <PeriodPicker
                value={period}
                onPeriodChange={handlePeriodChange}
                testId="settlements-period-picker"
              />

              <StatusFilter
                value={statusFilter}
                onChange={(newStatus) => {
                  setStatusFilter(newStatus)
                  setCurrentPage(1)
                }}
                testId="settlements-status-filter"
              />
            </div>
          </div>

          {/* Loading state */}
          {loading ? (
            <div
              data-testid="settlements-loading"
              style={{
                padding: tableSpacing.cellPadding,
                textAlign: 'center',
                color: tableColors.cellSecondaryText,
              }}
            >
              {t('common.loading')}
            </div>
          ) : settlements.length === 0 ? (
            /* Empty state */
            <div
              data-testid="settlements-empty-state"
              style={{
                padding: tableSpacing.cellPadding,
                textAlign: 'center',
                color: tableColors.cellSecondaryText,
              }}
            >
              {t('settlements.noSettlements')}
            </div>
          ) : (
            /* Table */
            <>
              <div data-testid="settlements-table-wrapper" style={tableWrapperStyles}>
                <table
                  data-testid="settlements-table"
                  style={tableElementStyles}
                >
                  <thead>
                    <tr style={headerRowStyle}>
                      <th
                        style={{
                          ...headerCellBaseStyle,
                          cursor: 'pointer',
                          userSelect: 'none',
                        }}
                        onClick={() => {
                          if (sortKey === 'created_at') {
                            setSortOrder(sortOrder === 'asc' ? 'desc' : 'asc')
                          } else {
                            setSortKey('created_at')
                            setSortOrder('desc')
                          }
                        }}
                        title="Click to sort by date"
                        data-testid="settlements-header-date"
                      >
                        {t('common.date')} {sortKey === 'created_at' && (sortOrder === 'asc' ? '↑' : '↓')}
                      </th>
                      <th
                        style={{
                          ...headerCellBaseStyle,
                          cursor: 'pointer',
                          userSelect: 'none',
                        }}
                        onClick={() => {
                          if (sortKey === 'created_by') {
                            setSortOrder(sortOrder === 'asc' ? 'desc' : 'asc')
                          } else {
                            setSortKey('created_by')
                            setSortOrder('asc')
                          }
                        }}
                        title="Click to sort by created by"
                        data-testid="settlements-header-created-by"
                      >
                        {t('settlements.createdBy')} {sortKey === 'created_by' && (sortOrder === 'asc' ? '↑' : '↓')}
                      </th>
                      <th style={{ ...headerCellBaseStyle, textAlign: 'right' }}>{t('settlements.summary')}</th>
                      <th style={{ ...headerCellBaseStyle, textAlign: 'right' }}>{t('common.amount')}</th>
                      <th style={headerCellBaseStyle}>{t('settlements.status')}</th>
                      <th style={{ ...headerCellBaseStyle, textAlign: 'center' }}>{t('common.actions')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {settlements.map((settlement) => (
                      <tr
                        key={settlement.id}
                        data-testid={`settlements-table-row-${settlement.id}`}
                        style={getRowStyle(!settlement.is_cancelled)}
                        onMouseEnter={(e: React.MouseEvent<HTMLTableRowElement>) => {
                          if (!settlement.is_cancelled) {
                            e.currentTarget.style.backgroundColor = tableColors.rowActiveHoverBg
                          }
                        }}
                        onMouseLeave={(e: React.MouseEvent<HTMLTableRowElement>) => {
                          e.currentTarget.style.backgroundColor = settlement.is_cancelled
                            ? tableColors.rowInactiveBg
                            : tableColors.rowActiveBg
                        }}
                      >
                        {/* Date */}
                        <td
                          data-testid={`settlements-table-cell-date-${settlement.id}`}
                          style={{
                            padding: tableSpacing.cellPadding,
                            color: tableColors.cellText,
                          }}
                        >
                          <div>{formatters.formatDate(settlement.created_at)}</div>
                          {formatDateRange(settlement.transaction_date_min, settlement.transaction_date_max) && (
                            <div style={{ fontSize: 12, color: tableColors.cellSecondaryText }}>
                              {formatDateRange(settlement.transaction_date_min, settlement.transaction_date_max)}
                            </div>
                          )}
                        </td>

                        {/* Created By */}
                        <td
                          data-testid={`settlements-table-cell-created-by-${settlement.id}`}
                          style={{
                            padding: tableSpacing.cellPadding,
                            color: tableColors.cellText,
                          }}
                        >
                          {settlement.created_by_admin_name || '—'}
                        </td>

                        {/* Summary */}
                        <td
                          data-testid={`settlements-table-cell-members-${settlement.id}`}
                          style={{
                            padding: tableSpacing.cellPadding,
                            color: tableColors.cellText,
                            textAlign: 'right',
                          }}
                        >
                          <div data-testid={`settlements-member-count-${settlement.id}`}>
                            {settlement.member_count} {t('settlements.memberCount')}
                          </div>
                          <div data-testid={`settlements-transaction-count-${settlement.id}`} style={{ fontSize: 12, color: tableColors.cellSecondaryText }}>
                            {settlement.transaction_count} {t('settlements.transactionCount')}
                          </div>
                        </td>

                        {/* Amount */}
                        <td
                          data-testid={`settlements-table-cell-amount-${settlement.id}`}
                          style={{
                            padding: tableSpacing.cellPadding,
                            color: tableColors.cellText,
                            fontWeight: 700,
                            fontFamily: 'JetBrains Mono, monospace',
                            fontSize: '14px',
                            textAlign: 'right',
                          }}
                        >
                          <span data-testid={`settlements-price-${settlement.id}`}>
                            {formatters.formatPrice(settlement.total_amount_cents)}
                          </span>
                        </td>

                        {/* Status */}
                        <td
                          data-testid={`settlements-table-cell-status-${settlement.id}`}
                          style={{
                            padding: tableSpacing.cellPadding,
                          }}
                        >
                          <span
                            data-testid={`settlements-badge-status-${settlement.id}`}
                            style={{
                              padding: '4px 8px',
                              borderRadius: 4,
                              fontSize: 12,
                              fontWeight: 500,
                              backgroundColor:
                                status(settlement) === 'exported' ? '#10b981' :
                                status(settlement) === 'cancelled' ? '#ef4444' :
                                '#3b82f6',
                              color: '#ffffff',
                            }}
                          >
                            {status(settlement) === 'exported' ? t('settlements.exported') :
                             status(settlement) === 'cancelled' ? t('settlements.cancelled') :
                             t('settlements.active')}
                          </span>
                        </td>

                        {/* Actions */}
                        <td
                          data-testid={`settlements-table-cell-actions-${settlement.id}`}
                          style={{
                            padding: tableSpacing.cellPadding,
                            textAlign: 'center',
                          }}
                        >
                          <div style={{ display: 'flex', gap: 8, justifyContent: 'center' }}>
                            {/* Export SEPA XML */}
                            <button
                              data-testid={`settlements-export-sepa-btn-${settlement.id}`}
                              onClick={() => handleExportSepa(settlement.id)}
                              disabled={settlement.is_cancelled}
                              style={{
                                padding: '4px 8px',
                                backgroundColor: settlement.is_cancelled ? '#6b7280' : '#3b82f6',
                                color: '#ffffff',
                                border: 'none',
                                borderRadius: 4,
                                fontSize: 12,
                                fontWeight: 500,
                                cursor: settlement.is_cancelled ? 'not-allowed' : 'pointer',
                                transition: 'background-color 0.15s',
                              }}
                              onMouseEnter={(e) => {
                                if (!settlement.is_cancelled) {
                                  e.currentTarget.style.backgroundColor = '#2563eb'
                                }
                              }}
                              onMouseLeave={(e) => {
                                if (!settlement.is_cancelled) {
                                  e.currentTarget.style.backgroundColor = '#3b82f6'
                                }
                              }}
                              title="Export SEPA XML"
                            >
                              SEPA
                            </button>

                            {/* Export CSV (aggregated) */}
                            <button
                              data-testid={`settlements-export-csv-btn-${settlement.id}`}
                              onClick={() => handleExportCsv(settlement.id)}
                              disabled={settlement.is_cancelled}
                              style={{
                                padding: '4px 8px',
                                backgroundColor: settlement.is_cancelled ? '#6b7280' : '#10b981',
                                color: '#ffffff',
                                border: 'none',
                                borderRadius: 4,
                                fontSize: 12,
                                fontWeight: 500,
                                cursor: settlement.is_cancelled ? 'not-allowed' : 'pointer',
                                transition: 'background-color 0.15s',
                              }}
                              onMouseEnter={(e) => {
                                if (!settlement.is_cancelled) {
                                  e.currentTarget.style.backgroundColor = '#059669'
                                }
                              }}
                              onMouseLeave={(e) => {
                                if (!settlement.is_cancelled) {
                                  e.currentTarget.style.backgroundColor = '#10b981'
                                }
                              }}
                              title="Export CSV (aggregated by member)"
                            >
                              CSV
                            </button>

                            {/* Export Transactions CSV (detailed) */}
                            <button
                              data-testid={`settlements-export-transactions-btn-${settlement.id}`}
                              onClick={() => handleExportTransactionsCsv(settlement.id)}
                              disabled={settlement.is_cancelled}
                              style={{
                                padding: '4px 8px',
                                backgroundColor: settlement.is_cancelled ? '#6b7280' : '#8b5cf6',
                                color: '#ffffff',
                                border: 'none',
                                borderRadius: 4,
                                fontSize: 12,
                                fontWeight: 500,
                                cursor: settlement.is_cancelled ? 'not-allowed' : 'pointer',
                                transition: 'background-color 0.15s',
                              }}
                              onMouseEnter={(e) => {
                                if (!settlement.is_cancelled) {
                                  e.currentTarget.style.backgroundColor = '#7c3aed'
                                }
                              }}
                              onMouseLeave={(e) => {
                                if (!settlement.is_cancelled) {
                                  e.currentTarget.style.backgroundColor = '#8b5cf6'
                                }
                              }}
                              title="Export detailed transactions CSV"
                            >
                              TXN
                            </button>

                            {/* Undo Settlement */}
                            <button
                              data-testid={`settlements-undo-btn-${settlement.id}`}
                              onClick={() => handleUndoSettlement(settlement.id)}
                              disabled={settlement.is_cancelled}
                              style={{
                                padding: '4px 8px',
                                backgroundColor:
                                  settlement.is_cancelled
                                    ? '#6b7280'
                                    : '#ef4444',
                                color: '#ffffff',
                                border: 'none',
                                borderRadius: 4,
                                fontSize: 12,
                                fontWeight: 500,
                                cursor:
                                  settlement.is_cancelled
                                    ? 'not-allowed'
                                    : 'pointer',
                                transition: 'background-color 0.15s',
                              }}
                              onMouseEnter={(e) => {
                                if (!settlement.is_cancelled) {
                                  e.currentTarget.style.backgroundColor = '#dc2626'
                                }
                              }}
                              onMouseLeave={(e) => {
                                if (!settlement.is_cancelled) {
                                  e.currentTarget.style.backgroundColor = '#ef4444'
                                }
                              }}
                              title={t('common.undo')}
                            >
                              {t('common.undo')}
                            </button>
                          </div>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              {/* Pagination */}
              {totalItems > 0 && (
                <PaginationToolbar
                  data-testid="settlements-pagination"
                  currentPage={currentPage}
                  totalPages={totalPages}
                  totalItems={totalItems}
                  pageSize={pageSize}
                  onPageChange={setCurrentPage}
                  onPageSizeChange={(size) => {
                    setPageSize(size)
                    setCurrentPage(1)
                  }}
                  testId="settlements"
                  showInfo={true}
                  showPageSize={true}
                />
              )}
            </>
          )}
          </>
        )}

      <ConfirmDialog
        isOpen={!!undoConfirm}
        message={t('settlements.undoConfirm')}
        confirmLabel={t('common.undo')}
        variant="danger"
        onConfirm={handleUndoSettlementConfirmed}
        onCancel={() => setUndoConfirm(null)}
      />
      </div>
    )
}
