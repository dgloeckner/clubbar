/**
 * TokenDisplayModal Component
 * Modal for displaying generated API token (one-time view)
 */

import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'

export interface TokenDisplayModalProps {
  isOpen: boolean
  token: string | null
  onClose: () => void
}

export function TokenDisplayModal({ isOpen, token, onClose }: TokenDisplayModalProps) {
  const { t } = useTranslation()

  if (!isOpen || !token) {
    return null
  }

  const handleCopy = () => {
    navigator.clipboard.writeText(token)
    onClose()
  }

  return (
    <div
      data-testid="settings-terminal-token-modal"
      style={{
        position: 'fixed',
        inset: 0,
        background: 'rgba(0, 0, 0, 0.5)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        zIndex: 1000,
      }}
      onClick={onClose}
    >
      <div
        style={{
          background: theme.colors.bg.primary,
          borderRadius: theme.borderRadius.lg,
          padding: theme.spacing.xl,
          maxWidth: '400px',
          width: '90%',
        }}
        onClick={(e) => e.stopPropagation()}
      >
        <h2 style={{ margin: 0, marginBottom: theme.spacing.lg, color: 'rgb(234, 88, 12)' }}>{t('settings.tokenGenerated')}</h2>
        <p style={{ margin: 0, marginBottom: theme.spacing.lg, color: theme.colors.text.secondary }}>
          {t('settings.tokenWarning')}
        </p>
        <div
          data-testid="settings-terminal-token-display"
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
          }}
        >
          {token}
        </div>
        <div style={{ display: 'flex', gap: theme.spacing.sm }}>
          <button
            data-testid="settings-terminal-token-copy-button"
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
            Copy & Close
          </button>
          <button
            data-testid="settings-terminal-token-close-button"
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
            {t('common.close')}
          </button>
        </div>
      </div>
    </div>
  )
}
