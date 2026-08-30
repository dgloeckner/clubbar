/**
 * Login Form Component
 * Handles email/password authentication
 */

import React, { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { Button } from '../common/Button'
import { Input } from '../common/Input'
import { Card } from '../common/Card'
import { useInstanceConfig } from '../../context/InstanceConfigContext'

interface LoginFormProps {
  onSubmit: (email: string, password: string) => Promise<void>
  loading?: boolean
  error?: string
  /**
   * Pre-fills the address field — used when an invitee has just set their
   * password (migration 058) and is being sent here to sign in. They have only
   * ever seen the address in an email, and it is the account's identifier
   * rather than something they chose, so asking them to retype it is asking
   * them to guess.
   */
  initialEmail?: string
  /** A one-line success message shown above the form, e.g. after accepting an invitation. */
  notice?: string
}

export function LoginForm({ onSubmit, loading = false, error, initialEmail = '', notice }: LoginFormProps) {
  const { t } = useTranslation()
  const { instanceName } = useInstanceConfig()
  const [email, setEmail] = useState(initialEmail)
  const [password, setPassword] = useState('')
  const [validationErrors, setValidationErrors] = useState<Record<string, string>>({})

  const validateForm = (): boolean => {
    const errors: Record<string, string> = {}

    if (!email) {
      errors.email = t('validation.required')
    } else if (!email.includes('@')) {
      errors.email = t('validation.invalidEmail')
    }

    if (!password) {
      errors.password = t('validation.required')
    } else if (password.length < 6) {
      errors.password = t('validation.minLength', { min: 6 })
    }

    setValidationErrors(errors)
    return Object.keys(errors).length === 0
  }

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (validateForm()) {
      await onSubmit(email, password)
    }
  }

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
          <div
            style={{
              display: 'flex',
              justifyContent: 'center',
              marginBottom: theme.spacing.md,
            }}
          >
            <img
              src="/logo.svg"
              alt="Club Bar Logo"
              style={{
                width: '120px',
                height: '120px',
              }}
            />
          </div>
          <h1
            data-testid="login-brand-name"
            style={{
              fontSize: theme.typography.fontSize['2xl'],
              fontWeight: theme.typography.fontWeight.bold,
              margin: 0,
              marginBottom: theme.spacing.sm,
              color: theme.colors.text.primary,
            }}
          >
            {instanceName}
          </h1>
          <p
            style={{
              fontSize: theme.typography.fontSize.sm,
              color: theme.colors.text.secondary,
              margin: 0,
            }}
          >
            Admin Panel Login
          </p>
        </div>

        <form onSubmit={handleSubmit} style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.lg }}>
          {notice && (
            <div
              data-testid="login-notice"
              style={{
                background: `${theme.colors.semantic.success}20`,
                border: `1px solid ${theme.colors.semantic.success}`,
                borderRadius: theme.borderRadius.md,
                padding: theme.spacing.md,
                fontSize: theme.typography.fontSize.sm,
                color: theme.colors.semantic.success,
              }}
            >
              {notice}
            </div>
          )}

          {error && (
            <div
              data-testid="login-error"
              style={{
                background: `${theme.colors.semantic.danger}20`,
                border: `1px solid ${theme.colors.semantic.danger}`,
                borderRadius: theme.borderRadius.md,
                padding: theme.spacing.md,
                fontSize: theme.typography.fontSize.sm,
                color: theme.colors.semantic.danger,
              }}
            >
              {error}
            </div>
          )}

          <Input
            data-testid="login-email-input"
            label={t('auth.email')}
            type="email"
            value={email}
            onChange={(e) => {
              setEmail(e.target.value)
              if (validationErrors.email) {
                setValidationErrors({ ...validationErrors, email: '' })
              }
            }}
            error={validationErrors.email}
            placeholder="admin@example.com"
            disabled={loading}
          />

          <Input
            data-testid="login-password-input"
            label={t('auth.password')}
            type="password"
            value={password}
            onChange={(e) => {
              setPassword(e.target.value)
              if (validationErrors.password) {
                setValidationErrors({ ...validationErrors, password: '' })
              }
            }}
            error={validationErrors.password}
            placeholder="••••••"
            disabled={loading}
          />

          <Button
            type="submit"
            disabled={loading}
            loading={loading}
            style={{ width: '100%' }}
            data-testid="login-submit-button"
          >
            {loading ? t('auth.loggingIn') : t('auth.login')}
          </Button>
        </form>

      </Card>
    </div>
  )
}
