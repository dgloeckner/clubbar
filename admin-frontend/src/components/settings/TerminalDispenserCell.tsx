/**
 * What a terminal last said about its token dispenser (ADR-0057, #954).
 *
 * The machine bolted next to the terminal can fail on its own — it jams, it
 * runs empty, it gets unplugged, its controller crashes and comes back, and
 * its firmware ships on a cadence of its own so a club can be running a
 * protocol the terminal does not speak. None of that used to be visible
 * anywhere an admin looks: a fault was discovered by a member at the kiosk.
 *
 * Four states, all distinguishable from the two fields the terminals list
 * already carries (see {@link dispenserDisplay}):
 *
 * - **unknown** — nothing has ever been reported. The cell makes *no claim*,
 *   the way `TerminalVersionCell` does for a terminal that never sent a
 *   version. It is not "no dispenser".
 * - **no dispenser** — `configured: false`, which is a report of its own.
 * - **available** — the backend's verdict, not this component's arithmetic.
 * - **unavailable** — with the reason named. Never a bare "unavailable": the
 *   errand behind `jam`, `offline` and `protocol_mismatch` is a different
 *   errand each time, and the third one is not a device fault at all.
 *
 * Three rules here are not styling preferences:
 *
 * 1. **The age is always beside the status.** The backend cannot poll the
 *    dispenser, so a terminal that is off reports nothing and a dropped report
 *    freezes this cell silently. A status with no age is a claim nobody can
 *    check — ADR-0057's own mitigation for its worst consequence.
 * 2. **A fault is dated to when it began**, from `state_since`, not to the
 *    last report about it. A jam re-reported every thirty seconds for an hour
 *    is one fault that started an hour ago.
 * 3. **There is no clear, reset or acknowledge affordance** — here or in the
 *    detail panel. The device has no reset route and a jam is cleared by a
 *    power cycle (owner decision 3 of #944); a button would change a screen
 *    and not a hopper. The only control is the one that opens the detail.
 *
 * The vocabulary is the kiosk's, deliberately: *Stau oder leer*,
 * *Hopper-Fehler N*, *Protokoll passt nicht*, *Nicht erreichbar*, *Störung*.
 * Two surfaces describing one machine differently is how a club stops trusting
 * either.
 */

import { useTranslation } from 'react-i18next'
import { theme, formatDateTime } from '../../styles/design-system'
import { Badge, type BadgeProps } from '../common/Badge'
import { useDispenserAgeText } from '../../hooks/useDispenserAgeText'
import {
  dispenserAge,
  dispenserDisplay,
  type DispenserDisplayState,
} from '../../utils/dispenserStatus'
import type {
  Terminal as GeneratedTerminal,
  TerminalDispenserStatusUnavailableReason,
} from '../../api/generated/model'

export type DispenserTerminal = Pick<GeneratedTerminal, 'dispenser_status' | 'dispenser_status_at'>

type Variant = NonNullable<BadgeProps['variant']>

/**
 * Reason → the copy that names it, and the colour that says which errand it is.
 *
 * `protocol_mismatch` is `warning` rather than `danger` on purpose: nothing is
 * wrong at the machine, and an admin reading the row should not be sent to the
 * bar with a power cable (epic finding 13).
 */
const REASON: Record<
  NonNullable<TerminalDispenserStatusUnavailableReason>,
  { key: string; variant: Variant }
> = {
  offline: { key: 'settings.terminalDispenserUnavailableOffline', variant: 'danger' },
  protocol_mismatch: { key: 'settings.terminalDispenserUnavailableProtocol', variant: 'warning' },
  jam: { key: 'settings.terminalDispenserUnavailableJam', variant: 'danger' },
  hopper_error: { key: 'settings.terminalDispenserUnavailableHopperError', variant: 'danger' },
  unspecified_fault: { key: 'settings.terminalDispenserUnavailableFault', variant: 'danger' },
}

export function TerminalDispenserCell({
  terminal,
  testId,
  onSelect,
}: {
  terminal: DispenserTerminal
  testId: string
  /** Opens the detail. Absent on a surface that has no panel to open. */
  onSelect?: () => void
}) {
  const { t } = useTranslation()
  const ageText = useDispenserAgeText()

  const display = dispenserDisplay(terminal.dispenser_status)
  const age = dispenserAge(terminal.dispenser_status_at)
  const status = terminal.dispenser_status

  const variant: Variant | null =
    display.state === 'available'
      ? 'success'
      : display.state === 'unavailable' && display.reason
        ? REASON[display.reason].variant
        : null

  // The state travels as attributes as well as a badge, because the badge text
  // is translated and E2E has to read something stable.
  const attributes: Record<string, string> = {
    'data-testid': testId,
    'data-dispenser-state': display.state satisfies DispenserDisplayState,
    'data-dispenser-reason': display.reason ?? '',
    'data-dispenser-variant': variant ?? '',
  }

  const label =
    display.state === 'unavailable' && display.reason
      ? t(REASON[display.reason].key, { code: display.faultCode ?? 0 })
      : t('settings.terminalDispenserReady')

  const body =
    display.state === 'unknown' ? (
      <span style={{ color: theme.colors.text.muted }}>{t('settings.terminalDispenserUnknown')}</span>
    ) : display.state === 'none' ? (
      <span style={{ color: theme.colors.text.muted }}>{t('settings.terminalDispenserNone')}</span>
    ) : (
      <Badge label={label} variant={variant ?? 'neutral'} showDot={false} testId={`${testId}-badge`} />
    )

  return (
    <span
      {...attributes}
      style={{ display: 'inline-flex', alignItems: 'center', gap: theme.spacing.sm, flexWrap: 'wrap' }}
    >
      {/* Nothing has been reported, so there is nothing to open either. */}
      {display.state === 'unknown' || !onSelect ? (
        body
      ) : (
        <button
          type="button"
          onClick={onSelect}
          data-testid={`${testId}-details`}
          aria-label={t('settings.terminalDispenserOpenDetails')}
          style={{ background: 'none', border: 'none', padding: 0, margin: 0, cursor: 'pointer', font: 'inherit', color: 'inherit' }}
        >
          {body}
        </button>
      )}

      {/* Rule 1: never the status alone. */}
      {age && (
        <span
          data-testid={`${testId}-age`}
          title={
            terminal.dispenser_status_at
              ? t('settings.terminalDispenserAgeHint', {
                  timestamp: formatDateTime(terminal.dispenser_status_at),
                })
              : undefined
          }
          style={{ color: theme.colors.text.muted, fontSize: theme.typography.fontSize.xs }}
        >
          {ageText(age)}
        </span>
      )}

      {/* Rule 2: a fault is dated to when it began. */}
      {display.state === 'unavailable' && status?.state_since && (
        <span
          data-testid={`${testId}-since`}
          style={{ color: theme.colors.text.muted, fontSize: theme.typography.fontSize.xs }}
        >
          {t('settings.terminalDispenserSince', { timestamp: formatDateTime(status.state_since) })}
        </span>
      )}
    </span>
  )
}
