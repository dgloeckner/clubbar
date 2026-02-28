/**
 * Settings Page
 * Configuration management for system settings (SEPA configuration, admin users)
 */

import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../styles/design-system'
import { useLoading } from '../context/LoadingContext'
import { getSepaConfig, updateSepaConfig } from '../services/sepa-config'
import { getAdminUsers, createAdminUser, updateAdminUser, deactivateAdminUser, reactivateAdminUser, resetAdminPassword } from '../services/admin-users'
import { getTerminals, createTerminal, updateTerminal, rotateTerminalToken, revokeTerminalAccess } from '../services/terminals'
import { SepaConfig, UpdateSepaConfigRequest, AdminUser, Terminal } from '../types'
import { AxiosError } from 'axios'
import { SepaConfigTab } from '../components/settings/SepaConfigTab'
import { AdminUsersTab } from '../components/settings/AdminUsersTab'
import { CreateAdminModal } from '../components/modals/CreateAdminModal'
import { EditAdminModal } from '../components/modals/EditAdminModal'
import { PasswordDisplayModal } from '../components/modals/PasswordDisplayModal'
import { ConfirmDialog } from '../components/modals/ConfirmDialog'
import { TerminalsTab } from '../components/settings/TerminalsTab'
import { CreateTerminalModal } from '../components/modals/CreateTerminalModal'
import { EditTerminalModal } from '../components/modals/EditTerminalModal'
import { TokenDisplayModal } from '../components/modals/TokenDisplayModal'

export function SettingsPage() {
  const { t } = useTranslation()
  const { setIsLoading } = useLoading()

  // State management
  const [activeTab, setActiveTab] = useState<'sepa' | 'admin-users' | 'terminals'>('admin-users')
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
    payment_reference_prefix: '',
  })
  const [formData, setFormData] = useState<UpdateSepaConfigRequest>({
    creditor_id: '',
    creditor_name: '',
    creditor_iban: '',
    creditor_address_street: '',
    creditor_address_city: '',
    creditor_address_country: '',
    payment_reference_prefix: '',
  })
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  // Admin Users State
  const [adminUsers, setAdminUsers] = useState<AdminUser[]>([])
  const [adminUsersLoading, setAdminUsersLoading] = useState(false)
  const [showCreateAdminModal, setShowCreateAdminModal] = useState(false)
  const [showEditAdminModal, setShowEditAdminModal] = useState(false)
  const [editingAdmin, setEditingAdmin] = useState<AdminUser | null>(null)
  const [createAdminFormData, setCreateAdminFormData] = useState<{
    email: string
    display_name: string
    locale: 'de' | 'en'
  }>({
    email: '',
    display_name: '',
    locale: 'de',
  })
  const [editAdminFormData, setEditAdminFormData] = useState<{
    email: string
    display_name: string
    locale: 'de' | 'en'
  }>({
    email: '',
    display_name: '',
    locale: 'de',
  })
  const [generatedPassword, setGeneratedPassword] = useState<string | null>(null)
  const [showPasswordModal, setShowPasswordModal] = useState(false)
  const [deactivateConfirm, setDeactivateConfirm] = useState<string | null>(null)

  // Terminal State
  const [terminals, setTerminals] = useState<Terminal[]>([])
  const [terminalsLoading, setTerminalsLoading] = useState(false)
  const [showCreateTerminalModal, setShowCreateTerminalModal] = useState(false)
  const [showEditTerminalModal, setShowEditTerminalModal] = useState(false)
  const [editingTerminal, setEditingTerminal] = useState<Terminal | null>(null)
  const [createTerminalFormData, setCreateTerminalFormData] = useState<{ name: string; device_id: string }>({
    name: '',
    device_id: '',
  })
  const [editTerminalFormData, setEditTerminalFormData] = useState<{ name: string }>({ name: '' })
  const [generatedToken, setGeneratedToken] = useState<string | null>(null)
  const [showTokenModal, setShowTokenModal] = useState(false)
  const [terminalConfirmAction, setTerminalConfirmAction] = useState<{ type: 'deactivate' | 'rotate' | 'revoke'; id: string } | null>(null)

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
            payment_reference_prefix: config.payment_reference_prefix,
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

  // Load terminals when terminals tab is active
  useEffect(() => {
    if (activeTab === 'terminals') {
      loadTerminals()
    }
  }, [activeTab])

  const loadAdminUsers = async () => {
    try {
      setAdminUsersLoading(true)
      const response = await getAdminUsers(1, 500, 'all')
      setAdminUsers(response.data || [])
    } catch (err) {
      console.error('Failed to load admin users:', err)
    } finally {
      setAdminUsersLoading(false)
    }
  }

  const loadTerminals = async () => {
    try {
      setTerminalsLoading(true)
      const response = await getTerminals(1, 500)
      setTerminals(response.data || [])
    } catch (err) {
      console.error('Failed to load terminals:', err)
    } finally {
      setTerminalsLoading(false)
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

  const handleDeactivateAdmin = (id: string) => {
    setDeactivateConfirm(id)
  }

  const handleDeactivateAdminConfirmed = async () => {
    if (!deactivateConfirm) return
    const id = deactivateConfirm
    setDeactivateConfirm(null)
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

  const handleCreateTerminal = async () => {
    try {
      const result = await createTerminal(createTerminalFormData)
      setGeneratedToken(result.api_token)
      setShowTokenModal(true)
      setShowCreateTerminalModal(false)
      setCreateTerminalFormData({ name: '', device_id: '' })
      await loadTerminals()
    } catch (err) {
      console.error('Failed to create terminal:', err)
      setError('Failed to create terminal')
    }
  }

  const handleUpdateTerminal = async () => {
    if (!editingTerminal) return
    try {
      await updateTerminal(editingTerminal.id, { name: editTerminalFormData.name })
      setShowEditTerminalModal(false)
      setEditingTerminal(null)
      setEditTerminalFormData({ name: '' })
      await loadTerminals()
    } catch (err) {
      console.error('Failed to update terminal:', err)
      setError('Failed to update terminal')
    }
  }

  const handleDeactivateTerminal = (id: string) => {
    setTerminalConfirmAction({ type: 'deactivate', id })
  }

  const handleReactivateTerminal = async (id: string) => {
    try {
      await updateTerminal(id, { is_active: true })
      await loadTerminals()
    } catch (err) {
      console.error('Failed to reactivate terminal:', err)
      setError('Failed to reactivate terminal')
    }
  }

  const handleRotateToken = (id: string) => {
    setTerminalConfirmAction({ type: 'rotate', id })
  }

  const handleRevokeAccess = (id: string) => {
    setTerminalConfirmAction({ type: 'revoke', id })
  }

  const handleTerminalConfirmAction = async () => {
    if (!terminalConfirmAction) return
    const { type, id } = terminalConfirmAction
    setTerminalConfirmAction(null)
    try {
      if (type === 'deactivate') {
        await updateTerminal(id, { is_active: false })
      } else if (type === 'rotate') {
        const result = await rotateTerminalToken(id)
        setGeneratedToken(result.api_token)
        setShowTokenModal(true)
      } else if (type === 'revoke') {
        await revokeTerminalAccess(id)
      }
      await loadTerminals()
    } catch (err) {
      console.error(`Failed to ${type} terminal:`, err)
      setError(`Failed to ${type} terminal`)
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

  // isCreditorIdSet is available for warning display if needed
  // const isCreditorIdSet = !!existingConfig

  // Tab styles (prototype styling: button group container)
  const tabContainerStyle = {
    display: 'inline-flex' as const,
    background: '#1a2744',
    borderRadius: '12px',
    padding: '4px',
    gap: '4px',
    border: '1px solid rgba(71,85,105,0.3)',
  }

  const tabStyle = (isActive: boolean) => ({
    padding: `${theme.spacing.md} ${theme.spacing.lg}`,
    borderRadius: '8px',
    background: isActive ? theme.colors.semantic.primary : 'transparent',
    color: isActive ? 'white' : theme.colors.text.secondary,
    cursor: 'pointer',
    fontSize: theme.typography.fontSize.sm,
    fontWeight: isActive ? theme.typography.fontWeight.semibold : theme.typography.fontWeight.medium,
    transition: `all ${theme.transitions.default}`,
    border: 'none',
    display: 'flex',
    alignItems: 'center',
    gap: theme.spacing.sm,
  })

  if (loading) {
    return (
      <div data-testid="settings-page-loading" style={{ textAlign: 'center', padding: theme.spacing.xl }}>
        {t('settings.loadingSettings')}
      </div>
    )
  }

  return (
    <div data-testid="settings-page" style={{ padding: '20px' }}>
      <h1 style={{ margin: '0 0 20px 0' }}>{t('settings.title')}</h1>

      {/* Tabs Navigation */}
      <div style={{ marginBottom: theme.spacing.xl }}>
        <div
          data-testid="settings-tabs"
          style={tabContainerStyle}
        >
          <button
            data-testid="settings-tab-admin-users"
            onClick={() => setActiveTab('admin-users')}
            style={tabStyle(activeTab === 'admin-users') as any}
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
              <circle cx="8.5" cy="7" r="4" />
              <circle cx="18.5" cy="7" r="4" />
              <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
            </svg>
            {t('settings.adminUsers')}
          </button>
          <button
            data-testid="settings-tab-sepa"
            onClick={() => setActiveTab('sepa')}
            style={tabStyle(activeTab === 'sepa') as any}
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <rect x="2" y="5" width="20" height="14" rx="2" />
              <line x1="2" y1="10" x2="22" y2="10" />
            </svg>
            {t('settings.sepaConfig')}
          </button>
          <button
            data-testid="settings-tab-terminals"
            onClick={() => setActiveTab('terminals')}
            style={tabStyle(activeTab === 'terminals') as any}
          >
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
              <rect x="2" y="3" width="20" height="14" rx="2" ry="2" />
              <line x1="8" y1="21" x2="16" y2="21" />
              <line x1="12" y1="17" x2="12" y2="21" />
            </svg>
            {t('settings.terminals')}
          </button>
        </div>
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
              locale: (admin.locale === 'en' ? 'en' : 'de') as 'de' | 'en',
            })
            setShowEditAdminModal(true)
          }}
          onResetPassword={handleResetPassword}
          onDeactivateUser={handleDeactivateAdmin}
          onReactivateUser={handleReactivateAdmin}
        />
      )}

      {/* Terminals Tab */}
      {activeTab === 'terminals' && (
        <TerminalsTab
          terminals={terminals}
          loading={terminalsLoading}
          onCreateTerminal={() => setShowCreateTerminalModal(true)}
          onEditTerminal={(terminal) => {
            setEditingTerminal(terminal)
            setEditTerminalFormData({ name: terminal.name })
            setShowEditTerminalModal(true)
          }}
          onRotateToken={handleRotateToken}
          onRevokeAccess={handleRevokeAccess}
          onDeactivateTerminal={handleDeactivateTerminal}
          onReactivateTerminal={handleReactivateTerminal}
        />
      )}

      {/* Modals */}
      <CreateAdminModal
        isOpen={showCreateAdminModal}
        formData={createAdminFormData}
        onFormChange={(field, value) => {
          if (field === 'locale') {
            setCreateAdminFormData((prev) => ({
              ...prev,
              locale: value as 'de' | 'en',
            }))
          } else {
            setCreateAdminFormData((prev) => ({
              ...prev,
              [field]: value,
            }))
          }
        }}
        onSubmit={handleCreateAdmin}
        onClose={() => setShowCreateAdminModal(false)}
      />

      <EditAdminModal
        isOpen={showEditAdminModal}
        formData={editAdminFormData}
        onFormChange={(field, value) => {
          if (field === 'locale') {
            setEditAdminFormData((prev) => ({
              ...prev,
              locale: value as 'de' | 'en',
            }))
          } else {
            setEditAdminFormData((prev) => ({
              ...prev,
              [field]: value,
            }))
          }
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

      <ConfirmDialog
        isOpen={!!deactivateConfirm}
        message={t('settings.deactivateAdminConfirm')}
        confirmLabel={t('common.deactivate')}
        variant="danger"
        onConfirm={handleDeactivateAdminConfirmed}
        onCancel={() => setDeactivateConfirm(null)}
      />

      <CreateTerminalModal
        isOpen={showCreateTerminalModal}
        formData={createTerminalFormData}
        onFormChange={(field, value) => {
          setCreateTerminalFormData((prev) => ({ ...prev, [field]: value }))
        }}
        onSubmit={handleCreateTerminal}
        onClose={() => setShowCreateTerminalModal(false)}
      />

      <EditTerminalModal
        isOpen={showEditTerminalModal}
        formData={editTerminalFormData}
        onFormChange={(field, value) => {
          setEditTerminalFormData((prev) => ({ ...prev, [field]: value }))
        }}
        onSubmit={handleUpdateTerminal}
        onClose={() => setShowEditTerminalModal(false)}
      />

      <TokenDisplayModal
        isOpen={showTokenModal}
        token={generatedToken}
        onClose={() => {
          setShowTokenModal(false)
          setGeneratedToken(null)
        }}
      />

      <ConfirmDialog
        isOpen={!!terminalConfirmAction}
        message={
          terminalConfirmAction?.type === 'deactivate'
            ? t('settings.deactivateTerminalConfirm')
            : terminalConfirmAction?.type === 'rotate'
              ? t('settings.rotateTokenConfirm')
              : t('settings.revokeTerminalConfirm')
        }
        confirmLabel={
          terminalConfirmAction?.type === 'deactivate'
            ? t('common.deactivate')
            : terminalConfirmAction?.type === 'rotate'
              ? t('common.confirm')
              : t('common.confirm')
        }
        variant="danger"
        onConfirm={handleTerminalConfirmAction}
        onCancel={() => setTerminalConfirmAction(null)}
      />
    </div>
  )
}
