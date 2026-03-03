/**
 * CreateTerminalModal Component
 * Modal for creating new terminal devices
 */

import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'

export interface CreateTerminalModalProps {
  isOpen: boolean
  formData: {
    name: string
    device_id: string
  }
  onFormChange: (field: string, value: string) => void
  onSubmit: () => void
  onClose: () => void
}

export function CreateTerminalModal({ isOpen, formData, onFormChange, onSubmit, onClose }: CreateTerminalModalProps) {
  const { t } = useTranslation()

  if (!isOpen) {
    return null
  }

  return (
    <div
      data-testid="settings-terminal-create-modal"
      style={{
        position: 'fixed',
        inset: 0,
        background: 'rgba(0, 0, 0, 0.5)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        zIndex: 1100,
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
        <h2 style={{ margin: 0, marginBottom: theme.spacing.lg }}>{t('settings.createTerminal')}</h2>
        <input
          data-testid="settings-terminal-create-name"
          type="text"
          placeholder="Terminal Name"
          value={formData.name}
          onChange={(e) => onFormChange('name', e.target.value)}
          style={{
            width: '100%',
            padding: theme.spacing.md,
            marginBottom: theme.spacing.md,
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.md,
            boxSizing: 'border-box',
            background: theme.colors.bg.secondary,
            color: theme.colors.text.primary,
          }}
        />
        <input
          data-testid="settings-terminal-create-device-id"
          type="text"
          placeholder="Device ID"
          value={formData.device_id}
          onChange={(e) => onFormChange('device_id', e.target.value)}
          style={{
            width: '100%',
            padding: theme.spacing.md,
            marginBottom: theme.spacing.lg,
            border: `1px solid ${theme.colors.border.light}`,
            borderRadius: theme.borderRadius.md,
            boxSizing: 'border-box',
            background: theme.colors.bg.secondary,
            color: theme.colors.text.primary,
          }}
        />
        <div style={{ display: 'flex', gap: theme.spacing.md }}>
          <button
            data-testid="settings-terminal-create-confirm-button"
            onClick={onSubmit}
            style={{
              flex: 1,
              padding: theme.spacing.md,
              background: theme.colors.semantic.primary,
              color: 'white',
              border: 'none',
              borderRadius: theme.borderRadius.md,
              cursor: 'pointer',
              transition: `all ${theme.transitions.default}`,
            }}
            onMouseEnter={(e) => {
              e.currentTarget.style.background = 'rgb(37, 99, 235)'
            }}
            onMouseLeave={(e) => {
              e.currentTarget.style.background = theme.colors.semantic.primary
            }}
          >
            {t('common.create')}
          </button>
          <button
            data-testid="settings-terminal-create-cancel-button"
            onClick={onClose}
            style={{
              flex: 1,
              padding: theme.spacing.md,
              background: 'transparent',
              color: theme.colors.text.secondary,
              border: `1px solid ${theme.colors.border.light}`,
              borderRadius: theme.borderRadius.md,
              cursor: 'pointer',
              transition: `all ${theme.transitions.default}`,
            }}
            onMouseEnter={(e) => {
              e.currentTarget.style.backgroundColor = theme.colors.bg.tertiary
            }}
            onMouseLeave={(e) => {
              e.currentTarget.style.backgroundColor = 'transparent'
            }}
          >
            {t('common.cancel')}
          </button>
        </div>
      </div>
    </div>
  )
}
