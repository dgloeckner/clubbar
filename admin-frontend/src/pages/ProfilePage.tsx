/**
 * Profile Page
 * Personal settings for the logged-in admin user
 * - View/edit profile information (email, display name, language)
 * - Change password
 */

import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../styles/design-system'
import { getAuthentication } from '../api/generated/authentication/authentication'
import { getProfile, updateProfileWithSession } from '../auth/session'
import type { AdminProfile } from '../api/generated'
import { LanguageSelector } from '../components/forms/LanguageSelector'
import { changeLanguage } from '../i18n/config'
import { useFormatters } from '../hooks/useFormatters'

export function ProfilePage() {
  const { t } = useTranslation()
  const { intlLocale } = useFormatters()
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
  const [passwordError, setPasswordError] = useState<string | null>(null)
  const [passwordSuccess, setPasswordSuccess] = useState<string | null>(null)
  const [changingPassword, setChangingPassword] = useState(false)

  useEffect(() => {
    loadProfile()
  }, [])

  const loadProfile = async () => {
    try {
      setLoading(true)
      const data = await getProfile()
      setProfile(data)
      setEmail(data.email ?? '')
      setDisplayName(data.display_name ?? '')
      setLocale((data.locale as 'de' | 'en') ?? 'de')
    } catch (err) {
      setError(t('errors.generic'))
    } finally {
      setLoading(false)
    }
  }

  const handleLanguageChange = async (newLocale: 'de' | 'en') => {
    changeLanguage(newLocale)  // Update i18n and localStorage immediately
    setLocale(newLocale)       // Update local state
    try {
      await updateProfileWithSession({ locale: newLocale })  // Persist to backend
    } catch {
      // locale preference save failed — non-critical
    }
  }

  const handleSaveProfile = async () => {
    try {
      setSaving(true)
      setError(null)
      setSuccess(null)

      const updated = await updateProfileWithSession({
        email,
        display_name: displayName,
        locale,
      })

      setProfile(updated)
      setSuccess(t('profile.profileUpdated'))

      // Clear success after 3 seconds
      setTimeout(() => setSuccess(null), 3000)
    } catch (err: unknown) {
      const message = err instanceof Error && 'response' in err
        ? (err as { response?: { data?: { message?: string } } }).response?.data?.message ?? t('profile.saveFailed')
        : t('profile.saveFailed')
      setError(message)
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
      setPasswordError(t('validation.minLength', { min: 8 }))
      return
    }
    if (newPassword !== confirmPassword) {
      setPasswordError(t('validation.passwordMismatch'))
      return
    }

    try {
      setChangingPassword(true)
      await getAuthentication().changePassword({
        current_password: currentPassword,
        new_password: newPassword,
        confirm_password: confirmPassword,
      })

      setPasswordSuccess(t('profile.passwordChanged'))
      setCurrentPassword('')
      setNewPassword('')
      setConfirmPassword('')

      // Clear success after 3 seconds
      setTimeout(() => setPasswordSuccess(null), 3000)
    } catch (err: unknown) {
      const message = err instanceof Error && 'response' in err
        ? (err as { response?: { data?: { message?: string } } }).response?.data?.message ?? t('profile.saveFailed')
        : t('profile.saveFailed')
      setPasswordError(message)
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
      <div data-testid="profile-page" style={{ padding: '20px' }}>
        <div style={{ textAlign: 'center', padding: theme.spacing.xl, color: theme.colors.text.secondary }}>
          {t('common.loading')}
        </div>
      </div>
    )
  }

  return (
    <div data-testid="profile-page" style={{ padding: '20px', maxWidth: '600px' }}>
      <h1 style={{ margin: 0, marginBottom: theme.spacing.xl }}>{t('profile.title')}</h1>

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
    </div>
  )
}
