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
 * Note: Settlement creation lives on the New Settlement screen this page links
 * to (UC-A30, ADR-0030). It used to be a transaction picker in the Journal,
 * which described something the system stopped doing when a run began sweeping
 * whole member positions.
 * Settlement details view was removed - no additional value beyond list view.
 */

import { useCallback, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import axios from 'axios'
import { theme } from '../styles/design-system'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { MobileToolbar } from '../components/layout/MobileToolbar'
import { UndoSettlementDialog } from '../components/modals/UndoSettlementDialog'
import { useFormatters } from '../hooks/useFormatters'
import { PeriodPicker } from '../components/forms/PeriodPicker'
import { PillFilter, type PillFilterOption } from '../components/forms/PillFilter'
import { PaginationToolbar } from '../components/tables/PaginationToolbar'
import { useListQuery } from '../hooks/useListQuery'
import { downloadBlob, downloadFile } from '../api/client'
import { DEFAULT_PERIOD, getPeriodRange, type PeriodKey } from '../utils/periods'
import {
  tableWrapperStyles,
  tableElementStyles,
  headerRowStyle,
  headerCellBaseStyle,
  tableColors,
  tableSpacing,
  getRowStyle,
} from '../styles/tableTokens'
import { getSettlements as getSettlementsFactory } from '../api/generated/settlements/settlements'
import type { SettlementListItem, ListSettlementsParams } from '../api/generated'


/**
 * Extended settlement list item — includes runtime fields returned by the backend
 * that are not yet declared in the generated SettlementListItem schema.
 */
interface SettlementListItemExtended extends SettlementListItem {
  transaction_count?: number
  transaction_date_min?: string | null
  transaction_date_max?: string | null
  created_by_admin_name?: string | null
  created_by_admin_id?: string | null
}

/**
 * Derive settlement status from fields
 */
function getSettlementStatus(settlement: SettlementListItemExtended): 'active' | 'cancelled' | 'exported' {
  if (settlement.is_cancelled) return 'cancelled'
  if (settlement.exported_at !== null && settlement.exported_at !== undefined) return 'exported'
  return 'active'
}

/**
 * The Undo button's colour. Red says "this undoes a live run"; the muted stone
 * says "the gate is shut" — the button still opens the dialog, which states
 * the backend's reason, but it is not dressed as a destructive action (#127).
 */
function undoButtonColor(settlement: SettlementListItemExtended): string {
  if (settlement.is_cancelled) return '#6b7280'
  return settlement.is_cancellable === false ? '#78716c' : '#ef4444'
}

function undoButtonHoverColor(settlement: SettlementListItemExtended): string {
  return settlement.is_cancellable === false ? '#57534e' : '#dc2626'
}

/**
 * Format a transaction date range for display.
 * Abbreviates the first date's year when both dates share the same year.
 * Examples: "15.01. – 28.02.2026" (same year), "15.12.2025 – 03.01.2026" (different year)
 */
function formatDateRange(minStr: string | null | undefined, maxStr: string | null | undefined): string | null {
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

type SettlementSortKey = 'created_at' | 'created_by'

interface SettlementFilters {
  period: PeriodKey
  dateFrom: string | undefined
  dateTo: string | undefined
  status: 'all' | 'active' | 'cancelled'
}

export function SettlementsPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const formatters = useFormatters()
  const breakpoint = useBreakpoint()
  // Pagination, filters and sorting share the list-query state (#121). The
  // initial range is derived from the default preset here rather than announced
  // by the PeriodPicker from an effect — that effect re-fired on every render
  // and reset the page, so paging was impossible (#89).
  const list = useListQuery<SettlementListItemExtended, SettlementFilters, SettlementSortKey>({
    initialFilters: {
      period: DEFAULT_PERIOD,
      dateFrom: getPeriodRange(DEFAULT_PERIOD).dateFrom,
      dateTo: getPeriodRange(DEFAULT_PERIOD).dateTo,
      status: 'all',
    },
    initialSortKey: 'created_at',
    initialSortDirection: 'desc',
    initialPageSize: defaultPageSize,
    fetcher: async ({ page, pageSize, sortKey, sortDirection, filters, signal }) => {
      // Both headers are real sorts now (#120). The key used to be dropped here
      // because the repository ignored it, so clicking "Created By" moved the
      // arrow onto a column the order had nothing to do with.
      const params: ListSettlementsParams = {
        page,
        per_page: pageSize,
        sort: sortKey,
        order: sortDirection,
      }

      if (filters.dateFrom) params.date_from = filters.dateFrom
      if (filters.dateTo) params.date_to = filters.dateTo
      if (filters.status !== 'all') params.status = filters.status

      const response = await getSettlementsFactory().listSettlements(params, { signal })
      return {
        items: (response.data ?? []) as SettlementListItemExtended[],
        total: response.pagination?.total ?? 0,
      }
    },
    parseError: (err) =>
      axios.isAxiosError(err)
        ? (err.response?.data?.message ?? err.message)
        : err instanceof Error
          ? err.message
          : t('settlements.errors.load'),
  })

  const { items: settlements, total: totalItems, totalPages, loading, error, setError } = list
  const { period, status: statusFilter } = list.filters
  const sortKey = list.sortKey
  const sortOrder = list.sortDirection

  // The settlement the undo dialog is asking about — the whole row, not its
  // id: the dialog states date, amount and member count before undoing (#127).
  const [undoTarget, setUndoTarget] = useState<SettlementListItemExtended | null>(null)

  // What the last SEPA export could not collect (#114). Not an error — the
  // file downloaded and is valid — but the treasurer has to be told it asks
  // the bank for less than the settlement records.
  const [exportWarning, setExportWarning] = useState<string | null>(null)

  // Mobile responsive
  const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'
  const [showMobileFilters, setShowMobileFilters] = useState(false)

  const mobileFilterCount = [
    period !== DEFAULT_PERIOD ? 1 : 0,
    statusFilter !== 'all' ? 1 : 0,
  ].reduce((a, b) => a + b, 0)

  const statusOptions: ReadonlyArray<PillFilterOption<SettlementFilters['status']>> = [
    { value: 'all', label: t('common.all'), color: '#6b7280' },
    { value: 'active', label: t('settlements.active'), color: '#3b82f6' },
    { value: 'cancelled', label: t('settlements.cancelled'), color: '#ef4444' },
  ]

  const mobileSortOptions = [
    { value: 'created_at_desc', label: t('settlements.sortNewest'), direction: 'desc' as const },
    { value: 'created_at_asc', label: t('settlements.sortOldest'), direction: 'asc' as const },
  ]

  const mobileSortValue = list.sortValue

  // Handle period change from PeriodPicker. Memoized so the picker sees a
  // stable handler identity across renders (#89).
  const setFilters = list.setFilters
  const handlePeriodChange = useCallback(
    (from: string | undefined, to: string | undefined, periodKey: PeriodKey) => {
      setFilters({ period: periodKey, dateFrom: from, dateTo: to })
    },
    [setFilters]
  )

  const handleExportSepa = async (settlementId: string) => {
    try {
      setExportWarning(null)
      // Routed through downloadFile rather than the generated client because
      // the omissions ride on response headers, and the generated call returns
      // the blob alone (#114). The bank file itself cannot carry a warning.
      const headers = await downloadFile(
        `/admin/settlements/${settlementId}/export/sepa-xml`,
        `sepa-${settlementId}.xml`
      )

      const uncollectable = headers['x-uncollectable-members']
      if (uncollectable) {
        setExportWarning(
          t('settlements.exportShortfall', {
            count: uncollectable.split(',').length,
            shortfall: formatters.formatPrice(Number(headers['x-shortfall-amount-cents'] ?? 0)),
            collected: formatters.formatPrice(Number(headers['x-collected-amount-cents'] ?? 0)),
            total: formatters.formatPrice(Number(headers['x-settlement-amount-cents'] ?? 0)),
          })
        )
      }

      // Reload list so status updates to "Exported"
      await list.reload()
    } catch (err: unknown) {
      if (axios.isAxiosError(err)) {
        setError(err.response?.data?.message ?? err.message)
      } else if (err instanceof Error) {
        setError(err.message)
      } else {
        setError(t('settlements.errors.exportSepa'))
      }
    }
  }

  const handleExportCsv = async (settlementId: string) => {
    try {
      const blob = await getSettlementsFactory().downloadSettlementCsv(settlementId)
      downloadBlob(blob as unknown as Blob, `settlement-${settlementId}.csv`)
    } catch (err: unknown) {
      if (axios.isAxiosError(err)) {
        setError(err.response?.data?.message ?? err.message)
      } else if (err instanceof Error) {
        setError(err.message)
      } else {
        setError(t('settlements.errors.exportCsv'))
      }
    }
  }

  const handleExportTransactionsCsv = async (settlementId: string) => {
    try {
      const blob = await getSettlementsFactory().exportSettlementTransactions(settlementId)
      downloadBlob(blob, `transactions-${settlementId}.csv`)
    } catch (err: unknown) {
      if (axios.isAxiosError(err)) {
        setError(err.response?.data?.message ?? err.message)
      } else if (err instanceof Error) {
        setError(err.message)
      } else {
        setError(t('settlements.errors.exportTransactions'))
      }
    }
  }

  const handleUndoSettlementConfirmed = async () => {
    const settlementId = undoTarget?.id
    if (!settlementId) return
    setUndoTarget(null)
    try {
      setError(null)
      await getSettlementsFactory().cancelSettlement(settlementId)
      await list.reload()
    } catch (err: unknown) {
      if (axios.isAxiosError(err)) {
        setError(err.response?.data?.message ?? err.message)
      } else if (err instanceof Error) {
        setError(err.message)
      } else {
        setError(t('settlements.errors.undo'))
      }
    }
  }

  const status = (settlement: SettlementListItemExtended) => getSettlementStatus(settlement)

    return (
      <div data-testid="settlements-page">
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: theme.spacing.md,
            flexWrap: 'wrap',
            margin: '0 0 20px 0',
          }}
        >
          <h1 style={{ margin: 0 }}>{t('settlements.title')}</h1>
          {/* UC-A30's trigger, and since ADR-0030 the only way in. */}
          <button
            data-testid="settlements-new-btn"
            onClick={() => navigate('/settlements/new')}
            style={{
              padding: '10px 20px',
              backgroundColor: '#10b981',
              color: '#ffffff',
              border: 'none',
              borderRadius: 6,
              fontSize: 14,
              fontWeight: 500,
              cursor: 'pointer',
            }}
          >
            {t('newSettlement.title')}
          </button>
        </div>

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

          {/* The SEPA file collects less than the settlement records (#114). */}
          {exportWarning && (
            <div
              data-testid="settlements-export-warning"
              style={{
                padding: tableSpacing.cellPadding,
                backgroundColor: '#78350f',
                color: '#fcd34d',
                borderRadius: 6,
                margin: tableSpacing.cellPadding,
              }}
            >
              {exportWarning}
            </div>
          )}

        {isMobile ? (
          <>
            <MobileToolbar
              testId="settlements-mobile-toolbar"
              sort={{
                options: mobileSortOptions,
                value: mobileSortValue,
                onChange: list.setSortValue,
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
                  <PillFilter
                    value={statusFilter}
                    onChange={(newStatus) => list.setFilter('status', newStatus)}
                    options={statusOptions}
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
                        {formatters.formatDate(settlement.created_at ?? '')}
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
                      {formatters.formatPrice(settlement.total_amount_cents ?? 0)}
                    </div>

                    {/* Row 5: action buttons */}
                    <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap' }}>
                      <button
                        data-testid={`settlements-export-sepa-btn-${settlement.id}`}
                        onClick={() => handleExportSepa(settlement.id ?? '')}
                        disabled={settlement.is_cancelled}
                        title={t('settlements.exportSepaHint')}
                        aria-label={t('settlements.exportSepaHint')}
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
                        {t('settlements.exportSepa')}
                      </button>
                      <button
                        data-testid={`settlements-export-csv-btn-${settlement.id}`}
                        onClick={() => handleExportCsv(settlement.id ?? '')}
                        disabled={settlement.is_cancelled}
                        title={t('settlements.exportCsvHint')}
                        aria-label={t('settlements.exportCsvHint')}
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
                        {t('settlements.exportCsv')}
                      </button>
                      <button
                        data-testid={`settlements-export-transactions-btn-${settlement.id}`}
                        onClick={() => handleExportTransactionsCsv(settlement.id ?? '')}
                        disabled={settlement.is_cancelled}
                        title={t('settlements.exportTransactionsHint')}
                        aria-label={t('settlements.exportTransactionsHint')}
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
                        {t('settlements.exportTransactions')}
                      </button>
                      {/* Clickable while the gate refuses: the dialog carries
                          the reason, which a phone can never hover to read. */}
                      <button
                        data-testid={`settlements-undo-btn-${settlement.id}`}
                        onClick={() => setUndoTarget(settlement)}
                        disabled={settlement.is_cancelled}
                        title={settlement.cancellation_blocked_reason ?? undefined}
                        aria-label={t('settlements.undoSettlement')}
                        style={{
                          padding: '5px 10px',
                          backgroundColor: undoButtonColor(settlement),
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
                currentPage={list.page}
                totalPages={totalPages}
                totalItems={totalItems}
                pageSize={list.pageSize}
                onPageChange={list.setPage}
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
              {t('settlements.countFound', { count: totalItems })}
            </div>

            {/* Right: Period picker + Status filter */}
            <div style={{ display: 'flex', gap: tableSpacing.actionButtonGap, alignItems: 'center' }}>
              <PeriodPicker
                value={period}
                onPeriodChange={handlePeriodChange}
                testId="settlements-period-picker"
              />

              <PillFilter
                value={statusFilter}
                onChange={(newStatus) => list.setFilter('status', newStatus)}
                options={statusOptions}
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
                        onClick={() => list.toggleSort('created_at', 'desc')}
                        title={t('common.sortByColumn', { column: t('common.date') })}
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
                        onClick={() => list.toggleSort('created_by', 'asc')}
                        title={t('common.sortByColumn', { column: t('settlements.createdBy') })}
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
                          <div>{formatters.formatDate(settlement.created_at ?? '')}</div>
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
                            {formatters.formatPrice(settlement.total_amount_cents ?? 0)}
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
                          <div style={{ display: 'flex', gap: 8, justifyContent: 'center', flexWrap: 'wrap' }}>
                            {/* Export SEPA XML */}
                            <button
                              data-testid={`settlements-export-sepa-btn-${settlement.id}`}
                              onClick={() => handleExportSepa(settlement.id ?? '')}
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
                              title={t('settlements.exportSepaHint')}
                              aria-label={t('settlements.exportSepaHint')}
                            >
                              {t('settlements.exportSepa')}
                            </button>

                            {/* Export CSV (aggregated) */}
                            <button
                              data-testid={`settlements-export-csv-btn-${settlement.id}`}
                              onClick={() => handleExportCsv(settlement.id ?? '')}
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
                              title={t('settlements.exportCsvHint')}
                              aria-label={t('settlements.exportCsvHint')}
                            >
                              {t('settlements.exportCsv')}
                            </button>

                            {/* Export Transactions CSV (detailed) */}
                            <button
                              data-testid={`settlements-export-transactions-btn-${settlement.id}`}
                              onClick={() => handleExportTransactionsCsv(settlement.id ?? '')}
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
                              title={t('settlements.exportTransactionsHint')}
                              aria-label={t('settlements.exportTransactionsHint')}
                            >
                              {t('settlements.exportTransactions')}
                            </button>

                            {/* Undo Settlement. Disabled only once cancelled —
                                a settlement the gate refuses still opens the
                                dialog, which states why (#127). */}
                            <button
                              data-testid={`settlements-undo-btn-${settlement.id}`}
                              onClick={() => setUndoTarget(settlement)}
                              disabled={settlement.is_cancelled}
                              style={{
                                padding: '4px 8px',
                                backgroundColor: undoButtonColor(settlement),
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
                                  e.currentTarget.style.backgroundColor = undoButtonHoverColor(settlement)
                                }
                              }}
                              onMouseLeave={(e) => {
                                if (!settlement.is_cancelled) {
                                  e.currentTarget.style.backgroundColor = undoButtonColor(settlement)
                                }
                              }}
                              title={settlement.cancellation_blocked_reason ?? t('settlements.undoSettlement')}
                              aria-label={t('settlements.undoSettlement')}
                            >
                              <span aria-hidden="true">↩</span>
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
                  currentPage={list.page}
                  totalPages={totalPages}
                  totalItems={totalItems}
                  pageSize={list.pageSize}
                  onPageChange={list.setPage}
                  onPageSizeChange={list.setPageSize}
                  testId="settlements"
                  showInfo={true}
                  showPageSize={true}
                />
              )}
            </>
          )}
          </>
        )}

      <UndoSettlementDialog
        settlement={undoTarget}
        onConfirm={handleUndoSettlementConfirmed}
        onCancel={() => setUndoTarget(null)}
      />
      </div>
    )
}
