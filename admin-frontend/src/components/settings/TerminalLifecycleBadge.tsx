/**
 * Single source of truth for how a terminal token's lifecycle tier is
 * labelled and coloured. Used by both the Terminals tab and the Schlüssel
 * (Security & Credentials) tab so the same `lifecycle_state` always reads
 * the same way, regardless of which tab it is shown on.
 */

import { useTranslation } from 'react-i18next'
import { Badge, type BadgeProps } from '../common/Badge'
import type { TerminalLifecycleState } from '../../api/generated'

export type TokenLifecycleBadgeState = TerminalLifecycleState | 'none'

const LIFECYCLE_VARIANT: Record<TokenLifecycleBadgeState, NonNullable<BadgeProps['variant']>> = {
  none: 'neutral',
  ok: 'success',
  info: 'info',
  warning: 'warning',
  critical: 'danger',
  expired: 'danger',
}

export function TerminalLifecycleBadge({
  lifecycle,
  testId,
}: {
  lifecycle: TokenLifecycleBadgeState
  testId?: string
}) {
  const { t } = useTranslation()
  const label = lifecycle === 'none' ? t('settings.tokenNone') : t(`settings.credentials.terminals.state.${lifecycle}`)

  return <Badge label={label} variant={LIFECYCLE_VARIANT[lifecycle]} showDot={false} testId={testId} />
}
