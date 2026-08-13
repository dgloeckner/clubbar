/**
 * SecretBox Component
 *
 * Presents a value the user must capture before it becomes unrecoverable
 * (terminal API tokens, generated admin passwords, the TOTP backup key).
 *
 * The behaviour is deliberate, not decorative:
 * - the clipboard write is awaited, so success is confirmed rather than assumed
 * - a failed write selects the value so it can still be copied by keyboard
 * - a new secret always resets the previous copy verdict
 *
 * Callers that need this inside a dialog should use SecretDisplayModal, which
 * wraps this component and adds the modal shell.
 */

import { ReactNode, useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { useClipboardCopy } from '../../hooks/useClipboardCopy'
import { theme } from '../../styles/design-system'

export interface SecretBoxProps {
  /** The value to display. */
  secret: string
  /** Prefix for this box's test IDs, e.g. `settings-terminal-token`. */
  testIdPrefix: string
  /** Test ID of the value element. Defaults to `${testIdPrefix}-display`. */
  valueTestId?: string
  /** Extra buttons rendered alongside the copy button, e.g. an acknowledge button. */
  actions?: ReactNode
  /**
   * Weight of the copy button. Use `secondary` when the surrounding form has
   * its own primary action — two saturated buttons make the real one recede.
   */
  variant?: 'primary' | 'secondary'
}

export function SecretBox({
  secret,
  testIdPrefix,
  valueTestId,
  actions,
  variant = 'primary',
}: SecretBoxProps) {
  const { t } = useTranslation()
  const { status, copy, reset } = useClipboardCopy()
  const secretRef = useRef<HTMLDivElement>(null)
  const isPrimary = variant === 'primary'

  // A new secret is a new copy attempt — never inherit the previous verdict.
  useEffect(() => {
    reset()
  }, [secret, reset])

  const selectSecretText = () => {
    const node = secretRef.current
    const selection = typeof window === 'undefined' ? null : window.getSelection()
    if (!node || !selection) return

    const range = document.createRange()
    range.selectNodeContents(node)
    selection.removeAllRanges()
    selection.addRange(range)
  }

  const handleCopy = async () => {
    const copied = await copy(secret)

    // Manual-selection fallback: put the value under the cursor so the user
    // can copy it with the keyboard instead of losing it.
    if (!copied) {
      selectSecretText()
    }
  }

  return (
    <>
      <div
        ref={secretRef}
        data-testid={valueTestId ?? `${testIdPrefix}-display`}
        onClick={selectSecretText}
        style={{
          padding: theme.spacing.md,
          background: theme.colors.bg.tertiary,
          borderRadius: theme.borderRadius.md,
          marginBottom: theme.spacing.lg,
          fontFamily: 'monospace',
          fontSize: theme.typography.fontSize.sm,
          textAlign: 'center',
          wordBreak: 'break-all',
          color: theme.colors.text.primary,
          userSelect: 'all',
          cursor: 'text',
        }}
      >
        {secret}
      </div>

      {status === 'copied' && (
        <p
          data-testid={`${testIdPrefix}-copy-status`}
          role="status"
          style={{
            margin: 0,
            marginBottom: theme.spacing.lg,
            color: theme.colors.semantic.success,
            fontWeight: theme.typography.fontWeight.semibold,
          }}
        >
          {t('settings.secretCopied')}
        </p>
      )}

      {status === 'failed' && (
        <p
          data-testid={`${testIdPrefix}-copy-error`}
          role="alert"
          style={{
            margin: 0,
            marginBottom: theme.spacing.lg,
            color: theme.colors.semantic.danger,
            fontWeight: theme.typography.fontWeight.semibold,
          }}
        >
          {t('settings.secretCopyFailed')}
        </p>
      )}

      <div style={{ display: 'flex', gap: theme.spacing.sm }}>
        <button
          type="button"
          data-testid={`${testIdPrefix}-copy-button`}
          onClick={handleCopy}
          style={{
            flex: 1,
            padding: theme.spacing.md,
            background: isPrimary ? theme.colors.semantic.primary : theme.colors.bg.tertiary,
            color: isPrimary ? 'white' : theme.colors.text.primary,
            border: isPrimary ? 'none' : `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.md,
            cursor: 'pointer',
            transition: `all ${theme.transitions.default}`,
            fontWeight: theme.typography.fontWeight.semibold,
          }}
          onMouseEnter={(e) => {
            e.currentTarget.style.background = isPrimary
              ? theme.colors.semantic.primaryHover
              : theme.colors.bg.hover
          }}
          onMouseLeave={(e) => {
            e.currentTarget.style.background = isPrimary
              ? theme.colors.semantic.primary
              : theme.colors.bg.tertiary
          }}
        >
          {status === 'copied' ? t('settings.secretCopyAgain') : t('settings.secretCopy')}
        </button>
        {actions}
      </div>
    </>
  )
}
