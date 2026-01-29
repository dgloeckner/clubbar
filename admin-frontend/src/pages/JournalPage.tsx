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
import { TypeFilter } from '../components/forms/TypeFilter'
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
  const [filterType, setFilterType] = useState<'all' | 'purchase' | 'correction'>('all')
  const [search, setSearch] = useState('')

  // Sorting state
  const [sortKey, setSortKey] = useState<'created_at' | 'amount' | 'type' | 'member'>('created_at')
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('desc')

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
  }, [currentPage, pageSize, dateFrom, dateTo, filterType, search, sortKey, sortDirection])

  async function loadTransactions() {
    try {
      setState((prev) => ({ ...prev, loading: true, error: null }))

      const result = await getTransactions(
        currentPage,
        pageSize,
        dateFrom || undefined,
        dateTo || undefined,
        filterType,
        undefined, // memberId - future enhancement
        search || undefined,
        sortKey,
        sortDirection
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

  const handleFilterTypeChange = (type: 'all' | 'purchase' | 'correction') => {
    setFilterType(type)
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

  // Calculate pagination info
  const totalPages = Math.ceil(state.totalItems / pageSize)

  return (
    <div data-testid="journal-page">
      <Card title="Buchungsjournal" subtitle="Transaction journal and booking log">
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
          <div style={{ flex: 1, minWidth: 200 }}>
            <input
              data-testid="journal-search-input"
              type="text"
              placeholder="Search member name or notes..."
              value={search}
              onChange={(e) => handleSearch(e.target.value)}
              style={{
                width: '100%',
                padding: '8px 12px',
                border: `1px solid ${tableColors.rowActiveBorder}`,
                borderRadius: 6,
                backgroundColor: 'rgba(15, 29, 50, 0.4)',
                color: tableColors.cellText,
                fontSize: 14,
              }}
            />
          </div>

          {/* Center-right: Period picker (segmented control) */}
          <PeriodPicker
            value={period}
            onPeriodChange={handlePeriodChange}
            testId="journal-period-picker"
          />

          {/* Type filter (colored toggle pills) */}
          <TypeFilter
            value={filterType}
            onTypeChange={handleFilterTypeChange}
            testId="journal-type-filter"
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
                  </tr>
                </thead>
                <tbody>
                  {state.transactions.map((tx) => (
                    <tr
                      key={tx.id}
                      data-testid={`journal-table-row-${tx.id}`}
                      style={{
                        borderBottom: tableColors.rowActiveBorder,
                        backgroundColor: tableColors.rowActiveBg,
                        transition: 'background-color 150ms',
                      }}
                    >
                      {/* Date */}
                      <td
                        data-testid={`journal-table-cell-date-${tx.id}`}
                        style={{
                          padding: tableSpacing.cellPadding,
                          color: tableColors.cellText,
                        }}
                      >
                        {new Date(tx.created_at).toLocaleDateString('en-US', {
                          year: 'numeric',
                          month: '2-digit',
                          day: '2-digit',
                        })}
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
