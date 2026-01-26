/**
 * Login Form Component
 * Handles email/password authentication
 */

import React, { useState } from 'react'
import { theme } from '../../styles/design-system'
import { Button } from '../common/Button'
import { Input } from '../common/Input'
import { Card } from '../common/Card'

interface LoginFormProps {
  onSubmit: (email: string, password: string) => Promise<void>
  loading?: boolean
  error?: string
}

export function LoginForm({ onSubmit, loading = false, error }: LoginFormProps) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [validationErrors, setValidationErrors] = useState<Record<string, string>>({})

  const validateForm = (): boolean => {
    const errors: Record<string, string> = {}

    if (!email) {
      errors.email = 'Email is required'
    } else if (!email.includes('@')) {
      errors.email = 'Please enter a valid email'
    }

    if (!password) {
      errors.password = 'Password is required'
    } else if (password.length < 6) {
      errors.password = 'Password must be at least 6 characters'
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
              fontSize: '48px',
              marginBottom: theme.spacing.md,
            }}
          >
            🚣
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
            Ruderbar
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
          {error && (
            <div
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
            label="Email"
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
            label="Password"
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
            {loading ? 'Logging in...' : 'Login'}
          </Button>
        </form>

        <div
          style={{
            marginTop: theme.spacing['2xl'],
            paddingTop: theme.spacing.lg,
            borderTop: `1px solid ${theme.colors.border.light}`,
            fontSize: theme.typography.fontSize.xs,
            color: theme.colors.text.secondary,
            textAlign: 'center',
          }}
        >
          Demo credentials available on backend
        </div>
      </Card>
    </div>
  )
}
