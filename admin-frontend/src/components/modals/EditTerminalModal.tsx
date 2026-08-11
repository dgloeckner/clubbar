/**
 * EditTerminalModal Component
 * Modal for editing terminal device name
 */

import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { FieldError, ModalError, modalInputStyle } from './ModalError'
import { useModalDialog } from '../../hooks/useModalDialog'

export interface EditTerminalModalProps {
  isOpen: boolean
  formData: {
    name: string
  }
  /** Message from the last failed submit. */
  error?: string | null
  /** Field name → message, as the API reported it. */
  fieldErrors?: Record<string, string>
  onFormChange: (field: string, value: string) => void
  onSubmit: () => void
  onClose: () => void
}

export function EditTerminalModal({
  isOpen,
  formData,
  error,
  fieldErrors = {},
  onFormChange,
  onSubmit,
  onClose,
}: EditTerminalModalProps) {
  const { t } = useTranslation()
  const contentRef = useModalDialog(isOpen, onClose)

  if (!isOpen) {
    return null
  }

  // The backdrop deliberately carries no close handler: a stray click beside
  // the dialog used to discard everything typed into it (#131). It closes
  // through Cancel or a successful save.
  return (
    <div
      data-testid="settings-terminal-edit-modal"
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
        aria-labelledby="settings-terminal-edit-title"
        tabIndex={-1}
        style={{
          background: theme.colors.bg.primary,
          borderRadius: theme.borderRadius.lg,
          padding: theme.spacing.xl,
          maxWidth: '400px',
          width: '90%',
        }}
      >
        <h2 id="settings-terminal-edit-title" style={{ margin: 0, marginBottom: theme.spacing.lg }}>{t('settings.editTerminal')}</h2>

        <ModalError message={error} testId="settings-terminal-edit-error" />

        <input
          data-testid="settings-terminal-edit-name"
          type="text"
          placeholder={t('settings.terminalName')}
          value={formData.name}
          onChange={(e) => onFormChange('name', e.target.value)}
          style={{
            ...modalInputStyle(!!fieldErrors.name),
            marginBottom: fieldErrors.name ? theme.spacing.xs : theme.spacing.lg,
          }}
        />
        <FieldError message={fieldErrors.name} testId="settings-terminal-edit-name-error" />
        <div style={{ display: 'flex', gap: theme.spacing.md }}>
          <button
            data-testid="settings-terminal-edit-confirm-button"
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
            {t('common.save')}
          </button>
          <button
            data-testid="settings-terminal-edit-cancel-button"
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
