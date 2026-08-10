/**
 * CreateAdminModal Component
 * Modal for creating new admin users
 */

import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { LanguageSelector } from '../forms/LanguageSelector'
import { FieldError, ModalError, modalInputStyle } from './ModalError'
import { validateCreateAdminForm } from '../../utils/settingsForms'

export interface CreateAdminModalProps {
  isOpen: boolean
  formData: {
    email: string
    display_name: string
    locale: 'de' | 'en'
  }
  /** Message from the last failed submit, e.g. an email the API already knows. */
  error?: string | null
  /** Field name → message, as the API reported it. */
  fieldErrors?: Record<string, string>
  onFormChange: (field: string, value: string) => void
  onSubmit: () => void
  onClose: () => void
}

export function CreateAdminModal({
  isOpen,
  formData,
  error,
  fieldErrors = {},
  onFormChange,
  onSubmit,
  onClose,
}: CreateAdminModalProps) {
  const { t } = useTranslation()
  // Field name → i18n key, from validating locally before the request goes out.
  const [validationErrors, setValidationErrors] = useState<Record<string, string>>({})

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
    const errors = validateCreateAdminForm(formData)
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
      data-testid="settings-admin-create-modal"
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
        style={{
          background: theme.colors.bg.primary,
          borderRadius: theme.borderRadius.lg,
          padding: theme.spacing.xl,
          maxWidth: '400px',
          width: '90%',
        }}
      >
        <h2 style={{ margin: 0, marginBottom: theme.spacing.lg }}>{t('settings.createAdminUser')}</h2>

        <ModalError message={error} testId="settings-admin-create-error" />

        <input
          data-testid="settings-admin-create-email"
          type="email"
          placeholder={t('settings.adminEmail')}
          value={formData.email}
          onChange={(e) => onFormChange('email', e.target.value)}
          style={modalInputStyle(!!messageFor('email'))}
        />
        <FieldError message={messageFor('email')} testId="settings-admin-create-email-error" />

        <input
          data-testid="settings-admin-create-display-name"
          type="text"
          placeholder={t('settings.adminDisplayName')}
          value={formData.display_name}
          onChange={(e) => onFormChange('display_name', e.target.value)}
          style={modalInputStyle(!!messageFor('display_name'))}
        />
        <FieldError message={messageFor('display_name')} testId="settings-admin-create-display-name-error" />

        <div style={{ marginBottom: theme.spacing.lg }}>
          <LanguageSelector
            value={formData.locale}
            onChange={(language) => onFormChange('locale', language)}
            testId="settings-admin-create-locale"
          />
        </div>
        <div style={{ display: 'flex', gap: theme.spacing.md }}>
          <button
            data-testid="settings-admin-create-confirm-button"
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
            data-testid="settings-admin-create-cancel-button"
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
