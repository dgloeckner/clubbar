/**
 * Journal Page (Buchungsjournal)
 * Global transaction journal with filtering, sorting, and pagination
 *
 * Features:
 * - Display all transactions across all members
 * - Filter by date range, transaction type, member
 * - Search by member name or transaction notes
 * - Sort by date, amount, type, or member
 * - Paginated display (20 transactions per page)
 *
 * Implements:
 * - Pattern: Table implementation (admin-frontend/patterns/table-implementation.md)
 * - Test IDs pattern (admin-frontend/patterns/test-ids.md)
 * - UC-A20 derivative: Global transaction viewing
 *
 * Uses TDD with E2E tests in e2etests/tests/admin/journal.spec.ts
 */

import axios from 'axios'
import type React from 'react'
import { useCallback, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { PeriodPicker } from '../components/forms/PeriodPicker'
import { useFormatters } from '../hooks/useFormatters'
import { getClubTimeZone } from '../utils/clubTimeZone'
import { parseApiDate } from '../utils/dates'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { MobileToolbar } from '../components/layout/MobileToolbar'
import { PillFilter, type PillFilterOption } from '../components/forms/PillFilter'
import { PaginationToolbar } from '../components/tables/PaginationToolbar'
import { PageActionButton } from '../components/common/PageActionButton'
import { PageHeader } from '../components/layout/PageHeader'
import { useListQuery } from '../hooks/useListQuery'
import { getTransactions } from '../api/generated/transactions/transactions'
import { getTransactionTypeColor, getTransactionAmountColor } from '../utils/transactions'
import { getCurrentLanguage } from '../i18n/config'
import { getLocalizedName } from '../utils/i18n-helpers'
import { DEFAULT_PERIOD, getPeriodRange, type PeriodKey } from '../utils/periods'
import { StornoConfirmDialog } from '../components/modals/StornoConfirmDialog'
import { UndoIcon } from '../components/icons'
import type { GlobalTransaction } from '../api/generated'
import { theme } from '../styles/design-system'
import {
  tableColors,
  tableSpacing,
  headerCellBaseStyle,
  headerRowStyle,
} from '../styles/tableTokens'

// Local resolved type with non-optional fields for JSX safety
interface ResolvedTransaction {
  id: string
  member_id: string
  member_name: string
  type: string
  amount_cents: number
  description: string
  product_id: string | null
  product_name: string | null
  created_at: string
  is_settled: boolean
  settlement_date: string | null
  // For a storno row: the transaction it reverses. Null otherwise.
  related_transaction_id: string | null
  // For an original that has been stornoed: the id of its storno. Null otherwise.
  stornoed_by_transaction_id: string | null
}

function localizeTransactionItems(items: GlobalTransaction[]): ResolvedTransaction[] {
  const lang = getCurrentLanguage()
  return items.map((item) => {
    let product_name: string | null = item.product_name ?? null
    if (typeof item.product_names === 'string') {
      try {
        product_name = getLocalizedName(JSON.parse(item.product_names), lang)
      } catch {
        // ignore parse errors
      }
    }
    return {
      id: item.id ?? '',
      member_id: item.member_id ?? '',
      member_name: item.member_name ?? '',
      type: item.type ?? '',
      amount_cents: item.amount_cents ?? 0,
      description: item.description ?? '',
      product_id: item.product_id ?? null,
      product_name,
      created_at: item.created_at ?? '',
      is_settled: item.is_settled ?? !!item.settlement_date,
      settlement_date: item.settlement_date ?? null,
      related_transaction_id: item.related_transaction_id ?? null,
      stornoed_by_transaction_id: item.stornoed_by_transaction_id ?? null,
    }
  })
}

const defaultPageSize = 20

type JournalSortKey = 'created_at' | 'amount' | 'type' | 'member'

/** A load failure leaves nothing to page or filter back from, so the banner
 *  carries its own way out rather than making the admin change a filter (#132). */
const retryButtonStyle: React.CSSProperties = {
  padding: '6px 14px',
  borderRadius: 6,
  border: `1px solid ${theme.colors.banner.dangerText}`,
  background: 'transparent',
  color: theme.colors.banner.dangerText,
  fontSize: 13,
  fontWeight: 600,
  cursor: 'pointer',
}

interface JournalFilters {
  period: PeriodKey
  dateFrom: string | undefined
  dateTo: string | undefined
  settlementStatus: 'all' | 'open' | 'settled'
}

export function JournalPage() {
  const { t } = useTranslation()
  const { formatPrice, formatDate, intlLocale } = useFormatters()
  const navigate = useNavigate()
  const breakpoint = useBreakpoint()
  const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'

  // Pagination, filters, search and sorting share the list-query state, which
  // owns the debounce, the abort guard and the page resets (#121). The initial
  // range is derived from the default preset here rather than announced by the
  // PeriodPicker from an effect — that effect re-fired on every render and reset
  // the page, so paging was impossible (#89).
  const list = useListQuery<ResolvedTransaction, JournalFilters, JournalSortKey>({
    initialFilters: {
      period: DEFAULT_PERIOD,
      dateFrom: getPeriodRange(DEFAULT_PERIOD).dateFrom,
      dateTo: getPeriodRange(DEFAULT_PERIOD).dateTo,
      settlementStatus: 'all',
    },
    initialSortKey: 'created_at',
    initialSortDirection: 'desc',
    initialPageSize: defaultPageSize,
    fetcher: async ({ page, pageSize, sortKey, sortDirection, search, filters, signal }) => {
      const result = await getTransactions().listTransactions(
        {
          page,
          per_page: pageSize,
          date_from: filters.dateFrom || undefined,
          date_to: filters.dateTo || undefined,
          search: search || undefined,
          sort: sortKey,
          order: sortDirection,
          settlement_status:
            filters.settlementStatus !== 'all' ? filters.settlementStatus : undefined,
        },
        { signal }
      )
      return {
        items: localizeTransactionItems(result.data ?? []),
        total: result.pagination?.total ?? 0,
      }
    },
    parseError: (err) => (err instanceof Error ? err.message : t('journal.errors.load')),
  })

  const { items: transactions, total: totalItems, totalPages, loading, error, search } = list
  const { period, settlementStatus } = list.filters
  const sortKey = list.sortKey
  const sortDirection = list.sortDirection

  // Storno confirmation dialog state — a row action, not a form. The amount
  // is never entered by the admin; it is the exact negation of the
  // transaction being reversed, and the member is implied by it (#169).
  const [stornoTarget, setStornoTarget] = useState<ResolvedTransaction | null>(null)
  const [stornoReason, setStornoReason] = useState('')
  const [stornoError, setStornoError] = useState<string | null>(null)
  const [stornoLoading, setStornoLoading] = useState(false)

  // Mobile state
  const [showMobileFilters, setShowMobileFilters] = useState(false)

  const settlementStatusOptions: ReadonlyArray<PillFilterOption<JournalFilters['settlementStatus']>> = [
    { value: 'all', label: t('common.all'), color: theme.colors.semantic.neutral },
    { value: 'open', label: t('journal.open'), color: theme.colors.semantic.emerald },
    { value: 'settled', label: t('journal.settled'), color: theme.colors.semantic.purple },
  ]

  const mobileSortOptions = [
    { value: 'created_at_desc', label: t('journal.sortNewest'), direction: 'desc' as const },
    { value: 'created_at_asc', label: t('journal.sortOldest'), direction: 'asc' as const },
    { value: 'member_asc', label: t('journal.sortMember'), direction: 'asc' as const },
    { value: 'member_desc', label: t('journal.sortMemberDesc'), direction: 'desc' as const },
    { value: 'amount_desc', label: t('journal.sortAmountHigh'), direction: 'desc' as const },
    { value: 'amount_asc', label: t('journal.sortAmountLow'), direction: 'asc' as const },
  ]

  const mobileSortValue = list.sortValue

  const mobileFilterCount = [
    period !== DEFAULT_PERIOD ? 1 : 0,
    settlementStatus !== 'all' ? 1 : 0,
  ].reduce((a, b) => a + b, 0)

  // Handle filter changes. Memoized so the PeriodPicker sees a stable handler
  // across renders (#89) — it is only called from a click now, but a filter
  // handler whose identity churns on every render is what let the old effect
  // fire on every render in the first place.
  const setFilters = list.setFilters
  const handlePeriodChange = useCallback(
    (from: string | undefined, to: string | undefined, periodKey: PeriodKey) => {
      setFilters({ period: periodKey, dateFrom: from, dateTo: to })
    },
    [setFilters]
  )

  const handleSearch = list.setSearch

  const handleSettlementStatusChange = (status: 'all' | 'open' | 'settled') => {
    list.setFilter('settlementStatus', status)
  }

  // Sorting a desktop column resets to page 1 exactly as the mobile dropdown
  // does — that divergence was the whole point of centralizing this (#121).
  const handleSort = (field: JournalSortKey) => {
    list.toggleSort(field, 'desc')
  }

  const handleOpenStorno = (tx: ResolvedTransaction) => {
    setStornoTarget(tx)
    setStornoReason('')
    setStornoError(null)
  }

  const handleCloseStorno = () => {
    if (stornoLoading) return
    setStornoTarget(null)
    setStornoReason('')
    setStornoError(null)
  }

  const handleConfirmStorno = async () => {
    if (!stornoTarget) return
    if (!stornoReason.trim()) {
      setStornoError(t('journal.stornoDialog.reasonRequired'))
      return
    }

    try {
      setStornoLoading(true)
      setStornoError(null)

      await getTransactions().stornoTransaction(stornoTarget.id, { reason: stornoReason })

      setStornoTarget(null)
      setStornoReason('')
      await list.reload()
    } catch (err) {
      // Read the backend's own error code rather than sniffing the message —
      // both refusals are states a second admin can legitimately race us into,
      // so they need to say which one happened, not "something went wrong".
      const code = axios.isAxiosError(err)
        ? (err.response?.data as { error?: string } | undefined)?.error
        : undefined

      if (code === 'already_stornoed') {
        setStornoError(t('journal.stornoDialog.errorAlreadyStornoed'))
      } else if (code === 'cannot_storno_a_storno') {
        setStornoError(t('journal.stornoDialog.errorCannotStornoAStorno'))
      } else {
        setStornoError(t('journal.stornoDialog.errorGeneric'))
      }

      // Either refusal means our view of this row is stale — the row is
      // already reversed. Refresh so the action disables itself.
      if (code === 'already_stornoed') {
        await list.reload()
      }
    } finally {
      setStornoLoading(false)
    }
  }

  return (
    <div data-testid="journal-page">
      <PageHeader
        title={t('journal.title')}
        actions={
          /*
            Settlement selection left this screen in ADR-0030: a run picks
            members and settles each in full, which a paginated transaction
            list under a date filter cannot honestly represent. So this is a
            link to that run, and since #375 it looks like the same link on
            Settlements — same label, same colour, same corner of the page.
            It used to be a blue `+ `-prefixed button on a bar of its own,
            below the title, which made one action look like two.
          */
          <PageActionButton
            variant="success"
            data-testid="journal-new-settlement-link"
            onClick={() => navigate('/settlements/new')}
          >
            {t('newSettlement.title')}
          </PageActionButton>
        }
      />

        {isMobile ? (
          <>
            <MobileToolbar
              testId="journal-mobile-toolbar"
              search={{
                value: search,
                onChange: (v) => { handleSearch(v) },
                testId: 'journal-search-input',
              }}
              sort={{
                options: mobileSortOptions,
                value: mobileSortValue,
                onChange: list.setSortValue,
              }}
              filterCount={mobileFilterCount}
              onFilterToggle={() => setShowMobileFilters(!showMobileFilters)}
              showFilters={showMobileFilters}
              filterContent={
                <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
                  <div>
                    <div style={{ fontSize: '12px', color: theme.colors.text.label, fontWeight: 500, textTransform: 'uppercase', marginBottom: '6px' }}>
                      {t('journal.period')}
                    </div>
                    <PeriodPicker
                      value={period}
                      onPeriodChange={handlePeriodChange}
                      testId="journal-period-picker"
                    />
                  </div>
                  <div>
                    <div style={{ fontSize: '12px', color: theme.colors.text.label, fontWeight: 500, textTransform: 'uppercase', marginBottom: '6px' }}>
                      {t('journal.settlementStatus')}
                    </div>
                    <PillFilter
                      value={settlementStatus}
                      onChange={handleSettlementStatusChange}
                      options={settlementStatusOptions}
                      testId="journal-settlement-status-filter"
                    />
                  </div>
                </div>
              }
            />

            {/* Mobile card list */}
            {loading ? (
              <div data-testid="journal-loading" style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
                {t('common.loading')}
              </div>
            ) : error ? (
              <div
                data-testid="journal-error-message"
                style={{
                  padding: theme.spacing.md,
                  backgroundColor: theme.colors.banner.dangerBg,
                  color: theme.colors.banner.dangerText,
                  borderRadius: 6,
                  display: 'flex',
                  flexDirection: 'column',
                  gap: theme.spacing.sm,
                  alignItems: 'flex-start',
                }}
              >
                <span>{error}</span>
                <button
                  data-testid="journal-retry-button"
                  onClick={() => list.reload()}
                  style={retryButtonStyle}
                >
                  {t('common.retry')}
                </button>
              </div>
            ) : transactions.length === 0 ? (
              <div data-testid="journal-empty-state" style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
                {t('journal.noTransactions')}
              </div>
            ) : (
              <div data-testid="journal-mobile-cards" style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                {transactions.map((tx) => (
                  <div
                    key={tx.id}
                    data-testid={`journal-card-${tx.id}`}
                    style={{
                      background: theme.mobileCard.bg,
                      border: `1px solid ${theme.mobileCard.border}`,
                      borderRadius: '10px',
                      padding: '14px 16px',
                    }}
                  >
                    {/* Row 1: date+time (left), type badge (right) */}
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' }}>
                      <span style={{ fontSize: '12px', color: theme.colors.text.secondary }}>
                        {parseApiDate(tx.created_at).toLocaleDateString(intlLocale, {
                          year: 'numeric',
                          month: '2-digit',
                          day: '2-digit',
                          timeZone: getClubTimeZone(),
                        })}{' '}
                        {parseApiDate(tx.created_at).toLocaleTimeString(intlLocale, {
                          hour: '2-digit',
                          minute: '2-digit',
                          hour12: false,
                          timeZone: getClubTimeZone(),
                        })}
                      </span>
                      <span
                        style={{
                          display: 'inline-block',
                          padding: '3px 10px',
                          borderRadius: 10,
                          fontSize: 11,
                          fontWeight: 600,
                          backgroundColor: getTransactionTypeColor(tx.type).bg,
                          color: getTransactionTypeColor(tx.type).text,
                        }}
                      >
                        {t(`journal.types.${tx.type}`, tx.type)}
                      </span>
                    </div>
                    {/* Row 2: member name + amount. The amount rides on the
                        name row rather than down beside the action, the same
                        headline shape the member and product cards use: the
                        figure is what the eye comes for, and the bottom row is
                        left to the action alone. */}
                    <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '4px' }}>
                      <span style={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', fontWeight: 600, fontSize: '14px', color: theme.colors.text.primary }}>
                        {tx.member_name}
                      </span>
                      <span
                        style={{
                          flexShrink: 0,
                          whiteSpace: 'nowrap',
                          fontFamily: 'JetBrains Mono, monospace',
                          fontSize: '13px',
                          fontWeight: 700,
                          fontVariantNumeric: 'tabular-nums',
                          color: getTransactionAmountColor(tx.amount_cents),
                        }}
                      >
                        {formatPrice(tx.amount_cents)}
                      </span>
                    </div>
                    {/* Row 3: product name + description */}
                    {(tx.product_name || tx.description) && (
                      <div style={{ fontSize: '12px', color: theme.colors.text.secondary, marginBottom: '6px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                        {[tx.product_name, tx.description].filter(Boolean).join(' \u2022 ')}
                      </div>
                    )}
                    {/* Storno linkage (either direction) */}
                    {tx.type === 'storno' && tx.related_transaction_id && (
                      <div
                        data-testid={`journal-storno-link-${tx.id}`}
                        style={{ fontSize: '11px', color: theme.colors.text.secondary, marginBottom: '6px' }}
                      >
                        {t('journal.stornoOf', { id: tx.related_transaction_id.slice(0, 8) })}
                      </div>
                    )}
                    {tx.type !== 'storno' && tx.stornoed_by_transaction_id && (
                      <div
                        data-testid={`journal-stornoed-badge-${tx.id}`}
                        style={{
                          display: 'inline-block',
                          fontSize: '11px',
                          fontWeight: 600,
                          color: theme.colors.semantic.warning,
                          marginBottom: '6px',
                        }}
                      >
                        {t('journal.stornoedBadge')}
                      </div>
                    )}
                    {/* Row 4: actions, right-aligned — where every other card
                        list in the admin keeps them. A storno transaction has
                        no action of its own, so it gets no row at all rather
                        than an empty one. */}
                    {tx.type !== 'storno' && (
                      <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '8px' }}>
                        <button
                          data-testid={`journal-storno-btn-${tx.id}`}
                          onClick={() => handleOpenStorno(tx)}
                          disabled={!!tx.stornoed_by_transaction_id}
                          title={tx.stornoed_by_transaction_id ? t('journal.stornoedBadge') : undefined}
                          style={{
                            display: 'flex', alignItems: 'center', gap: '4px',
                            padding: '6px 12px', borderRadius: '6px', border: 'none',
                            background: tx.stornoed_by_transaction_id ? theme.badges.neutral.bg : theme.badges.danger.bg,
                            color: tx.stornoed_by_transaction_id ? theme.colors.text.secondary : theme.colors.semantic.danger,
                            fontSize: '12px',
                            cursor: tx.stornoed_by_transaction_id ? 'not-allowed' : 'pointer',
                            opacity: tx.stornoed_by_transaction_id ? 0.5 : 1,
                          }}
                        >
                          <UndoIcon size={14} /> {t('journal.stornoAction')}
                        </button>
                      </div>
                    )}
                  </div>
                ))}
              </div>
            )}

            {/* Mobile pagination */}
            {totalItems > 0 && !loading && (
              <PaginationToolbar
                data-testid="journal-pagination"
                currentPage={list.page}
                totalPages={totalPages}
                totalItems={totalItems}
                pageSize={list.pageSize}
                onPageChange={list.setPage}
                onPageSizeChange={list.setPageSize}
                testId="journal"
                showInfo={true}
                showPageSize={true}
              />
            )}
          </>
        ) : (
          <>
        {/* Toolbar */}
        <div
          data-testid="journal-toolbar"
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
            data-testid="journal-count-summary"
            style={{
              fontSize: 14,
              color: tableColors.cellSecondaryText,
            }}
          >
            {t('journal.countFound', { count: totalItems })}
          </div>

          {/* Center-left: Search input */}
          <input
            type="text"
            value={search}
            onChange={(e) => {
              handleSearch(e.target.value)
            }}
            placeholder={t('common.searchPlaceholder')}
            data-testid="journal-search-input"
            style={{
              flex: 1,
              padding: '8px 12px',
              backgroundColor: theme.colors.bg.input,
              border: `1px solid ${theme.colors.border.input}`,
              borderRadius: 8,
              color: tableColors.cellText,
              fontSize: '14px',
              fontFamily: 'inherit',
              maxWidth: '400px',
              height: '40px',
              boxSizing: 'border-box',
              verticalAlign: 'middle',
              transition: 'all 0.15s',
            }}
            onFocus={(e) => {
              e.currentTarget.style.borderColor = theme.activeTint.primaryBorder
            }}
            onBlur={(e) => {
              e.currentTarget.style.borderColor = theme.colors.border.input
            }}
          />

          {/* Center-right: Period picker (segmented control) */}
          <PeriodPicker
            value={period}
            onPeriodChange={handlePeriodChange}
            testId="journal-period-picker"
          />

          {/* Settlement status filter (colored toggle pills) */}
          <PillFilter
            value={settlementStatus}
            onChange={handleSettlementStatusChange}
            options={settlementStatusOptions}
            testId="journal-settlement-status-filter"
          />
        </div>

        {/* Loading state */}
        {loading ? (
          <div
            data-testid="journal-loading"
            style={{
              padding: tableSpacing.cellPadding,
              textAlign: 'center',
              color: tableColors.cellSecondaryText,
            }}
          >
            {t('common.loading')}
          </div>
        ) : error ? (
          <div
            data-testid="journal-error-message"
            style={{
              padding: tableSpacing.cellPadding,
              backgroundColor: theme.colors.banner.dangerBg,
              color: theme.colors.banner.dangerText,
              borderRadius: 6,
              margin: tableSpacing.cellPadding,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'space-between',
              gap: theme.spacing.md,
              flexWrap: 'wrap',
            }}
          >
            <span>{error}</span>
            <button
              data-testid="journal-retry-button"
              onClick={() => list.reload()}
              style={retryButtonStyle}
            >
              {t('common.retry')}
            </button>
          </div>
        ) : transactions.length === 0 ? (
          <div
            data-testid="journal-empty-state"
            style={{
              padding: tableSpacing.cellPadding,
              textAlign: 'center',
              color: tableColors.cellSecondaryText,
            }}
          >
            {t('journal.noTransactions')}
          </div>
        ) : (
          <div
            data-testid="journal-table-wrapper"
            style={{
              overflowX: 'auto',
              overflowY: 'hidden',
              borderRadius: tableSpacing.tableWrapperRadius,
            }}
          >
            <table
              data-testid="journal-table"
              style={{
                width: '100%',
                borderCollapse: 'collapse',
              }}
            >
                <thead>
                  <tr style={headerRowStyle}>
                    <th
                      style={{
                        ...headerCellBaseStyle,
                        cursor: 'pointer',
                        userSelect: 'none',
                      }}
                      onClick={() => handleSort('created_at')}
                      title={t('common.sortByColumn', { column: t('journal.date') })}
                      data-testid="journal-header-date"
                    >
                      {t('journal.date')} {sortKey === 'created_at' && (sortDirection === 'asc' ? '↑' : '↓')}
                    </th>
                    <th
                      style={{
                        ...headerCellBaseStyle,
                        cursor: 'pointer',
                        userSelect: 'none',
                      }}
                      onClick={() => handleSort('type')}
                      title={t('common.sortByColumn', { column: t('journal.type') })}
                      data-testid="journal-header-type"
                    >
                      {t('journal.type')} {sortKey === 'type' && (sortDirection === 'asc' ? '↑' : '↓')}
                    </th>
                    <th
                      style={{
                        ...headerCellBaseStyle,
                        cursor: 'pointer',
                        userSelect: 'none',
                      }}
                      onClick={() => handleSort('member')}
                      title={t('common.sortByColumn', { column: t('journal.member') })}
                      data-testid="journal-header-member"
                    >
                      {t('journal.member')} {sortKey === 'member' && (sortDirection === 'asc' ? '↑' : '↓')}
                    </th>
                    <th
                      style={headerCellBaseStyle}
                      data-testid="journal-header-details"
                    >
                      {t('journal.details')}
                    </th>
                    <th
                      style={{
                        ...headerCellBaseStyle,
                        cursor: 'pointer',
                        userSelect: 'none',
                      }}
                      onClick={() => handleSort('amount')}
                      title={t('common.sortByColumn', { column: t('common.amount') })}
                      data-testid="journal-header-amount"
                    >
                      {t('common.amount')} {sortKey === 'amount' && (sortDirection === 'asc' ? '↑' : '↓')}
                    </th>
                    <th
                      style={headerCellBaseStyle}
                      data-testid="journal-header-settlement-date"
                    >
                      {t('journal.settlementDate')}
                    </th>
                    <th
                      style={headerCellBaseStyle}
                      data-testid="journal-header-actions"
                    >
                      {t('common.actions')}
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {transactions.map((tx) => (
                    <tr
                      key={tx.id}
                      data-testid={`journal-table-row-${tx.id}`}
                      style={{
                        borderBottom: tableColors.rowActiveBorder,
                        backgroundColor: tableColors.rowActiveBg,
                        transition: 'background-color 150ms',
                      }}
                    >
                      {/* Date and Time */}
                      <td
                        data-testid={`journal-table-cell-date-${tx.id}`}
                        style={{
                          padding: tableSpacing.cellPadding,
                          color: tableColors.cellText,
                        }}
                      >
                        <div style={{ display: 'flex', flexDirection: 'column', gap: '2px' }}>
                          <div>
                            {parseApiDate(tx.created_at).toLocaleDateString(intlLocale, {
                              year: 'numeric',
                              month: '2-digit',
                              day: '2-digit',
                              timeZone: getClubTimeZone(),
                            })}
                          </div>
                          <div style={{ fontSize: '12px', color: tableColors.cellSecondaryText }}>
                            {parseApiDate(tx.created_at).toLocaleTimeString(intlLocale, {
                              hour: '2-digit',
                              minute: '2-digit',
                              second: '2-digit',
                              hour12: false,
                              timeZone: getClubTimeZone(),
                            })}
                          </div>
                        </div>
                      </td>


                      {/* Type */}
                      <td
                        data-testid={`journal-table-cell-type-${tx.id}`}
                        style={{
                          padding: tableSpacing.cellPadding,
                          color: tableColors.cellText,
                        }}
                      >
                        <span
                          style={{
                            display: 'inline-block',
                            padding: '4px 12px',
                            borderRadius: 12,
                            fontSize: 12,
                            fontWeight: 600,
                            backgroundColor: getTransactionTypeColor(tx.type).bg,
                            color: getTransactionTypeColor(tx.type).text,
                          }}
                        >
                          {t(`journal.types.${tx.type}`, tx.type)}
                        </span>
                      </td>

                      {/* Member */}
                      <td
                        data-testid={`journal-table-cell-member-${tx.id}`}
                        style={{
                          padding: tableSpacing.cellPadding,
                          color: tableColors.cellText,
                        }}
                      >
                        {tx.member_name}
                      </td>

                      {/* Details (Product + Description) */}
                      <td
                        data-testid={`journal-table-cell-details-${tx.id}`}
                        style={{
                          padding: tableSpacing.cellPadding,
                          color: tableColors.cellSecondaryText,
                          fontSize: 13,
                          maxWidth: 300,
                          overflow: 'hidden',
                          textOverflow: 'ellipsis',
                        }}
                        title={
                          [tx.product_name, tx.description]
                            .filter((v) => v)
                            .join('\n')
                            .trim() || t('journal.noDetails')
                        }
                      >
                        <div style={{ display: 'flex', flexDirection: 'column', gap: '4px' }}>
                          {tx.product_name && (
                            <div
                              style={{
                                whiteSpace: 'nowrap',
                                overflow: 'hidden',
                                textOverflow: 'ellipsis',
                              }}
                            >
                              {tx.product_name}
                            </div>
                          )}
                          {tx.description && (
                            <div
                              style={{
                                whiteSpace: 'nowrap',
                                overflow: 'hidden',
                                textOverflow: 'ellipsis',
                                color: tableColors.cellText,
                              }}
                            >
                              {tx.description}
                            </div>
                          )}
                          {!tx.product_name && !tx.description && <span>—</span>}
                          {tx.type === 'storno' && tx.related_transaction_id && (
                            <div
                              data-testid={`journal-storno-link-${tx.id}`}
                              style={{ fontSize: '11px', color: tableColors.cellSecondaryText }}
                            >
                              {t('journal.stornoOf', { id: tx.related_transaction_id.slice(0, 8) })}
                            </div>
                          )}
                          {tx.type !== 'storno' && tx.stornoed_by_transaction_id && (
                            <div
                              data-testid={`journal-stornoed-badge-${tx.id}`}
                              style={{
                                display: 'inline-block',
                                fontSize: '11px',
                                fontWeight: 600,
                                color: theme.colors.semantic.warning,
                              }}
                            >
                              {t('journal.stornoedBadge')}
                            </div>
                          )}
                        </div>
                      </td>

                      {/* Amount */}
                      <td
                        data-testid={`journal-table-cell-amount-${tx.id}`}
                        style={{
                          padding: tableSpacing.cellPadding,
                          color: getTransactionAmountColor(tx.amount_cents),
                          fontWeight: 700,
                          fontFamily: 'JetBrains Mono, monospace',
                          fontSize: '14px',
                        }}
                      >
                        {formatPrice(tx.amount_cents)}
                      </td>

                      {/* Settlement Date */}
                      <td
                        data-testid={`journal-table-cell-settlement-date-${tx.id}`}
                        style={{
                          padding: tableSpacing.cellPadding,
                          color: tableColors.cellText,
                        }}
                      >
                        {/* DATE-only (ADR-0004): a calendar day carries no
                            time of day. `new Date('2026-08-05')` is UTC
                            midnight, which printed a "02:00:00" nobody booked
                            and, west of Greenwich, the previous day. */}
                        {tx.settlement_date ? formatDate(tx.settlement_date) : '—'}
                      </td>

                      {/* Actions */}
                      <td
                        data-testid={`journal-table-cell-actions-${tx.id}`}
                        style={{
                          padding: tableSpacing.cellPadding,
                        }}
                        onClick={(e) => e.stopPropagation()}
                      >
                        {tx.type !== 'storno' && (
                          <button
                            data-testid={`journal-storno-btn-${tx.id}`}
                            onClick={() => handleOpenStorno(tx)}
                            disabled={!!tx.stornoed_by_transaction_id}
                            title={tx.stornoed_by_transaction_id ? t('journal.stornoedBadge') : undefined}
                            style={{
                              padding: '4px 10px',
                              fontSize: '11px',
                              fontWeight: 600,
                              background: 'transparent',
                              border: `1px solid ${theme.colors.border.light}`,
                              borderRadius: theme.borderRadius.sm,
                              color: tx.stornoed_by_transaction_id ? theme.colors.text.secondary : theme.colors.semantic.danger,
                              cursor: tx.stornoed_by_transaction_id ? 'not-allowed' : 'pointer',
                              opacity: tx.stornoed_by_transaction_id ? 0.5 : 1,
                            }}
                          >
                            {t('journal.stornoAction')}
                          </button>
                        )}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>

              {/* Pagination */}
              {totalItems > 0 && (
                <PaginationToolbar
                  data-testid="journal-pagination"
                  currentPage={list.page}
                  totalPages={totalPages}
                  totalItems={totalItems}
                  pageSize={list.pageSize}
                  onPageChange={list.setPage}
                  onPageSizeChange={list.setPageSize}
                  testId="journal"
                  showInfo={true}
                  showPageSize={true}
                />
              )}
            </div>
        )}
          </>
        )}

        {/* Storno Confirmation Dialog */}
        <StornoConfirmDialog
          isOpen={stornoTarget !== null}
          transaction={stornoTarget}
          reason={stornoReason}
          onReasonChange={setStornoReason}
          onConfirm={handleConfirmStorno}
          onCancel={handleCloseStorno}
          isLoading={stornoLoading}
          error={stornoError}
        />
    </div>
  )
}
