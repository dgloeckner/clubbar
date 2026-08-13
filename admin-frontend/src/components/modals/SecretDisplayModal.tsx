/**
 * SecretDisplayModal Component
 *
 * Modal shell for values shown exactly once (terminal API tokens, generated
 * admin passwords). Such a value is unrecoverable, so the modal never closes
 * on a stray click and never on an unverified copy: the backdrop is inert and
 * only the explicit acknowledge button closes.
 *
 * The value itself, the copy button and its confirmed/failed verdict come from
 * SecretBox — which is also used inline (without a modal) during TOTP
 * enrollment. Test IDs are unchanged: SecretBox derives them from testIdPrefix.
 */

import { useTranslation } from 'react-i18next'
import { SecretBox } from '../common/SecretBox'
import { useModalDialog } from '../../hooks/useModalDialog'
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
  const contentRef = useModalDialog(isOpen, onClose)

  if (!isOpen || !secret) {
    return null
  }

  const titleId = `${testIdPrefix}-title`

  return (
    <div
      data-testid={`${testIdPrefix}-modal`}
      style={{
        position: 'fixed',
        inset: 0,
        background: theme.overlay.backdrop,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        zIndex: 1100,
      }}
    >
      <div
        ref={contentRef}
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        tabIndex={-1}
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
        <SecretBox
          secret={secret}
          testIdPrefix={testIdPrefix}
          actions={
            <button
              type="button"
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
          }
        />
      </div>
    </div>
  )
}
