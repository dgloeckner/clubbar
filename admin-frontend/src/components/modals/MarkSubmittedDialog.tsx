/**
 * The question asked before a settlement is recorded as being with the bank
 * (#377, ruling #142 §1).
 *
 * Generating a pain.008 file is not sending it, so `exported_at` deliberately
 * does not lock a run — the treasurer still has to open online banking and
 * upload it. Marking the run submitted is the click that records they did, and
 * it is the real point of no return: from here the run can no longer be
 * cancelled, and money that comes back comes back as a reversal.
 *
 * So the dialog states the same figures the undo dialog states — which run,
 * for how much, for how many members — plus the one consequence that is not
 * obvious from the button: cancellation is foreclosed.
 *
 * No step-up credential is asked for, unlike the SEPA export beside it:
 * nothing is decrypted here, and the write is a timestamp.
 *
 * Test IDs: the surrounding ConfirmDialog's (`confirm-dialog`, `-ok`,
 * `-cancel`), plus `mark-submitted-detail-{date,amount,members}` and
 * `mark-submitted-warning`.
 */

import { type CSSProperties } from 'react'
import { useTranslation } from 'react-i18next'
import { ConfirmDialog } from './ConfirmDialog'
import { theme } from '../../styles/design-system'
import { useFormatters } from '../../hooks/useFormatters'

export interface MarkSubmittedDialogSettlement {
  id?: string
  settlement_date?: string
  created_at?: string
  total_amount_cents?: number
  member_count?: number
}

export interface MarkSubmittedDialogProps {
  /** The settlement in question; null closes the dialog. */
  settlement: MarkSubmittedDialogSettlement | null
  onConfirm: () => void
  onCancel: () => void
}

export function MarkSubmittedDialog({ settlement, onConfirm, onCancel }: MarkSubmittedDialogProps) {
  const { t } = useTranslation()
  const formatters = useFormatters()

  if (!settlement) return null

  const date = settlement.settlement_date || settlement.created_at || ''

  return (
    <ConfirmDialog
      isOpen
      variant="primary"
      title={t('settlements.markSubmittedTitle')}
      confirmLabel={t('settlements.markSubmitted')}
      onConfirm={onConfirm}
      onCancel={onCancel}
      message={
        <>
          <p style={{ margin: 0 }}>{t('settlements.markSubmittedConfirm')}</p>

          <div
            style={{
              display: 'grid',
              gridTemplateColumns: 'auto 1fr',
              gap: `${theme.spacing.xs} ${theme.spacing.lg}`,
              margin: `${theme.spacing.lg} 0`,
              padding: theme.spacing.md,
              background: theme.colors.bg.tertiary,
              borderRadius: theme.borderRadius.md,
              color: theme.colors.text.primary,
              fontSize: theme.typography.fontSize.sm,
            }}
          >
            <span style={labelStyle}>{t('common.date')}</span>
            <span data-testid="mark-submitted-detail-date">
              {date ? formatters.formatDate(date) : '—'}
            </span>

            <span style={labelStyle}>{t('settlements.totalAmount')}</span>
            <span data-testid="mark-submitted-detail-amount" style={amountStyle}>
              {formatters.formatPrice(settlement.total_amount_cents ?? 0)}
            </span>

            <span style={labelStyle}>{t('settlements.memberCount')}</span>
            <span data-testid="mark-submitted-detail-members">{settlement.member_count ?? 0}</span>
          </div>

          {/* The fact the button does not carry: this closes the undo path. */}
          <div
            data-testid="mark-submitted-warning"
            style={{
              padding: theme.spacing.md,
              borderRadius: theme.borderRadius.md,
              background: 'rgba(251, 146, 60, 0.12)',
              border: '1px solid rgba(251, 146, 60, 0.4)',
              color: theme.colors.banner.warningText,
            }}
          >
            {t('settlements.markSubmittedWarning')}
          </div>
        </>
      }
    />
  )
}

const labelStyle: CSSProperties = {
  color: theme.colors.text.secondary,
}

const amountStyle: CSSProperties = {
  fontFamily: 'JetBrains Mono, monospace',
  fontWeight: theme.typography.fontWeight.bold,
}
