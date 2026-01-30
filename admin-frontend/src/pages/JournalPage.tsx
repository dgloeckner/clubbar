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
import { Card } from '../components/common/Card'
import { PeriodPicker } from '../components/forms/PeriodPicker'
import { SettlementStatusFilter } from '../components/forms/SettlementStatusFilter'
import { PaginationToolbar } from '../components/tables/PaginationToolbar'
import { onLoadingStateChange } from '../services/api'
import {
  getTransactions,
  formatTransactionType,
  getTransactionTypeColor,
  getAmountColor,
  type GlobalTransaction
} from '../services/transactions'
import {
  createSettlement
} from '../services/settlements'
import {
  tableColors,
  tableSpacing,
  headerCellBaseStyle,
  headerRowStyle,
} from '../styles/tableTokens'

interface JournalPageState {
  transactions: GlobalTransaction[]
  totalItems: number
  loading: boolean
  error: string | null
}

const defaultPageSize = 20

export function JournalPage() {
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

  // Track if component is mounted to prevent state updates on unmounted component
  const isMountedRef = useRef(true)

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
    loadTransactions()
  }, [currentPage, pageSize, dateFrom, dateTo, settlementStatus, search, sortKey, sortDirection])

  async function loadTransactions() {
    try {
      setState((prev) => ({ ...prev, loading: true, error: null }))

      const result = await getTransactions(
        currentPage,
        pageSize,
        dateFrom || undefined,
        dateTo || undefined,
        'all', // type - deprecated, always 'all' for now
        undefined, // memberId - future enhancement
        search || undefined,
        sortKey,
        sortDirection,
        settlementStatus
      )

      // Only update state if component is still mounted
      if (isMountedRef.current) {
        setState((prev) => ({
          ...prev,
          transactions: result.items,
          totalItems: result.total,
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

  const handleCreateCorrection = () => {
    // TODO: Implement correction creation modal/workflow
    console.log('Create correction clicked')
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
  }

  const handleSettleAll = async () => {
    if (!confirm('Settle all open transactions?')) return

    try {
      setState((prev) => ({ ...prev, loading: true, error: null }))

      const openTransactions = state.transactions.filter(t => !t.is_settled)
      if (openTransactions.length === 0) {
        setState((prev) => ({ ...prev, loading: false, error: 'No open transactions to settle' }))
        return
      }

      const today = new Date().toISOString().split('T')[0]
      const executionDate = new Date()
      executionDate.setDate(executionDate.getDate() + 7)
      const executionDateStr = executionDate.toISOString().split('T')[0]

      await createSettlement(
        openTransactions.map(t => t.id),
        today,
        executionDateStr
      )

      setSettlementMode('none')
      setSelectedTransactionIds(new Set())
      await loadTransactions()
    } catch (err) {
      const errorMsg = err instanceof Error ? err.message : 'Failed to settle all transactions'
      setState((prev) => ({ ...prev, loading: false, error: errorMsg }))
    }
  }

  const handleConcludeSettlement = async () => {
    if (selectedTransactionIds.size === 0) {
      alert('Please select at least one transaction')
      return
    }

    try {
      setState((prev) => ({ ...prev, loading: true, error: null }))

      const today = new Date().toISOString().split('T')[0]
      const executionDate = new Date()
      executionDate.setDate(executionDate.getDate() + 7)
      const executionDateStr = executionDate.toISOString().split('T')[0]

      await createSettlement(
        Array.from(selectedTransactionIds),
        today,
        executionDateStr
      )

      setSettlementMode('none')
      setSelectedTransactionIds(new Set())
      await loadTransactions()
    } catch (err) {
      const errorMsg = err instanceof Error ? err.message : 'Failed to create settlement'
      setState((prev) => ({ ...prev, loading: false, error: errorMsg }))
    }
  }

  // Calculate pagination info
  const totalPages = Math.ceil(state.totalItems / pageSize)

  return (
    <div data-testid="journal-page">
      <Card title="Buchungsjournal" subtitle="Transaction journal and booking log">
        {/* Action buttons bar */}
        <div
          data-testid="journal-actions-bar"
          style={{
            padding: tableSpacing.cellPadding,
            display: 'flex',
            justifyContent: 'flex-end',
            gap: 12,
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
            + Correction
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
                + Settlement (selected)
              </button>
              <button
                data-testid="journal-settlement-all-btn"
                onClick={handleSettleAll}
                style={{
                  padding: '8px 16px',
                  backgroundColor: '#10b981',
                  color: '#ffffff',
                  border: 'none',
                  borderRadius: 6,
                  fontSize: 14,
                  fontWeight: 500,
                  cursor: 'pointer',
                  transition: 'background-color 0.15s',
                }}
                onMouseEnter={(e) => {
                  e.currentTarget.style.backgroundColor = '#059669'
                }}
                onMouseLeave={(e) => {
                  e.currentTarget.style.backgroundColor = '#10b981'
                }}
              >
                + Settlement (all)
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
                Conclude Settlement ({selectedTransactionIds.size})
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
                Cancel Settlement
              </button>
            </div>
          )}
        </div>

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
            {state.totalItems} Transactions gefunden
          </div>

          {/* Center-left: Search input */}
          <input
            type="text"
            value={search}
            onChange={(e) => {
              handleSearch(e.target.value)
            }}
            placeholder="Search members or details..."
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
            Loading transactions...
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
            No transactions found
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
                      Date {sortKey === 'created_at' && (sortDirection === 'asc' ? '↑' : '↓')}
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
                      Type {sortKey === 'type' && (sortDirection === 'asc' ? '↑' : '↓')}
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
                      Member {sortKey === 'member' && (sortDirection === 'asc' ? '↑' : '↓')}
                    </th>
                    <th
                      style={headerCellBaseStyle}
                      data-testid="journal-header-details"
                    >
                      Details
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
                      Amount {sortKey === 'amount' && (sortDirection === 'asc' ? '↑' : '↓')}
                    </th>
                    <th
                      style={headerCellBaseStyle}
                      data-testid="journal-header-settlement-date"
                    >
                      Settlement Date
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {state.transactions.map((tx) => (
                    <tr
                      key={tx.id}
                      data-testid={`journal-table-row-${tx.id}`}
                      style={{
                        borderBottom: tableColors.rowActiveBorder,
                        backgroundColor: selectedTransactionIds.has(tx.id)
                          ? 'rgba(59, 130, 246, 0.1)'
                          : tableColors.rowActiveBg,
                        transition: 'background-color 150ms',
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
                            {new Date(tx.created_at).toLocaleDateString('en-US', {
                              year: 'numeric',
                              month: '2-digit',
                              day: '2-digit',
                            })}
                          </div>
                          <div style={{ fontSize: '12px', color: tableColors.cellSecondaryText }}>
                            {new Date(tx.created_at).toLocaleTimeString('en-US', {
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
                            padding: '4px 8px',
                            borderRadius: 4,
                            fontSize: 12,
                            fontWeight: 500,
                            backgroundColor: getTransactionTypeColor(tx.type).split(' ')[0],
                            color: getTransactionTypeColor(tx.type).split(' ')[1],
                          }}
                        >
                          {formatTransactionType(tx.type)}
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
                          fontWeight: 500,
                        }}
                      >
                        €{(tx.amount_cents / 100).toFixed(2)}
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
                              {new Date(tx.settlement_date).toLocaleDateString('en-US', {
                                year: 'numeric',
                                month: '2-digit',
                                day: '2-digit',
                              })}
                            </div>
                            <div style={{ fontSize: '12px', color: tableColors.cellSecondaryText }}>
                              {new Date(tx.settlement_date).toLocaleTimeString('en-US', {
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
      </Card>
    </div>
  )
}
