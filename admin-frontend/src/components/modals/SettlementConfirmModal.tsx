/**
 * SettlementConfirmModal Component
 * Confirmation modal before creating a settlement.
 * Derives summary stats from the transactions to be settled.
 */

import { useTranslation } from 'react-i18next'
import type { SettlementFilterPreview } from '../../api/generated'
import { theme } from '../../styles/design-system'
import { useFormatters } from '../../hooks/useFormatters'

interface TransactionSummary {
  member_id?: string
  amount_cents?: number
}

export interface SettlementConfirmModalProps {
  isOpen: boolean
  /** Provide either transactions (settle-selected) or preview (settle-all) */
  transactions?: TransactionSummary[]
  preview?: SettlementFilterPreview
  onConfirm: () => void
  onCancel: () => void
  isLoading: boolean
  error?: string | null
}

function formatIsoDate(date: Date): string {
  return date.toISOString().split('T')[0]
}

export function SettlementConfirmModal({
  isOpen,
  transactions,
  preview,
  onConfirm,
  onCancel,
  isLoading,
  error,
}: SettlementConfirmModalProps) {
  const { t } = useTranslation()
  const { formatPrice, formatDate } = useFormatters()

  if (!isOpen) return null

  const transactionCount = preview?.transaction_count ?? transactions?.length ?? 0
  const memberCount = preview?.member_count ?? new Set(transactions?.map((tx) => tx.member_id) ?? []).size
  const totalCents = preview?.total_amount_cents ?? transactions?.reduce((sum, tx) => sum + (tx.amount_cents ?? 0), 0) ?? 0

  const today = new Date()
  const executionDate = new Date()
  executionDate.setDate(executionDate.getDate() + 7)

  const settlementDateStr = formatIsoDate(today)
  const executionDateStr = formatIsoDate(executionDate)

  return (
    <div
      data-testid="journal-settlement-confirm-modal"
      style={{
        position: 'fixed',
        inset: 0,
        background: 'rgba(0, 0, 0, 0.5)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        zIndex: 1100,
      }}
      onClick={onCancel}
    >
      <div
        style={{
          background: theme.colors.bg.primary,
          borderRadius: theme.borderRadius.lg,
          padding: theme.spacing.xl,
          maxWidth: '420px',
          width: '90%',
        }}
        onClick={(e) => e.stopPropagation()}
      >
        <h2 style={{ margin: 0, marginBottom: theme.spacing.lg, fontSize: 18, fontWeight: 600 }}>
          {t('journal.settlementConfirm.title')}
        </h2>

        <dl
          style={{
            margin: 0,
            marginBottom: theme.spacing.lg,
            display: 'grid',
            gridTemplateColumns: 'auto 1fr',
            gap: `${theme.spacing.sm} ${theme.spacing.xl}`,
          }}
        >
          <dt style={{ color: theme.colors.text.secondary, fontSize: 14 }}>
            {t('journal.settlementConfirm.transactions')}
          </dt>
          <dd
            data-testid="journal-settlement-confirm-transaction-count"
            style={{ margin: 0, fontWeight: 500, fontSize: 14 }}
          >
            {transactionCount}
          </dd>

          <dt style={{ color: theme.colors.text.secondary, fontSize: 14 }}>
            {t('journal.settlementConfirm.members')}
          </dt>
          <dd
            data-testid="journal-settlement-confirm-member-count"
            style={{ margin: 0, fontWeight: 500, fontSize: 14 }}
          >
            {memberCount}
          </dd>

          <dt style={{ color: theme.colors.text.secondary, fontSize: 14 }}>
            {t('journal.settlementConfirm.totalAmount')}
          </dt>
          <dd
            data-testid="journal-settlement-confirm-total-amount"
            style={{ margin: 0, fontWeight: 500, fontSize: 14 }}
          >
            {formatPrice(totalCents)}
          </dd>

          <dt style={{ color: theme.colors.text.secondary, fontSize: 14 }}>
            {t('journal.settlementConfirm.settlementDate')}
          </dt>
          <dd style={{ margin: 0, fontWeight: 500, fontSize: 14 }}>
            {formatDate(settlementDateStr)}
          </dd>

          <dt style={{ color: theme.colors.text.secondary, fontSize: 14 }}>
            {t('journal.settlementConfirm.executionDate')}
          </dt>
          <dd style={{ margin: 0, fontWeight: 500, fontSize: 14 }}>
            {formatDate(executionDateStr)}
          </dd>
        </dl>

        {error && (
          <p
            data-testid="journal-settlement-confirm-error"
            style={{
              margin: 0,
              marginBottom: theme.spacing.md,
              color: theme.colors.semantic.danger,
              fontSize: 14,
            }}
          >
            {error}
          </p>
        )}

        <div style={{ display: 'flex', gap: theme.spacing.md }}>
          <button
            data-testid="journal-settlement-confirm-submit-btn"
            onClick={onConfirm}
            disabled={isLoading}
            style={{
              flex: 1,
              padding: theme.spacing.md,
              background: isLoading ? theme.colors.bg.tertiary : '#10b981',
              color: 'white',
              border: 'none',
              borderRadius: theme.borderRadius.md,
              cursor: isLoading ? 'not-allowed' : 'pointer',
              fontWeight: 500,
              fontSize: 14,
              transition: `all ${theme.transitions.default}`,
            }}
            onMouseEnter={(e) => {
              if (!isLoading) e.currentTarget.style.background = '#059669'
            }}
            onMouseLeave={(e) => {
              if (!isLoading) e.currentTarget.style.background = '#10b981'
            }}
          >
            {isLoading ? t('common.loading') : t('journal.settlementConfirm.confirm')}
          </button>
          <button
            data-testid="journal-settlement-confirm-cancel-btn"
            onClick={onCancel}
            disabled={isLoading}
            style={{
              flex: 1,
              padding: theme.spacing.md,
              background: 'transparent',
              color: theme.colors.text.secondary,
              border: `1px solid ${theme.colors.border.light}`,
              borderRadius: theme.borderRadius.md,
              cursor: isLoading ? 'not-allowed' : 'pointer',
              fontSize: 14,
              transition: `all ${theme.transitions.default}`,
            }}
            onMouseEnter={(e) => {
              if (!isLoading) e.currentTarget.style.backgroundColor = theme.colors.bg.tertiary
            }}
            onMouseLeave={(e) => {
              if (!isLoading) e.currentTarget.style.backgroundColor = 'transparent'
            }}
          >
            {t('journal.settlementConfirm.cancel')}
          </button>
        </div>
      </div>
    </div>
  )
}
