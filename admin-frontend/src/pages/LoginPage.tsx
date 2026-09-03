/**
 * Login Page
 * Handles email/password login, MFA verification, and first-time TOTP enrollment.
 */

import { useState, useEffect, useRef, FormEvent } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { LoginForm } from '../components/forms/LoginForm'
import { AuthCard } from '../components/auth/AuthCard'
import { useAuth } from '../context/AuthContext'
import { landingPath } from '../utils/adminRoles'
import { Input } from '../components/common/Input'
import { Button } from '../components/common/Button'
import { SecretBox } from '../components/common/SecretBox'
import { theme } from '../styles/design-system'
import { useModalEscape } from '../hooks/useModalDialog'
import { otpFromInput, useOtpAutoSubmit } from '../hooks/useOtpAutoSubmit'

// ─── TOTP info modal ──────────────────────────────────────────────────────────

function TotpInfoModal({ onClose }: { onClose: () => void }) {
  const { t } = useTranslation()
  const closeRef = useRef<HTMLButtonElement>(null)

  useEffect(() => {
    closeRef.current?.focus()
  }, [])

  useModalEscape(true, onClose)

  const sections = [
    { title: t('auth.setupInfoWhatTitle'),   text: t('auth.setupInfoWhatText') },
    { title: t('auth.setupInfoQrTitle'),     text: t('auth.setupInfoQrText') },
    { title: t('auth.setupInfoFutureTitle'), text: t('auth.setupInfoFutureText') },
    { title: t('auth.setupInfoKeepTitle'),   text: t('auth.setupInfoKeepText') },
  ]

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="totp-info-title"
      style={{
        position: 'fixed',
        inset: 0,
        background: 'rgba(0,0,0,0.6)',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        zIndex: 2000,
        padding: theme.spacing.lg,
      }}
      onClick={onClose}
    >
      <div
        style={{
          background: theme.colors.bg.secondary,
          borderRadius: theme.borderRadius.lg,
          padding: theme.spacing.xl,
          maxWidth: '480px',
          width: '100%',
          boxShadow: theme.shadows.modalStrong,
          maxHeight: '90vh',
          overflowY: 'auto',
        }}
        onClick={(e) => e.stopPropagation()}
      >
        <h2
          id="totp-info-title"
          style={{
            margin: 0,
            marginBottom: theme.spacing.xl,
            fontSize: theme.typography.fontSize.lg,
            fontWeight: theme.typography.fontWeight.semibold,
            color: theme.colors.text.primary,
          }}
        >
          {t('auth.setupInfoModalTitle')}
        </h2>

        <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.lg }}>
          {sections.map((s) => (
            <div key={s.title}>
              <p
                style={{
                  margin: 0,
                  marginBottom: theme.spacing.xs,
                  fontSize: theme.typography.fontSize.sm,
                  fontWeight: theme.typography.fontWeight.semibold,
                  color: theme.colors.text.primary,
                }}
              >
                {s.title}
              </p>
              <p
                style={{
                  margin: 0,
                  fontSize: theme.typography.fontSize.sm,
                  color: theme.colors.text.secondary,
                  lineHeight: theme.typography.lineHeight.normal,
                }}
              >
                {s.text}
              </p>
            </div>
          ))}
        </div>

        <button
          ref={closeRef}
          type="button"
          onClick={onClose}
          style={{
            marginTop: theme.spacing.xl,
            width: '100%',
            padding: `${theme.spacing.md} ${theme.spacing.lg}`,
            background: theme.colors.semantic.primary,
            border: 'none',
            borderRadius: theme.borderRadius.md,
            color: 'white',
            cursor: 'pointer',
            fontSize: theme.typography.fontSize.sm,
            fontWeight: theme.typography.fontWeight.semibold,
          }}
        >
          {t('auth.setupInfoClose')}
        </button>
      </div>
    </div>
  )
}

function ErrorBanner({ message, testId }: { message: string; testId: string }) {
  return (
    <div
      data-testid={testId}
      style={{
        background: `${theme.colors.semantic.danger}20`,
        border: `1px solid ${theme.colors.semantic.danger}`,
        borderRadius: theme.borderRadius.md,
        padding: theme.spacing.md,
        fontSize: theme.typography.fontSize.sm,
        color: theme.colors.semantic.danger,
        marginBottom: theme.spacing.lg,
      }}
    >
      {message}
    </div>
  )
}

// ─── MFA verification step ────────────────────────────────────────────────────

function MfaStep() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { submitMfa, loading } = useAuth()
  const [code, setCode] = useState('')
  const [localError, setLocalError] = useState<string>()

  const submit = async () => {
    setLocalError(undefined)
    const result = await submitMfa(code)
    if (result.success) {
      navigate(landingPath(result.roles ?? []))
    } else {
      setLocalError(result.error || t('auth.mfaInvalidCode'))
    }
  }

  // The sixth digit is the whole message this screen carries, so it submits by
  // itself. The hook is also what the form goes through, so the button and
  // Enter can never spend a second attempt on a code already sent (#338).
  const attemptSubmit = useOtpAutoSubmit(code, () => void submit(), !loading)

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault()
    attemptSubmit()
  }

  return (
    <AuthCard title={t('auth.mfaTitle')} subtitle={t('auth.mfaInstruction')}>
      <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.lg }}>
        {localError && <ErrorBanner message={localError} testId="mfa-error" />}
        <Input
          data-testid="mfa-code-input"
          label={t('auth.mfaCode')}
          type="text"
          inputMode="numeric"
          // Lets a phone offer the code from the keyboard bar. An autofill
          // lands all six digits in one change, which auto-submit then finishes.
          autoComplete="one-time-code"
          value={code}
          onChange={(e) => setCode(otpFromInput(e.target.value))}
          placeholder="000000"
          disabled={loading}
          autoFocus
        />
        <Button
          type="submit"
          disabled={loading || code.length !== 6}
          loading={loading}
          style={{ width: '100%' }}
          data-testid="mfa-submit-button"
        >
          {loading ? t('auth.mfaSubmitting') : t('auth.mfaSubmit')}
        </Button>
      </form>
    </AuthCard>
  )
}

// ─── TOTP enrollment step ─────────────────────────────────────────────────────

function TotpSetupStep() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { setupTotp, confirmTotp, loading } = useAuth()
  const [qrCode, setQrCode] = useState<string>()
  const [secret, setSecret] = useState<string>()
  const [code, setCode] = useState('')
  const [localError, setLocalError] = useState<string>()
  const [fetchError, setFetchError] = useState<string>()
  const [showInfo, setShowInfo] = useState(false)
  // StrictMode (main.tsx) double-invokes effects in development. Without this
  // guard, this ran twice and POSTed /auth/2fa/setup twice — since the
  // backend rotates the secret per call, the QR code shown could be for a
  // secret that was no longer the one actually stored (#136).
  const hasFetchedRef = useRef(false)

  useEffect(() => {
    if (hasFetchedRef.current) return
    hasFetchedRef.current = true

    setupTotp()
      .then(({ qrCode, secret }) => {
        setQrCode(qrCode)
        setSecret(secret)
      })
      .catch(() => setFetchError(t('auth.setupFetchError')))
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const submit = async () => {
    setLocalError(undefined)
    const result = await confirmTotp(code)
    if (result.success) {
      navigate(landingPath(result.roles ?? []))
    } else {
      setLocalError(result.error || t('auth.mfaInvalidCode'))
    }
  }

  // Same as the MFA step, plus the QR code: there is nothing to confirm a code
  // against until the secret this screen is enrolling has actually arrived.
  const attemptSubmit = useOtpAutoSubmit(code, () => void submit(), !loading && !!qrCode)

  const handleSubmit = (e: FormEvent) => {
    e.preventDefault()
    attemptSubmit()
  }

  return (
    <>
      {showInfo && <TotpInfoModal onClose={() => setShowInfo(false)} />}
      <AuthCard title={t('auth.setupTitle')} subtitle={t('auth.setupInstruction')} onInfo={() => setShowInfo(true)}>
      {fetchError && <ErrorBanner message={fetchError} testId="totp-setup-fetch-error" />}

      {qrCode && (
        <div style={{ display: 'flex', justifyContent: 'center', marginBottom: theme.spacing.lg }}>
          <img
            src={qrCode}
            alt="TOTP QR Code"
            data-testid="totp-qr-code"
            style={{ width: '200px', height: '200px' }}
          />
        </div>
      )}

      {/*
        The key is the only way back into the account if the authenticator app
        is lost — there are no recovery codes, so an admin who misses it needs
        direct database recovery (#386). It is therefore presented as its own
        bordered block with a copy button, not as a caption under the QR code.
      */}
      {secret && (
        <section
          data-testid="totp-setup-backup-key"
          style={{
            border: `1px solid ${theme.colors.semantic.warning}`,
            borderRadius: theme.borderRadius.md,
            padding: theme.spacing.md,
            marginBottom: theme.spacing.lg,
          }}
        >
          <h2
            style={{
              margin: 0,
              marginBottom: theme.spacing.xs,
              fontSize: theme.typography.fontSize.base,
              fontWeight: theme.typography.fontWeight.semibold,
              color: theme.colors.semantic.warning,
            }}
          >
            {t('auth.setupBackupKeyTitle')}
          </h2>
          <p
            style={{
              margin: 0,
              marginBottom: theme.spacing.md,
              fontSize: theme.typography.fontSize.sm,
              color: theme.colors.semantic.warningLight,
            }}
          >
            {t('auth.setupBackupKeyHint')}
          </p>
          {/*
            valueTestId keeps the historical `totp-setup-secret` ID on the value
            element alone, so the page object's getTotpSetupSecret() still reads
            back the bare secret and not the surrounding label.
          */}
          <SecretBox
            secret={secret}
            testIdPrefix="totp-setup-backup-key"
            valueTestId="totp-setup-secret"
            variant="secondary"
          />
        </section>
      )}

      <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.lg }}>
        {localError && <ErrorBanner message={localError} testId="totp-setup-error" />}
        <Input
          data-testid="setup-code-input"
          label={t('auth.setupCodeLabel')}
          type="text"
          inputMode="numeric"
          // Lets a phone offer the code from the keyboard bar. An autofill
          // lands all six digits in one change, which auto-submit then finishes.
          autoComplete="one-time-code"
          value={code}
          onChange={(e) => setCode(otpFromInput(e.target.value))}
          placeholder="000000"
          disabled={loading || !qrCode}
          autoFocus={!!qrCode}
        />
        <Button
          type="submit"
          disabled={loading || code.length !== 6 || !qrCode}
          loading={loading}
          style={{ width: '100%' }}
          data-testid="setup-confirm-button"
        >
          {loading ? t('auth.setupConfirming') : t('auth.setupConfirm')}
        </Button>
      </form>
      </AuthCard>
    </>
  )
}

// ─── Login Page ───────────────────────────────────────────────────────────────

export function LoginPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const { t } = useTranslation()
  // Set by the accept-invitation page when it sends a new admin here
  // (migration 058). `replace: true` there means a reload drops it, which is
  // right: the notice is about what just happened, not about this page.
  const arrivedFromInvitation = (location.state ?? {}) as { email?: string; invitationAccepted?: boolean }
  const { login, requiresMfa, requiresTotpSetup, loading, error } = useAuth()
  const [localError, setLocalError] = useState<string>()

  if (requiresMfa) return <MfaStep />
  if (requiresTotpSetup) return <TotpSetupStep />

  const handleSubmit = async (email: string, password: string) => {
    setLocalError(undefined)
    const result = await login({ email, password })
    if (result.success) {
      // Per role (ADR-0044, #516): a Getränkewart's dashboard is a 403, so
      // "logged in" has to mean a page they can actually work on. The roles
      // come off the result rather than the context — see AuthResult.
      navigate(landingPath(result.roles ?? []))
    } else if (!requiresMfa && !requiresTotpSetup) {
      setLocalError(result.error || t('auth.loginFailed'))
    }
  }

  // `error` covers the case where the MFA step ended on its own — the attempt cap
  // or the rate limiter sent us back here, and the reason must not vanish with it.
  return (
    <LoginForm
      onSubmit={handleSubmit}
      loading={loading}
      error={localError ?? error}
      initialEmail={arrivedFromInvitation.email}
      notice={arrivedFromInvitation.invitationAccepted ? t('invite.done') : undefined}
    />
  )
}
