/**
 * What a click on the dispenser cell opens (#954).
 *
 * The cell answers "can it serve a token, and how old is that claim". This is
 * everything else the terminal reported about the machine, and it is read
 * rather than fetched: `GET /api/admin/terminals` already carries the whole
 * document, so opening this panel costs no request (ADR-0057 — the fields ride
 * the list the page already loads).
 *
 * Four things here are load-bearing:
 *
 * 1. **A missing counter is not a zero.** When `contact != reported` the
 *    firmware, uptime, RSSI, reset reason and the entire `lifetime` object are
 *    *absent*; `filtered_pulses` is absent even on a healthy report from the
 *    mock. Rendering "0 Staus" for a machine nobody reached invents a
 *    measurement, so an absent field reads as an em dash and the panel says in
 *    one line why it is missing.
 * 2. **Requested against dispensed** is the simplest tamper-or-defect
 *    indicator there is, so the two sit next to each other with their
 *    difference named.
 * 3. **`manual_reconciliations` is money waiting for a human**, so it is
 *    highlighted above zero. Both reconciliation counts are rows in the
 *    terminal's own database, which is why they are present even when the
 *    device is dark.
 * 4. **Nothing here commands the machine.** No clear, no reset, no
 *    acknowledge — only the remedy sentence and a close button. The device has
 *    no reset route, and a jam is cleared by a power cycle.
 */

import { useTranslation } from 'react-i18next'
import { theme, formatDateTime } from '../../styles/design-system'
import { useModalDialog } from '../../hooks/useModalDialog'
import { TerminalDispenserCell, type DispenserTerminal } from './TerminalDispenserCell'
import { useDispenserAgeText } from '../../hooks/useDispenserAgeText'
import { dispenserDisplay, durationParts, hasCounter } from '../../utils/dispenserStatus'

/** What to do about it. A protocol mismatch is a deployment errand, not a hopper one. */
const REMEDY_KEY: Record<string, string> = {
  jam: 'settings.terminalDispenserRemedyFault',
  hopper_error: 'settings.terminalDispenserRemedyFault',
  unspecified_fault: 'settings.terminalDispenserRemedyFault',
  offline: 'settings.terminalDispenserRemedyOffline',
  protocol_mismatch: 'settings.terminalDispenserRemedyProtocol',
}

const ABSENT = '—'

export interface TerminalDispenserPanelProps {
  isOpen: boolean
  terminalName: string
  terminal: DispenserTerminal | null
  onClose: () => void
}

function Row({ label, value, testId, highlight }: { label: string; value: string; testId: string; highlight?: boolean }) {
  return (
    <div style={{ display: 'flex', justifyContent: 'space-between', gap: theme.spacing.md }}>
      <span style={{ color: theme.colors.text.secondary }}>{label}</span>
      <span
        data-testid={testId}
        style={{
          color: highlight ? theme.colors.semantic.danger : theme.colors.text.primary,
          fontWeight: highlight ? theme.typography.fontWeight.semibold : undefined,
          textAlign: 'right',
        }}
      >
        {value}
      </span>
    </div>
  )
}

export function TerminalDispenserPanel({ isOpen, terminalName, terminal, onClose }: TerminalDispenserPanelProps) {
  const { t } = useTranslation()
  const ageText = useDispenserAgeText()
  const contentRef = useModalDialog(isOpen, onClose)

  if (!isOpen || !terminal) return null

  const status = terminal.dispenser_status
  const display = dispenserDisplay(status)
  const lifetime = status?.lifetime ?? {}
  const remedy = display.reason ? REMEDY_KEY[display.reason] : undefined

  const counter = (value: unknown, testId: string, label: string, highlight = false) => (
    <Row
      key={testId}
      label={label}
      testId={testId}
      value={hasCounter(value) ? String(value) : ABSENT}
      highlight={highlight && hasCounter(value) && value > 0}
    />
  )

  return (
    <div
      data-testid="terminal-dispenser-panel"
      tabIndex={-1}
      style={{
        position: 'fixed',
        inset: 0,
        background: theme.overlay.backdrop,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        zIndex: 2000,
      }}
      onClick={onClose}
    >
      <div
        ref={contentRef}
        data-testid="terminal-dispenser-panel-content"
        role="dialog"
        aria-modal="true"
        aria-labelledby="terminal-dispenser-panel-title"
        tabIndex={-1}
        style={{
          background: theme.colors.bg.secondary,
          borderRadius: theme.borderRadius.lg,
          padding: theme.spacing.xl,
          maxWidth: '560px',
          width: '90%',
          maxHeight: '80vh',
          overflowY: 'auto',
          boxShadow: theme.shadows.modalStrong,
          fontSize: theme.typography.fontSize.sm,
        }}
        onClick={(e) => e.stopPropagation()}
      >
        <h2
          id="terminal-dispenser-panel-title"
          style={{
            margin: 0,
            marginBottom: theme.spacing.md,
            fontSize: theme.typography.fontSize.lg,
            fontWeight: theme.typography.fontWeight.semibold,
            color: theme.colors.text.primary,
          }}
        >
          {t('settings.terminalDispenserPanelTitle', { name: terminalName })}
        </h2>

        <TerminalDispenserCell terminal={terminal} testId="terminal-dispenser-panel-status" />

        {remedy && (
          <p
            data-testid="terminal-dispenser-panel-remedy"
            style={{ marginTop: theme.spacing.md, marginBottom: 0, color: theme.colors.text.secondary }}
          >
            {t(remedy)}
          </p>
        )}

        {status?.contact !== 'reported' && (
          <p
            data-testid="terminal-dispenser-panel-no-contact"
            style={{ marginTop: theme.spacing.md, marginBottom: 0, color: theme.colors.text.muted }}
          >
            {t('settings.terminalDispenserNoContact')}
          </p>
        )}

        <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.sm, marginTop: theme.spacing.lg }}>
          <Row
            label={t('settings.terminalDispenserReported')}
            testId="terminal-dispenser-detail-reported"
            value={terminal.dispenser_status_at ? formatDateTime(terminal.dispenser_status_at) : ABSENT}
          />
          <Row
            label={t('settings.terminalDispenserStateSince')}
            testId="terminal-dispenser-detail-since"
            value={status?.state_since ? formatDateTime(status.state_since) : ABSENT}
          />
          <Row
            label={t('settings.terminalDispenserObservedAt')}
            testId="terminal-dispenser-detail-observed"
            value={status?.observed_at ? formatDateTime(status.observed_at) : ABSENT}
          />
          <Row
            label={t('settings.terminalDispenserFirmware')}
            testId="terminal-dispenser-detail-firmware"
            value={status?.firmware ?? ABSENT}
          />
          <Row
            label={t('settings.terminalDispenserProtocol')}
            testId="terminal-dispenser-detail-protocol"
            value={hasCounter(status?.protocol) ? String(status?.protocol) : ABSENT}
          />
          <Row
            label={t('settings.terminalDispenserRssi')}
            testId="terminal-dispenser-detail-rssi"
            value={hasCounter(status?.rssi) ? t('settings.terminalDispenserRssiValue', { value: status?.rssi }) : ABSENT}
          />
          <Row
            label={t('settings.terminalDispenserUptime')}
            testId="terminal-dispenser-detail-uptime"
            value={hasCounter(status?.uptime_s) ? ageText(durationParts(status!.uptime_s!)) : ABSENT}
          />
          <Row
            label={t('settings.terminalDispenserResetReason')}
            testId="terminal-dispenser-detail-reset-reason"
            value={status?.reset_reason ?? ABSENT}
          />
        </div>

        <h3
          style={{
            margin: `${theme.spacing.lg} 0 ${theme.spacing.sm}`,
            fontSize: theme.typography.fontSize.sm,
            fontWeight: theme.typography.fontWeight.semibold,
            color: theme.colors.text.primary,
          }}
        >
          {t('settings.terminalDispenserLifetime')}
        </h3>
        <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.sm }}>
          {counter(lifetime.requested_tokens, 'terminal-dispenser-detail-requested', t('settings.terminalDispenserRequested'))}
          {counter(lifetime.dispensed_tokens, 'terminal-dispenser-detail-dispensed', t('settings.terminalDispenserDispensed'))}
          {/* The gap between the two above, which is the cheapest tamper or
              defect indicator this document carries. */}
          <Row
            label={t('settings.terminalDispenserShortfall')}
            testId="terminal-dispenser-detail-shortfall"
            value={
              hasCounter(lifetime.requested_tokens) && hasCounter(lifetime.dispensed_tokens)
                ? String(lifetime.requested_tokens - lifetime.dispensed_tokens)
                : ABSENT
            }
            highlight={
              hasCounter(lifetime.requested_tokens) &&
              hasCounter(lifetime.dispensed_tokens) &&
              lifetime.requested_tokens !== lifetime.dispensed_tokens
            }
          />
          {counter(lifetime.jams, 'terminal-dispenser-detail-jams', t('settings.terminalDispenserJams'))}
          {counter(lifetime.crashes, 'terminal-dispenser-detail-crashes', t('settings.terminalDispenserCrashes'))}
          {counter(lifetime.overrun_tokens, 'terminal-dispenser-detail-overrun', t('settings.terminalDispenserOverrun'))}
          {counter(lifetime.filtered_pulses, 'terminal-dispenser-detail-filtered', t('settings.terminalDispenserFiltered'))}
        </div>

        <h3
          style={{
            margin: `${theme.spacing.lg} 0 ${theme.spacing.sm}`,
            fontSize: theme.typography.fontSize.sm,
            fontWeight: theme.typography.fontWeight.semibold,
            color: theme.colors.text.primary,
          }}
        >
          {t('settings.terminalDispenserReconciliations')}
        </h3>
        <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.sm }}>
          {/* Rows in the terminal's own database, which is why they are here
              even when the device is dark. */}
          {counter(status?.pending_reconciliations, 'terminal-dispenser-detail-pending', t('settings.terminalDispenserPending'), true)}
          {counter(status?.manual_reconciliations, 'terminal-dispenser-detail-manual', t('settings.terminalDispenserManual'), true)}
        </div>

        <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: theme.spacing.lg }}>
          <button
            type="button"
            data-testid="terminal-dispenser-panel-close"
            onClick={onClose}
            style={{
              padding: `${theme.spacing.sm} ${theme.spacing.lg}`,
              background: theme.colors.bg.tertiary,
              color: theme.colors.text.primary,
              border: `1px solid ${theme.colors.border.light}`,
              borderRadius: theme.borderRadius.md,
              cursor: 'pointer',
            }}
          >
            {t('common.close')}
          </button>
        </div>
      </div>
    </div>
  )
}
