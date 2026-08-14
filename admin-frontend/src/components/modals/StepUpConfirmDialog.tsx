/**
 * The step-up credential prompt shown before a cross-account 2FA reset or
 * password reset (#337). Both actions used to need nothing beyond an active
 * session — a hijacked or borrowed session could strip 2FA off every admin
 * account and reset any password with a single click. The backend now
 * requires the caller to re-prove their own identity (current password,
 * plus a fresh TOTP code if the caller has 2FA enabled) before either write
 * happens; this dialog is where that credential is collected.
 *
 * Composes `ConfirmDialog`, the same way `UndoSettlementDialog` does — the
 * extra inputs live in the `message` slot, and the confirm button stays
 * disabled until a password (and, when required, a code) has been entered.
 *
 * On a failed step-up (401) the caller passes `error` back in and the
 * dialog stays open, so the admin can retry without re-opening it and
 * losing the target they were acting on.
 *
 * Test IDs: the surrounding ConfirmDialog's (`confirm-dialog`, `-ok`,
 * `-cancel`), plus `step-up-password`, `step-up-totp-code`, `step-up-error`.
 */

import { useEffect, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { ConfirmDialog } from './ConfirmDialog'
import { ModalError, modalInputStyle } from './ModalError'
import { theme } from '../../styles/design-system'

export interface StepUpCredentials {
  current_password: string
  totp_code?: string
}

export interface StepUpConfirmDialogProps {
  /** Whether the dialog is shown; a fresh open resets the entered credential. */
  isOpen: boolean
  title?: string
  /** The question being confirmed, e.g. "Reset 2FA for this admin user?" */
  message: string
  confirmLabel?: string
  /** True when the caller (not the target) has TOTP enabled — shows the code field. */
  requiresTotp: boolean
  /** Message from the last failed step-up attempt, e.g. a wrong password. */
  error?: string | null
  /**
   * Extra input rendered above the credential fields, for an action that needs
   * something of its own alongside the step-up. The SEPA export uses it for the
   * club's private key (#393): same credential prompt, one more secret.
   */
  extraFields?: ReactNode
  /** Additional reason to keep the confirm button disabled, e.g. no key chosen yet. */
  confirmDisabled?: boolean
  onConfirm: (credentials: StepUpCredentials) => void
  onCancel: () => void
}

export function StepUpConfirmDialog({
  isOpen,
  title,
  message,
  confirmLabel,
  requiresTotp,
  error,
  extraFields,
  confirmDisabled = false,
  onConfirm,
  onCancel,
}: StepUpConfirmDialogProps) {
  const [password, setPassword] = useState('')
  const [totpCode, setTotpCode] = useState('')

  // A fresh open asks for the credential again — a password typed for one
  // target, or left over from a failed attempt, must not carry into the next.
  useEffect(() => {
    if (isOpen) {
      setPassword('')
      setTotpCode('')
    }
  }, [isOpen])

  const canConfirm = !confirmDisabled && isStepUpComplete(password, totpCode, requiresTotp)

  return (
    <ConfirmDialog
      isOpen={isOpen}
      title={title}
      confirmLabel={confirmLabel}
      confirmDisabled={!canConfirm}
      onCancel={onCancel}
      onConfirm={() => onConfirm({ current_password: password, totp_code: requiresTotp ? totpCode : undefined })}
      message={
        <>
          <p style={{ margin: 0, marginBottom: theme.spacing.md }}>{message}</p>

          <ModalError message={error} testId="step-up-error" />

          {extraFields}

          <StepUpCredentialFields
            requiresTotp={requiresTotp}
            password={password}
            totpCode={totpCode}
            invalid={!!error}
            onPasswordChange={setPassword}
            onTotpCodeChange={setTotpCode}
          />
        </>
      }
    />
  )
}

/** Whether an entered credential is complete enough to send. */
export function isStepUpComplete(password: string, totpCode: string, requiresTotp: boolean): boolean {
  return password.trim() !== '' && (!requiresTotp || /^\d{6}$/.test(totpCode))
}

/**
 * The credential inputs themselves, so a dialog that cannot compose
 * `StepUpConfirmDialog` — one with a form of its own to submit, like terminal
 * enrolment (#395) — still asks for the credential in the same words, with the
 * same test IDs, and normalises the code the same way.
 */
export function StepUpCredentialFields({
  requiresTotp,
  password,
  totpCode,
  invalid = false,
  onPasswordChange,
  onTotpCodeChange,
}: {
  requiresTotp: boolean
  password: string
  totpCode: string
  invalid?: boolean
  onPasswordChange: (value: string) => void
  onTotpCodeChange: (value: string) => void
}) {
  const { t } = useTranslation()

  return (
    <>
      <input
        data-testid="step-up-password"
        type="password"
        autoComplete="current-password"
        placeholder={t('settings.stepUpPasswordLabel')}
        value={password}
        onChange={(e) => onPasswordChange(e.target.value)}
        style={modalInputStyle(invalid)}
      />

      {requiresTotp && (
        <>
          <p style={{ margin: `0 0 ${theme.spacing.sm} 0`, fontSize: theme.typography.fontSize.xs, color: theme.colors.text.secondary }}>
            {t('settings.stepUpTotpHint')}
          </p>
          <input
            data-testid="step-up-totp-code"
            type="text"
            inputMode="numeric"
            autoComplete="one-time-code"
            placeholder={t('settings.stepUpTotpLabel')}
            value={totpCode}
            // Digits only, six at most: the field is the last thing standing
            // between the admin and a 401 they would read as a wrong password.
            onChange={(e) => onTotpCodeChange(e.target.value.replace(/\D/g, '').slice(0, 6))}
            style={modalInputStyle(invalid)}
          />
        </>
      )}
    </>
  )
}
