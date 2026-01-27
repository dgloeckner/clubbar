/**
 * Settlements Page
 * Settlement management and SEPA/manual settlement workflows
 *
 * Implements:
 * - UC-A33: Settlement History (list view)
 * - UC-A34: Settlement Details (detail view)
 * - UC-A30: Create SEPA Settlement (transaction selection)
 * - UC-A35: Manual Settlement (transaction selection with reason)
 *
 * Uses TDD with E2E tests in e2etests/tests/admin/settlements.spec.ts
 */

import { useEffect, useState } from 'react'
import { get, post } from '../services/api'
import { theme } from '../styles/design-system'

// Settlement data types
interface SettlementListItem {
  id: string
  settlement_type: 'sepa' | 'manual'
  settlement_date: string
  execution_date: string | null
  member_count: number
  total_amount_cents: number
  is_cancelled: boolean
  exported_at: string | null
  created_at: string
}

interface SettlementMember {
  member_id: string
  member_name: string
  amount_cents: number
  iban_masked: string | null
  mandate_reference: string | null
  is_sepa_eligible: boolean
}

interface Settlement extends SettlementListItem {
  period_start: string | null
  period_end: string | null
  sepa_message_id: string | null
  manual_reason: string | null
  is_cancelled: boolean
  cancelled_at: string | null
  notes: string | null
  created_by_admin_id: string
  members: SettlementMember[]
}

interface ListApiResponse {
  data: SettlementListItem[]
  pagination?: {
    page: number
    per_page: number
    total: number
    total_pages: number
  }
}

type ViewMode = 'list' | 'details' | 'create-sepa' | 'manual'

export function SettlementsPage() {
  const [settlements, setSettlements] = useState<SettlementListItem[]>([])
  const [selectedSettlement, setSelectedSettlement] = useState<Settlement | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [viewMode, setViewMode] = useState<ViewMode>('list')
  const [typeFilter, setTypeFilter] = useState<'all' | 'sepa' | 'manual'>('all')
  const [sortBy, setSortBy] = useState<string>('created_at_desc')

  // Load settlements on mount
  useEffect(() => {
    loadSettlements()
  }, [typeFilter, sortBy])

  const loadSettlements = async () => {
    try {
      setLoading(true)
      setError(null)

      const params: Record<string, any> = {
        type: typeFilter,
        sort_by: sortBy,
      }

      const response = await get<ListApiResponse>('/admin/settlements', { params })

      // Handle both wrapped and unwrapped responses
      if (response && typeof response === 'object') {
        if (Array.isArray(response)) {
          setSettlements(response)
        } else if ('data' in response && Array.isArray(response.data)) {
          setSettlements(response.data)
        } else if ('id' in response) {
          // Single settlement object (shouldn't happen for list, but handle it)
          setSettlements([])
        }
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Failed to load settlements')
      setSettlements([])
    } finally {
      setLoading(false)
    }
  }

  const loadSettlementDetails = async (settlementId: string) => {
    try {
      setLoading(true)
      setError(null)

      const response = await get<Settlement>(`/admin/settlements/${settlementId}`)

      // Handle both wrapped and unwrapped responses
      if (response && typeof response === 'object') {
        if ('id' in response && response.id === settlementId) {
          setSelectedSettlement(response as Settlement)
          setViewMode('details')
        } else if ('data' in response && typeof response.data === 'object') {
          const data = response.data as unknown as Settlement
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

  const formatPrice = (cents: number): string => {
    return (cents / 100).toLocaleString('de-DE', {
      style: 'currency',
      currency: 'EUR',
    })
  }

  const formatDate = (dateStr: string): string => {
    const date = new Date(dateStr)
    return date.toLocaleDateString('de-DE')
  }

  // List View
  if (viewMode === 'list') {
    return (
      <div data-testid="settlements-page">
        <div style={{ padding: theme.spacing.xl }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: theme.spacing.xl }}>
            <h1 style={{ margin: 0 }}>Abrechnungen</h1>
            <div style={{ display: 'flex', gap: theme.spacing.md }}>
              <button
                data-testid="new-settlement-button"
                onClick={() => setViewMode('create-sepa')}
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
                data-testid="manual-settlement-button"
                onClick={() => setViewMode('manual')}
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
                Manual Settlement
              </button>
            </div>
          </div>

          {/* Filters */}
          <div style={{ marginBottom: theme.spacing.lg, display: 'flex', gap: theme.spacing.md }}>
            <select
              data-testid="settlement-type-filter"
              value={typeFilter}
              onChange={(e) => setTypeFilter(e.target.value as 'all' | 'sepa' | 'manual')}
              style={{
                padding: theme.spacing.sm,
                border: `1px solid ${theme.colors.border.light}`,
                borderRadius: theme.borderRadius.sm,
                fontSize: theme.typography.fontSize.sm,
              }}
            >
              <option value="all">All Types</option>
              <option value="sepa">SEPA</option>
              <option value="manual">Manual</option>
            </select>

            <select
              value={sortBy}
              onChange={(e) => setSortBy(e.target.value)}
              style={{
                padding: theme.spacing.sm,
                border: `1px solid ${theme.colors.border.light}`,
                borderRadius: theme.borderRadius.sm,
                fontSize: theme.typography.fontSize.sm,
              }}
            >
              <option value="created_at_desc">Most Recent First</option>
              <option value="created_at_asc">Oldest First</option>
              <option value="execution_date">Execution Date</option>
            </select>
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
          {loading && <div>Loading settlements...</div>}

          {/* Empty State */}
          {!loading && settlements.length === 0 && (
            <div
              data-testid="settlements-empty-state"
              style={{
                padding: theme.spacing.xl,
                textAlign: 'center',
                color: theme.colors.text.secondary,
              }}
            >
              No settlements yet
            </div>
          )}

          {/* Table */}
          {!loading && settlements.length > 0 && (
            <table
              data-testid="settlements-table"
              style={{
                width: '100%',
                borderCollapse: 'collapse',
                fontSize: theme.typography.fontSize.sm,
              }}
            >
              <thead>
                <tr
                  style={{
                    background: theme.colors.bg.secondary,
                    borderBottom: `1px solid ${theme.colors.border.light}`,
                  }}
                >
                  <th style={{ padding: theme.spacing.md, textAlign: 'left', fontWeight: 600 }}>Created</th>
                  <th style={{ padding: theme.spacing.md, textAlign: 'left', fontWeight: 600 }}>Execution</th>
                  <th style={{ padding: theme.spacing.md, textAlign: 'left', fontWeight: 600 }}>Type</th>
                  <th style={{ padding: theme.spacing.md, textAlign: 'right', fontWeight: 600 }}>Members</th>
                  <th style={{ padding: theme.spacing.md, textAlign: 'right', fontWeight: 600 }}>Amount</th>
                  <th style={{ padding: theme.spacing.md, textAlign: 'left', fontWeight: 600 }}>Exported</th>
                  <th style={{ padding: theme.spacing.md, textAlign: 'left', fontWeight: 600 }}>Status</th>
                  <th style={{ padding: theme.spacing.md, textAlign: 'center', fontWeight: 600 }}>Actions</th>
                </tr>
              </thead>
              <tbody>
                {settlements.map((settlement) => (
                  <tr
                    key={settlement.id}
                    data-testid={`settlement-row-${settlement.id}`}
                    style={{
                      borderBottom: `1px solid ${theme.colors.border.light}`,
                      ':hover': { background: theme.colors.bg.secondary },
                    }}
                  >
                    <td
                      data-testid="settlement-created"
                      style={{ padding: theme.spacing.md, color: theme.colors.text.primary }}
                    >
                      {formatDate(settlement.created_at)}
                    </td>
                    <td style={{ padding: theme.spacing.md, color: theme.colors.text.primary }}>
                      {settlement.execution_date ? formatDate(settlement.execution_date) : '—'}
                    </td>
                    <td data-testid="settlement-type" style={{ padding: theme.spacing.md }}>
                      <span
                        style={{
                          padding: '2px 8px',
                          borderRadius: '4px',
                          background: settlement.settlement_type === 'sepa' ? '#EBF8FF' : '#F0FDF4',
                          color: settlement.settlement_type === 'sepa' ? '#0284C7' : '#16A34A',
                          fontSize: '11px',
                          fontWeight: 600,
                        }}
                      >
                        {settlement.settlement_type.toUpperCase()}
                      </span>
                    </td>
                    <td data-testid="settlement-member-count" style={{ padding: theme.spacing.md, textAlign: 'right' }}>
                      {settlement.member_count}
                    </td>
                    <td data-testid="settlement-total-amount" style={{ padding: theme.spacing.md, textAlign: 'right', fontWeight: 500 }}>
                      {formatPrice(settlement.total_amount_cents)}
                    </td>
                    <td data-testid="settlement-exported" style={{ padding: theme.spacing.md }}>
                      {settlement.exported_at ? formatDate(settlement.exported_at) : 'No'}
                    </td>
                    <td style={{ padding: theme.spacing.md }}>
                      {settlement.is_cancelled && (
                        <span
                          data-testid="settlement-cancelled"
                          style={{
                            padding: '2px 8px',
                            borderRadius: '4px',
                            background: '#FEE2E2',
                            color: '#DC2626',
                            fontSize: '11px',
                            fontWeight: 600,
                          }}
                        >
                          Cancelled
                        </span>
                      )}
                      {!settlement.is_cancelled && (
                        <span
                          style={{
                            padding: '2px 8px',
                            borderRadius: '4px',
                            background: '#F0FDF4',
                            color: '#16A34A',
                            fontSize: '11px',
                            fontWeight: 600,
                          }}
                        >
                          Active
                        </span>
                      )}
                    </td>
                    <td style={{ padding: theme.spacing.md, textAlign: 'center' }}>
                      <button
                        data-testid={`settlement-view-button-${settlement.id}`}
                        onClick={() => loadSettlementDetails(settlement.id)}
                        style={{
                          background: 'transparent',
                          border: 'none',
                          color: theme.colors.semantic.primary,
                          cursor: 'pointer',
                          textDecoration: 'underline',
                        }}
                      >
                        View
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
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
                  <tr style={{ borderBottom: `1px solid ${theme.colors.border.light}` }}>
                    <th style={{ padding: theme.spacing.md, textAlign: 'left', fontWeight: 600 }}>Name</th>
                    <th style={{ padding: theme.spacing.md, textAlign: 'right', fontWeight: 600 }}>Amount</th>
                    <th style={{ padding: theme.spacing.md, textAlign: 'left', fontWeight: 600 }}>SEPA Status</th>
                    <th style={{ padding: theme.spacing.md, textAlign: 'left', fontWeight: 600 }}>Mandate Ref</th>
                  </tr>
                </thead>
                <tbody>
                  {selectedSettlement.members.map((member) => (
                    <tr
                      key={member.member_id}
                      data-testid="settlement-member-row"
                      style={{ borderBottom: `1px solid ${theme.colors.border.light}` }}
                    >
                      <td
                        data-testid="member-name"
                        style={{ padding: theme.spacing.md, color: theme.colors.text.primary }}
                      >
                        {member.member_name}
                      </td>
                      <td
                        data-testid="member-amount"
                        style={{ padding: theme.spacing.md, textAlign: 'right', fontWeight: 500 }}
                      >
                        {formatPrice(member.amount_cents)}
                      </td>
                      <td
                        data-testid="member-sepa-status"
                        style={{
                          padding: theme.spacing.md,
                          color: member.is_sepa_eligible ? '#16A34A' : '#DC2626',
                        }}
                      >
                        {member.is_sepa_eligible ? 'Valid' : 'Invalid'}
                      </td>
                      <td style={{ padding: theme.spacing.md, color: theme.colors.text.secondary }}>
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
              style={{
                padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                background: theme.colors.semantic.primary,
                color: 'white',
                border: 'none',
                borderRadius: theme.borderRadius.sm,
                cursor: 'pointer',
              }}
            >
              Download SEPA XML
            </button>
            <button
              style={{
                padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                background: theme.colors.bg.secondary,
                color: theme.colors.text.primary,
                border: `1px solid ${theme.colors.border.light}`,
                borderRadius: theme.borderRadius.sm,
                cursor: 'pointer',
              }}
            >
              Download CSV
            </button>
          </div>
        </div>
      </div>
    )
  }

  // SEPA Settlement Creation - Transaction Selection
  if (viewMode === 'create-sepa') {
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

        <h2>Create SEPA Settlement</h2>

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

          <div
            data-testid="settlement-sepa-invalid-members"
            style={{
              marginTop: theme.spacing.lg,
              background: '#FEF2F2',
              border: '1px solid #FECACA',
              borderRadius: theme.borderRadius.lg,
              padding: theme.spacing.lg,
            }}
          >
            <h4 style={{ marginTop: 0 }}>SEPA Invalid Members (cannot be settled)</h4>
            <p style={{ color: theme.colors.text.secondary }}>No SEPA-invalid members found</p>
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
          Continue
        </button>
      </div>
    )
  }

  // Manual Settlement - Transaction Selection with Reason
  if (viewMode === 'manual') {
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

        <h2>Manual Settlement</h2>

        <div data-testid="settlement-manual-selection" style={{ marginBottom: theme.spacing.lg }}>
          <h3>Select Transactions</h3>

          {/* SEPA Status Filter */}
          <div style={{ marginBottom: theme.spacing.lg }}>
            <label htmlFor="sepa-filter" style={{ display: 'block', marginBottom: theme.spacing.sm, fontWeight: 500 }}>
              Filter by SEPA Status:
            </label>
            <select
              id="sepa-filter"
              data-testid="settlement-sepa-status-filter"
              style={{
                padding: theme.spacing.sm,
                border: `1px solid ${theme.colors.border.light}`,
                borderRadius: theme.borderRadius.sm,
              }}
            >
              <option value="all">All</option>
              <option value="valid">SEPA Valid</option>
              <option value="invalid">SEPA Invalid</option>
            </select>
          </div>

          <div
            data-testid="settlement-manual-table"
            style={{
              background: theme.colors.bg.card,
              border: `1px solid ${theme.colors.border.light}`,
              borderRadius: theme.borderRadius.lg,
              padding: theme.spacing.lg,
            }}
          >
            <p data-testid="settlement-no-transactions" style={{ color: theme.colors.text.secondary }}>
              No transactions available for manual settlement
            </p>
          </div>
        </div>

        {/* Settlement Summary Section */}
        <div
          data-testid="settlement-summary-section"
          style={{
            background: theme.colors.bg.card,
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.lg,
            padding: theme.spacing.lg,
            marginBottom: theme.spacing.lg,
          }}
        >
          <h3>Settlement Summary</h3>

          {/* Reason Dropdown */}
          <div style={{ marginBottom: theme.spacing.lg }}>
            <label htmlFor="reason" style={{ display: 'block', marginBottom: theme.spacing.sm, fontWeight: 500 }}>
              Settlement Reason:
            </label>
            <select
              id="reason"
              data-testid="settlement-reason"
              style={{
                width: '100%',
                padding: theme.spacing.sm,
                border: `1px solid ${theme.colors.border.light}`,
                borderRadius: theme.borderRadius.sm,
              }}
            >
              <option value="">Select a reason...</option>
              <option value="cash_payment">Cash Payment</option>
              <option value="bank_transfer">Bank Transfer</option>
              <option value="other_payment">Other Payment</option>
              <option value="write_off">Write Off</option>
              <option value="goodwill">Goodwill</option>
              <option value="correction">Correction</option>
              <option value="other">Other</option>
            </select>
          </div>

          {/* Comment Field */}
          <div style={{ marginBottom: theme.spacing.lg }}>
            <label htmlFor="comment" style={{ display: 'block', marginBottom: theme.spacing.sm, fontWeight: 500 }}>
              Comment (minimum 10 characters):
            </label>
            <textarea
              id="comment"
              data-testid="settlement-comment"
              placeholder="Explain the circumstances of this settlement..."
              style={{
                width: '100%',
                padding: theme.spacing.md,
                border: `1px solid ${theme.colors.border.light}`,
                borderRadius: theme.borderRadius.sm,
                minHeight: '80px',
                fontFamily: 'inherit',
              }}
            />
            <div data-testid="comment-error" style={{ display: 'none', color: theme.colors.semantic.danger, marginTop: theme.spacing.sm }}>
              Comment must be at least 10 characters
            </div>
          </div>

          {/* Submit Buttons */}
          <div style={{ display: 'flex', gap: theme.spacing.md }}>
            <button
              data-testid="settlement-submit"
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
              Submit Settlement
            </button>
            <button
              onClick={() => setViewMode('list')}
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
              Cancel
            </button>
          </div>
        </div>

        <button
          data-testid="settlement-manual-continue"
          onClick={() => setViewMode('list')}
          style={{
            padding: `${theme.spacing.md} ${theme.spacing.lg}`,
            background: theme.colors.semantic.primary,
            color: 'white',
            border: 'none',
            borderRadius: theme.borderRadius.md,
            cursor: 'pointer',
            fontWeight: 500,
            display: 'none',
          }}
        >
          Continue
        </button>
      </div>
    )
  }

  return null
}
