/**
 * Settings Page
 * Configuration management for system settings (SEPA configuration, etc.)
 */

import { useEffect, useState } from 'react'
import { theme } from '../styles/design-system'
import { useLoading } from '../context/LoadingContext'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { getSepaConfig, updateSepaConfig } from '../services/sepa-config'
import { SepaConfig, UpdateSepaConfigRequest } from '../types'
import { AxiosError } from 'axios'

export function SettingsPage() {
  const breakpoint = useBreakpoint()
  const { setIsLoading } = useLoading()
  const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'

  // State management
  const [activeTab, setActiveTab] = useState<'sepa'>('sepa')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [successMessage, setSuccessMessage] = useState<string | null>(null)
  const [existingConfig, setExistingConfig] = useState<SepaConfig | null>(null)
  const [originalFormData, setOriginalFormData] = useState<UpdateSepaConfigRequest>({
    creditor_id: '',
    creditor_name: '',
    creditor_iban: '',
    creditor_address_street: '',
    creditor_address_city: '',
    creditor_address_country: '',
  })
  const [formData, setFormData] = useState<UpdateSepaConfigRequest>({
    creditor_id: '',
    creditor_name: '',
    creditor_iban: '',
    creditor_address_street: '',
    creditor_address_city: '',
    creditor_address_country: '',
  })
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  // Load SEPA config on mount
  useEffect(() => {
    const loadConfig = async () => {
      try {
        setLoading(true)
        setIsLoading(true)
        const config = await getSepaConfig()

        if (config) {
          setExistingConfig(config)
          // Pre-fill form with masked values
          const formValues: UpdateSepaConfigRequest = {
            creditor_id: config.creditor_id, // masked: ****3000
            creditor_name: config.creditor_name,
            creditor_iban: config.creditor_iban, // masked: ****3000
            creditor_address_street: config.creditor_address_street,
            creditor_address_city: config.creditor_address_city,
            creditor_address_country: config.creditor_address_country,
          }
          setFormData(formValues)
          setOriginalFormData(formValues)
        }

        setError(null)
      } catch (err) {
        console.error('Failed to load SEPA config:', err)
        setError('Failed to load settings')
      } finally {
        setLoading(false)
        setIsLoading(false)
      }
    }

    loadConfig()
  }, [setIsLoading])

  // Validate IBAN format (basic client-side validation)
  const validateIban = (iban: string): boolean => {
    if (!iban) return false
    // Basic format: 15-34 chars, starts with 2-letter country code, alphanumeric
    return /^[A-Z]{2}[0-9A-Z]{13,32}$/.test(iban.toUpperCase())
  }

  // Validate form
  const validateForm = (): boolean => {
    const newErrors: Record<string, string> = {}

    if (!formData.creditor_name?.trim()) {
      newErrors.creditor_name = 'Creditor name is required'
    } else if (formData.creditor_name.length > 70) {
      newErrors.creditor_name = 'Creditor name must be 70 characters or less'
    }

    if (!formData.creditor_iban?.trim()) {
      newErrors.creditor_iban = 'Creditor IBAN is required'
    } else if (!validateIban(formData.creditor_iban)) {
      newErrors.creditor_iban = 'Invalid IBAN format (must be 15-34 alphanumeric characters)'
    }

    if (!formData.creditor_address_street?.trim()) {
      newErrors.creditor_address_street = 'Street address is required'
    } else if (formData.creditor_address_street.length > 70) {
      newErrors.creditor_address_street = 'Street address must be 70 characters or less'
    }

    if (!formData.creditor_address_city?.trim()) {
      newErrors.creditor_address_city = 'City is required'
    } else if (formData.creditor_address_city.length > 70) {
      newErrors.creditor_address_city = 'City must be 70 characters or less'
    }

    if (!formData.creditor_address_country?.trim()) {
      newErrors.creditor_address_country = 'Country code is required'
    } else if (!/^[A-Z]{2}$/.test(formData.creditor_address_country)) {
      newErrors.creditor_address_country = 'Country must be a 2-letter ISO code (e.g., DE, AT, CH)'
    }

    // Creditor ID validation (only if setting for first time)
    if (!existingConfig && !formData.creditor_id?.trim()) {
      newErrors.creditor_id = 'Creditor ID is required'
    } else if (formData.creditor_id && formData.creditor_id.length > 35) {
      newErrors.creditor_id = 'Creditor ID must be 35 characters or less'
    }

    setFieldErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  // Handle field changes
  const handleFieldChange = (field: keyof UpdateSepaConfigRequest, value: string) => {
    let finalValue = value

    // Auto-uppercase IBAN and country code
    if (field === 'creditor_iban' || field === 'creditor_address_country') {
      finalValue = value.toUpperCase()
    }

    setFormData((prev) => ({
      ...prev,
      [field]: finalValue,
    }))

    // Clear error for this field when user starts typing
    if (fieldErrors[field]) {
      setFieldErrors((prev) => {
        const newErrors = { ...prev }
        delete newErrors[field]
        return newErrors
      })
    }

    // Clear success message when user edits form
    if (successMessage) {
      setSuccessMessage(null)
    }
  }

  // Handle save
  const handleSave = async () => {
    if (!validateForm()) {
      return
    }

    try {
      setSaving(true)
      setError(null)
      setSuccessMessage(null)

      const result = await updateSepaConfig(formData)
      setExistingConfig(result)
      setOriginalFormData(formData)
      setFieldErrors({})
      setSuccessMessage('SEPA configuration saved successfully')

      // Clear success message after 5 seconds
      setTimeout(() => {
        setSuccessMessage(null)
      }, 5000)
    } catch (err) {
      const axiosError = err as AxiosError
      console.error('Failed to save SEPA config:', err)

      // Handle validation errors (422)
      if (axiosError.response?.status === 422) {
        const data = axiosError.response.data as any
        if (data.errors && typeof data.errors === 'object') {
          // Map field errors
          const mappedErrors: Record<string, string> = {}
          for (const [field, messages] of Object.entries(data.errors)) {
            if (Array.isArray(messages)) {
              mappedErrors[field] = messages[0]
            } else if (typeof messages === 'string') {
              mappedErrors[field] = messages
            }
          }
          setFieldErrors(mappedErrors)
          setError('Please fix validation errors')
        } else if (data.message) {
          setError(data.message)
        } else {
          setError('Validation failed')
        }
      } else if (axiosError.response?.status === 400) {
        const data = axiosError.response.data as any
        setError(data.message || 'Invalid request')
      } else {
        setError('Failed to save SEPA configuration. Please try again.')
      }
    } finally {
      setSaving(false)
    }
  }

  // Handle cancel
  const handleCancel = () => {
    setFormData(originalFormData)
    setFieldErrors({})
    setError(null)
  }

  // Check if creditor_id is already set (immutability)
  const isCreditorIdSet = !!existingConfig

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
  }: {
    label: string
    fieldKey: keyof UpdateSepaConfigRequest
    value: string
    type?: string
    placeholder?: string
    disabled?: boolean
    helperText?: string
    monospace?: boolean
  }) => {
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
        <label
          style={{
            fontSize: theme.typography.fontSize.sm,
            fontWeight: theme.typography.fontWeight.medium,
            color: theme.colors.text.primary,
          }}
        >
          {label}
          {fieldKey !== 'creditor_address_country' && fieldKey !== 'creditor_address_street' && fieldKey !== 'creditor_address_city' ? null : ''}
        </label>

        <input
          type={type}
          value={value}
          onChange={(e) => handleFieldChange(fieldKey, e.target.value)}
          disabled={disabled}
          placeholder={placeholder}
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

  // Tab styles
  const tabStyle = (isActive: boolean) => ({
    padding: `${theme.spacing.md} ${theme.spacing.lg}`,
    borderBottom: isActive ? `2px solid ${theme.colors.semantic.primary}` : '1px solid transparent',
    color: isActive ? theme.colors.semantic.primary : theme.colors.text.secondary,
    background: 'transparent',
    border: 'none',
    cursor: 'pointer',
    fontSize: theme.typography.fontSize.sm,
    fontWeight: isActive ? theme.typography.fontWeight.semibold : theme.typography.fontWeight.medium,
    transition: `all ${theme.transitions.default}`,
  })

  if (loading) {
    return (
      <div data-testid="settings-page-loading" style={{ textAlign: 'center', padding: theme.spacing.xl }}>
        Loading settings...
      </div>
    )
  }

  return (
    <div data-testid="settings-page" style={{ maxWidth: '800px' }}>
      {/* Page Header */}
      <div style={{ marginBottom: theme.spacing.xl }}>
        <h1
          style={{
            margin: 0,
            fontSize: theme.typography.fontSize['2xl'],
            fontWeight: theme.typography.fontWeight.bold,
            color: theme.colors.text.primary,
          }}
        >
          Einstellungen
        </h1>
        <p
          style={{
            margin: `${theme.spacing.sm} 0 0 0`,
            fontSize: theme.typography.fontSize.sm,
            color: theme.colors.text.secondary,
          }}
        >
          System configuration and preferences
        </p>
      </div>

      {/* Tabs */}
      <div
        data-testid="settings-tabs"
        style={{
          borderBottom: `1px solid ${theme.colors.border.light}`,
          marginBottom: theme.spacing.xl,
          display: 'flex',
          gap: 0,
        }}
      >
        <button
          data-testid="settings-tab-sepa"
          onClick={() => setActiveTab('sepa')}
          style={tabStyle(activeTab === 'sepa') as any}
        >
          SEPA-Konfiguration
        </button>
      </div>

      {/* SEPA Configuration Tab */}
      {activeTab === 'sepa' && (
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
              handleSave()
            }}
            style={{
              display: 'flex',
              flexDirection: 'column',
            }}
          >
            {/* Creditor ID Field */}
            <FormField
              label="Gläubiger ID (Creditor ID)"
              fieldKey="creditor_id"
              value={formData.creditor_id}
              placeholder="e.g., DE01ZZZ09999999999"
              disabled={isCreditorIdSet}
              helperText={
                isCreditorIdSet ? '⚠️ Cannot be changed after initial setup' : 'Unique SEPA creditor identifier (max 35 chars)'
              }
            />

            {/* Creditor Name Field */}
            <FormField
              label="Gläubiger Name (Creditor Name)"
              fieldKey="creditor_name"
              value={formData.creditor_name}
              placeholder="e.g., Rowing Club Name"
              helperText="Your organization name (max 70 chars)"
            />

            {/* Creditor IBAN Field */}
            <FormField
              label="Gläubiger IBAN (Creditor IBAN)"
              fieldKey="creditor_iban"
              value={formData.creditor_iban}
              placeholder="e.g., DE89370400440532013000"
              monospace={true}
              helperText="Bank account IBAN (15-34 characters, will be validated)"
            />

            {/* Street Address Field */}
            <FormField
              label="Straße (Street Address)"
              fieldKey="creditor_address_street"
              value={formData.creditor_address_street}
              placeholder="e.g., Mainstreet 123"
              helperText="Organization street address (max 70 chars)"
            />

            {/* City Field */}
            <FormField
              label="Stadt (City)"
              fieldKey="creditor_address_city"
              value={formData.creditor_address_city}
              placeholder="e.g., Munich"
              helperText="City name (max 70 chars)"
            />

            {/* Country Code Field */}
            <FormField
              label="Ländercode (Country Code)"
              fieldKey="creditor_address_country"
              value={formData.creditor_address_country}
              placeholder="e.g., DE"
              helperText="2-letter ISO country code (DE, AT, CH, etc.)"
            />

            {/* Action Buttons */}
            <div
              style={{
                display: 'flex',
                gap: theme.spacing.md,
                marginTop: theme.spacing.xl,
                justifyContent: isMobile ? 'stretch' : 'flex-start',
              }}
            >
              <button
                data-testid="settings-sepa-save-button"
                onClick={handleSave}
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
                onClick={handleCancel}
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
      )}
    </div>
  )
}
