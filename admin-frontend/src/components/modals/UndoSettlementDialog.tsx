/**
 * The question asked before a settlement is undone (#127).
 *
 * Undoing a run returns its transactions to the unsettled pool, so the next
 * run collects them again. That is harmless while the money has not moved and
 * a double debit once it has — which makes this dialog the last place able to
 * state *which* run is about to be undone, for how much and for how many
 * members, and whether a SEPA file for it already exists.
 *
 * Three shapes, one component:
 *
 * | settlement                          | dialog                                    |
 * |-------------------------------------|-------------------------------------------|
 * | cancellable, never exported         | figures + confirm                         |
 * | cancellable, a file was generated   | figures + warning + acknowledgement first |
 * | the backend refuses (money moved)   | figures + its reason, no confirm button   |
 *
 * The third shape is why the Undo button is no longer merely disabled for a
 * settlement the gate refuses: the reason lived in a `title` tooltip, which a
 * touch device never shows.
 *
 * Test IDs: the surrounding ConfirmDialog's (`confirm-dialog`, `-ok`,
 * `-cancel`), plus `undo-settlement-detail-{date,amount,members,transactions}`,
 * `undo-settlement-blocked-reason`, `undo-settlement-export-warning` and
 * `undo-settlement-export-ack`.
 */

import { useEffect, useState, type CSSProperties } from 'react'
import { useTranslation } from 'react-i18next'
import { ConfirmDialog } from './ConfirmDialog'
import { theme } from '../../styles/design-system'
import { useFormatters } from '../../hooks/useFormatters'
import { describeUndo, undoDisplayDate, type UndoSettlementTarget } from '../../utils/settlementUndo'

export interface UndoSettlementDialogSettlement extends UndoSettlementTarget {
  id?: string
  total_amount_cents?: number
  member_count?: number
  transaction_count?: number
}

export interface UndoSettlementDialogProps {
  /** The settlement in question; null closes the dialog. */
  settlement: UndoSettlementDialogSettlement | null
  onConfirm: () => void
  onCancel: () => void
}

export function UndoSettlementDialog({ settlement, onConfirm, onCancel }: UndoSettlementDialogProps) {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const [exportAcknowledged, setExportAcknowledged] = useState(false)

  // A fresh dialog asks the acknowledgement again — ticking it for one
  // settlement must not carry over to the next one opened.
  useEffect(() => {
    setExportAcknowledged(false)
  }, [settlement?.id])

  if (!settlement) return null

  const decision = describeUndo(settlement)
  const date = undoDisplayDate(settlement)

  const details = (
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
      <span data-testid="undo-settlement-detail-date">
        {date ? formatters.formatDate(date) : '—'}
      </span>

      <span style={labelStyle}>{t('settlements.totalAmount')}</span>
      <span data-testid="undo-settlement-detail-amount" style={amountStyle}>
        {formatters.formatPrice(settlement.total_amount_cents ?? 0)}
      </span>

      <span style={labelStyle}>{t('settlements.memberCount')}</span>
      <span data-testid="undo-settlement-detail-members">{settlement.member_count ?? 0}</span>

      <span style={labelStyle}>{t('settlements.transactionCount')}</span>
      <span data-testid="undo-settlement-detail-transactions">
        {settlement.transaction_count ?? 0}
      </span>
    </div>
  )

  if (decision.blocked) {
    return (
      <ConfirmDialog
        isOpen
        title={t('settlements.undoBlockedTitle')}
        onCancel={onCancel}
        onConfirm={onConfirm}
        showConfirm={false}
        cancelLabel={t('common.close')}
        message={
          <>
            <p data-testid="undo-settlement-blocked-reason" style={{ margin: 0 }}>
              {decision.blockedReason ?? t('settlements.undoBlockedFallback')}
            </p>
            {details}
          </>
        }
      />
    )
  }

  return (
    <ConfirmDialog
      isOpen
      title={t('settlements.undoTitle')}
      onCancel={onCancel}
      onConfirm={onConfirm}
      confirmLabel={t('common.undo')}
      confirmDisabled={decision.needsExportAcknowledgement && !exportAcknowledged}
      message={
        <>
          <p style={{ margin: 0 }}>{t('settlements.undoConfirm')}</p>
          {details}
          {decision.needsExportAcknowledgement && (
            <>
              <div
                data-testid="undo-settlement-export-warning"
                style={{
                  padding: theme.spacing.md,
                  borderRadius: theme.borderRadius.md,
                  background: 'rgba(251, 146, 60, 0.12)',
                  border: '1px solid rgba(251, 146, 60, 0.4)',
                  color: theme.colors.banner.warningText,
                }}
              >
                {t('settlements.undoExportedWarning')}
              </div>
              <label
                style={{
                  display: 'flex',
                  alignItems: 'flex-start',
                  gap: theme.spacing.sm,
                  marginTop: theme.spacing.md,
                  color: theme.colors.text.primary,
                  cursor: 'pointer',
                }}
              >
                <input
                  type="checkbox"
                  data-testid="undo-settlement-export-ack"
                  checked={exportAcknowledged}
                  onChange={(e) => setExportAcknowledged(e.target.checked)}
                  style={{ marginTop: '3px', cursor: 'pointer' }}
                />
                <span>{t('settlements.undoExportedAcknowledge')}</span>
              </label>
            </>
          )}
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
