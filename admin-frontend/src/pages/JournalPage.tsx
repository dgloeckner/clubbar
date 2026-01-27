/**
 * Journal Page
 * Transaction journal showing member transaction history
 * Implements UC-A20: View Tab (member transaction history)
 */

import { useEffect, useState } from 'react'
import { Card } from '../components/common/Card'
import { theme, formatPrice, formatDate } from '../styles/design-system'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { useLoading } from '../context/LoadingContext'
import { getMembers, Member } from '../services/members'
import { getMemberTransactionHistory, MemberTransactionHistory } from '../services/journal'

type TransactionFilter = 'all' | 'purchase' | 'correction'

export function JournalPage() {
  const breakpoint = useBreakpoint()
  const { setIsLoading } = useLoading()

  // Members list state
  const [members, setMembers] = useState<Member[]>([])
  const [search, setSearch] = useState('')
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  // Transaction detail state
  const [selectedMember, setSelectedMember] = useState<Member | null>(null)
  const [transactionHistory, setTransactionHistory] = useState<MemberTransactionHistory | null>(null)
  const [transactionFilter, setTransactionFilter] = useState<TransactionFilter>('all')
  const [transactionLoading, setTransactionLoading] = useState(false)
  const [transactionPage, setTransactionPage] = useState(1)

  // Load members list
  useEffect(() => {
    const loadMembers = async () => {
      try {
        setLoading(true)
        setIsLoading(true)
        const response = await getMembers(1, 100, search || undefined, { is_active: true })

        setMembers(response.items)
        setError(null)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load members')
      } finally {
        setLoading(false)
        setIsLoading(false)
      }
    }

    const timer = setTimeout(loadMembers, search ? 500 : 0) // Debounce search
    return () => clearTimeout(timer)
  }, [search, setIsLoading])

  // Load transaction history for selected member
  useEffect(() => {
    if (!selectedMember) return

    const loadTransactions = async () => {
      try {
        setTransactionLoading(true)
        setIsLoading(true)
        const history = await getMemberTransactionHistory(selectedMember.id, {
          type: transactionFilter,
        })

        setTransactionHistory(history)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load transactions')
      } finally {
        setTransactionLoading(false)
        setIsLoading(false)
      }
    }

    loadTransactions()
  }, [selectedMember, transactionFilter, setIsLoading])

  // Filter transactions based on current filter
  const filteredTransactions = transactionHistory?.transactions || []
  const itemsPerPage = 20
  const totalPages = Math.ceil(filteredTransactions.length / itemsPerPage)
  const startIndex = (transactionPage - 1) * itemsPerPage
  const paginatedTransactions = filteredTransactions.slice(startIndex, startIndex + itemsPerPage)

  // Render members list view
  if (!selectedMember) {
    return (
      <div data-testid="journal-page">
        <Card title="Buchungsjournal" subtitle="Member transaction history">
          {/* Search box */}
          <div style={{ marginBottom: theme.spacing.lg }}>
            <input
              data-testid="journal-search"
              type="text"
              placeholder="Search members..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              style={{
                width: '100%',
                padding: theme.spacing.md,
                border: `1px solid ${theme.colors.border.light}`,
                borderRadius: theme.borderRadius.md,
                background: theme.colors.bg.input,
                color: theme.colors.text.primary,
                fontSize: theme.typography.fontSize.base,
              }}
            />
          </div>

          {/* Error message */}
          {error && (
            <div
              style={{
                padding: theme.spacing.md,
                background: 'rgba(239, 68, 68, 0.1)',
                color: theme.colors.semantic.danger,
                borderRadius: theme.borderRadius.md,
                marginBottom: theme.spacing.lg,
              }}
            >
              {error}
            </div>
          )}

          {/* Loading state */}
          {loading && (
            <div style={{ padding: theme.spacing.lg, color: theme.colors.text.secondary }}>
              Loading members...
            </div>
          )}

          {/* Members list */}
          {!loading && members.length > 0 && (
            <div>
              <div
                style={{
                  overflowX: breakpoint === 'smallMobile' ? 'auto' : 'visible',
                  WebkitOverflowScrolling: 'touch',
                }}
              >
                <table
                  style={{
                    width: '100%',
                    borderCollapse: 'collapse',
                    color: theme.colors.text.primary,
                  }}
                >
                  <thead>
                    <tr
                      style={{
                        background: theme.colors.bg.secondary,
                        borderBottom: `1px solid ${theme.colors.border.light}`,
                      }}
                    >
                      <th
                        style={{
                          padding: theme.spacing.md,
                          textAlign: 'left',
                          fontWeight: theme.typography.fontWeight.semibold,
                          fontSize: theme.typography.fontSize.sm,
                          color: theme.colors.text.secondary,
                        }}
                      >
                        Name
                      </th>
                      <th
                        style={{
                          padding: theme.spacing.md,
                          textAlign: 'left',
                          fontWeight: theme.typography.fontWeight.semibold,
                          fontSize: theme.typography.fontSize.sm,
                          color: theme.colors.text.secondary,
                        }}
                      >
                        Balance
                      </th>
                      <th
                        style={{
                          padding: theme.spacing.md,
                          textAlign: 'left',
                          fontWeight: theme.typography.fontWeight.semibold,
                          fontSize: theme.typography.fontSize.sm,
                          color: theme.colors.text.secondary,
                        }}
                      >
                        Action
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {members.map((member) => (
                      <tr
                        key={member.id}
                        data-testid="journal-member-row"
                        style={{
                          borderBottom: `1px solid ${theme.colors.border.light}`,
                          transition: `background ${theme.transitions.default}`,
                          cursor: 'pointer',
                        }}
                        onMouseEnter={(e) => {
                          e.currentTarget.style.background = theme.colors.bg.hover
                        }}
                        onMouseLeave={(e) => {
                          e.currentTarget.style.background = 'transparent'
                        }}
                      >
                        <td
                          data-testid="member-name"
                          style={{
                            padding: theme.spacing.md,
                            fontSize: theme.typography.fontSize.base,
                          }}
                        >
                          {member.first_name} {member.last_name}
                        </td>
                        <td
                          data-testid="member-balance"
                          style={{
                            padding: theme.spacing.md,
                            fontSize: theme.typography.fontSize.base,
                            fontFamily: 'monospace',
                            color:
                              member.balance_cents > 0
                                ? theme.colors.semantic.success
                                : member.balance_cents < 0
                                  ? theme.colors.semantic.warning
                                  : theme.colors.text.secondary,
                            fontWeight: theme.typography.fontWeight.semibold,
                          }}
                        >
                          {formatPrice(member.balance_cents)}
                        </td>
                        <td style={{ padding: theme.spacing.md }}>
                          <button
                            data-testid="view-transactions-button"
                            onClick={() => setSelectedMember(member)}
                            style={{
                              padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                              background: theme.colors.semantic.primary,
                              color: 'white',
                              border: 'none',
                              borderRadius: theme.borderRadius.md,
                              fontSize: theme.typography.fontSize.sm,
                              fontWeight: theme.typography.fontWeight.semibold,
                              cursor: 'pointer',
                              transition: `all ${theme.transitions.default}`,
                            }}
                            onMouseEnter={(e) => {
                              e.currentTarget.style.background = '#2563eb'
                            }}
                            onMouseLeave={(e) => {
                              e.currentTarget.style.background = theme.colors.semantic.primary
                            }}
                          >
                            View
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div
                style={{
                  marginTop: theme.spacing.lg,
                  fontSize: theme.typography.fontSize.sm,
                  color: theme.colors.text.secondary,
                }}
              >
                {members.length} active members
              </div>
            </div>
          )}

          {/* Empty state */}
          {!loading && members.length === 0 && (
            <div
              data-testid="empty-members-state"
              style={{
                padding: theme.spacing.lg,
                textAlign: 'center',
                color: theme.colors.text.secondary,
              }}
            >
              No members found. Try a different search.
            </div>
          )}
        </Card>
      </div>
    )
  }

  // Render transaction history view
  return (
    <div data-testid="journal-page">
      <Card
        title={`${selectedMember.first_name} ${selectedMember.last_name} - Transactions`}
        subtitle={`Balance: ${formatPrice(transactionHistory?.current_balance_cents || 0)}`}
      >
        {/* Back button */}
        <div style={{ marginBottom: theme.spacing.lg }}>
          <button
            data-testid="journal-back"
            onClick={() => {
              setSelectedMember(null)
              setTransactionPage(1)
              setTransactionFilter('all')
            }}
            style={{
              padding: `${theme.spacing.sm} ${theme.spacing.md}`,
              background: 'rgba(59, 130, 246, 0.1)',
              color: theme.colors.semantic.primary,
              border: `1px solid ${theme.colors.semantic.primary}`,
              borderRadius: theme.borderRadius.md,
              fontSize: theme.typography.fontSize.sm,
              fontWeight: theme.typography.fontWeight.semibold,
              cursor: 'pointer',
              transition: `all ${theme.transitions.default}`,
            }}
          >
            ← Back to Members
          </button>
        </div>

        {/* Filter buttons */}
        <div style={{ display: 'flex', gap: theme.spacing.sm, marginBottom: theme.spacing.lg, flexWrap: 'wrap' }}>
          {(['all', 'purchase', 'correction'] as const).map((filter) => (
            <button
              key={filter}
              data-testid={`transaction-filter-${filter}`}
              onClick={() => {
                setTransactionFilter(filter)
                setTransactionPage(1)
              }}
              style={{
                padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                background: transactionFilter === filter ? theme.colors.semantic.primary : 'rgba(59, 130, 246, 0.1)',
                color: transactionFilter === filter ? 'white' : theme.colors.semantic.primary,
                border: 'none',
                borderRadius: theme.borderRadius.md,
                fontSize: theme.typography.fontSize.sm,
                fontWeight: theme.typography.fontWeight.semibold,
                cursor: 'pointer',
                transition: `all ${theme.transitions.default}`,
              }}
            >
              {filter === 'all' ? 'All' : filter === 'purchase' ? 'Purchases' : 'Corrections'}
            </button>
          ))}
        </div>

        {/* Error message */}
        {error && (
          <div
            style={{
              padding: theme.spacing.md,
              background: 'rgba(239, 68, 68, 0.1)',
              color: theme.colors.semantic.danger,
              borderRadius: theme.borderRadius.md,
              marginBottom: theme.spacing.lg,
            }}
          >
            {error}
          </div>
        )}

        {/* Loading state */}
        {transactionLoading && (
          <div style={{ padding: theme.spacing.lg, color: theme.colors.text.secondary }}>
            Loading transactions...
          </div>
        )}

        {/* Transaction list */}
        {!transactionLoading && paginatedTransactions.length > 0 && (
          <div>
            <div
              data-testid="transaction-list"
              style={{
                overflowX: breakpoint === 'smallMobile' ? 'auto' : 'visible',
                WebkitOverflowScrolling: 'touch',
                marginBottom: theme.spacing.lg,
              }}
            >
              <table
                data-testid="transactions-table"
                style={{
                  width: '100%',
                  borderCollapse: 'collapse',
                  color: theme.colors.text.primary,
                }}
              >
                <thead>
                  <tr
                    style={{
                      background: theme.colors.bg.secondary,
                      borderBottom: `1px solid ${theme.colors.border.light}`,
                    }}
                  >
                    <th
                      style={{
                        padding: theme.spacing.md,
                        textAlign: 'left',
                        fontWeight: theme.typography.fontWeight.semibold,
                        fontSize: theme.typography.fontSize.sm,
                        color: theme.colors.text.secondary,
                      }}
                    >
                      Date
                    </th>
                    <th
                      style={{
                        padding: theme.spacing.md,
                        textAlign: 'left',
                        fontWeight: theme.typography.fontWeight.semibold,
                        fontSize: theme.typography.fontSize.sm,
                        color: theme.colors.text.secondary,
                      }}
                    >
                      Type
                    </th>
                    <th
                      style={{
                        padding: theme.spacing.md,
                        textAlign: 'left',
                        fontWeight: theme.typography.fontWeight.semibold,
                        fontSize: theme.typography.fontSize.sm,
                        color: theme.colors.text.secondary,
                      }}
                    >
                      Description
                    </th>
                    <th
                      style={{
                        padding: theme.spacing.md,
                        textAlign: 'right',
                        fontWeight: theme.typography.fontWeight.semibold,
                        fontSize: theme.typography.fontSize.sm,
                        color: theme.colors.text.secondary,
                      }}
                    >
                      Amount
                    </th>
                    <th
                      style={{
                        padding: theme.spacing.md,
                        textAlign: 'right',
                        fontWeight: theme.typography.fontWeight.semibold,
                        fontSize: theme.typography.fontSize.sm,
                        color: theme.colors.text.secondary,
                      }}
                    >
                      Balance
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {paginatedTransactions.map((transaction) => (
                    <tr
                      key={transaction.id}
                      data-testid="transaction-row"
                      style={{
                        borderBottom: `1px solid ${theme.colors.border.light}`,
                      }}
                    >
                      <td
                        data-testid="transaction-date"
                        style={{
                          padding: theme.spacing.md,
                          fontSize: theme.typography.fontSize.sm,
                          color: theme.colors.text.secondary,
                          whiteSpace: 'nowrap',
                        }}
                      >
                        {formatDate(transaction.date)}
                      </td>
                      <td
                        data-testid="transaction-type"
                        style={{
                          padding: theme.spacing.md,
                          fontSize: theme.typography.fontSize.sm,
                        }}
                      >
                        <span
                          style={{
                            display: 'inline-block',
                            padding: `2px 8px`,
                            background:
                              transaction.type === 'correction'
                                ? 'rgba(59, 130, 246, 0.15)'
                                : 'rgba(249, 115, 22, 0.15)',
                            color:
                              transaction.type === 'correction'
                                ? theme.colors.semantic.primary
                                : theme.colors.semantic.warning,
                            borderRadius: '4px',
                            fontWeight: theme.typography.fontWeight.semibold,
                            fontSize: theme.typography.fontSize.xs,
                          }}
                        >
                          {transaction.type === 'correction' ? 'KORR' : 'PURCH'}
                        </span>
                      </td>
                      <td
                        style={{
                          padding: theme.spacing.md,
                          fontSize: theme.typography.fontSize.sm,
                        }}
                      >
                        {transaction.description}
                      </td>
                      <td
                        data-testid="transaction-amount"
                        style={{
                          padding: theme.spacing.md,
                          fontSize: theme.typography.fontSize.sm,
                          fontFamily: 'monospace',
                          textAlign: 'right',
                          color:
                            transaction.amount_cents < 0
                              ? theme.colors.semantic.success
                              : theme.colors.semantic.warning,
                          fontWeight: theme.typography.fontWeight.semibold,
                        }}
                      >
                        {transaction.amount_cents < 0 ? '-' : '+'}
                        {formatPrice(Math.abs(transaction.amount_cents))}
                      </td>
                      <td
                        style={{
                          padding: theme.spacing.md,
                          fontSize: theme.typography.fontSize.sm,
                          fontFamily: 'monospace',
                          textAlign: 'right',
                          color:
                            transaction.running_total_cents > 0
                              ? theme.colors.semantic.success
                              : transaction.running_total_cents < 0
                                ? theme.colors.semantic.warning
                                : theme.colors.text.secondary,
                          fontWeight: theme.typography.fontWeight.semibold,
                        }}
                      >
                        {formatPrice(transaction.running_total_cents)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            {/* Pagination */}
            {totalPages > 1 && (
              <div
                style={{
                  display: 'flex',
                  justifyContent: 'space-between',
                  alignItems: 'center',
                  marginTop: theme.spacing.lg,
                  fontSize: theme.typography.fontSize.sm,
                  color: theme.colors.text.secondary,
                }}
              >
                <button
                  onClick={() => setTransactionPage(Math.max(1, transactionPage - 1))}
                  disabled={transactionPage === 1}
                  style={{
                    padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                    background: transactionPage === 1 ? 'rgba(94, 109, 116, 0.3)' : theme.colors.semantic.primary,
                    color: transactionPage === 1 ? theme.colors.text.muted : 'white',
                    border: 'none',
                    borderRadius: theme.borderRadius.md,
                    cursor: transactionPage === 1 ? 'default' : 'pointer',
                  }}
                >
                  ← Previous
                </button>
                <span>
                  Page {transactionPage} of {totalPages}
                </span>
                <button
                  onClick={() => setTransactionPage(Math.min(totalPages, transactionPage + 1))}
                  disabled={transactionPage === totalPages}
                  style={{
                    padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                    background: transactionPage === totalPages ? 'rgba(94, 109, 116, 0.3)' : theme.colors.semantic.primary,
                    color: transactionPage === totalPages ? theme.colors.text.muted : 'white',
                    border: 'none',
                    borderRadius: theme.borderRadius.md,
                    cursor: transactionPage === totalPages ? 'default' : 'pointer',
                  }}
                >
                  Next →
                </button>
              </div>
            )}

            {/* Transaction count */}
            <div
              style={{
                marginTop: theme.spacing.lg,
                fontSize: theme.typography.fontSize.sm,
                color: theme.colors.text.secondary,
              }}
            >
              {filteredTransactions.length} transactions ({transactionPage * itemsPerPage - itemsPerPage + 1}-
              {Math.min(transactionPage * itemsPerPage, filteredTransactions.length)})
            </div>
          </div>
        )}

        {/* Empty state */}
        {!transactionLoading && paginatedTransactions.length === 0 && (
          <div
            data-testid="empty-transactions"
            style={{
              padding: theme.spacing.lg,
              textAlign: 'center',
              color: theme.colors.text.secondary,
            }}
          >
            No transactions found for this member.
          </div>
        )}
      </Card>
    </div>
  )
}
