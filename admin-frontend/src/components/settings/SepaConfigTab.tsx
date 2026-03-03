/**
 * SepaConfigTab Component
 * SEPA configuration form with warning, character counters, and validation indicators
 */

import { theme } from '../../styles/design-system'
import { Alert } from '../common/Alert'
import { CharacterCounter } from '../forms/CharacterCounter'
import { ValidationIndicator } from '../forms/ValidationIndicator'
import { useBreakpoint } from '../../hooks/useBreakpoint'
import { UpdateSepaConfigRequest, SepaConfig } from '../../types'

export interface SepaConfigTabProps {
  config: SepaConfig | null
  loading: boolean
  saving: boolean
  error: string | null
  successMessage: string | null
  formData: UpdateSepaConfigRequest
  fieldErrors: Record<string, string>
  onFieldChange: (field: keyof UpdateSepaConfigRequest, value: string) => void
  onSave: () => void
  onCancel: () => void
  validateIban: (iban: string) => boolean
}

export function SepaConfigTab({
  config,
  loading,
  saving,
  error,
  successMessage,
  formData,
  fieldErrors,
  onFieldChange,
  onSave,
  onCancel,
  validateIban,
}: SepaConfigTabProps) {
  const breakpoint = useBreakpoint()
  const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'
  const isCreditorIdSet = !!config

  // Form field component
  const FormField = ({
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
  }: {
    label: string
    fieldKey: keyof UpdateSepaConfigRequest
    value: string | null | undefined
    type?: string
    placeholder?: string
    disabled?: boolean
    helperText?: string
    monospace?: boolean
    maxLength?: number
    showCharCounter?: boolean
    showValidation?: boolean
  }) => {
    // Normalize value to empty string if null or undefined
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
            e.currentTarget.style.boxShadow = `0 0 0 3px ${hasError ? 'rgba(239, 68, 68, 0.1)' : 'rgba(59, 130, 246, 0.1)'}`
          }}
          onBlur={(e) => {
            e.currentTarget.style.borderColor = hasError ? theme.colors.semantic.danger : theme.colors.border.light
            e.currentTarget.style.boxShadow = 'none'
          }}
        />

        {helperText && (
          <p
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

  if (loading) {
    return (
      <div style={{ textAlign: 'center', padding: theme.spacing.xl }}>
        Loading SEPA configuration...
      </div>
    )
  }

  return (
    <div>
      {/* Error Message */}
      {error && (
        <div
          data-testid="settings-sepa-error-message"
          style={{
            padding: theme.spacing.md,
            marginBottom: theme.spacing.lg,
            background: 'rgba(239, 68, 68, 0.1)',
            border: `1px solid ${theme.colors.semantic.danger}`,
            borderRadius: theme.borderRadius.md,
            color: theme.colors.semantic.danger,
            fontSize: theme.typography.fontSize.sm,
          }}
        >
          {error}
        </div>
      )}

      {/* Success Message */}
      {successMessage && (
        <div
          data-testid="settings-sepa-success-message"
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
        {isCreditorIdSet && (
          <Alert
            variant="warning"
            icon="⚠️"
            title="Warning"
            message="The creditor ID is a legal identifier that appears in SEPA exports and settlement records. Changing it may impact compliance and audit trails."
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
            label="Gläubiger ID (Creditor ID)"
            fieldKey="creditor_id"
            value={formData.creditor_id}
            placeholder="z.B. DE01ZZZ09999999999"
            helperText="SEPA Gläubiger-Identifikationsnummer (max 35 Zeichen)"
            maxLength={35}
            showCharCounter={true}
          />

          {/* Creditor Name Field */}
          <FormField
            label="Gläubiger Name (Creditor Name)"
            fieldKey="creditor_name"
            value={formData.creditor_name}
            placeholder="z.B. Ruderverein Musterstadt e.V."
            helperText="Name der Organisation (max 70 Zeichen)"
            maxLength={70}
            showCharCounter={true}
          />

          {/* Creditor IBAN Field */}
          <FormField
            label="Gläubiger IBAN (Creditor IBAN)"
            fieldKey="creditor_iban"
            value={formData.creditor_iban}
            placeholder="z.B. DE89370400440532013000"
            monospace={true}
            helperText="Bankkonto IBAN (15-34 Zeichen)"
            showValidation={true}
          />

          {/* Street Address Field */}
          <FormField
            label="Straße (Street Address)"
            fieldKey="creditor_address_street"
            value={formData.creditor_address_street}
            placeholder="z.B. Hauptstraße 123"
            helperText="Straße und Hausnummer (max 70 Zeichen)"
            maxLength={70}
            showCharCounter={true}
          />

          {/* City Field */}
          <FormField
            label="Stadt (City)"
            fieldKey="creditor_address_city"
            value={formData.creditor_address_city}
            placeholder="z.B. München"
            helperText="Stadtname (max 70 Zeichen)"
            maxLength={70}
            showCharCounter={true}
          />

          {/* Country Code Field */}
          <FormField
            label="Ländercode (Country Code)"
            fieldKey="creditor_address_country"
            value={formData.creditor_address_country}
            placeholder="z.B. DE"
            helperText="2-stelliger ISO-Ländercode (DE, AT, CH)"
          />

          {/* Payment Reference Prefix Field - spans both columns */}
          <div style={{ gridColumn: '1 / -1' }}>
            <FormField
              label="Verwendungszweck-Präfix (Payment Reference Prefix)"
              fieldKey="payment_reference_prefix"
              value={formData.payment_reference_prefix}
              placeholder="z.B. Club Bar Abrechnung"
              helperText="Präfix für den Verwendungszweck. Das Abrechnungsdatum wird automatisch angehängt."
              maxLength={100}
              showCharCounter={true}
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
            {saving ? 'Saving...' : 'Save'}
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
            Cancel
          </button>
        </div>
      </form>
    </div>
  )
}
