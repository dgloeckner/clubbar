/**
 * InstanceBrandingTab Component
 * Single-field form for the deploying club's instance name (ADR-0034, UC-A64)
 */

import { theme } from '../../styles/design-system'
import { CharacterCounter } from '../forms/CharacterCounter'
import { useTranslation } from 'react-i18next'

export interface InstanceBrandingTabProps {
  loading: boolean
  saving: boolean
  /**
   * Failures are reported by the page-level banner, shared with every other
   * Settings tab — this tab renders only its own success message, matching
   * the SEPA tab's split (#91).
   */
  successMessage: string | null
  value: string
  fieldErrors: Record<string, string>
  onChange: (value: string) => void
  onSave: () => void
  onCancel: () => void
}

export function InstanceBrandingTab({
  loading,
  saving,
  successMessage,
  value,
  fieldErrors,
  onChange,
  onSave,
  onCancel,
}: InstanceBrandingTabProps) {
  const { t } = useTranslation()
  const hasError = !!fieldErrors.instance_name

  if (loading) {
    return (
      <div style={{ textAlign: 'center', padding: theme.spacing.xl }}>
        {t('settings.instance.loading')}
      </div>
    )
  }

  return (
    <div>
      {/* Success Message */}
      {successMessage && (
        <div
          data-testid="settings-instance-success-message"
          style={{
            padding: theme.spacing.md,
            marginBottom: theme.spacing.lg,
            background: 'rgba(34, 197, 94, 0.1)',
            border: `1px solid rgba(34, 197, 94, 0.5)`,
            borderRadius: theme.borderRadius.md,
            color: 'rgb(34, 197, 94)',
            fontSize: theme.typography.fontSize.sm,
          }}
        >
          {successMessage}
        </div>
      )}

      <form
        data-testid="settings-instance-form"
        onSubmit={(e) => {
          e.preventDefault()
          onSave()
        }}
        style={{
          display: 'flex',
          flexDirection: 'column',
          maxWidth: '480px',
        }}
      >
        <div
          style={{
            marginBottom: theme.spacing.lg,
            display: 'flex',
            flexDirection: 'column',
            gap: theme.spacing.sm,
          }}
        >
          <div
            style={{
              display: 'flex',
              justifyContent: 'space-between',
              alignItems: 'center',
              gap: theme.spacing.md,
            }}
          >
            <label
              style={{
                fontSize: theme.typography.fontSize.sm,
                fontWeight: theme.typography.fontWeight.medium,
                color: theme.colors.text.primary,
              }}
            >
              {t('settings.instance.label')}
            </label>
            <CharacterCounter
              currentLength={value.length}
              maxLength={100}
              testId="settings-instance-char-counter-name"
            />
          </div>

          <input
            type="text"
            value={value}
            maxLength={100}
            placeholder={t('settings.instance.placeholder')}
            onChange={(e) => onChange(e.target.value)}
            data-testid="settings-instance-input-name"
            style={{
              padding: `${theme.spacing.md} ${theme.spacing.md}`,
              border: `1px solid ${hasError ? theme.colors.semantic.danger : theme.colors.border.light}`,
              borderRadius: theme.borderRadius.md,
              fontSize: theme.typography.fontSize.sm,
              fontFamily: theme.typography.fontFamily.base,
              backgroundColor: theme.colors.bg.primary,
              color: theme.colors.text.primary,
              transition: `all ${theme.transitions.default}`,
            }}
            onFocus={(e) => {
              e.currentTarget.style.borderColor = hasError ? theme.colors.semantic.danger : theme.colors.semantic.primary
              e.currentTarget.style.boxShadow = `0 0 0 3px ${hasError ? theme.badges.danger.bg : theme.badges.info.bg}`
            }}
            onBlur={(e) => {
              e.currentTarget.style.borderColor = hasError ? theme.colors.semantic.danger : theme.colors.border.light
              e.currentTarget.style.boxShadow = 'none'
            }}
          />

          <p
            style={{
              margin: 0,
              fontSize: theme.typography.fontSize.xs,
              color: theme.colors.text.secondary,
            }}
          >
            {t('settings.instance.helper')}
          </p>

          {fieldErrors.instance_name && (
            <p
              data-testid="settings-instance-error-name"
              style={{
                margin: 0,
                fontSize: theme.typography.fontSize.xs,
                color: theme.colors.semantic.danger,
              }}
            >
              {fieldErrors.instance_name}
            </p>
          )}
        </div>

        <div
          style={{
            display: 'flex',
            gap: theme.spacing.md,
            marginTop: theme.spacing.md,
            justifyContent: 'flex-start',
          }}
        >
          <button
            data-testid="settings-instance-save-button"
            onClick={onSave}
            disabled={saving}
            style={{
              padding: `${theme.spacing.md} ${theme.spacing.lg}`,
              background: theme.colors.semantic.primary,
              color: 'white',
              border: 'none',
              borderRadius: theme.borderRadius.md,
              fontSize: theme.typography.fontSize.sm,
              fontWeight: theme.typography.fontWeight.semibold,
              cursor: saving ? 'not-allowed' : 'pointer',
              opacity: saving ? 0.6 : 1,
              transition: `all ${theme.transitions.default}`,
            }}
            onMouseEnter={(e) => {
              if (!saving) {
                e.currentTarget.style.background = 'rgb(37, 99, 235)'
              }
            }}
            onMouseLeave={(e) => {
              e.currentTarget.style.background = theme.colors.semantic.primary
            }}
          >
            {saving ? t('settings.saving') : t('common.save')}
          </button>

          <button
            data-testid="settings-instance-cancel-button"
            onClick={onCancel}
            disabled={saving}
            style={{
              padding: `${theme.spacing.md} ${theme.spacing.lg}`,
              background: 'transparent',
              color: theme.colors.text.secondary,
              border: `1px solid ${theme.colors.border.light}`,
              borderRadius: theme.borderRadius.md,
              fontSize: theme.typography.fontSize.sm,
              fontWeight: theme.typography.fontWeight.semibold,
              cursor: saving ? 'not-allowed' : 'pointer',
              opacity: saving ? 0.6 : 1,
              transition: `all ${theme.transitions.default}`,
            }}
            onMouseEnter={(e) => {
              if (!saving) {
                e.currentTarget.style.backgroundColor = theme.colors.bg.tertiary
              }
            }}
            onMouseLeave={(e) => {
              e.currentTarget.style.backgroundColor = 'transparent'
            }}
          >
            {t('common.cancel')}
          </button>
        </div>
      </form>
    </div>
  )
}
