/**
 * Settlements Page
 * Settlement management with unified workflow
 *
 * Implements:
 * - UC-A33: Settlement History (list view with table)
 * - UC-A34: Settlement Details (detail view with export options)
 * - UC-A30/A35: Create Settlement (unified transaction selection)
 *
 * Workflow:
 * 1. List view: Display all settlements with filtering and pagination
 * 2. Create: Select transactions to include in settlement
 * 3. Details: View settlement members, export as SEPA XML or CSV
 *
 * Pattern: Table implementation with filtering, sorting, and pagination
 * Uses TDD with E2E tests in e2etests/tests/admin/settlements.spec.ts
 */

import { useEffect, useState } from 'react'
import { get } from '../services/api'
import { theme } from '../styles/design-system'
import { Card } from '../components/common/Card'
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
  formatPrice,
  formatDate,
  undoSettlement,
  downloadTransactionsCsv,
  Settlement,
} from '../services/settlements'


interface SettlementMember {
  member_id: string
  member_name: string
  amount_cents: number
  iban_masked: string | null
  mandate_reference: string | null
  is_sepa_eligible: boolean
}

interface SettlementDetail extends Settlement {
  period_start: string | null
  period_end: string | null
  sepa_message_id: string | null
  manual_reason: string | null
  cancelled_at: string | null
  notes: string | null
  created_by_admin_id: string
  members: SettlementMember[]
}

type ViewMode = 'list' | 'details' | 'create'

const defaultPageSize = 20

export function SettlementsPage() {
  const [settlements, setSettlements] = useState<Settlement[]>([])
  const [selectedSettlement, setSelectedSettlement] = useState<SettlementDetail | null>(null)
  const [totalItems, setTotalItems] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [viewMode, setViewMode] = useState<ViewMode>('list')

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

  // Load settlements when filters, sorting, or pagination changes
  useEffect(() => {
    if (viewMode === 'list') {
      loadSettlements()
    }
  }, [currentPage, pageSize, dateFrom, dateTo, statusFilter, sortKey, sortOrder, viewMode])

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

  const loadSettlementDetails = async (settlementId: string) => {
    try {
      setLoading(true)
      setError(null)

      const response = await get<SettlementDetail>(`/admin/settlements/${settlementId}`)

      // Handle both wrapped and unwrapped responses
      if (response && typeof response === 'object') {
        if ('id' in response && (response as any).id === settlementId) {
          setSelectedSettlement(response as unknown as SettlementDetail)
          setViewMode('details')
        } else if ('data' in response && typeof (response as any).data === 'object') {
          const data = (response as any).data as unknown as SettlementDetail
          if (data && typeof data === 'object' && 'id' in data) {
            setSelectedSettlement(data)
            setViewMode('details')
          }
        }
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load settlement details')
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
      // Trigger SEPA XML download
      const url = `/api/admin/settlements/${settlementId}/export-sepa`
      window.open(url, '_blank')
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

  const handleUndoSettlement = async (settlementId: string) => {
    if (!confirm('Undo this settlement? All transactions will return to Open state.')) {
      return
    }

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

  // List View
  if (viewMode === 'list') {
    const status = (settlement: Settlement) => getSettlementStatus(settlement as any)

    return (
      <div data-testid="settlements-page">
        <Card title="Abrechnungen" subtitle="Settlement history and management">
          {/* Toolbar */}
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
              {totalItems} Settlements gefunden
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
              Loading settlements...
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
              No settlements found
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
                        Date {sortKey === 'created_at' && (sortOrder === 'asc' ? '↑' : '↓')}
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
                        Created By {sortKey === 'created_by' && (sortOrder === 'asc' ? '↑' : '↓')}
                      </th>
                      <th style={{ ...headerCellBaseStyle, textAlign: 'right' }}>Members</th>
                      <th style={{ ...headerCellBaseStyle, textAlign: 'right' }}>Amount</th>
                      <th style={headerCellBaseStyle}>Status</th>
                      <th style={{ ...headerCellBaseStyle, textAlign: 'center' }}>Actions</th>
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
                          {formatDate(settlement.created_at)}
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

                        {/* Members */}
                        <td
                          data-testid={`settlements-table-cell-members-${settlement.id}`}
                          style={{
                            padding: tableSpacing.cellPadding,
                            color: tableColors.cellText,
                            textAlign: 'right',
                          }}
                        >
                          {settlement.member_count}
                        </td>

                        {/* Amount */}
                        <td
                          data-testid={`settlements-table-cell-amount-${settlement.id}`}
                          style={{
                            padding: tableSpacing.cellPadding,
                            color: tableColors.cellText,
                            fontWeight: 500,
                            textAlign: 'right',
                          }}
                        >
                          <span data-testid={`settlements-price-${settlement.id}`}>
                            {formatPrice(settlement.total_amount_cents)}
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
                            {status(settlement) === 'exported' ? 'Exported' :
                             status(settlement) === 'cancelled' ? 'Cancelled' :
                             'Active'}
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
                            {/* View Details */}
                            <button
                              data-testid={`settlement-view-button-${settlement.id}`}
                              onClick={() => loadSettlementDetails(settlement.id)}
                              style={{
                                padding: '4px 8px',
                                backgroundColor: '#06b6d4',
                                color: '#ffffff',
                                border: 'none',
                                borderRadius: 4,
                                fontSize: 12,
                                fontWeight: 500,
                                cursor: 'pointer',
                                transition: 'background-color 0.15s',
                              }}
                              onMouseEnter={(e) => {
                                e.currentTarget.style.backgroundColor = '#0891b2'
                              }}
                              onMouseLeave={(e) => {
                                e.currentTarget.style.backgroundColor = '#06b6d4'
                              }}
                              title="View Settlement Details"
                            >
                              View
                            </button>

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
                              disabled={settlement.is_cancelled || settlement.exported_at !== null}
                              style={{
                                padding: '4px 8px',
                                backgroundColor:
                                  settlement.is_cancelled || settlement.exported_at !== null
                                    ? '#6b7280'
                                    : '#ef4444',
                                color: '#ffffff',
                                border: 'none',
                                borderRadius: 4,
                                fontSize: 12,
                                fontWeight: 500,
                                cursor:
                                  settlement.is_cancelled || settlement.exported_at !== null
                                    ? 'not-allowed'
                                    : 'pointer',
                                transition: 'background-color 0.15s',
                              }}
                              onMouseEnter={(e) => {
                                if (!(settlement.is_cancelled || settlement.exported_at !== null)) {
                                  e.currentTarget.style.backgroundColor = '#dc2626'
                                }
                              }}
                              onMouseLeave={(e) => {
                                if (!(settlement.is_cancelled || settlement.exported_at !== null)) {
                                  e.currentTarget.style.backgroundColor = '#ef4444'
                                }
                              }}
                              title="Undo Settlement"
                            >
                              Undo
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
        </Card>
      </div>
    )
  }

  // Details View
  if (viewMode === 'details' && selectedSettlement) {
    return (
      <div data-testid="settlement-details-page">
        <div style={{ padding: theme.spacing.xl }}>
          <button
            data-testid="settlement-back-button"
            onClick={() => {
              setViewMode('list')
              setSelectedSettlement(null)
            }}
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
            ← Back to Settlements
          </button>

          {/* Summary Section */}
          <div
            data-testid="settlement-summary"
            style={{
              background: theme.colors.bg.card,
              border: `1px solid ${theme.colors.border.light}`,
              borderRadius: theme.borderRadius.lg,
              padding: theme.spacing.lg,
              marginBottom: theme.spacing.lg,
            }}
          >
            <h2 style={{ marginTop: 0 }}>Settlement Summary</h2>
            <div
              style={{
                display: 'grid',
                gridTemplateColumns: 'repeat(2, 1fr)',
                gap: theme.spacing.lg,
              }}
            >
              <div>
                <div style={{ color: theme.colors.text.secondary, fontSize: theme.typography.fontSize.sm }}>Created</div>
                <div data-testid="settlement-summary-created" style={{ fontWeight: 500, fontSize: theme.typography.fontSize.lg }}>
                  {formatDate(selectedSettlement.created_at)}
                </div>
              </div>
              <div>
                <div style={{ color: theme.colors.text.secondary, fontSize: theme.typography.fontSize.sm }}>Execution Date</div>
                <div data-testid="settlement-execution-date" style={{ fontWeight: 500, fontSize: theme.typography.fontSize.lg }}>
                  {selectedSettlement.execution_date ? formatDate(selectedSettlement.execution_date) : '—'}
                </div>
              </div>
              <div>
                <div style={{ color: theme.colors.text.secondary, fontSize: theme.typography.fontSize.sm }}>Total Members</div>
                <div data-testid="settlement-total-members" style={{ fontWeight: 500, fontSize: theme.typography.fontSize.lg }}>
                  {selectedSettlement.member_count}
                </div>
              </div>
              <div>
                <div style={{ color: theme.colors.text.secondary, fontSize: theme.typography.fontSize.sm }}>Total Amount</div>
                <div data-testid="settlement-summary-total" style={{ fontWeight: 500, fontSize: theme.typography.fontSize.lg }}>
                  {formatPrice(selectedSettlement.total_amount_cents)}
                </div>
              </div>
            </div>
          </div>

          {/* Members List */}
          {selectedSettlement.members && selectedSettlement.members.length > 0 && (
            <div
              style={{
                background: theme.colors.bg.card,
                border: `1px solid ${theme.colors.border.light}`,
                borderRadius: theme.borderRadius.lg,
                padding: theme.spacing.lg,
                marginBottom: theme.spacing.lg,
              }}
            >
              <h3>Settlement Members</h3>
              <table
                data-testid="settlement-members-table"
                style={{
                  width: '100%',
                  borderCollapse: 'collapse',
                  fontSize: theme.typography.fontSize.sm,
                }}
              >
                <thead>
                  <tr style={{ borderBottom: `1px solid ${tableColors.rowActiveBorder}` }}>
                    <th style={{ padding: tableSpacing.cellPadding, textAlign: 'left', fontWeight: 600, color: tableColors.headerText }}>Name</th>
                    <th style={{ padding: tableSpacing.cellPadding, textAlign: 'right', fontWeight: 600, color: tableColors.headerText }}>Amount</th>
                    <th style={{ padding: tableSpacing.cellPadding, textAlign: 'left', fontWeight: 600, color: tableColors.headerText }}>SEPA Status</th>
                    <th style={{ padding: tableSpacing.cellPadding, textAlign: 'left', fontWeight: 600, color: tableColors.headerText }}>Mandate Ref</th>
                  </tr>
                </thead>
                <tbody>
                  {selectedSettlement.members.map((member) => (
                    <tr
                      key={member.member_id}
                      data-testid="settlement-member-row"
                      style={{ borderBottom: `1px solid ${tableColors.rowActiveBorder}` }}
                    >
                      <td
                        data-testid="member-name"
                        style={{ padding: tableSpacing.cellPadding, color: tableColors.cellText }}
                      >
                        {member.member_name}
                      </td>
                      <td
                        data-testid="member-amount"
                        style={{ padding: tableSpacing.cellPadding, textAlign: 'right', fontWeight: 500, color: tableColors.cellText }}
                      >
                        {formatPrice(member.amount_cents)}
                      </td>
                      <td
                        data-testid="member-sepa-status"
                        style={{
                          padding: tableSpacing.cellPadding,
                          color: member.is_sepa_eligible ? '#16A34A' : '#DC2626',
                        }}
                      >
                        {member.is_sepa_eligible ? 'Valid' : 'Invalid'}
                      </td>
                      <td style={{ padding: tableSpacing.cellPadding, color: tableColors.cellSecondaryText }}>
                        {member.mandate_reference ? member.mandate_reference : '—'}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {/* Downloads Section */}
          <div
            data-testid="settlement-downloads"
            style={{
              background: theme.colors.bg.card,
              border: `1px solid ${theme.colors.border.light}`,
              borderRadius: theme.borderRadius.lg,
              padding: theme.spacing.lg,
              display: 'flex',
              gap: theme.spacing.md,
            }}
          >
            <button
              onClick={() => handleExportSepa(selectedSettlement.id)}
              style={{
                padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                background: theme.colors.semantic.primary,
                color: 'white',
                border: 'none',
                borderRadius: theme.borderRadius.sm,
                cursor: 'pointer',
              }}
              onMouseEnter={(e) => {
                e.currentTarget.style.opacity = '0.9'
              }}
              onMouseLeave={(e) => {
                e.currentTarget.style.opacity = '1'
              }}
            >
              Download SEPA XML
            </button>
            <button
              onClick={() => handleExportCsv(selectedSettlement.id)}
              style={{
                padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                background: theme.colors.bg.secondary,
                color: theme.colors.text.primary,
                border: `1px solid ${theme.colors.border.light}`,
                borderRadius: theme.borderRadius.sm,
                cursor: 'pointer',
              }}
              onMouseEnter={(e) => {
                e.currentTarget.style.opacity = '0.9'
              }}
              onMouseLeave={(e) => {
                e.currentTarget.style.opacity = '1'
              }}
            >
              Download CSV
            </button>
          </div>
        </div>
      </div>
    )
  }

  // Settlement Creation - Transaction Selection (Step 1)
  if (viewMode === 'create') {
    return (
      <div style={{ padding: theme.spacing.xl }}>
        <button
          onClick={() => setViewMode('list')}
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
          ← Back
        </button>

        <h2>Create Settlement</h2>
        <p style={{ color: theme.colors.text.secondary, marginBottom: theme.spacing.lg }}>
          Select transactions to include in this settlement. After creation, you can export the settlement as SEPA XML or CSV.
        </p>

        <div data-testid="settlement-transaction-selection" style={{ marginBottom: theme.spacing.lg }}>
          <h3>Select Transactions</h3>
          <div
            data-testid="settlement-selection-table"
            style={{
              background: theme.colors.bg.card,
              border: `1px solid ${theme.colors.border.light}`,
              borderRadius: theme.borderRadius.lg,
              padding: theme.spacing.lg,
            }}
          >
            <p style={{ color: theme.colors.text.secondary }}>Transactions loading...</p>
            <div style={{ display: 'flex', gap: theme.spacing.md, marginTop: theme.spacing.md }}>
              <button
                data-testid="settlement-select-all"
                style={{
                  padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                  background: theme.colors.semantic.primary,
                  color: 'white',
                  border: 'none',
                  borderRadius: theme.borderRadius.sm,
                  cursor: 'pointer',
                }}
              >
                Select All
              </button>
              <button
                data-testid="settlement-select-none"
                style={{
                  padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                  background: theme.colors.bg.secondary,
                  color: theme.colors.text.primary,
                  border: `1px solid ${theme.colors.border.light}`,
                  borderRadius: theme.borderRadius.sm,
                  cursor: 'pointer',
                }}
              >
                Select None
              </button>
            </div>
          </div>
        </div>

        <button
          data-testid="settlement-selection-continue"
          onClick={() => setViewMode('list')}
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
          Create Settlement
        </button>
      </div>
    )
  }

  return null
}
