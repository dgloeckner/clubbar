/**
 * SepaConfigTab Component
 * SEPA configuration form with warning, character counters, and validation indicators
 */

import { theme } from '../../styles/design-system'
import { Alert } from '../common/Alert'
import { CharacterCounter } from '../forms/CharacterCounter'
import { ValidationIndicator } from '../forms/ValidationIndicator'
import { useBreakpoint } from '../../hooks/useBreakpoint'
import { useTranslation } from 'react-i18next'
import type { SepaConfigFormData } from '../../utils/sepaConfig'

function FormField({
  label,
  fieldKey,
  value,
  type = 'text',
  placeholder = '',
  disabled = false,
  helperText,
  monospace = false,
  maxLength,
  showCharCounter = false,
  showValidation = false,
  fieldErrors,
  onFieldChange,
  validateIban,
}: {
  label: string
  fieldKey: keyof SepaConfigFormData
  value: string | null | undefined
  type?: string
  placeholder?: string
  disabled?: boolean
  helperText?: string
  monospace?: boolean
  maxLength?: number
  showCharCounter?: boolean
  showValidation?: boolean
  fieldErrors: Record<string, string>
  onFieldChange: (field: keyof SepaConfigFormData, value: string) => void
  validateIban: (iban: string) => boolean
}) {
  const normalizedValue = value ?? ''
  const hasError = !!fieldErrors[fieldKey]
  const errorMessage = fieldErrors[fieldKey]

  return (
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
          {label}
        </label>
        <div style={{ display: 'flex', gap: theme.spacing.md, alignItems: 'center' }}>
          {showCharCounter && maxLength && (
            <CharacterCounter
              currentLength={normalizedValue.length}
              maxLength={maxLength}
              testId={`settings-sepa-char-counter-${fieldKey}`}
            />
          )}
          {showValidation && fieldKey === 'creditor_iban' && (
            <ValidationIndicator
              isValid={validateIban(normalizedValue)}
              show={normalizedValue.length > 0}
              testId={`settings-sepa-validation-${fieldKey}`}
            />
          )}
        </div>
      </div>

      <input
        type={type}
        disabled={disabled}
        placeholder={placeholder}
        value={normalizedValue}
        onChange={(e) => onFieldChange(fieldKey, e.target.value)}
        data-testid={`settings-sepa-input-${fieldKey}`}
        style={{
          padding: `${theme.spacing.md} ${theme.spacing.md}`,
          border: `1px solid ${hasError ? theme.colors.semantic.danger : theme.colors.border.light}`,
          borderRadius: theme.borderRadius.md,
          fontSize: theme.typography.fontSize.sm,
          fontFamily: monospace ? 'monospace' : theme.typography.fontFamily.base,
          backgroundColor: disabled ? theme.colors.bg.tertiary : theme.colors.bg.primary,
          color: disabled ? theme.colors.text.secondary : theme.colors.text.primary,
          cursor: disabled ? 'not-allowed' : 'auto',
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

      {helperText && (
        <p
          data-testid={`settings-sepa-helper-${fieldKey}`}
          style={{
            margin: 0,
            fontSize: theme.typography.fontSize.xs,
            color: theme.colors.text.secondary,
          }}
        >
          {helperText}
        </p>
      )}

      {errorMessage && (
        <p
          style={{
            margin: 0,
            fontSize: theme.typography.fontSize.xs,
            color: theme.colors.semantic.danger,
          }}
        >
          {errorMessage}
        </p>
      )}
    </div>
  )
}

export interface SepaConfigTabProps {
  /** True once the creditor ID has been set — it is immutable from then on (ADR-0007). */
  creditorIdLocked: boolean
  /**
   * The stored creditor IBAN as the API returns it — masked (#392). Shown
   * beside the input, which stays blank because there is no full value to
   * prefill it with. Empty when nothing is configured yet.
   */
  storedCreditorIban?: string | null
  loading: boolean
  saving: boolean
  /**
   * Failures are reported by the page-level banner, which is shared with the
   * Admin Users and Terminals tabs — this tab renders only its own success
   * message (#91).
   */
  successMessage: string | null
  formData: SepaConfigFormData
  fieldErrors: Record<string, string>
  onFieldChange: (field: keyof SepaConfigFormData, value: string) => void
  onSave: () => void
  onCancel: () => void
  validateIban: (iban: string) => boolean
}

export function SepaConfigTab({
  creditorIdLocked,
  storedCreditorIban,
  loading,
  saving,
  successMessage,
  formData,
  fieldErrors,
  onFieldChange,
  onSave,
  onCancel,
  validateIban,
}: SepaConfigTabProps) {
  const { t } = useTranslation()
  const breakpoint = useBreakpoint()
  const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'

  if (loading) {
    return (
      <div style={{ textAlign: 'center', padding: theme.spacing.xl }}>
        {t('settings.loadingSepa')}
      </div>
    )
  }

  return (
    <div>
      {/* Success Message */}
      {successMessage && (
        <div
          data-testid="settings-sepa-success-message"
          style={{
            padding: theme.spacing.md,
            marginBottom: theme.spacing.lg,
            background: theme.badges.success.bg,
            border: `1px solid rgba(34, 197, 94, 0.5)`,
            borderRadius: theme.borderRadius.md,
            color: 'rgb(34, 197, 94)',
            fontSize: theme.typography.fontSize.sm,
          }}
        >
          {successMessage}
        </div>
      )}

      {/* Form */}
      <form
        data-testid="settings-sepa-form"
        onSubmit={(e) => {
          e.preventDefault()
          onSave()
        }}
        style={{
          display: 'flex',
          flexDirection: 'column',
        }}
      >
        {/* Warning Alert */}
        {creditorIdLocked && (
          <Alert
            variant="warning"
            icon="⚠️"
            title={t('settings.sepaWarningTitle')}
            message={t('settings.sepaWarningMessage')}
            testId="settings-sepa-alert-warning"
          />
        )}

        {/* Form fields grid - single column on mobile, 2 columns on desktop */}
        <div
          style={{
            display: 'grid',
            gridTemplateColumns: isMobile ? '1fr' : 'repeat(2, 1fr)',
            gap: theme.spacing.lg,
          }}
        >
          {/* Creditor ID Field */}
          <FormField
            label={t('settings.creditorId')}
            fieldKey="creditor_id"
            value={formData.creditor_id}
            placeholder={t('settings.sepaPlaceholders.creditorId')}
            disabled={creditorIdLocked}
            helperText={
              creditorIdLocked
                ? t('settings.sepaHelpers.creditorIdLocked')
                : t('settings.sepaHelpers.creditorId')
            }
            maxLength={35}
            showCharCounter={!creditorIdLocked}
            fieldErrors={fieldErrors}
            onFieldChange={onFieldChange}
            validateIban={validateIban}
          />

          {/* Creditor Name Field */}
          <FormField
            label={t('settings.creditorName')}
            fieldKey="creditor_name"
            value={formData.creditor_name}
            placeholder={t('settings.sepaPlaceholders.creditorName')}
            helperText={t('settings.sepaHelpers.creditorName')}
            maxLength={70}
            showCharCounter={true}
            fieldErrors={fieldErrors}
            onFieldChange={onFieldChange}
            validateIban={validateIban}
          />

          {/* Creditor IBAN Field */}
          <FormField
            label={t('settings.creditorIban')}
            fieldKey="creditor_iban"
            value={formData.creditor_iban}
            placeholder={t('settings.sepaPlaceholders.creditorIban')}
            monospace={true}
            helperText={
              storedCreditorIban
                ? t('settings.sepaHelpers.creditorIbanStored', { masked: storedCreditorIban })
                : t('settings.sepaHelpers.creditorIban')
            }
            showValidation={true}
            fieldErrors={fieldErrors}
            onFieldChange={onFieldChange}
            validateIban={validateIban}
          />

          {/* Street Address Field */}
          <FormField
            label={t('settings.creditorStreet')}
            fieldKey="creditor_address_street"
            value={formData.creditor_address_street}
            placeholder={t('settings.sepaPlaceholders.street')}
            helperText={t('settings.sepaHelpers.street')}
            maxLength={70}
            showCharCounter={true}
            fieldErrors={fieldErrors}
            onFieldChange={onFieldChange}
            validateIban={validateIban}
          />

          {/* City Field */}
          <FormField
            label={t('settings.creditorCity')}
            fieldKey="creditor_address_city"
            value={formData.creditor_address_city}
            placeholder={t('settings.sepaPlaceholders.city')}
            helperText={t('settings.sepaHelpers.city')}
            maxLength={70}
            showCharCounter={true}
            fieldErrors={fieldErrors}
            onFieldChange={onFieldChange}
            validateIban={validateIban}
          />

          {/* Country Code Field */}
          <FormField
            label={t('settings.creditorCountry')}
            fieldKey="creditor_address_country"
            value={formData.creditor_address_country}
            placeholder={t('settings.sepaPlaceholders.country')}
            helperText={t('settings.sepaHelpers.country')}
            fieldErrors={fieldErrors}
            onFieldChange={onFieldChange}
            validateIban={validateIban}
          />

          {/* Payment Reference Prefix Field - spans both columns */}
          <div style={{ gridColumn: '1 / -1' }}>
            <FormField
              label={t('settings.paymentReferencePrefix')}
              fieldKey="payment_reference_prefix"
              value={formData.payment_reference_prefix}
              placeholder={t('settings.sepaPlaceholders.paymentPrefix')}
              helperText={t('settings.sepaHelpers.paymentPrefix')}
              maxLength={100}
              showCharCounter={true}
              fieldErrors={fieldErrors}
              onFieldChange={onFieldChange}
              validateIban={validateIban}
            />
          </div>

          {/* Mandate Template URL Field - spans both columns. The blank
              mandate PDF moved out of the app (#360); this is where the club
              says where the externally hosted form now lives. Left blank,
              SepaExportService refuses to export (#456), and the dashboard
              warns before an admin gets that far. */}
          <div style={{ gridColumn: '1 / -1' }}>
            <FormField
              label={t('settings.mandateTemplateUrl')}
              fieldKey="mandate_template_url"
              value={formData.mandate_template_url}
              type="url"
              placeholder={t('settings.sepaPlaceholders.mandateTemplateUrl')}
              helperText={t('settings.sepaHelpers.mandateTemplateUrl')}
              maxLength={255}
              showCharCounter={true}
              fieldErrors={fieldErrors}
              onFieldChange={onFieldChange}
              validateIban={validateIban}
            />
          </div>
        </div>

        {/* Action Buttons */}
        <div
          style={{
            display: 'flex',
            gap: theme.spacing.md,
            marginTop: theme.spacing.xl,
            justifyContent: 'flex-start',
          }}
        >
          <button
            data-testid="settings-sepa-save-button"
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
            data-testid="settings-sepa-cancel-button"
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
