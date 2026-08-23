/**
 * CreditLimitsTab Component
 *
 * The club's credit ceiling and warning band (ADR-0047, UC-A65).
 *
 * The Kassenwart's own slice of Settings, and the reason `/settings` opened to
 * the treasury at all: setting what members may run up on their Deckel is
 * their job. Both verbs behind this tab are TREASURY on the server, so nothing
 * here answers 403 for the role that can see it.
 *
 * Two fields the treasurer sets — the ceiling in euros, the band as a
 * percentage — and one they only read: where the warning actually starts. That
 * third figure is derived from the other two by integer division, exactly as
 * the terminal derives it, so the page can state the number rather than making
 * the treasurer do the arithmetic to find out what they just configured.
 */

import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'

export interface CreditLimitsTabProps {
  loading: boolean
  saving: boolean
  /**
   * Failures are reported by the page-level banner shared with every other
   * Settings tab; this tab renders only its own success message, matching the
   * instance and SEPA tabs (#91).
   */
  successMessage: string | null
  /** The club ceiling, as the euro string the input holds. */
  limitEuros: string
  /** The band, as the percentage string the input holds. */
  warnPercent: string
  fieldErrors: Record<string, string>
  onLimitChange: (value: string) => void
  onWarnPercentChange: (value: string) => void
  onSave: () => void
  onCancel: () => void
}

/**
 * Where the warning starts, in cents — `intdiv(limit * percent, 100)`.
 *
 * Integer division, matching `CreditLimit::warnAtCents()` on the server and
 * `CreditLimitCheck` on the terminal. A boundary cent the three sides round
 * differently is a member one of them warns and another does not, so this
 * rounds the same way rather than the way JavaScript would prefer.
 */
export function warnAtCents(limitCents: number, warnPercent: number): number {
  if (limitCents <= 0) return 0

  return Math.floor((limitCents * warnPercent) / 100)
}

/** A euro string as whole cents, or NaN when the field is not a number yet. */
export function eurosToCents(value: string): number {
  const normalised = value.trim().replace(',', '.')
  if (normalised === '') return NaN

  return Math.round(Number(normalised) * 100)
}

export function centsToEuros(cents: number): string {
  return (cents / 100).toFixed(2)
}

export function CreditLimitsTab({
  loading,
  saving,
  successMessage,
  limitEuros,
  warnPercent,
  fieldErrors,
  onLimitChange,
  onWarnPercentChange,
  onSave,
  onCancel,
}: CreditLimitsTabProps) {
  const { t, i18n } = useTranslation()

  if (loading) {
    return (
      <div data-testid="settings-limits-loading" style={{ textAlign: 'center', padding: theme.spacing.xl }}>
        {t('settings.limits.loading')}
      </div>
    )
  }

  const limitCents = eurosToCents(limitEuros)
  const percent = Number(warnPercent)
  const derivable = Number.isFinite(limitCents) && Number.isFinite(percent) && percent > 0
  const unlimited = derivable && limitCents <= 0

  const inputStyle = (hasError: boolean) => ({
    padding: `${theme.spacing.md} ${theme.spacing.md}`,
    border: `1px solid ${hasError ? theme.colors.semantic.danger : theme.colors.border.light}`,
    borderRadius: theme.borderRadius.md,
    fontSize: theme.typography.fontSize.sm,
    fontFamily: theme.typography.fontFamily.base,
    backgroundColor: theme.colors.bg.primary,
    color: theme.colors.text.primary,
    transition: `all ${theme.transitions.default}`,
  })

  const labelStyle = {
    fontSize: theme.typography.fontSize.sm,
    fontWeight: theme.typography.fontWeight.medium,
    color: theme.colors.text.primary,
  }

  const helperStyle = {
    margin: 0,
    fontSize: theme.typography.fontSize.xs,
    color: theme.colors.text.secondary,
  }

  return (
    <div>
      {successMessage && (
        <div
          data-testid="settings-limits-success-message"
          style={{
            padding: theme.spacing.md,
            marginBottom: theme.spacing.lg,
            background: 'rgba(34, 197, 94, 0.1)',
            border: '1px solid rgba(34, 197, 94, 0.5)',
            borderRadius: theme.borderRadius.md,
            color: 'rgb(34, 197, 94)',
            fontSize: theme.typography.fontSize.sm,
          }}
        >
          {successMessage}
        </div>
      )}

      <form
        data-testid="settings-limits-form"
        onSubmit={(e) => {
          e.preventDefault()
          onSave()
        }}
        style={{ display: 'flex', flexDirection: 'column', maxWidth: '480px' }}
      >
        <div
          style={{
            marginBottom: theme.spacing.lg,
            display: 'flex',
            flexDirection: 'column',
            gap: theme.spacing.sm,
          }}
        >
          <label style={labelStyle} htmlFor="settings-limits-input-default">
            {t('settings.limits.label')}
          </label>
          <input
            id="settings-limits-input-default"
            type="number"
            inputMode="decimal"
            min={0}
            step="0.01"
            value={limitEuros}
            onChange={(e) => onLimitChange(e.target.value)}
            data-testid="settings-limits-input-default"
            style={inputStyle(!!fieldErrors.default_limit_cents)}
          />
          <p style={helperStyle} data-testid="settings-limits-helper-default">
            {t('settings.limits.helper')}
          </p>
          {fieldErrors.default_limit_cents && (
            <p
              data-testid="settings-limits-error-default"
              style={{ ...helperStyle, color: theme.colors.semantic.danger }}
            >
              {fieldErrors.default_limit_cents}
            </p>
          )}
        </div>

        <div
          style={{
            marginBottom: theme.spacing.lg,
            display: 'flex',
            flexDirection: 'column',
            gap: theme.spacing.sm,
          }}
        >
          <label style={labelStyle} htmlFor="settings-limits-input-warn">
            {t('settings.limits.warnLabel')}
          </label>
          <input
            id="settings-limits-input-warn"
            type="number"
            inputMode="numeric"
            min={1}
            max={100}
            step="1"
            value={warnPercent}
            onChange={(e) => onWarnPercentChange(e.target.value)}
            data-testid="settings-limits-input-warn"
            style={inputStyle(!!fieldErrors.warn_threshold_percent)}
          />
          <p style={helperStyle}>{t('settings.limits.warnHelper')}</p>
          {fieldErrors.warn_threshold_percent && (
            <p
              data-testid="settings-limits-error-warn"
              style={{ ...helperStyle, color: theme.colors.semantic.danger }}
            >
              {fieldErrors.warn_threshold_percent}
            </p>
          )}
        </div>

        {/* Read-only, and the point of the tab: the treasurer sees the line
            they just drew without computing it themselves. */}
        <div
          style={{
            marginBottom: theme.spacing.lg,
            padding: theme.spacing.md,
            background: theme.colors.bg.tertiary,
            borderRadius: theme.borderRadius.md,
            display: 'flex',
            flexDirection: 'column',
            gap: theme.spacing.xs,
          }}
        >
          <span style={labelStyle}>{t('settings.limits.warnAtLabel')}</span>
          <span
            data-testid="settings-limits-warn-at"
            style={{
              fontSize: theme.typography.fontSize.lg,
              fontWeight: theme.typography.fontWeight.semibold,
              color: theme.colors.text.primary,
            }}
          >
            {!derivable
              ? '—'
              : unlimited
                ? t('settings.limits.unlimited')
                : new Intl.NumberFormat(i18n.language, {
                    style: 'currency',
                    currency: 'EUR',
                  }).format(warnAtCents(limitCents, percent) / 100)}
          </span>
          <p style={helperStyle}>{t('settings.limits.warnAtHelper')}</p>
        </div>

        <div style={{ display: 'flex', gap: theme.spacing.md, marginTop: theme.spacing.md }}>
          <button
            type="submit"
            data-testid="settings-limits-save-button"
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
            }}
          >
            {saving ? t('settings.saving') : t('common.save')}
          </button>

          <button
            type="button"
            data-testid="settings-limits-cancel-button"
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
            }}
          >
            {t('common.cancel')}
          </button>
        </div>
      </form>
    </div>
  )
}
