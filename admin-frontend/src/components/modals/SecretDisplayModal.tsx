/**
 * SecretDisplayModal Component
 *
 * Shared shell for values shown exactly once (terminal API tokens, generated
 * admin passwords). Such a value is unrecoverable, so the modal never closes
 * on a stray click and never on an unverified copy:
 *
 * - the clipboard write is awaited; success is confirmed in the UI
 * - a failed write keeps the modal open and selects the value for manual copy
 * - the backdrop is inert; only the explicit acknowledge button closes
 */

import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { useClipboardCopy } from '../../hooks/useClipboardCopy'
import { theme } from '../../styles/design-system'

export interface SecretDisplayModalProps {
  isOpen: boolean
  secret: string | null
  title: string
  warning: string
  /** Prefix for the modal's test IDs, e.g. `settings-terminal-token`. */
  testIdPrefix: string
  onClose: () => void
}

export function SecretDisplayModal({
  isOpen,
  secret,
  title,
  warning,
  testIdPrefix,
  onClose,
}: SecretDisplayModalProps) {
  const { t } = useTranslation()
  const { status, copy, reset } = useClipboardCopy()
  const secretRef = useRef<HTMLDivElement>(null)

  // A new secret is a new copy attempt — never inherit the previous verdict.
  useEffect(() => {
    reset()
  }, [secret, reset])

  if (!isOpen || !secret) {
    return null
  }

  const handleCopy = async () => {
    const copied = await copy(secret)

    // Manual-selection fallback: put the value under the cursor so the user
    // can copy it with the keyboard instead of losing it.
    if (!copied) {
      selectSecretText()
    }
  }

  const selectSecretText = () => {
    const node = secretRef.current
    const selection = typeof window === 'undefined' ? null : window.getSelection()
    if (!node || !selection) return

    const range = document.createRange()
    range.selectNodeContents(node)
    selection.removeAllRanges()
    selection.addRange(range)
  }

  const titleId = `${testIdPrefix}-title`

  return (
    <div
      data-testid={`${testIdPrefix}-modal`}
      style={{
        position: 'fixed',
        inset: 0,
        background: 'rgba(0, 0, 0, 0.5)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        zIndex: 1100,
      }}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        style={{
          background: theme.colors.bg.primary,
          borderRadius: theme.borderRadius.lg,
          padding: theme.spacing.xl,
          maxWidth: '400px',
          width: '90%',
        }}
      >
        <h2
          id={titleId}
          style={{ margin: 0, marginBottom: theme.spacing.lg, color: 'rgb(234, 88, 12)' }}
        >
          {title}
        </h2>
        <p style={{ margin: 0, marginBottom: theme.spacing.lg, color: theme.colors.text.secondary }}>
          {warning}
        </p>
        <div
          ref={secretRef}
          data-testid={`${testIdPrefix}-display`}
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
            data-testid={`${testIdPrefix}-copy-button`}
            onClick={handleCopy}
            style={{
              flex: 1,
              padding: theme.spacing.md,
              background: theme.colors.semantic.primary,
              color: 'white',
              border: 'none',
              borderRadius: theme.borderRadius.md,
              cursor: 'pointer',
              transition: `all ${theme.transitions.default}`,
              fontWeight: theme.typography.fontWeight.semibold,
            }}
            onMouseEnter={(e) => {
              e.currentTarget.style.background = 'rgb(37, 99, 235)'
            }}
            onMouseLeave={(e) => {
              e.currentTarget.style.background = theme.colors.semantic.primary
            }}
          >
            {status === 'copied' ? t('settings.secretCopyAgain') : t('settings.secretCopy')}
          </button>
          <button
            data-testid={`${testIdPrefix}-close-button`}
            onClick={onClose}
            style={{
              padding: theme.spacing.md,
              background: theme.colors.bg.tertiary,
              color: theme.colors.text.primary,
              border: `1px solid ${theme.colors.border.light}`,
              borderRadius: theme.borderRadius.md,
              cursor: 'pointer',
              transition: `all ${theme.transitions.default}`,
              fontWeight: theme.typography.fontWeight.semibold,
            }}
          >
            {t('settings.secretAcknowledge')}
          </button>
        </div>
      </div>
    </div>
  )
}
