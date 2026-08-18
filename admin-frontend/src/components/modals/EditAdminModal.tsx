/**
 * EditAdminModal Component
 * Modal for editing admin users
 */

import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { LanguageSelector } from '../forms/LanguageSelector'
import { RoleSelector } from '../forms/RoleSelector'
import { FieldError, ModalError, modalInputStyle } from './ModalError'
import { useModalDialog } from '../../hooks/useModalDialog'
import { sameRoleSet } from '../../utils/adminRoles'
import { AdminRole } from '../../api/generated/adminRole'
import { StepUpCredentialFields, isStepUpComplete, type StepUpCredentials } from './StepUpConfirmDialog'

export interface EditAdminModalProps {
  isOpen: boolean
  formData: {
    email: string
    display_name: string
    locale: 'de' | 'en'
    roles: AdminRole[]
  }
  /** The roles the account held when the modal was opened — the step-up
   *  fields (and the change itself) fire only when `formData.roles` has
   *  actually moved away from this. */
  initialRoles: AdminRole[]
  /** True when the account being edited is the signed-in caller's own —
   *  nobody can change their own roles (CONTEXT.md's Role entry), so the
   *  selector is locked rather than merely hidden. */
  isSelf: boolean
  /** True when the caller (not the target) has 2FA enabled — shows the code field. */
  requiresTotp: boolean
  /** Message from the last failed submit, e.g. an email the API already knows. */
  error?: string | null
  /** Field name → message, as the API reported it. */
  fieldErrors?: Record<string, string>
  onFormChange: (field: string, value: string) => void
  onRolesChange: (roles: AdminRole[]) => void
  onSubmit: (credentials?: StepUpCredentials) => void
  onClose: () => void
}

export function EditAdminModal({
  isOpen,
  formData,
  initialRoles,
  isSelf,
  requiresTotp,
  error,
  fieldErrors = {},
  onFormChange,
  onRolesChange,
  onSubmit,
  onClose,
}: EditAdminModalProps) {
  const { t } = useTranslation()
  const contentRef = useModalDialog(isOpen, onClose)
  const [password, setPassword] = useState('')
  const [totpCode, setTotpCode] = useState('')
  const [stepUpValidationError, setStepUpValidationError] = useState<string | null>(null)

  // A fresh open asks for the credential again — a password typed while
  // editing one account must not carry over to the next.
  useEffect(() => {
    if (!isOpen) {
      setPassword('')
      setTotpCode('')
      setStepUpValidationError(null)
    }
  }, [isOpen])

  if (!isOpen) {
    return null
  }

  const rolesChanged = !sameRoleSet(formData.roles, initialRoles)

  const handleSubmit = () => {
    if (!rolesChanged) {
      onSubmit()
      return
    }

    if (!isStepUpComplete(password, totpCode, requiresTotp)) {
      setStepUpValidationError('settings.validation.stepUpRequiredCreateAdmin')
      return
    }

    setStepUpValidationError(null)
    onSubmit({ current_password: password, totp_code: requiresTotp ? totpCode : undefined })
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

        <RoleSelector
          value={formData.roles}
          onChange={onRolesChange}
          disabled={isSelf}
          disabledReason={isSelf ? t('settings.cannotEditOwnRoles') : undefined}
          testId="settings-admin-edit-role"
        />
        <FieldError message={fieldErrors.roles} testId="settings-admin-edit-role-error" />

        {rolesChanged && (
          <div
            style={{
              marginTop: theme.spacing.md,
              marginBottom: theme.spacing.md,
              paddingTop: theme.spacing.md,
              borderTop: `1px solid ${theme.colors.border.light}`,
            }}
          >
            <p
              style={{
                margin: `0 0 ${theme.spacing.md} 0`,
                fontSize: theme.typography.fontSize.xs,
                color: theme.colors.text.secondary,
              }}
            >
              {t('settings.createAdminStepUpHint')}
            </p>
            <StepUpCredentialFields
              requiresTotp={requiresTotp}
              password={password}
              totpCode={totpCode}
              invalid={!!error || !!stepUpValidationError}
              onPasswordChange={setPassword}
              onTotpCodeChange={setTotpCode}
            />
            <FieldError
              message={stepUpValidationError ? t(stepUpValidationError) : undefined}
              testId="settings-admin-edit-credential-error"
            />
          </div>
        )}

        <div style={{ display: 'flex', gap: theme.spacing.md }}>
          <button
            data-testid="settings-admin-edit-confirm-button"
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
