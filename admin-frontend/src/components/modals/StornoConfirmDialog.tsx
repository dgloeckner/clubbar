/**
 * StornoConfirmDialog Component
 * Confirmation dialog for reversing a single transaction (storno).
 *
 * A storno cannot be a free-amount form (Issue #169): the amount is always
 * the exact negation of the referenced transaction, and the member is
 * implied by it. This dialog therefore only ever asks the admin to confirm
 * WHAT is being reversed (member, product/description, amount, date) and
 * WHY (a required reason for the audit log) — never an amount.
 *
 * `ConfirmDialog` (../modals/ConfirmDialog.tsx) does not fit here: it only
 * renders a single message string and has no slot for a required text
 * input, so this is a dedicated dialog rather than a contorted reuse.
 */

import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { useFormatters } from '../../hooks/useFormatters'

export interface StornoConfirmDialogTarget {
  id: string
  member_name: string
  product_name: string | null
  description: string
  amount_cents: number
  created_at: string
}

export interface StornoConfirmDialogProps {
  isOpen: boolean
  transaction: StornoConfirmDialogTarget | null
  reason: string
  onReasonChange: (value: string) => void
  onConfirm: () => void
  onCancel: () => void
  isLoading: boolean
  error?: string | null
}

const REASON_MAX_LENGTH = 500

export function StornoConfirmDialog({
  isOpen,
  transaction,
  reason,
  onReasonChange,
  onConfirm,
  onCancel,
  isLoading,
  error,
}: StornoConfirmDialogProps) {
  const { t } = useTranslation()
  const { formatPrice, formatDateTime } = useFormatters()
  const contentRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (isOpen) {
      contentRef.current?.focus()
    }
  }, [isOpen])

  if (!isOpen || !transaction) return null

  const productOrDescription = transaction.product_name || transaction.description || '—'
  const canConfirm = reason.trim().length > 0 && !isLoading

  return (
    <div
      data-testid="journal-storno-dialog"
      tabIndex={-1}
      style={{
        position: 'fixed',
        inset: 0,
        background: 'rgba(0, 0, 0, 0.5)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        zIndex: 2000,
      }}
      // No backdrop close handler: the reason is mandatory and typed by hand,
      // and a stray click beside the dialog used to throw it away (#131).
      // Escape stays — that one is deliberate.
      onKeyDown={(e) => { if (e.key === 'Escape') onCancel() }}
    >
      <div
        ref={contentRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby="journal-storno-dialog-title"
        tabIndex={-1}
        style={{
          background: theme.colors.bg.secondary,
          borderRadius: theme.borderRadius.lg,
          padding: theme.spacing.xl,
          maxWidth: '480px',
          width: '90%',
          maxHeight: '90vh',
          overflowY: 'auto',
          boxShadow: '0 25px 50px rgba(0, 0, 0, 0.5)',
        }}
      >
        <h2
          id="journal-storno-dialog-title"
          style={{
            margin: 0,
            marginBottom: theme.spacing.lg,
            fontSize: theme.typography.fontSize.xl,
            fontWeight: theme.typography.fontWeight.semibold,
            color: theme.colors.text.primary,
          }}
        >
          {t('journal.stornoDialog.title')}
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
            {t('journal.member')}
          </dt>
          <dd data-testid="journal-storno-dialog-member" style={{ margin: 0, fontWeight: 500, fontSize: 14 }}>
            {transaction.member_name}
          </dd>

          <dt style={{ color: theme.colors.text.secondary, fontSize: 14 }}>
            {t('journal.product')}
          </dt>
          <dd data-testid="journal-storno-dialog-product" style={{ margin: 0, fontWeight: 500, fontSize: 14 }}>
            {productOrDescription}
          </dd>

          <dt style={{ color: theme.colors.text.secondary, fontSize: 14 }}>
            {t('common.amount')}
          </dt>
          <dd
            data-testid="journal-storno-dialog-amount"
            style={{ margin: 0, fontWeight: 700, fontSize: 14, fontFamily: 'JetBrains Mono, monospace' }}
          >
            {formatPrice(transaction.amount_cents)}
          </dd>

          <dt style={{ color: theme.colors.text.secondary, fontSize: 14 }}>
            {t('journal.date')}
          </dt>
          <dd data-testid="journal-storno-dialog-date" style={{ margin: 0, fontWeight: 500, fontSize: 14 }}>
            {formatDateTime(transaction.created_at)}
          </dd>
        </dl>

        <div style={{ marginBottom: theme.spacing.lg }}>
          <label
            htmlFor="journal-storno-dialog-reason"
            style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}
          >
            {t('journal.reason')} *
          </label>
          <textarea
            id="journal-storno-dialog-reason"
            data-testid="journal-storno-dialog-reason-input"
            value={reason}
            onChange={(e) => onReasonChange(e.target.value)}
            placeholder={t('journal.stornoDialog.reasonPlaceholder')}
            maxLength={REASON_MAX_LENGTH}
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
              resize: 'vertical' as const,
            }}
          />
        </div>

        {error && (
          <p
            data-testid="journal-storno-dialog-error"
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

        <div style={{ display: 'flex', gap: theme.spacing.md, justifyContent: 'flex-end' }}>
          <button
            data-testid="journal-storno-dialog-cancel"
            onClick={onCancel}
            disabled={isLoading}
            style={{
              padding: `${theme.spacing.md} ${theme.spacing.lg}`,
              background: 'transparent',
              border: `1px solid ${theme.colors.border.light}`,
              borderRadius: theme.borderRadius.md,
              color: theme.colors.text.primary,
              cursor: isLoading ? 'not-allowed' : 'pointer',
              fontSize: theme.typography.fontSize.sm,
              fontWeight: theme.typography.fontWeight.semibold,
              opacity: isLoading ? 0.6 : 1,
            }}
          >
            {t('common.cancel')}
          </button>
          <button
            data-testid="journal-storno-dialog-confirm"
            onClick={onConfirm}
            disabled={!canConfirm}
            style={{
              padding: `${theme.spacing.md} ${theme.spacing.lg}`,
              background: canConfirm ? theme.colors.semantic.danger : theme.colors.bg.tertiary,
              border: 'none',
              borderRadius: theme.borderRadius.md,
              color: 'white',
              cursor: canConfirm ? 'pointer' : 'not-allowed',
              fontSize: theme.typography.fontSize.sm,
              fontWeight: theme.typography.fontWeight.semibold,
            }}
          >
            {isLoading ? t('journal.saving') : t('common.confirm')}
          </button>
        </div>
      </div>
    </div>
  )
}
