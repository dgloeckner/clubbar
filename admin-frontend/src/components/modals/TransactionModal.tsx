/**
 * Transaction Modal Component
 *
 * Displays member transaction history in a modal overlay.
 * Implements UC-A20: View Tab (member transaction details)
 *
 * Features:
 * - Transaction list with filtering
 * - Date/type/description/amount/running total columns
 * - Filter by type: All, Purchase, Storno
 * - Empty state handling
 * - Error handling
 * - Loading state
 */

import { useEffect, useState } from 'react'
import { theme, formatPrice, formatDate } from '../../styles/design-system'
import { getTransactions } from '../../api/generated/transactions/transactions'
import type { MemberTransactionHistory } from '../../api/generated'

type TransactionFilter = 'all' | 'purchase' | 'storno'

interface TransactionModalProps {
  isOpen: boolean
  memberId: string
  memberName: string
  currentBalance: number
  onClose: () => void
}

export function TransactionModal({
  isOpen,
  memberId,
  memberName,
  currentBalance,
  onClose,
}: TransactionModalProps) {
  const [transactionHistory, setTransactionHistory] = useState<MemberTransactionHistory | null>(null)
  const [filter, setFilter] = useState<TransactionFilter>('all')
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // Load transactions when modal opens
  useEffect(() => {
    if (!isOpen) return

    const loadTransactions = async () => {
      try {
        setLoading(true)
        const history = await getTransactions().getMemberTransactions(memberId, {
          type: filter === 'all' ? undefined : filter,
        })

        setTransactionHistory(history)
        setError(null)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load transactions')
      } finally {
        setLoading(false)
      }
    }

    loadTransactions()
  }, [isOpen, memberId, filter])

  if (!isOpen) return null

  const transactions = transactionHistory?.transactions || []

  // Modal backdrop
  return (
    <div
      data-testid="transaction-modal-backdrop"
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
        padding: theme.spacing.lg,
      }}
      onClick={onClose}
    >
      {/* Modal content */}
      <div
        data-testid="transaction-modal"
        style={{
          background: theme.colors.bg.card,
          border: `1px solid ${theme.colors.border.light}`,
          borderRadius: theme.borderRadius.lg,
          width: '100%',
          maxWidth: '900px',
          maxHeight: '80vh',
          display: 'flex',
          flexDirection: 'column',
          boxShadow: theme.shadows.modal,
        }}
        onClick={(e) => e.stopPropagation()}
      >
        {/* Header */}
        <div
          data-testid="transaction-modal-header"
          style={{
            padding: theme.spacing.lg,
            borderBottom: `1px solid ${theme.colors.border.light}`,
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'flex-start',
          }}
        >
          <div>
            <h2
              style={{
                margin: 0,
                fontSize: theme.typography.fontSize.xl,
                fontWeight: theme.typography.fontWeight.bold,
                color: theme.colors.text.primary,
              }}
            >
              {memberName} - Transaction History
            </h2>
            <p
              style={{
                margin: `${theme.spacing.sm} 0 0 0`,
                fontSize: theme.typography.fontSize.sm,
                color: theme.colors.text.secondary,
              }}
            >
              Current Balance: <strong style={{ fontFamily: 'JetBrains Mono, monospace' }}>{formatPrice(currentBalance)}</strong>
            </p>
          </div>

          {/* Close button */}
          <button
            data-testid="modal-close-button"
            onClick={onClose}
            style={{
              background: 'transparent',
              border: 'none',
              color: theme.colors.text.secondary,
              fontSize: theme.typography.fontSize.lg,
              cursor: 'pointer',
              padding: theme.spacing.sm,
              transition: `color ${theme.transitions.default}`,
            }}
            onMouseEnter={(e) => {
              e.currentTarget.style.color = theme.colors.text.primary
            }}
            onMouseLeave={(e) => {
              e.currentTarget.style.color = theme.colors.text.secondary
            }}
          >
            ✕
          </button>
        </div>

        {/* Body */}
        <div
          style={{
            flex: 1,
            overflow: 'auto',
            padding: theme.spacing.lg,
          }}
        >
          {/* Filter buttons */}
          <div style={{ display: 'flex', gap: theme.spacing.sm, marginBottom: theme.spacing.lg, flexWrap: 'wrap' }}>
            {(['all', 'purchase', 'storno'] as const).map((filterType) => (
              <button
                key={filterType}
                data-testid={`transaction-filter-${filterType}`}
                onClick={() => setFilter(filterType)}
                style={{
                  padding: `${theme.spacing.sm} ${theme.spacing.md}`,
                  background: filter === filterType ? theme.colors.semantic.primary : 'rgba(59, 130, 246, 0.1)',
                  color: filter === filterType ? 'white' : theme.colors.semantic.primary,
                  border: 'none',
                  borderRadius: theme.borderRadius.md,
                  fontSize: theme.typography.fontSize.sm,
                  fontWeight: theme.typography.fontWeight.semibold,
                  cursor: 'pointer',
                  transition: `all ${theme.transitions.default}`,
                }}
              >
                {filterType === 'all' ? 'All' : filterType === 'purchase' ? 'Purchases' : 'Stornos'}
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
                fontSize: theme.typography.fontSize.sm,
              }}
            >
              {error}
            </div>
          )}

          {/* Loading state */}
          {loading && (
            <div style={{ padding: theme.spacing.lg, color: theme.colors.text.secondary, textAlign: 'center' }}>
              Loading transactions...
            </div>
          )}

          {/* Transaction table */}
          {!loading && transactions.length > 0 && (
            <div style={{ overflowX: 'auto', marginBottom: theme.spacing.lg }}>
              <table
                data-testid="transaction-table"
                style={{
                  width: '100%',
                  borderCollapse: 'collapse',
                  color: theme.colors.text.primary,
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
                    <th
                      style={{
                        padding: theme.spacing.md,
                        textAlign: 'left',
                        fontWeight: theme.typography.fontWeight.semibold,
                        color: theme.colors.text.secondary,
                        fontSize: theme.typography.fontSize.xs,
                      }}
                    >
                      Date
                    </th>
                    <th
                      style={{
                        padding: theme.spacing.md,
                        textAlign: 'left',
                        fontWeight: theme.typography.fontWeight.semibold,
                        color: theme.colors.text.secondary,
                        fontSize: theme.typography.fontSize.xs,
                      }}
                    >
                      Type
                    </th>
                    <th
                      style={{
                        padding: theme.spacing.md,
                        textAlign: 'left',
                        fontWeight: theme.typography.fontWeight.semibold,
                        color: theme.colors.text.secondary,
                        fontSize: theme.typography.fontSize.xs,
                      }}
                    >
                      Description
                    </th>
                    <th
                      style={{
                        padding: theme.spacing.md,
                        textAlign: 'right',
                        fontWeight: theme.typography.fontWeight.semibold,
                        color: theme.colors.text.secondary,
                        fontSize: theme.typography.fontSize.xs,
                      }}
                    >
                      Amount
                    </th>
                    <th
                      style={{
                        padding: theme.spacing.md,
                        textAlign: 'right',
                        fontWeight: theme.typography.fontWeight.semibold,
                        color: theme.colors.text.secondary,
                        fontSize: theme.typography.fontSize.xs,
                      }}
                    >
                      Balance
                    </th>
                  </tr>
                </thead>
                <tbody>
                  {transactions.map((transaction) => (
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
                          color: theme.colors.text.secondary,
                          whiteSpace: 'nowrap',
                        }}
                      >
                        {formatDate(transaction.date ?? '')}
                      </td>
                      <td
                        data-testid="transaction-type"
                        style={{
                          padding: theme.spacing.md,
                        }}
                      >
                        <span
                          style={{
                            display: 'inline-block',
                            padding: `2px 6px`,
                            background:
                              transaction.type === 'storno'
                                ? 'rgba(59, 130, 246, 0.15)'
                                : 'rgba(249, 115, 22, 0.15)',
                            color:
                              transaction.type === 'storno'
                                ? theme.colors.semantic.primary
                                : theme.colors.semantic.warning,
                            borderRadius: '4px',
                            fontWeight: theme.typography.fontWeight.semibold,
                            fontSize: theme.typography.fontSize.xs,
                          }}
                        >
                          {transaction.type === 'storno' ? 'STORNO' : 'PURCH'}
                        </span>
                      </td>
                      <td
                        data-testid="transaction-description"
                        style={{
                          padding: theme.spacing.md,
                        }}
                      >
                        {transaction.description}
                      </td>
                      <td
                        data-testid="transaction-amount"
                        style={{
                          padding: theme.spacing.md,
                          textAlign: 'right',
                          fontFamily: 'JetBrains Mono, monospace',
                          color:
                            (transaction.amount_cents ?? 0) < 0
                              ? theme.colors.semantic.success
                              : theme.colors.semantic.warning,
                          fontWeight: theme.typography.fontWeight.semibold,
                        }}
                      >
                        {(transaction.amount_cents ?? 0) < 0 ? '-' : '+'}
                        {formatPrice(Math.abs(transaction.amount_cents ?? 0))}
                      </td>
                      <td
                        data-testid="transaction-running-total"
                        style={{
                          padding: theme.spacing.md,
                          textAlign: 'right',
                          fontFamily: 'JetBrains Mono, monospace',
                          color:
                            (transaction.running_total_cents ?? 0) > 0
                              ? theme.colors.semantic.success
                              : (transaction.running_total_cents ?? 0) < 0
                                ? theme.colors.semantic.warning
                                : theme.colors.text.secondary,
                          fontWeight: theme.typography.fontWeight.semibold,
                        }}
                      >
                        {formatPrice(transaction.running_total_cents ?? 0)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {/* Empty state */}
          {!loading && transactions.length === 0 && (
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

          {/* Transaction count */}
          {!loading && transactions.length > 0 && (
            <div
              style={{
                fontSize: theme.typography.fontSize.sm,
                color: theme.colors.text.secondary,
              }}
            >
              {transactions.length} transaction{transactions.length !== 1 ? 's' : ''} total
            </div>
          )}
        </div>
      </div>
    </div>
  )
}
