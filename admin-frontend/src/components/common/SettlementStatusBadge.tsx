/**
 * The badge that says where a settlement stands (#377).
 *
 * One component for the table and the mobile card, because they used to differ:
 * the table knew three states and the card knew two, so on a phone an exported
 * run was indistinguishable from a draft. A shared component is the only way
 * that regression cannot come back — there is nowhere left for a second
 * vocabulary to live.
 *
 * The label is the locale's, keyed on the server's `status`; `status_label` is
 * the server's own English and is used only for a status this build does not
 * know. See `utils/settlementStatus.ts` for why nothing is derived here.
 *
 * Test IDs: `${testId}` on the badge, `${testId}-awaiting` on the
 * awaiting-confirmation marker.
 */

import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import {
  awaitsBankConfirmation,
  settlementStatus,
  settlementStatusColor,
  settlementStatusLabelKey,
  type SettlementStatusTarget,
} from '../../utils/settlementStatus'

export interface SettlementStatusBadgeProps {
  settlement: SettlementStatusTarget
  testId: string
  /** Slightly tighter type and padding, for the mobile card. */
  compact?: boolean
}

export function SettlementStatusBadge({ settlement, testId, compact = false }: SettlementStatusBadgeProps) {
  const { t } = useTranslation()
  const status = settlementStatus(settlement)

  const label = status ? t(settlementStatusLabelKey(status)) : (settlement.status_label ?? '—')

  return (
    <span style={{ display: 'inline-flex', flexDirection: 'column', alignItems: 'flex-end', gap: 3 }}>
      <span
        data-testid={testId}
        style={{
          padding: compact ? '3px 8px' : '4px 8px',
          borderRadius: 4,
          fontSize: compact ? 11 : 12,
          fontWeight: 500,
          backgroundColor: settlementStatusColor(status),
          color: 'white',
          whiteSpace: 'nowrap',
        }}
      >
        {label}
      </span>

      {/*
        Ruling #142 §2: a file that was generated and never confirmed as sent
        is the one state the system cannot tell apart from a forgotten click.
        Saying so is the whole remedy — it drifts silently otherwise.
      */}
      {awaitsBankConfirmation(settlement) && (
        <span
          data-testid={`${testId}-awaiting`}
          style={{
            fontSize: compact ? 10 : 11,
            color: theme.colors.semantic.warningLight,
            whiteSpace: 'nowrap',
          }}
        >
          {t('settlements.awaitingConfirmation')}
        </span>
      )}
    </span>
  )
}
