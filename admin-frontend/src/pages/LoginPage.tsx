/**
 * Login Page
 * Handles email/password login, MFA verification, and first-time TOTP enrollment.
 */

import { useState, useEffect, FormEvent, ReactNode } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { LoginForm } from '../components/forms/LoginForm'
import { useAuth } from '../context/AuthContext'
import { Card } from '../components/common/Card'
import { Input } from '../components/common/Input'
import { Button } from '../components/common/Button'
import { theme } from '../styles/design-system'

// ─── Shared card wrapper (logo + title) ──────────────────────────────────────

function AuthCard({ title, subtitle, children }: { title: string; subtitle: string; children: ReactNode }) {
  return (
    <div
      style={{
        display: 'flex',
        justifyContent: 'center',
        alignItems: 'center',
        minHeight: '100vh',
        background: theme.colors.bg.primary,
        padding: theme.spacing.lg,
      }}
    >
      <Card style={{ width: '100%', maxWidth: '400px' }}>
        <div style={{ textAlign: 'center', marginBottom: theme.spacing['2xl'] }}>
          <div style={{ display: 'flex', justifyContent: 'center', marginBottom: theme.spacing.md }}>
            <img src="/logo.svg" alt="Club Bar Logo" style={{ width: '120px', height: '120px' }} />
          </div>
          <h1
            style={{
              fontSize: theme.typography.fontSize['2xl'],
              fontWeight: theme.typography.fontWeight.bold,
              margin: 0,
              marginBottom: theme.spacing.sm,
              color: theme.colors.text.primary,
            }}
          >
            {title}
          </h1>
          <p style={{ fontSize: theme.typography.fontSize.sm, color: theme.colors.text.secondary, margin: 0 }}>
            {subtitle}
          </p>
        </div>
        {children}
      </Card>
    </div>
  )
}

function ErrorBanner({ message }: { message: string }) {
  return (
    <div
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
  const { submitMfa, loading, error } = useAuth()
  const [code, setCode] = useState('')
  const [localError, setLocalError] = useState<string>()

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setLocalError(undefined)
    const success = await submitMfa(code)
    if (success) {
      navigate('/dashboard')
    } else {
      setLocalError(error || t('auth.mfaInvalidCode'))
    }
  }

  return (
    <AuthCard title={t('auth.mfaTitle')} subtitle={t('auth.mfaInstruction')}>
      <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.lg }}>
        {localError && <ErrorBanner message={localError} />}
        <Input
          data-testid="mfa-code-input"
          label={t('auth.mfaCode')}
          type="text"
          inputMode="numeric"
          value={code}
          onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
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
  const { setupTotp, confirmTotp, loading, error } = useAuth()
  const [qrCode, setQrCode] = useState<string>()
  const [secret, setSecret] = useState<string>()
  const [code, setCode] = useState('')
  const [localError, setLocalError] = useState<string>()
  const [fetchError, setFetchError] = useState<string>()

  useEffect(() => {
    setupTotp()
      .then(({ qrCode, secret }) => {
        setQrCode(qrCode)
        setSecret(secret)
      })
      .catch(() => setFetchError(t('auth.setupFetchError')))
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault()
    setLocalError(undefined)
    const success = await confirmTotp(code)
    if (success) {
      navigate('/dashboard')
    } else {
      setLocalError(error || t('auth.mfaInvalidCode'))
    }
  }

  return (
    <AuthCard title={t('auth.setupTitle')} subtitle={t('auth.setupInstruction')}>
      {fetchError && <ErrorBanner message={fetchError} />}

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

      {secret && (
        <p
          style={{
            textAlign: 'center',
            fontSize: theme.typography.fontSize.xs,
            color: theme.colors.text.secondary,
            wordBreak: 'break-all',
            marginBottom: theme.spacing.lg,
          }}
        >
          {t('auth.setupManualKey')}: <strong>{secret}</strong>
        </p>
      )}

      <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.lg }}>
        {localError && <ErrorBanner message={localError} />}
        <Input
          data-testid="setup-code-input"
          label={t('auth.setupCodeLabel')}
          type="text"
          inputMode="numeric"
          value={code}
          onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
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
  )
}

// ─── Login Page ───────────────────────────────────────────────────────────────

export function LoginPage() {
  const navigate = useNavigate()
  const { t } = useTranslation()
  const { login, requiresMfa, requiresTotpSetup, loading, error } = useAuth()
  const [localError, setLocalError] = useState<string>()

  if (requiresMfa) return <MfaStep />
  if (requiresTotpSetup) return <TotpSetupStep />

  const handleSubmit = async (email: string, password: string) => {
    setLocalError(undefined)
    const success = await login({ email, password })
    if (success) {
      navigate('/dashboard')
    } else if (!requiresMfa && !requiresTotpSetup) {
      setLocalError(error || t('auth.loginFailed'))
    }
  }

  return <LoginForm onSubmit={handleSubmit} loading={loading} error={localError} />
}
