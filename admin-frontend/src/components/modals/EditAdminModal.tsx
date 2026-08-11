/**
 * EditAdminModal Component
 * Modal for editing admin users
 */

import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { LanguageSelector } from '../forms/LanguageSelector'
import { FieldError, ModalError, modalInputStyle } from './ModalError'
import { useModalDialog } from '../../hooks/useModalDialog'

export interface EditAdminModalProps {
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

export function EditAdminModal({
  isOpen,
  formData,
  error,
  fieldErrors = {},
  onFormChange,
  onSubmit,
  onClose,
}: EditAdminModalProps) {
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
      data-testid="settings-admin-edit-modal"
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
        aria-labelledby="settings-admin-edit-title"
        tabIndex={-1}
        style={{
          background: theme.colors.bg.primary,
          borderRadius: theme.borderRadius.lg,
          padding: theme.spacing.xl,
          maxWidth: '400px',
          width: '90%',
        }}
      >
        <h2 id="settings-admin-edit-title" style={{ margin: 0, marginBottom: theme.spacing.lg }}>{t('settings.editAdminUser')}</h2>

        <ModalError message={error} testId="settings-admin-edit-error" />

        <input
          data-testid="settings-admin-edit-email"
          type="email"
          placeholder={t('settings.adminEmail')}
          value={formData.email}
          onChange={(e) => onFormChange('email', e.target.value)}
          style={modalInputStyle(!!fieldErrors.email)}
        />
        <FieldError message={fieldErrors.email} testId="settings-admin-edit-email-error" />

        <input
          data-testid="settings-admin-edit-display-name"
          type="text"
          placeholder={t('settings.adminDisplayName')}
          value={formData.display_name}
          onChange={(e) => onFormChange('display_name', e.target.value)}
          style={modalInputStyle(!!fieldErrors.display_name)}
        />
        <FieldError message={fieldErrors.display_name} testId="settings-admin-edit-display-name-error" />

        <div style={{ marginBottom: theme.spacing.lg }}>
          <LanguageSelector
            value={formData.locale}
            onChange={(language) => onFormChange('locale', language)}
            testId="settings-admin-edit-locale"
          />
        </div>
        <div style={{ display: 'flex', gap: theme.spacing.md }}>
          <button
            data-testid="settings-admin-edit-confirm-button"
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
            data-testid="settings-admin-edit-cancel-button"
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
