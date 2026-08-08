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
import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { PeriodPicker } from '../components/forms/PeriodPicker'
import { useFormatters } from '../hooks/useFormatters'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { useExecutionDateInfo } from '../hooks/useExecutionDateInfo'
import { MobileToolbar } from '../components/layout/MobileToolbar'
import { SettlementStatusFilter } from '../components/forms/SettlementStatusFilter'
import { PaginationToolbar } from '../components/tables/PaginationToolbar'
import { onLoadingStateChange } from '../api/client'
import { getTransactions } from '../api/generated/transactions/transactions'
import { getSettlements } from '../api/generated/settlements/settlements'
import { getTransactionTypeColor, getAmountColor } from '../utils/transactions'
import { getCurrentLanguage } from '../i18n/config'
import { getLocalizedName } from '../utils/i18n-helpers'
import { SettlementConfirmModal } from '../components/modals/SettlementConfirmModal'
import { StornoConfirmDialog } from '../components/modals/StornoConfirmDialog'
import type { GlobalTransaction, SettlementFilterPreview } from '../api/generated'
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

interface JournalPageState {
  transactions: ResolvedTransaction[]
  totalItems: number
  loading: boolean
  error: string | null
}

const defaultPageSize = 20

export function JournalPage() {
  const { t } = useTranslation()
  const { formatPrice, intlLocale } = useFormatters()
  const navigate = useNavigate()
  const breakpoint = useBreakpoint()
  const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'

  // Data state
  const [state, setState] = useState<JournalPageState>({
    transactions: [],
    totalItems: 0,
    loading: true,
    error: null,
  })

  // Pagination state
  const [currentPage, setCurrentPage] = useState(1)
  const [pageSize, setPageSize] = useState(defaultPageSize)

  // Filter state
  const [period, setPeriod] = useState('3m') // Period preset: '1m' | '3m' | '6m' | '1y' | '2y' | 'all'
  const [dateFrom, setDateFrom] = useState<string | undefined>(undefined) // Set by PeriodPicker
  const [dateTo, setDateTo] = useState<string | undefined>(undefined) // Set by PeriodPicker
  const [settlementStatus, setSettlementStatus] = useState<'all' | 'open' | 'settled'>('all')
  const [search, setSearch] = useState('')

  // Sorting state
  const [sortKey, setSortKey] = useState<'created_at' | 'amount' | 'type' | 'member'>('created_at')
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('desc')

  // Settlement mode state
  type SettlementMode = 'none' | 'edit'
  const [settlementMode, setSettlementMode] = useState<SettlementMode>('none')
  const [selectedTransactionIds, setSelectedTransactionIds] = useState<Set<string>>(new Set())

  // Settlement confirm modal state
  const [confirmModalOpen, setConfirmModalOpen] = useState(false)
  const [pendingTransactions, setPendingTransactions] = useState<ResolvedTransaction[]>([])
  const [confirmLoading, setConfirmLoading] = useState(false)
  const [confirmError, setConfirmError] = useState<string | null>(null)
  const [settleAllPreview, setSettleAllPreview] = useState<SettlementFilterPreview | null>(null)
  const [settleAllLoading, setSettleAllLoading] = useState(false)

  // The execution date comes from the backend so the displayed and submitted
  // value are the same one, and the TARGET2 rule is not duplicated here.
  const { info: executionDateInfo, error: executionDateError } = useExecutionDateInfo(confirmModalOpen)

  // Storno confirmation dialog state — a row action, not a form. The amount
  // is never entered by the admin; it is the exact negation of the
  // transaction being reversed, and the member is implied by it (#169).
  const [stornoTarget, setStornoTarget] = useState<ResolvedTransaction | null>(null)
  const [stornoReason, setStornoReason] = useState('')
  const [stornoError, setStornoError] = useState<string | null>(null)
  const [stornoLoading, setStornoLoading] = useState(false)

  // Track if component is mounted to prevent state updates on unmounted component
  const isMountedRef = useRef(true)

  // Mobile state
  const [showMobileFilters, setShowMobileFilters] = useState(false)

  const mobileSortOptions = [
    { value: 'created_at_desc', label: t('journal.sortNewest', 'Newest first'), direction: 'desc' as const },
    { value: 'created_at_asc', label: t('journal.sortOldest', 'Oldest first'), direction: 'asc' as const },
    { value: 'member_asc', label: t('journal.sortMember', 'Member A\u2013Z'), direction: 'asc' as const },
    { value: 'member_desc', label: t('journal.sortMemberDesc', 'Member Z\u2013A'), direction: 'desc' as const },
    { value: 'amount_desc', label: t('journal.sortAmountHigh', 'Amount high\u2013low'), direction: 'desc' as const },
    { value: 'amount_asc', label: t('journal.sortAmountLow', 'Amount low\u2013high'), direction: 'asc' as const },
  ]

  const mobileSortValue = `${sortKey}_${sortDirection}`

  const handleMobileSortChange = (value: string) => {
    const lastUnderscore = value.lastIndexOf('_')
    const key = value.substring(0, lastUnderscore) as typeof sortKey
    const dir = value.substring(lastUnderscore + 1) as 'asc' | 'desc'
    setSortKey(key)
    setSortDirection(dir)
    setCurrentPage(1)
  }

  const mobileFilterCount = [
    period !== '3m' ? 1 : 0,
    settlementStatus !== 'all' ? 1 : 0,
  ].reduce((a, b) => a + b, 0)

  // Subscribe to global loading state on mount
  useEffect(() => {
    isMountedRef.current = true
    const unsubscribe = onLoadingStateChange(() => {
      // Component will re-render when loading state changes due to state updates
    })
    return () => {
      isMountedRef.current = false
      unsubscribe()
    }
  }, [])

  // Load transactions when filters, sorting, or pagination changes
  useEffect(() => {
    const timer = setTimeout(loadTransactions, search ? 500 : 0)
    return () => clearTimeout(timer)
  }, [currentPage, pageSize, dateFrom, dateTo, settlementStatus, search, sortKey, sortDirection])

  async function loadTransactions() {
    try {
      setState((prev) => ({ ...prev, loading: true, error: null }))

      const result = await getTransactions().listTransactions({
        page: currentPage,
        per_page: pageSize,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        search: search || undefined,
        sort: sortKey as 'created_at' | 'amount' | 'type' | 'member',
        order: sortDirection,
        settlement_status: settlementStatus !== 'all' ? settlementStatus as 'open' | 'settled' : undefined,
      })

      const resolvedItems = localizeTransactionItems(result.data ?? [])

      // Only update state if component is still mounted
      if (isMountedRef.current) {
        setState((prev) => ({
          ...prev,
          transactions: resolvedItems,
          totalItems: result.pagination?.total ?? 0,
          loading: false,
        }))
      }
    } catch (err) {
      // Only update state if component is still mounted
      if (isMountedRef.current) {
        const errorMsg = err instanceof Error ? err.message : 'Failed to load transactions'
        setState((prev) => ({
          ...prev,
          transactions: [],
          loading: false,
          error: errorMsg,
        }))
      }
    }
  }

  // Handle filter changes
  const handlePeriodChange = (from: string | undefined, to: string | undefined, periodKey: string) => {
    setPeriod(periodKey)
    setDateFrom(from)
    setDateTo(to)
    setCurrentPage(1)
  }

  const handleSearch = (value: string) => {
    setSearch(value)
    setCurrentPage(1)
  }

  const handleSettlementStatusChange = (status: 'all' | 'open' | 'settled') => {
    setSettlementStatus(status)
    setCurrentPage(1)
  }

  const handleSort = (field: 'created_at' | 'amount' | 'type' | 'member') => {
    if (sortKey === field) {
      // Toggle direction if clicking the same field
      setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc')
    } else {
      // Switch to new field, default to desc
      setSortKey(field)
      setSortDirection('desc')
    }
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
      await loadTransactions()
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
        await loadTransactions()
      }
    } finally {
      setStornoLoading(false)
    }
  }

  const handleToggleTransaction = (id: string) => {
    setSelectedTransactionIds(prev => {
      const next = new Set(prev)
      if (next.has(id)) {
        next.delete(id)
      } else {
        next.add(id)
      }
      return next
    })
  }

  const handleSelectAll = () => {
    if (selectedTransactionIds.size === state.transactions.length && state.transactions.length > 0) {
      setSelectedTransactionIds(new Set())
    } else {
      // Only select unsettled transactions
      const unsettled = state.transactions.filter(t => !t.is_settled)
      setSelectedTransactionIds(new Set(unsettled.map(t => t.id)))
    }
  }

  const handleCancelSettlement = () => {
    setSettlementMode('none')
    setSelectedTransactionIds(new Set())
  }

  const handleEnterEditMode = () => {
    setSettlementMode('edit')
    setSelectedTransactionIds(new Set())
    setSettlementStatus('open')
  }

  const handleSettleAll = async () => {
    setSettleAllLoading(true)
    try {
      const preview = await getSettlements().previewSettlementByFilters({
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
        search: search || undefined,
      })
      if (preview.transaction_count === 0) {
        setState((prev) => ({ ...prev, error: t('journal.settlementNoOpen') }))
        return
      }
      setSettleAllPreview(preview)
      setConfirmError(null)
      setConfirmModalOpen(true)
    } catch (err) {
      setState((prev) => ({ ...prev, error: err instanceof Error ? err.message : 'Failed to load preview' }))
    } finally {
      setSettleAllLoading(false)
    }
  }

  const handleConcludeSettlement = () => {
    if (selectedTransactionIds.size === 0) {
      setState((prev) => ({ ...prev, error: t('journal.selectAtLeastOne') }))
      return
    }
    const selected = state.transactions.filter((tx) => selectedTransactionIds.has(tx.id))
    setPendingTransactions(selected)
    setConfirmError(null)
    setConfirmModalOpen(true)
  }

  const handleConfirmSettlement = async () => {
    // The backend owns the execution-date rule (ADR-0009); without it there is
    // nothing valid to submit, and guessing locally is what issue #11 was about.
    if (!executionDateInfo) {
      setConfirmError(executionDateError ?? t('journal.settlementConfirm.executionDateUnavailable'))
      return
    }

    setConfirmLoading(true)
    setConfirmError(null)
    try {
      // Both dates come from the server's clock, never this browser's. The
      // backend requires execution_date >= settlement_date + 7 days; if we
      // dated the settlement locally we would pair our calendar day with a
      // minimum_date derived from the server's. East of UTC those differ every
      // evening, and the settlement the admin just confirmed is refused with a
      // lead-time error naming a date the server itself suggested.
      const today = executionDateInfo.today
      const executionDateStr = executionDateInfo.minimum_date

      if (settleAllPreview) {
        await getSettlements().createSettlementByFilters({
          settlement_date: today,
          execution_date: executionDateStr,
          date_from: dateFrom || undefined,
          date_to: dateTo || undefined,
          search: search || undefined,
        })
      } else {
        // The backend also accepts transaction_ids + settlement_date which are not
        // modelled in the generated SettlementCreateRequest type, so we use a cast.
        // TODO: remove cast once transaction_ids is added to OAS SettlementCreateRequest schema
        // and orval is re-run to include it in the generated type
        await getSettlements().createSettlement(
          ({
            method: 'direct_debit',
            settlement_date: today,
            execution_date: executionDateStr,
            transaction_ids: pendingTransactions.map((tx) => tx.id),
          } as unknown) as Parameters<ReturnType<typeof getSettlements>['createSettlement']>[0]
        )
      }

      setConfirmModalOpen(false)
      setSettleAllPreview(null)
      setPendingTransactions([])
      setSettlementMode('none')
      setSelectedTransactionIds(new Set())
      navigate('/settlements')
    } catch (err) {
      setConfirmError(err instanceof Error ? err.message : 'Failed to create settlement')
    } finally {
      setConfirmLoading(false)
    }
  }

  // Calculate pagination info
  const totalPages = Math.ceil(state.totalItems / pageSize)

  return (
    <div data-testid="journal-page">
      <h1 style={{ margin: '0 0 20px 0' }}>{t('journal.title')}</h1>
        {/* Action buttons bar */}
        <div
          data-testid="journal-actions-bar"
          style={{
            padding: tableSpacing.cellPadding,
            display: 'flex',
            justifyContent: 'flex-end',
            gap: 12,
            flexWrap: 'wrap',
            borderBottom: `1px solid ${tableColors.rowActiveBorder}`,
          }}
        >
          {/* Settlement Controls */}
          {settlementMode === 'none' && (
            <div style={{ display: 'flex', gap: 8 }}>
              <button
                data-testid="journal-settlement-selected-btn"
                onClick={handleEnterEditMode}
                style={{
                  padding: '8px 16px',
                  backgroundColor: '#3b82f6',
                  color: '#ffffff',
                  border: 'none',
                  borderRadius: 6,
                  fontSize: 14,
                  fontWeight: 500,
                  cursor: 'pointer',
                  transition: 'background-color 0.15s',
                }}
                onMouseEnter={(e) => {
                  e.currentTarget.style.backgroundColor = '#2563eb'
                }}
                onMouseLeave={(e) => {
                  e.currentTarget.style.backgroundColor = '#3b82f6'
                }}
              >
                + {t('journal.settlementSelected')}
              </button>
              <button
                data-testid="journal-settlement-all-btn"
                onClick={handleSettleAll}
                disabled={settleAllLoading}
                style={{
                  padding: '8px 16px',
                  backgroundColor: settleAllLoading ? '#6b7280' : '#10b981',
                  color: '#ffffff',
                  border: 'none',
                  borderRadius: 6,
                  fontSize: 14,
                  fontWeight: 500,
                  cursor: settleAllLoading ? 'not-allowed' : 'pointer',
                  transition: 'background-color 0.15s',
                }}
                onMouseEnter={(e) => {
                  if (!settleAllLoading) e.currentTarget.style.backgroundColor = '#059669'
                }}
                onMouseLeave={(e) => {
                  if (!settleAllLoading) e.currentTarget.style.backgroundColor = '#10b981'
                }}
              >
                {settleAllLoading ? '...' : `+ ${t('journal.settlementAll')}`}
              </button>
            </div>
          )}

          {settlementMode === 'edit' && (
            <div style={{ display: 'flex', gap: 8 }}>
              <button
                data-testid="journal-settlement-conclude-btn"
                onClick={handleConcludeSettlement}
                disabled={selectedTransactionIds.size === 0}
                style={{
                  padding: '8px 16px',
                  backgroundColor: selectedTransactionIds.size > 0 ? '#10b981' : '#6b7280',
                  color: '#ffffff',
                  border: 'none',
                  borderRadius: 6,
                  fontSize: 14,
                  fontWeight: 500,
                  cursor: selectedTransactionIds.size > 0 ? 'pointer' : 'not-allowed',
                  transition: 'background-color 0.15s',
                }}
                onMouseEnter={(e) => {
                  if (selectedTransactionIds.size > 0) {
                    e.currentTarget.style.backgroundColor = '#059669'
                  }
                }}
                onMouseLeave={(e) => {
                  if (selectedTransactionIds.size > 0) {
                    e.currentTarget.style.backgroundColor = '#10b981'
                  }
                }}
              >
                {t('journal.concludeSettlement')} ({selectedTransactionIds.size})
              </button>
              <button
                data-testid="journal-settlement-cancel-btn"
                onClick={handleCancelSettlement}
                style={{
                  padding: '8px 16px',
                  backgroundColor: '#ef4444',
                  color: '#ffffff',
                  border: 'none',
                  borderRadius: 6,
                  fontSize: 14,
                  fontWeight: 500,
                  cursor: 'pointer',
                  transition: 'background-color 0.15s',
                }}
                onMouseEnter={(e) => {
                  e.currentTarget.style.backgroundColor = '#dc2626'
                }}
                onMouseLeave={(e) => {
                  e.currentTarget.style.backgroundColor = '#ef4444'
                }}
              >
                {t('journal.cancelSettlement')}
              </button>
            </div>
          )}
        </div>

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
                onChange: handleMobileSortChange,
              }}
              filterCount={mobileFilterCount}
              onFilterToggle={() => setShowMobileFilters(!showMobileFilters)}
              showFilters={showMobileFilters}
              filterContent={
                <div style={{ display: 'flex', flexDirection: 'column', gap: '12px' }}>
                  <div>
                    <div style={{ fontSize: '12px', color: 'rgba(255,255,255,0.35)', fontWeight: 500, textTransform: 'uppercase', marginBottom: '6px' }}>
                      {t('journal.period', 'Period')}
                    </div>
                    <PeriodPicker
                      value={period}
                      onPeriodChange={handlePeriodChange}
                      testId="journal-period-picker"
                    />
                  </div>
                  <div>
                    <div style={{ fontSize: '12px', color: 'rgba(255,255,255,0.35)', fontWeight: 500, textTransform: 'uppercase', marginBottom: '6px' }}>
                      {t('journal.settlementStatus', 'Settlement')}
                    </div>
                    <SettlementStatusFilter
                      value={settlementStatus}
                      onChange={handleSettlementStatusChange}
                      testId="journal-settlement-status-filter"
                    />
                  </div>
                </div>
              }
            />

            {/* Mobile card list */}
            {state.loading ? (
              <div data-testid="journal-loading" style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
                {t('common.loading')}
              </div>
            ) : state.error ? (
              <div data-testid="journal-error-message" style={{ padding: theme.spacing.md, backgroundColor: '#7f1d1d', color: '#fca5a5', borderRadius: 6 }}>
                Error: {state.error}
              </div>
            ) : state.transactions.length === 0 ? (
              <div data-testid="journal-empty-state" style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
                {t('journal.noTransactions')}
              </div>
            ) : (
              <div data-testid="journal-mobile-cards" style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                {state.transactions.map((tx) => (
                  <div
                    key={tx.id}
                    data-testid={`journal-card-${tx.id}`}
                    style={{
                      background: 'rgba(255,255,255,0.03)',
                      border: '1px solid rgba(255,255,255,0.06)',
                      borderRadius: '10px',
                      padding: '14px 16px',
                    }}
                  >
                    {/* Row 1: date+time (left), type badge (right) */}
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '6px' }}>
                      <span style={{ fontSize: '12px', color: theme.colors.text.secondary }}>
                        {new Date(tx.created_at).toLocaleDateString(intlLocale, {
                          year: 'numeric',
                          month: '2-digit',
                          day: '2-digit',
                        })}{' '}
                        {new Date(tx.created_at).toLocaleTimeString(intlLocale, {
                          hour: '2-digit',
                          minute: '2-digit',
                          hour12: false,
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
                    {/* Row 2: member name */}
                    <div style={{ fontWeight: 600, fontSize: '14px', color: theme.colors.text.primary, marginBottom: '4px' }}>
                      {tx.member_name}
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
                    {/* Row 4: amount (right-aligned) + storno action */}
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '8px' }}>
                      {tx.type !== 'storno' ? (
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
                      ) : (
                        <span />
                      )}
                      <div style={{ textAlign: 'right', fontWeight: 700, fontFamily: 'JetBrains Mono, monospace', fontSize: '14px', color: getAmountColor(tx.amount_cents) }}>
                        {formatPrice(tx.amount_cents)}
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}

            {/* Mobile pagination */}
            {state.totalItems > 0 && !state.loading && (
              <PaginationToolbar
                data-testid="journal-pagination"
                currentPage={currentPage}
                totalPages={totalPages}
                totalItems={state.totalItems}
                pageSize={pageSize}
                onPageChange={setCurrentPage}
                onPageSizeChange={(size) => {
                  setPageSize(size)
                  setCurrentPage(1)
                }}
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
            {state.totalItems} {t('statistics.transactions')} {t('common.found')}
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
              backgroundColor: '#0d1829',
              border: '1px solid #2d3748',
              borderRadius: 8,
              color: '#e2e8f0',
              fontSize: '14px',
              fontFamily: 'inherit',
              maxWidth: '400px',
              height: '40px',
              boxSizing: 'border-box',
              verticalAlign: 'middle',
              transition: 'all 0.15s',
            }}
            onFocus={(e) => {
              e.currentTarget.style.borderColor = 'rgba(59,130,246,0.5)'
            }}
            onBlur={(e) => {
              e.currentTarget.style.borderColor = '#2d3748'
            }}
          />

          {/* Center-right: Period picker (segmented control) */}
          <PeriodPicker
            value={period}
            onPeriodChange={handlePeriodChange}
            testId="journal-period-picker"
          />

          {/* Settlement status filter (colored toggle pills) */}
          <SettlementStatusFilter
            value={settlementStatus}
            onChange={handleSettlementStatusChange}
            testId="journal-settlement-status-filter"
          />
        </div>

        {/* Loading state */}
        {state.loading ? (
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
        ) : state.error ? (
          <div
            data-testid="journal-error-message"
            style={{
              padding: tableSpacing.cellPadding,
              backgroundColor: '#7f1d1d',
              color: '#fca5a5',
              borderRadius: 6,
              margin: tableSpacing.cellPadding,
            }}
          >
            Error: {state.error}
          </div>
        ) : state.transactions.length === 0 ? (
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
                    {/* Checkbox header (only in edit mode) */}
                    {settlementMode === 'edit' && (
                      <th
                        style={{
                          ...headerCellBaseStyle,
                          width: '50px',
                        }}
                      >
                        <input
                          type="checkbox"
                          data-testid="journal-select-all-checkbox"
                          checked={selectedTransactionIds.size === state.transactions.length && state.transactions.length > 0}
                          onChange={handleSelectAll}
                        />
                      </th>
                    )}
                    <th
                      style={{
                        ...headerCellBaseStyle,
                        cursor: 'pointer',
                        userSelect: 'none',
                      }}
                      onClick={() => handleSort('created_at')}
                      title="Click to sort by date"
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
                      title="Click to sort by type"
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
                      title="Click to sort by member"
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
                      title="Click to sort by amount"
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
                  {state.transactions.map((tx) => (
                    <tr
                      key={tx.id}
                      data-testid={`journal-table-row-${tx.id}`}
                      onClick={() => {
                        if (settlementMode === 'edit' && !tx.is_settled) {
                          handleToggleTransaction(tx.id)
                        }
                      }}
                      style={{
                        borderBottom: tableColors.rowActiveBorder,
                        backgroundColor: selectedTransactionIds.has(tx.id)
                          ? 'rgba(59, 130, 246, 0.1)'
                          : tableColors.rowActiveBg,
                        transition: 'background-color 150ms',
                        cursor: settlementMode === 'edit' && !tx.is_settled ? 'pointer' : 'default',
                      }}
                    >
                      {/* Checkbox column (only in edit mode) */}
                      {settlementMode === 'edit' && (
                        <td
                          style={{
                            padding: tableSpacing.cellPadding,
                            width: '50px',
                          }}
                        >
                          <input
                            type="checkbox"
                            data-testid={`journal-select-checkbox-${tx.id}`}
                            checked={selectedTransactionIds.has(tx.id)}
                            onChange={() => handleToggleTransaction(tx.id)}
                            onClick={(e) => e.stopPropagation()}
                            disabled={tx.is_settled}
                          />
                        </td>
                      )}
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
                            {new Date(tx.created_at).toLocaleDateString(intlLocale, {
                              year: 'numeric',
                              month: '2-digit',
                              day: '2-digit',
                            })}
                          </div>
                          <div style={{ fontSize: '12px', color: tableColors.cellSecondaryText }}>
                            {new Date(tx.created_at).toLocaleTimeString(intlLocale, {
                              hour: '2-digit',
                              minute: '2-digit',
                              second: '2-digit',
                              hour12: false,
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
                            .trim() || 'No details'
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
                          color: getAmountColor(tx.amount_cents),
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
                        {tx.settlement_date ? (
                          <div style={{ display: 'flex', flexDirection: 'column', gap: '2px' }}>
                            <div>
                              {new Date(tx.settlement_date).toLocaleDateString(intlLocale, {
                                year: 'numeric',
                                month: '2-digit',
                                day: '2-digit',
                              })}
                            </div>
                            <div style={{ fontSize: '12px', color: tableColors.cellSecondaryText }}>
                              {new Date(tx.settlement_date).toLocaleTimeString(intlLocale, {
                                hour: '2-digit',
                                minute: '2-digit',
                                second: '2-digit',
                                hour12: false,
                              })}
                            </div>
                          </div>
                        ) : (
                          '—'
                        )}
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
              {state.totalItems > 0 && (
                <PaginationToolbar
                  data-testid="journal-pagination"
                  currentPage={currentPage}
                  totalPages={totalPages}
                  totalItems={state.totalItems}
                  pageSize={pageSize}
                  onPageChange={setCurrentPage}
                  onPageSizeChange={(size) => {
                    setPageSize(size)
                    setCurrentPage(1)
                  }}
                  testId="journal"
                  showInfo={true}
                  showPageSize={true}
                />
              )}
            </div>
        )}
          </>
        )}

        {/* Settlement Confirm Modal */}
        <SettlementConfirmModal
          isOpen={confirmModalOpen}
          transactions={settleAllPreview ? undefined : pendingTransactions}
          preview={settleAllPreview ?? undefined}
          executionDate={executionDateInfo?.minimum_date ?? null}
          settlementDate={executionDateInfo?.today ?? null}
          onConfirm={handleConfirmSettlement}
          onCancel={() => {
            setConfirmModalOpen(false)
            setSettleAllPreview(null)
            setConfirmError(null)
          }}
          isLoading={confirmLoading}
          error={confirmError ?? executionDateError}
        />

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
