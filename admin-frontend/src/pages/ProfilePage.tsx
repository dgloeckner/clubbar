/**
 * Profile Page
 * Personal settings for the logged-in admin user
 * - View/edit profile information (email, display name, language)
 * - Change password
 *
 * Both credential changes on this page carry a step-up: the email address is
 * the login identifier, and the password is what a hijacked session would
 * rotate first. The email gate is deliberately conditional on the address
 * actually moving — Save PATCHes the whole form and `handleLanguageChange`
 * PATCHes on every toggle, so gating any profile write would demand a password
 * to switch language.
 */

import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useApiError } from '../hooks/useApiError'
import { PageHeader } from '../components/layout/PageHeader'
import { theme } from '../styles/design-system'
import { getAuthentication } from '../api/generated/authentication/authentication'
import { getProfile, updateProfileWithSession } from '../auth/session'
import type { AdminProfile } from '../api/generated'
import { LanguageSelector } from '../components/forms/LanguageSelector'
import { StepUpConfirmDialog, type StepUpCredentials } from '../components/modals/StepUpConfirmDialog'
import { changeLanguage } from '../i18n/config'
import { useFormatters } from '../hooks/useFormatters'
import { useAuth } from '../context/AuthContext'

export function ProfilePage() {
  const { t } = useTranslation()
  const { apiErrorMessage } = useApiError()
  const { intlLocale } = useFormatters()
  const { updateProfile: updateAuthProfile } = useAuth()
  const [profile, setProfile] = useState<AdminProfile | null>(null)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [success, setSuccess] = useState<string | null>(null)

  // Profile form
  const [email, setEmail] = useState('')
  const [displayName, setDisplayName] = useState('')
  const [locale, setLocale] = useState<'de' | 'en'>('de')

  // Password form
  const [currentPassword, setCurrentPassword] = useState('')
  const [newPassword, setNewPassword] = useState('')
  const [confirmPassword, setConfirmPassword] = useState('')
  const [passwordTotpCode, setPasswordTotpCode] = useState('')
  const [passwordError, setPasswordError] = useState<string | null>(null)
  const [passwordSuccess, setPasswordSuccess] = useState<string | null>(null)
  const [changingPassword, setChangingPassword] = useState(false)

  // Step-up prompt for an email change, which moves the login identifier.
  const [emailStepUpOpen, setEmailStepUpOpen] = useState(false)
  const [emailStepUpError, setEmailStepUpError] = useState<string | null>(null)

  // Guards state updates below against firing after unmount — `getProfile`
  // has no cancellation of its own, so a page navigated away from before it
  // resolves would otherwise still write into the departed component (#136).
  const isMountedRef = useRef(true)
  // Pending "clear success message" timers, cancelled on unmount for the
  // same reason, and cancelled on a fresh success so an earlier timer never
  // clears a newer message.
  const successTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null)
  const passwordSuccessTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  useEffect(() => {
    return () => {
      isMountedRef.current = false
      if (successTimeoutRef.current) clearTimeout(successTimeoutRef.current)
      if (passwordSuccessTimeoutRef.current) clearTimeout(passwordSuccessTimeoutRef.current)
    }
  }, [])

  useEffect(() => {
    loadProfile()
  }, [])

  const loadProfile = async () => {
    try {
      setLoading(true)
      const data = await getProfile()
      if (!isMountedRef.current) return
      setProfile(data)
      setEmail(data.email ?? '')
      setDisplayName(data.display_name ?? '')
      setLocale((data.locale as 'de' | 'en') ?? 'de')
    } catch (err) {
      if (!isMountedRef.current) return
      setError(t('errors.generic'))
    } finally {
      if (isMountedRef.current) setLoading(false)
    }
  }

  const handleLanguageChange = async (newLocale: 'de' | 'en') => {
    changeLanguage(newLocale)  // Update i18n and localStorage immediately
    setLocale(newLocale)       // Update local state
    try {
      const updated = await updateProfileWithSession({ locale: newLocale })  // Persist to backend
      updateAuthProfile(updated)  // Keep header/context in sync (#134)
    } catch {
      // locale preference save failed — non-critical
    }
  }

  /**
   * Is the form about to move the login identifier? Compared case-insensitively
   * to match the backend, which treats a case-only difference as no change
   * because `admin_users.email` is UNIQUE under a case-insensitive collation.
   */
  const emailIsChanging = email.trim().toLowerCase() !== (profile?.email ?? '').toLowerCase()

  const handleSaveProfile = () => {
    if (emailIsChanging) {
      setEmailStepUpError(null)
      setEmailStepUpOpen(true)
      return
    }
    void saveProfile()
  }

  const saveProfile = async (credentials?: StepUpCredentials) => {
    try {
      setSaving(true)
      setError(null)
      setSuccess(null)

      const updated = await updateProfileWithSession({
        email,
        display_name: displayName,
        locale,
        ...credentials,
      })

      setProfile(updated)
      updateAuthProfile(updated)  // Keep header/context in sync (#134)
      setEmailStepUpOpen(false)
      setSuccess(t('profile.profileUpdated'))

      // Clear success after 3 seconds. Cancel a still-pending timer from an
      // earlier save first, so it cannot clear this newer message.
      if (successTimeoutRef.current) clearTimeout(successTimeoutRef.current)
      successTimeoutRef.current = setTimeout(() => setSuccess(null), 3000)
    } catch (err: unknown) {
      const response = err instanceof Error && 'response' in err
        ? (err as { response?: { status?: number } }).response
        : undefined
      const message = apiErrorMessage(err, t('profile.saveFailed'))

      // A rejected credential keeps the dialog open so the admin can retry
      // without losing the address they typed; anything else is a page-level
      // failure and the dialog has nothing more to offer.
      if (credentials && response?.status === 401) {
        setEmailStepUpError(message)
      } else {
        setEmailStepUpOpen(false)
        setError(message)
      }
    } finally {
      setSaving(false)
    }
  }

  const handleChangePassword = async () => {
    setPasswordError(null)
    setPasswordSuccess(null)

    // Validate
    if (!currentPassword) {
      setPasswordError(t('validation.required'))
      return
    }
    if (!newPassword) {
      setPasswordError(t('validation.required'))
      return
    }
    if (newPassword.length < 8) {
      setPasswordError(t('validation.minLength', { min: 8 }))
      return
    }
    if (!/(?=.*[a-z])(?=.*[A-Z])(?=.*\d)/.test(newPassword)) {
      setPasswordError(t('validation.passwordComplexity'))
      return
    }
    if (newPassword !== confirmPassword) {
      setPasswordError(t('validation.passwordMismatch'))
      return
    }
    if (profile?.totp_enabled && !/^\d{6}$/.test(passwordTotpCode)) {
      setPasswordError(t('validation.totpCodeRequired'))
      return
    }

    try {
      setChangingPassword(true)
      await getAuthentication().changePassword({
        current_password: currentPassword,
        new_password: newPassword,
        new_password_confirmation: confirmPassword,
        ...(profile?.totp_enabled ? { totp_code: passwordTotpCode } : {}),
      })

      setPasswordSuccess(t('profile.passwordChanged'))
      setCurrentPassword('')
      setNewPassword('')
      setConfirmPassword('')
      setPasswordTotpCode('')

      // Clear success after 3 seconds. Cancel a still-pending timer from an
      // earlier change first, so it cannot clear this newer message.
      if (passwordSuccessTimeoutRef.current) clearTimeout(passwordSuccessTimeoutRef.current)
      passwordSuccessTimeoutRef.current = setTimeout(() => setPasswordSuccess(null), 3000)
    } catch (err: unknown) {
      setPasswordError(apiErrorMessage(err, t('profile.saveFailed')))
    } finally {
      setChangingPassword(false)
    }
  }

  const inputStyle = {
    width: '100%',
    padding: theme.spacing.md,
    background: theme.colors.bg.input,
    border: `1px solid ${theme.colors.border.light}`,
    borderRadius: theme.borderRadius.md,
    color: theme.colors.text.primary,
    fontSize: theme.typography.fontSize.base,
    boxSizing: 'border-box' as const,
  }

  const labelStyle = {
    display: 'block',
    marginBottom: theme.spacing.sm,
    color: theme.colors.text.secondary,
    fontSize: theme.typography.fontSize.sm,
    fontWeight: 500,
  }

  const cardStyle = {
    background: theme.colors.bg.card,
    border: `1px solid ${theme.colors.border.light}`,
    borderRadius: theme.borderRadius.lg,
    padding: theme.spacing.xl,
    marginBottom: theme.spacing.xl,
  }

  if (loading) {
    return (
      <div data-testid="profile-page">
        <div style={{ textAlign: 'center', padding: theme.spacing.xl, color: theme.colors.text.secondary }}>
          {t('common.loading')}
        </div>
      </div>
    )
  }

  return (
    <div data-testid="profile-page" style={{ maxWidth: '600px' }}>
      <PageHeader title={t('profile.title')} />

      {/* Profile Section */}
      <div data-testid="profile-section" style={cardStyle}>
        <h2 style={{ margin: 0, marginBottom: theme.spacing.lg, fontSize: '18px' }}>{t('profile.personalInfo')}</h2>

        {error && (
          <div
            data-testid="profile-error"
            style={{
              padding: theme.spacing.md,
              background: theme.badges.danger.bg,
              border: `1px solid ${theme.badges.danger.border}`,
              borderRadius: theme.borderRadius.md,
              color: theme.colors.semantic.danger,
              marginBottom: theme.spacing.lg,
            }}
          >
            {error}
          </div>
        )}

        {success && (
          <div
            data-testid="profile-success"
            style={{
              padding: theme.spacing.md,
              background: theme.badges.success.bg,
              border: `1px solid ${theme.badges.success.border}`,
              borderRadius: theme.borderRadius.md,
              color: theme.colors.semantic.success,
              marginBottom: theme.spacing.lg,
            }}
          >
            {success}
          </div>
        )}

        <div style={{ marginBottom: theme.spacing.lg }}>
          <label style={labelStyle}>{t('auth.email')}</label>
          <input
            data-testid="profile-email"
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            style={inputStyle}
          />
        </div>

        <div style={{ marginBottom: theme.spacing.lg }}>
          <label style={labelStyle}>{t('members.displayName')}</label>
          <input
            data-testid="profile-display-name"
            type="text"
            value={displayName}
            onChange={(e) => setDisplayName(e.target.value)}
            style={inputStyle}
          />
        </div>

        <div style={{ marginBottom: theme.spacing.lg }}>
          <label style={labelStyle}>{t('profile.language')}</label>
          <LanguageSelector
            value={locale}
            onChange={handleLanguageChange}
            testId="profile-locale"
          />
        </div>

        <button
          data-testid="profile-save-button"
          onClick={handleSaveProfile}
          disabled={saving}
          style={{
            padding: `${theme.spacing.md} ${theme.spacing.xl}`,
            background: saving ? theme.colors.bg.tertiary : theme.colors.semantic.primary,
            color: 'white',
            border: 'none',
            borderRadius: theme.borderRadius.md,
            cursor: saving ? 'not-allowed' : 'pointer',
            fontWeight: 500,
          }}
        >
          {saving ? t('common.loading') : t('common.save')}
        </button>
      </div>

      {/* Password Section */}
      <div data-testid="password-section" style={cardStyle}>
        <h2 style={{ margin: 0, marginBottom: theme.spacing.lg, fontSize: '18px' }}>{t('profile.changePassword')}</h2>

        {passwordError && (
          <div
            data-testid="password-error"
            style={{
              padding: theme.spacing.md,
              background: theme.badges.danger.bg,
              border: `1px solid ${theme.badges.danger.border}`,
              borderRadius: theme.borderRadius.md,
              color: theme.colors.semantic.danger,
              marginBottom: theme.spacing.lg,
            }}
          >
            {passwordError}
          </div>
        )}

        {passwordSuccess && (
          <div
            data-testid="password-success"
            style={{
              padding: theme.spacing.md,
              background: theme.badges.success.bg,
              border: `1px solid ${theme.badges.success.border}`,
              borderRadius: theme.borderRadius.md,
              color: theme.colors.semantic.success,
              marginBottom: theme.spacing.lg,
            }}
          >
            {passwordSuccess}
          </div>
        )}

        <div style={{ marginBottom: theme.spacing.lg }}>
          <label style={labelStyle}>{t('profile.currentPassword')}</label>
          <input
            data-testid="password-current"
            type="password"
            value={currentPassword}
            onChange={(e) => setCurrentPassword(e.target.value)}
            style={inputStyle}
          />
        </div>

        <div style={{ marginBottom: theme.spacing.lg }}>
          <label style={labelStyle}>{t('profile.newPassword')}</label>
          <input
            data-testid="password-new"
            type="password"
            value={newPassword}
            onChange={(e) => setNewPassword(e.target.value)}
            style={inputStyle}
            placeholder={t('validation.minLength', { min: 8 })}
          />
        </div>

        <div style={{ marginBottom: theme.spacing.lg }}>
          <label style={labelStyle}>{t('profile.confirmPassword')}</label>
          <input
            data-testid="password-confirm"
            type="password"
            value={confirmPassword}
            onChange={(e) => setConfirmPassword(e.target.value)}
            style={inputStyle}
          />
        </div>

        {/* The second factor is asked for here rather than in a dialog: this
            form already collects the current password, so the step-up needs
            only the one missing field. */}
        {profile?.totp_enabled && (
          <div style={{ marginBottom: theme.spacing.lg }}>
            <label style={labelStyle}>{t('settings.stepUpTotpLabel')}</label>
            <input
              data-testid="password-totp-code"
              type="text"
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={6}
              value={passwordTotpCode}
              onChange={(e) => setPasswordTotpCode(e.target.value.replace(/\D/g, ''))}
              style={inputStyle}
              placeholder="123456"
            />
            <p
              style={{
                margin: `${theme.spacing.xs} 0 0 0`,
                fontSize: theme.typography.fontSize.sm,
                color: theme.colors.text.muted,
              }}
            >
              {t('settings.stepUpTotpHint')}
            </p>
          </div>
        )}

        <button
          data-testid="password-change-button"
          onClick={handleChangePassword}
          disabled={changingPassword}
          style={{
            padding: `${theme.spacing.md} ${theme.spacing.xl}`,
            background: changingPassword ? theme.colors.bg.tertiary : theme.colors.semantic.primary,
            color: 'white',
            border: 'none',
            borderRadius: theme.borderRadius.md,
            cursor: changingPassword ? 'not-allowed' : 'pointer',
            fontWeight: 500,
          }}
        >
          {changingPassword ? t('common.loading') : t('profile.changePassword')}
        </button>
      </div>

      {/* Last Login Info */}
      {profile?.last_login_at && (
        <div style={{ color: theme.colors.text.muted, fontSize: theme.typography.fontSize.sm }}>
          {t('profile.lastLogin')}: {new Date(profile.last_login_at).toLocaleString(intlLocale)}
        </div>
      )}

      <StepUpConfirmDialog
        isOpen={emailStepUpOpen}
        title={t('profile.emailChangeTitle')}
        message={t('profile.emailChangeMessage', { email })}
        confirmLabel={t('common.save')}
        requiresTotp={profile?.totp_enabled ?? false}
        error={emailStepUpError}
        confirmDisabled={saving}
        onConfirm={(credentials) => void saveProfile(credentials)}
        onCancel={() => setEmailStepUpOpen(false)}
      />
    </div>
  )
}
