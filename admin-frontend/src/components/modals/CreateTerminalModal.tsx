/**
 * CreateTerminalModal Component
 * Modal for creating new terminal devices
 */

import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { FieldError, ModalError, modalInputStyle } from './ModalError'
import { validateCreateTerminalForm } from '../../utils/settingsForms'
import { useModalDialog } from '../../hooks/useModalDialog'

export interface CreateTerminalModalProps {
  isOpen: boolean
  formData: {
    name: string
    device_id: string
  }
  /** Message from the last failed submit, e.g. a device ID already registered. */
  error?: string | null
  /** Field name → message, as the API reported it. */
  fieldErrors?: Record<string, string>
  onFormChange: (field: string, value: string) => void
  onSubmit: () => void
  onClose: () => void
}

export function CreateTerminalModal({
  isOpen,
  formData,
  error,
  fieldErrors = {},
  onFormChange,
  onSubmit,
  onClose,
}: CreateTerminalModalProps) {
  const { t } = useTranslation()
  // Field name → i18n key, from validating locally before the request goes out.
  const [validationErrors, setValidationErrors] = useState<Record<string, string>>({})
  const contentRef = useModalDialog(isOpen, onClose)

  // The modal stays mounted while closed, so a stale complaint would otherwise
  // greet whoever opens it next.
  useEffect(() => {
    if (!isOpen) {
      setValidationErrors({})
    }
  }, [isOpen])

  if (!isOpen) {
    return null
  }

  const messageFor = (field: string): string | undefined =>
    validationErrors[field] ? t(validationErrors[field]) : fieldErrors[field]

  const handleSubmit = () => {
    const errors = validateCreateTerminalForm(formData)
    setValidationErrors(errors)
    if (Object.keys(errors).length > 0) {
      return
    }
    onSubmit()
  }

  // The backdrop deliberately carries no close handler: a stray click beside
  // the dialog used to discard everything typed into it (#131). It closes
  // through Cancel or a successful create.
  return (
    <div
      data-testid="settings-terminal-create-modal"
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
        aria-labelledby="settings-terminal-create-title"
        tabIndex={-1}
        style={{
          background: theme.colors.bg.primary,
          borderRadius: theme.borderRadius.lg,
          padding: theme.spacing.xl,
          maxWidth: '400px',
          width: '90%',
        }}
      >
        <h2 id="settings-terminal-create-title" style={{ margin: 0, marginBottom: theme.spacing.lg }}>{t('settings.createTerminal')}</h2>

        <ModalError message={error} testId="settings-terminal-create-error" />

        <input
          data-testid="settings-terminal-create-name"
          type="text"
          placeholder={t('settings.terminalName')}
          value={formData.name}
          onChange={(e) => onFormChange('name', e.target.value)}
          style={modalInputStyle(!!messageFor('name'))}
        />
        <FieldError message={messageFor('name')} testId="settings-terminal-create-name-error" />

        <input
          data-testid="settings-terminal-create-device-id"
          type="text"
          placeholder={t('settings.deviceId')}
          value={formData.device_id}
          onChange={(e) => onFormChange('device_id', e.target.value)}
          style={{
            ...modalInputStyle(!!messageFor('device_id')),
            marginBottom: messageFor('device_id') ? theme.spacing.xs : theme.spacing.lg,
          }}
        />
        <FieldError message={messageFor('device_id')} testId="settings-terminal-create-device-id-error" />

        <div style={{ display: 'flex', gap: theme.spacing.md }}>
          <button
            data-testid="settings-terminal-create-confirm-button"
            onClick={handleSubmit}
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
