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

import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { PeriodPicker } from '../components/forms/PeriodPicker'
import { useFormatters } from '../hooks/useFormatters'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { useExecutionDateInfo } from '../hooks/useExecutionDateInfo'
import { toIsoDate } from '../utils/dates'
import { MobileToolbar } from '../components/layout/MobileToolbar'
import { SettlementStatusFilter } from '../components/forms/SettlementStatusFilter'
import { PaginationToolbar } from '../components/tables/PaginationToolbar'
import { onLoadingStateChange } from '../api/client'
import { getTransactions } from '../api/generated/transactions/transactions'
import { getSettlements } from '../api/generated/settlements/settlements'
import { getMembers } from '../api/generated/members/members'
import { getTransactionTypeColor, getAmountColor } from '../utils/transactions'
import { getCurrentLanguage } from '../i18n/config'
import { getLocalizedName } from '../utils/i18n-helpers'
import { SettlementConfirmModal } from '../components/modals/SettlementConfirmModal'
import type { GlobalTransaction, SettlementFilterPreview, MemberListItem } from '../api/generated'
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

  // Correction modal state
  const [showCorrectionModal, setShowCorrectionModal] = useState(false)
  const [members, setMembers] = useState<MemberListItem[]>([])
  const [correctionForm, setCorrectionForm] = useState({
    memberId: '',
    amountCents: 0,
    reason: '',
  })
  const [correctionError, setCorrectionError] = useState<string | null>(null)
  const [correctionLoading, setCorrectionLoading] = useState(false)

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

      const resolvedItems = localizeTransactionItems(result.items ?? [])

      // Only update state if component is still mounted
      if (isMountedRef.current) {
        setState((prev) => ({
          ...prev,
          transactions: resolvedItems,
          totalItems: result.total ?? 0,
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

  const handleCreateCorrection = async () => {
    setShowCorrectionModal(true)
    setCorrectionError(null)
    setCorrectionForm({ memberId: '', amountCents: 0, reason: '' })

    // Load members for dropdown
    try {
      const response = await getMembers().listMembers({ page: 1, per_page: 100, sort_by: 'name_asc' })
      setMembers(response.data ?? [])
    } catch (err) {
      setCorrectionError('Failed to load members')
    }
  }

  const handleCorrectionModalClose = () => {
    setShowCorrectionModal(false)
    setCorrectionError(null)
    setCorrectionForm({ memberId: '', amountCents: 0, reason: '' })
  }

  const handleSubmitCorrection = async () => {
    // Validate
    if (!correctionForm.memberId) {
      setCorrectionError('Please select a member')
      return
    }
    if (!correctionForm.reason.trim()) {
      setCorrectionError('Please enter a reason')
      return
    }
    if (correctionForm.amountCents === 0) {
      setCorrectionError('Amount must not be zero')
      return
    }

    try {
      setCorrectionLoading(true)
      setCorrectionError(null)

      await getTransactions().createManualTransaction(correctionForm.memberId, {
        amount_cents: correctionForm.amountCents,
        notes: correctionForm.reason,
      })

      // Close modal and reload transactions
      handleCorrectionModalClose()
      await loadTransactions()
    } catch (err) {
      const errorMsg = err instanceof Error ? err.message : 'Failed to create correction'
      // Check if it's a SEPA validation error (422)
      if (err instanceof Error && errorMsg.includes('422')) {
        setCorrectionError('Member does not have valid SEPA mandate. Please update member IBAN and mandate reference.')
      } else if (err instanceof Error && errorMsg.includes('not_found')) {
        setCorrectionError('Member not found')
      } else {
        setCorrectionError(errorMsg)
      }
    } finally {
      setCorrectionLoading(false)
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
      const today = toIsoDate(new Date())
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
            settlement_type: 'sepa',
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
          <button
            onClick={handleCreateCorrection}
            data-testid="journal-create-correction-btn"
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
            + {t('journal.correction')}
          </button>

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
                    {/* Row 4: amount (right-aligned) */}
                    <div style={{ textAlign: 'right', fontWeight: 700, fontFamily: 'JetBrains Mono, monospace', fontSize: '14px', color: getAmountColor(tx.amount_cents) }}>
                      {formatPrice(tx.amount_cents)}
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
          onConfirm={handleConfirmSettlement}
          onCancel={() => {
            setConfirmModalOpen(false)
            setSettleAllPreview(null)
            setConfirmError(null)
          }}
          isLoading={confirmLoading}
          error={confirmError ?? executionDateError}
        />

        {/* Correction Modal */}
        {showCorrectionModal && (
          <div
            data-testid="journal-correction-modal"
            style={{
              position: 'fixed',
              top: 0,
              left: 0,
              right: 0,
              bottom: 0,
              background: 'rgba(0, 0, 0, 0.5)',
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              zIndex: 1100,
            }}
            onClick={handleCorrectionModalClose}
          >
            <div
              data-testid="journal-correction-modal-content"
              style={{
                background: theme.colors.bg.secondary,
                borderRadius: isMobile ? 0 : theme.borderRadius.lg,
                padding: isMobile ? theme.spacing.lg : theme.spacing.xl,
                maxWidth: isMobile ? '100%' : '500px',
                width: isMobile ? '100%' : '90%',
                height: isMobile ? '100%' : 'auto',
                maxHeight: isMobile ? '100%' : '90vh',
                overflowY: 'auto' as const,
                boxShadow: isMobile ? 'none' : '0 25px 50px rgba(0, 0, 0, 0.5)',
              }}
              onClick={(e) => e.stopPropagation()}
            >
              <h2 data-testid="journal-correction-modal-title" style={{ margin: 0, marginBottom: theme.spacing.lg, fontSize: theme.typography.fontSize.xl }}>
                {t('journal.addCorrection')}
              </h2>

              {correctionError && (
                <div
                  data-testid="journal-correction-error"
                  style={{
                    padding: theme.spacing.md,
                    background: `${theme.colors.semantic.danger}20`,
                    borderLeft: `3px solid ${theme.colors.semantic.danger}`,
                    color: theme.colors.semantic.danger,
                    marginBottom: theme.spacing.lg,
                    borderRadius: theme.borderRadius.md,
                    fontSize: theme.typography.fontSize.sm,
                  }}
                >
                  {correctionError}
                </div>
              )}

              <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.lg }}>
                {/* Member Selection */}
                <div>
                  <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                    {t('journal.member')} *
                  </label>
                  <select
                    data-testid="journal-correction-member-select"
                    value={correctionForm.memberId}
                    onChange={(e) => setCorrectionForm({ ...correctionForm, memberId: e.target.value })}
                    style={{
                      width: '100%',
                      padding: `${theme.spacing.md} 28px ${theme.spacing.md} ${theme.spacing.lg}`,
                      background: theme.colors.bg.input,
                      border: `1px solid ${theme.colors.border.light}`,
                      borderRadius: theme.borderRadius.md,
                      color: theme.colors.text.primary,
                      fontSize: theme.typography.fontSize.sm,
                      boxSizing: 'border-box',
                      cursor: 'pointer',
                      outline: 'none',
                      appearance: 'none',
                      WebkitAppearance: 'none',
                      backgroundImage: `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E")`,
                      backgroundRepeat: 'no-repeat',
                      backgroundPosition: 'right 10px center',
                      backgroundSize: '12px',
                    }}
                  >
                    <option value="">{t('journal.selectMember')}</option>
                    {members.map((member) => (
                      <option key={member.id} value={member.id}>
                        {member.first_name} {member.last_name} {!member.is_sepa_valid && '⚠ No SEPA'}
                      </option>
                    ))}
                  </select>
                </div>

                {/* Amount */}
                <div>
                  <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                    {t('journal.amountEur')} *
                  </label>
                  <input
                    data-testid="journal-correction-amount-input"
                    type="number"
                    step="0.01"
                    value={correctionForm.amountCents / 100}
                    onChange={(e) => setCorrectionForm({ ...correctionForm, amountCents: Math.round(parseFloat(e.target.value) * 100) })}
                    placeholder="0.00"
                    style={{
                      width: '100%',
                      padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                      background: theme.colors.bg.input,
                      border: `1px solid ${theme.colors.border.light}`,
                      borderRadius: theme.borderRadius.md,
                      color: theme.colors.text.primary,
                      fontSize: theme.typography.fontSize.sm,
                      boxSizing: 'border-box',
                    }}
                  />
                  <div style={{ fontSize: '11px', color: theme.colors.text.secondary, marginTop: '4px' }}>
                    {t('journal.amountHint')}
                  </div>
                </div>

                {/* Reason */}
                <div>
                  <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                    {t('journal.reason')} *
                  </label>
                  <textarea
                    data-testid="journal-correction-reason-input"
                    value={correctionForm.reason}
                    onChange={(e) => setCorrectionForm({ ...correctionForm, reason: e.target.value })}
                    placeholder={t('journal.reasonPlaceholder')}
                    maxLength={255}
                    style={{
                      width: '100%',
                      padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                      background: theme.colors.bg.input,
                      border: `1px solid ${theme.colors.border.light}`,
                      borderRadius: theme.borderRadius.md,
                      color: theme.colors.text.primary,
                      fontSize: theme.typography.fontSize.sm,
                      boxSizing: 'border-box',
                      minHeight: '80px',
                      fontFamily: 'inherit',
                      resize: 'vertical',
                    }}
                  />
                </div>

                {/* Buttons */}
                <div style={{ display: 'flex', gap: theme.spacing.md, justifyContent: 'flex-end' }}>
                  <button
                    onClick={handleCorrectionModalClose}
                    disabled={correctionLoading}
                    style={{
                      padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                      background: theme.colors.bg.tertiary,
                      border: `1px solid ${theme.colors.border.light}`,
                      borderRadius: theme.borderRadius.md,
                      color: theme.colors.text.primary,
                      cursor: correctionLoading ? 'not-allowed' : 'pointer',
                      fontSize: theme.typography.fontSize.sm,
                      fontWeight: 500,
                      opacity: correctionLoading ? 0.6 : 1,
                    }}
                  >
                    {t('common.cancel')}
                  </button>
                  <button
                    onClick={handleSubmitCorrection}
                    disabled={correctionLoading}
                    data-testid="journal-correction-submit-btn"
                    style={{
                      padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                      background: theme.colors.semantic.primary,
                      border: 'none',
                      borderRadius: theme.borderRadius.md,
                      color: '#ffffff',
                      cursor: correctionLoading ? 'not-allowed' : 'pointer',
                      fontSize: theme.typography.fontSize.sm,
                      fontWeight: 500,
                      opacity: correctionLoading ? 0.6 : 1,
                    }}
                  >
                    {correctionLoading ? t('journal.saving') : t('journal.addCorrection')}
                  </button>
                </div>
              </div>
            </div>
          </div>
        )}
    </div>
  )
}
