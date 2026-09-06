/**
 * What a terminal is running, and whether that is a problem (ADR-0054).
 *
 * A terminal installs exactly the version its backend reports, so there is one
 * healthy answer and four unhealthy ones — but only one of them is an alarm:
 *
 * - `current` — the invariant holds. Rendered as the bare tag, with no badge.
 *   A green "OK" on every row every day is a green nobody reads.
 * - `behind` — the ordinary state of every terminal in the club for the hours
 *   between a backend upgrade and that night's update run. Named, not alarmed.
 * - `blocked` — an update failed here and that tag will never be retried, and
 *   exact-match means it is also the only tag this terminal would consider
 *   next. It is frozen until a newer release ships, and *nothing on the
 *   terminal says so*. This is the state the whole reporting mechanism exists
 *   for, so it is the only one in danger colours.
 * - `ahead` — a hand-installed terminal, or a backend rolled back under one.
 *   Reported, never enforced: refusing to sync a too-new terminal would turn
 *   version skew into a bar that cannot sell drinks.
 * - `unknown` — nothing to compare. Either the terminal has not reported (a
 *   build older than the header, or a proxy that strips it) or the backend
 *   itself is on `dev`, which never moves terminals at all.
 *
 * The classification is the server's (`version_state`), not this component's:
 * comparing release tags is the same correctness question the Pi's updater
 * answers, and a second implementation in TypeScript would be a second thing
 * to get wrong.
 */

import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { Badge, type BadgeProps } from '../common/Badge'
import { Tooltip } from '../common/Tooltip'
import type { Terminal as GeneratedTerminal } from '../../api/generated'

export type TerminalVersionState = 'unknown' | 'current' | 'behind' | 'blocked' | 'ahead'

const BADGE_VARIANT: Record<
  Exclude<TerminalVersionState, 'current' | 'unknown'>,
  NonNullable<BadgeProps['variant']>
> = {
  behind: 'info',
  blocked: 'danger',
  ahead: 'warning',
}

function versionState(terminal: Pick<GeneratedTerminal, 'version_state'>): TerminalVersionState {
  switch (terminal.version_state) {
    case 'current':
    case 'behind':
    case 'blocked':
    case 'ahead':
      return terminal.version_state
    default:
      return 'unknown'
  }
}

export function TerminalVersionCell({
  terminal,
  testId,
}: {
  terminal: Pick<GeneratedTerminal, 'version_state' | 'reported_version' | 'blocked_version' | 'backend_version'>
  testId: string
}) {
  const { t } = useTranslation()
  const state = versionState(terminal)

  // The state travels as an attribute as well as a badge, because the badge
  // text is translated and E2E has to read something stable.
  const attributes = { 'data-testid': testId, 'data-version-state': state }

  if (state === 'unknown') {
    return (
      <span {...attributes} style={{ color: theme.colors.text.muted }}>
        {t('settings.terminalVersionUnknown')}
      </span>
    )
  }

  if (state === 'current') {
    return <span {...attributes}>{terminal.reported_version}</span>
  }

  const hint =
    state === 'blocked'
      ? t('settings.terminalVersionBlockedHint', { version: terminal.blocked_version })
      : t(`settings.terminalVersion${state === 'behind' ? 'Behind' : 'Ahead'}Hint`, {
          version: terminal.backend_version,
        })

  return (
    <span {...attributes} style={{ display: 'inline-flex', alignItems: 'center', gap: theme.spacing.sm }}>
      <span>{terminal.reported_version}</span>
      <Tooltip content={hint}>
        <span>
          <Badge
            label={
              state === 'blocked'
                ? t('settings.terminalVersionBlocked', { version: terminal.blocked_version })
                : t(`settings.terminalVersion${state === 'behind' ? 'Behind' : 'Ahead'}`)
            }
            variant={BADGE_VARIANT[state]}
            showDot={false}
            testId={`${testId}-badge`}
          />
        </span>
      </Tooltip>
    </span>
  )
}
