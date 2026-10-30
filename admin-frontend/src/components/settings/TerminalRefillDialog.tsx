/**
 * Recording a hopper refill (#955, ADR-0058).
 *
 * The dispenser cannot say it is running out — its *empty* switch is a factory
 * option this unit does not have — so the panel's fill level is arithmetic: a
 * counted refill, minus the tokens sold since. This dialog is the only place
 * that count ever gets in, which is why three of its rules are not styling:
 *
 * 1. **An exact count, not "added N".** The field asks how many tokens are in
 *    the hopper *now*, and that number replaces the estimate outright. Adding
 *    to a figure nobody has checked keeps the drift; counting ends it (owner
 *    decision 8).
 * 2. **It is not an acknowledgement.** Nothing here clears a fault, and the
 *    dialog says so in as many words: the device has no reset route and a jam
 *    is cleared by a power cycle (owner decision 3). That is also why this
 *    lives on the terminal's actions rather than inside the dispenser detail —
 *    a "record refill" button sitting under a jam badge reads as a button that
 *    answers the jam.
 * 3. **The threshold rides along.** When to warn is a property of this bar —
 *    how large its hopper is, how fast it sells — and the moment somebody is
 *    standing at the machine with a bag of tokens is the moment they know what
 *    that number should be. It is saved through the ordinary terminal update,
 *    because it is a setting rather than a fact about the hopper.
 */

import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { useModalDialog } from '../../hooks/useModalDialog'
import { useApiError } from '../../hooks/useApiError'
import { getTerminals } from '../../api/generated/terminals/terminals'
import type { Terminal as GeneratedTerminal } from '../../api/generated/model'

export type RefillTerminal = Pick<GeneratedTerminal, 'id' | 'name' | 'dispenser_fill'>

export interface TerminalRefillDialogProps {
  isOpen: boolean
  terminal: RefillTerminal | null
  onClose: () => void
  /** Fired after a successful save, so the caller can reload its list. */
  onSaved: () => void
}

/** Digits only: a token is a whole thing, so "12,5" cannot be typed at all. */
function onlyDigits(value: string): string {
  return value.replace(/\D/g, '')
}

const fieldStyle: React.CSSProperties = {
  width: '100%',
  padding: theme.spacing.md,
  background: theme.colors.bg.tertiary,
  color: theme.colors.text.primary,
  border: `1px solid ${theme.colors.border.light}`,
  borderRadius: theme.borderRadius.md,
  fontSize: theme.typography.fontSize.sm,
}

export function TerminalRefillDialog({ isOpen, terminal, onClose, onSaved }: TerminalRefillDialogProps) {
  const { t } = useTranslation()
  const { apiErrorMessage } = useApiError()
  const contentRef = useModalDialog(isOpen, onClose)

  const [tokens, setTokens] = useState('')
  const [threshold, setThreshold] = useState('')
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)

  // The count starts empty on purpose — it is the one number that must be
  // counted rather than confirmed, and a prefilled estimate invites a nod.
  // The threshold is the opposite: it is the stored setting, and arriving
  // empty would read as "no warning configured".
  useEffect(() => {
    if (!isOpen) return
    setTokens('')
    setThreshold(
      typeof terminal?.dispenser_fill?.low_threshold === 'number'
        ? String(terminal.dispenser_fill.low_threshold)
        : '',
    )
    setError(null)
  }, [isOpen, terminal])

  if (!isOpen || !terminal) return null

  const storedThreshold = terminal.dispenser_fill?.low_threshold
  const thresholdChanged = threshold !== '' && threshold !== String(storedThreshold ?? '')

  const save = async () => {
    if (tokens === '') return
    setSaving(true)
    setError(null)
    try {
      // The threshold first: a failure there must not leave a refill recorded
      // against a terminal whose settings the admin also meant to change,
      // because the refill is the write that cannot be repeated honestly —
      // the hopper has already been counted once.
      if (thresholdChanged) {
        await getTerminals().updateTerminal(terminal.id!, { dispenser_low_threshold: Number(threshold) })
      }
      await getTerminals().recordDispenserRefill(terminal.id!, { tokens: Number(tokens) })
      onSaved()
      onClose()
    } catch (err: unknown) {
      setError(apiErrorMessage(err, t('settings.terminalDispenserRefillError')))
    } finally {
      setSaving(false)
    }
  }

  return (
    <div
      data-testid="terminal-refill-dialog"
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
        data-testid="terminal-refill-dialog-content"
        role="dialog"
        aria-modal="true"
        aria-labelledby="terminal-refill-dialog-title"
        tabIndex={-1}
        style={{
          background: theme.colors.bg.secondary,
          borderRadius: theme.borderRadius.lg,
          padding: theme.spacing.xl,
          maxWidth: '480px',
          width: '90%',
          maxHeight: '80vh',
          overflowY: 'auto',
          boxShadow: theme.shadows.modalStrong,
          fontSize: theme.typography.fontSize.sm,
        }}
        onClick={(e) => e.stopPropagation()}
      >
        <h2
          id="terminal-refill-dialog-title"
          style={{
            margin: 0,
            marginBottom: theme.spacing.md,
            fontSize: theme.typography.fontSize.lg,
            fontWeight: theme.typography.fontWeight.semibold,
            color: theme.colors.text.primary,
          }}
        >
          {t('settings.terminalDispenserRefillTitle', { name: terminal.name })}
        </h2>

        {/* Rule 2, said out loud rather than implied by the absence of a
            button: nothing here reaches the machine. */}
        <p
          data-testid="terminal-refill-dialog-note"
          style={{ marginTop: 0, marginBottom: theme.spacing.lg, color: theme.colors.text.secondary }}
        >
          {t('settings.terminalDispenserRefillNote')}
        </p>

        <label
          htmlFor="terminal-refill-tokens"
          style={{ display: 'block', marginBottom: theme.spacing.sm, color: theme.colors.text.secondary }}
        >
          {t('settings.terminalDispenserRefillTokensLabel')}
        </label>
        <input
          id="terminal-refill-tokens"
          data-testid="terminal-refill-tokens-input"
          inputMode="numeric"
          autoComplete="off"
          value={tokens}
          onChange={(e) => setTokens(onlyDigits(e.target.value))}
          style={fieldStyle}
        />
        <p style={{ margin: `${theme.spacing.sm} 0 ${theme.spacing.lg}`, color: theme.colors.text.muted }}>
          {t('settings.terminalDispenserRefillTokensHint')}
        </p>

        <label
          htmlFor="terminal-refill-threshold"
          style={{ display: 'block', marginBottom: theme.spacing.sm, color: theme.colors.text.secondary }}
        >
          {t('settings.terminalDispenserLowThresholdLabel')}
        </label>
        <input
          id="terminal-refill-threshold"
          data-testid="terminal-refill-threshold-input"
          inputMode="numeric"
          autoComplete="off"
          value={threshold}
          onChange={(e) => setThreshold(onlyDigits(e.target.value))}
          style={fieldStyle}
        />
        <p style={{ margin: `${theme.spacing.sm} 0 0`, color: theme.colors.text.muted }}>
          {t('settings.terminalDispenserLowThresholdHint')}
        </p>

        {error && (
          <p
            data-testid="terminal-refill-dialog-error"
            style={{ marginTop: theme.spacing.lg, marginBottom: 0, color: theme.colors.semantic.danger }}
          >
            {error}
          </p>
        )}

        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: theme.spacing.sm, marginTop: theme.spacing.lg }}>
          <button
            type="button"
            data-testid="terminal-refill-dialog-cancel"
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
            {t('common.cancel')}
          </button>
          <button
            type="button"
            data-testid="terminal-refill-dialog-save"
            onClick={save}
            disabled={saving || tokens === ''}
            style={{
              padding: `${theme.spacing.sm} ${theme.spacing.lg}`,
              background: theme.colors.semantic.primary,
              color: 'white',
              border: 'none',
              borderRadius: theme.borderRadius.md,
              cursor: saving || tokens === '' ? 'not-allowed' : 'pointer',
              opacity: saving || tokens === '' ? 0.6 : 1,
            }}
          >
            {t('common.save')}
          </button>
        </div>
      </div>
    </div>
  )
}
