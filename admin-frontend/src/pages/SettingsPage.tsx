/**
 * Settings Page
 * Configuration management for system settings (SEPA configuration, admin users)
 */

import { useEffect, useState } from 'react'
import { theme } from '../styles/design-system'
import { useLoading } from '../context/LoadingContext'
import { getSepaConfig, updateSepaConfig } from '../services/sepa-config'
import { getAdminUsers, createAdminUser, updateAdminUser, deactivateAdminUser, reactivateAdminUser, resetAdminPassword } from '../services/admin-users'
import { SepaConfig, UpdateSepaConfigRequest, AdminUser, CreateAdminUserRequest, UpdateAdminUserRequest } from '../types'
import { AxiosError } from 'axios'
import { SepaConfigTab } from '../components/settings/SepaConfigTab'
import { AdminUsersTab } from '../components/settings/AdminUsersTab'
import { CreateAdminModal } from '../components/modals/CreateAdminModal'
import { EditAdminModal } from '../components/modals/EditAdminModal'
import { PasswordDisplayModal } from '../components/modals/PasswordDisplayModal'

export function SettingsPage() {
  const { setIsLoading } = useLoading()

  // State management
  const [activeTab, setActiveTab] = useState<'sepa' | 'admin-users'>('sepa')
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

  // Admin Users State
  const [adminUsers, setAdminUsers] = useState<AdminUser[]>([])
  const [adminUsersLoading, setAdminUsersLoading] = useState(false)
  const [showCreateAdminModal, setShowCreateAdminModal] = useState(false)
  const [showEditAdminModal, setShowEditAdminModal] = useState(false)
  const [editingAdmin, setEditingAdmin] = useState<AdminUser | null>(null)
  const [createAdminFormData, setCreateAdminFormData] = useState({
    email: '',
    display_name: '',
    locale: 'de',
  })
  const [editAdminFormData, setEditAdminFormData] = useState({
    email: '',
    display_name: '',
    locale: 'de',
  })
  const [generatedPassword, setGeneratedPassword] = useState<string | null>(null)
  const [showPasswordModal, setShowPasswordModal] = useState(false)

  // Load SEPA config on mount
  useEffect(() => {
    const loadConfig = async () => {
      try {
        setLoading(true)
        setIsLoading(true)
        const config = await getSepaConfig()

        if (config) {
          setExistingConfig(config)
          // Pre-fill form with full unmasked values (admin-only page, no need to mask)
          const formValues: UpdateSepaConfigRequest = {
            creditor_id: config.creditor_id,
            creditor_name: config.creditor_name,
            creditor_iban: config.creditor_iban,
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

  // Load admin users when admin-users tab is active
  useEffect(() => {
    if (activeTab === 'admin-users') {
      loadAdminUsers()
    }
  }, [activeTab])

  const loadAdminUsers = async () => {
    try {
      setAdminUsersLoading(true)
      const response = await getAdminUsers(1, 50, 'all')
      setAdminUsers(response.data || [])
    } catch (err) {
      console.error('Failed to load admin users:', err)
    } finally {
      setAdminUsersLoading(false)
    }
  }

  const handleCreateAdmin = async () => {
    try {
      const result = await createAdminUser({
        email: createAdminFormData.email,
        display_name: createAdminFormData.display_name,
        locale: createAdminFormData.locale,
      })
      setGeneratedPassword(result.password)
      setShowPasswordModal(true)
      setShowCreateAdminModal(false)
      setCreateAdminFormData({ email: '', display_name: '', locale: 'de' })
      await loadAdminUsers()
    } catch (err) {
      console.error('Failed to create admin user:', err)
      setError('Failed to create admin user')
    }
  }

  const handleUpdateAdmin = async () => {
    if (!editingAdmin) return
    try {
      await updateAdminUser(editingAdmin.id, {
        email: editAdminFormData.email || undefined,
        display_name: editAdminFormData.display_name || undefined,
        locale: editAdminFormData.locale || undefined,
      })
      setShowEditAdminModal(false)
      setEditingAdmin(null)
      setEditAdminFormData({ email: '', display_name: '', locale: 'de' })
      await loadAdminUsers()
    } catch (err) {
      console.error('Failed to update admin user:', err)
      setError('Failed to update admin user')
    }
  }

  const handleDeactivateAdmin = async (id: string) => {
    if (!window.confirm('Are you sure you want to deactivate this admin user?')) return
    try {
      await deactivateAdminUser(id)
      await loadAdminUsers()
    } catch (err) {
      console.error('Failed to deactivate admin user:', err)
      setError('Failed to deactivate admin user')
    }
  }

  const handleReactivateAdmin = async (id: string) => {
    try {
      await reactivateAdminUser(id)
      await loadAdminUsers()
    } catch (err) {
      console.error('Failed to reactivate admin user:', err)
      setError('Failed to reactivate admin user')
    }
  }

  const handleResetPassword = async (id: string) => {
    try {
      const result = await resetAdminPassword(id)
      setGeneratedPassword(result.password)
      setShowPasswordModal(true)
    } catch (err) {
      console.error('Failed to reset password:', err)
      setError('Failed to reset password')
    }
  }

  // Validate IBAN format (basic client-side validation)
  const validateIban = (iban: string): boolean => {
    if (!iban) return false
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

    if (field === 'creditor_iban' || field === 'creditor_address_country') {
      finalValue = value.toUpperCase()
    }

    setFormData((prev) => ({
      ...prev,
      [field]: finalValue,
    }))

    if (fieldErrors[field]) {
      setFieldErrors((prev) => {
        const newErrors = { ...prev }
        delete newErrors[field]
        return newErrors
      })
    }

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

  // Check if creditor_id is already set (for warning display)
  const isCreditorIdSet = !!existingConfig

  // Tab styles
  const tabStyle = (isActive: boolean) => ({
    padding: `${theme.spacing.md} ${theme.spacing.lg}`,
    borderBottom: isActive ? `2px solid ${theme.colors.semantic.primary}` : '1px solid transparent',
    color: isActive ? theme.colors.semantic.primary : theme.colors.text.secondary,
    background: 'transparent',
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

      {/* Tabs Navigation */}
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
        <button
          data-testid="settings-tab-admin-users"
          onClick={() => setActiveTab('admin-users')}
          style={tabStyle(activeTab === 'admin-users') as any}
        >
          Admin-Benutzer
        </button>
      </div>

      {/* SEPA Configuration Tab */}
      {activeTab === 'sepa' && (
        <SepaConfigTab
          config={existingConfig}
          loading={false}
          saving={saving}
          error={error}
          successMessage={successMessage}
          formData={formData}
          fieldErrors={fieldErrors}
          onFieldChange={handleFieldChange}
          onSave={handleSave}
          onCancel={handleCancel}
          validateIban={validateIban}
        />
      )}

      {/* Admin Users Tab */}
      {activeTab === 'admin-users' && (
        <AdminUsersTab
          users={adminUsers}
          loading={adminUsersLoading}
          onCreateUser={() => setShowCreateAdminModal(true)}
          onEditUser={(admin) => {
            setEditingAdmin(admin)
            setEditAdminFormData({
              email: admin.email,
              display_name: admin.display_name,
              locale: admin.locale,
            })
            setShowEditAdminModal(true)
          }}
          onResetPassword={handleResetPassword}
          onDeactivateUser={handleDeactivateAdmin}
          onReactivateUser={handleReactivateAdmin}
        />
      )}

      {/* Modals */}
      <CreateAdminModal
        isOpen={showCreateAdminModal}
        formData={createAdminFormData}
        onFormChange={(field, value) => {
          setCreateAdminFormData((prev) => ({
            ...prev,
            [field]: value,
          }))
        }}
        onSubmit={handleCreateAdmin}
        onClose={() => setShowCreateAdminModal(false)}
      />

      <EditAdminModal
        isOpen={showEditAdminModal}
        formData={editAdminFormData}
        onFormChange={(field, value) => {
          setEditAdminFormData((prev) => ({
            ...prev,
            [field]: value,
          }))
        }}
        onSubmit={handleUpdateAdmin}
        onClose={() => setShowEditAdminModal(false)}
      />

      <PasswordDisplayModal
        isOpen={showPasswordModal}
        password={generatedPassword}
        onClose={() => {
          setShowPasswordModal(false)
          setGeneratedPassword(null)
        }}
      />
    </div>
  )
}
