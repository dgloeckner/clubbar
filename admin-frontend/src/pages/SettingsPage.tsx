/**
 * Settings Page
 * Configuration management for system settings (SEPA configuration, admin users)
 */

import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { theme } from '../styles/design-system'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { getSepaConfiguration } from '../api/generated/sepa-configuration/sepa-configuration'
import { getAdminUsers } from '../api/generated/admin-users/admin-users'
import { getTerminals } from '../api/generated/terminals/terminals'
import { getAuthentication } from '../api/generated/authentication/authentication'
import { getInstanceBranding } from '../api/generated/instance-branding/instance-branding'
import { getProfile } from '../auth/session'
import { useInstanceConfig } from '../context/InstanceConfigContext'
import type { SepaConfig, AdminUser as GeneratedAdminUser, Terminal as GeneratedTerminal } from '../api/generated'

// Required fields that are always present in the API responses
type AdminUser = GeneratedAdminUser & { id: string; email: string; display_name: string; locale: string; is_active: boolean; created_at: string }
type Terminal = GeneratedTerminal & { id: string; name: string; is_active: boolean }
import axios from 'axios'
import { Alert } from '../components/common/Alert'
import { SepaConfigTab } from '../components/settings/SepaConfigTab'
import { AdminUsersTab } from '../components/settings/AdminUsersTab'
import { CreateAdminModal } from '../components/modals/CreateAdminModal'
import { EditAdminModal } from '../components/modals/EditAdminModal'
import { PasswordDisplayModal } from '../components/modals/PasswordDisplayModal'
import { ConfirmDialog } from '../components/modals/ConfirmDialog'
import { StepUpConfirmDialog, type StepUpCredentials } from '../components/modals/StepUpConfirmDialog'
import { TerminalsTab } from '../components/settings/TerminalsTab'
import { SecurityCheckTab } from '../components/settings/SecurityCheckTab'
import { InstanceBrandingTab } from '../components/settings/InstanceBrandingTab'
import { CreateTerminalModal } from '../components/modals/CreateTerminalModal'
import { EditTerminalModal } from '../components/modals/EditTerminalModal'
import { TokenDisplayModal } from '../components/modals/TokenDisplayModal'
import { validateIban } from '../utils/iban'
import { getApiErrorMessage, getApiFieldErrors } from '../utils/apiErrors'
import { MAX_PER_PAGE, loadAllPages } from '../utils/pagination'
import {
  buildCreateSepaConfigRequest,
  buildUpdateSepaConfigRequest,
  isCreditorIdSet,
  type SepaConfigFormData,
} from '../utils/sepaConfig'

export function SettingsPage() {
  const { t } = useTranslation()
  const breakpoint = useBreakpoint()
  const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'
  const { refetch: refetchInstanceConfig } = useInstanceConfig()

  // State management
  const [activeTab, setActiveTab] = useState<'sepa' | 'admin-users' | 'terminals' | 'security' | 'instance'>('admin-users')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  // Page-level failure, rendered above the tab content. A modal covers that
  // banner, so a failure raised while one is open goes to `modalError` instead.
  const [error, setError] = useState<string | null>(null)
  const [modalError, setModalError] = useState<string | null>(null)
  const [modalFieldErrors, setModalFieldErrors] = useState<Record<string, string>>({})
  // Confirmation that a one-shot action worked, rendered next to `error` above
  // the tab content. An action whose only outcome is server-side — resetting
  // 2FA changes nothing the table shows — is otherwise indistinguishable from
  // one that silently failed (#130).
  const [actionSuccess, setActionSuccess] = useState<string | null>(null)
  const [successMessage, setSuccessMessage] = useState<string | null>(null)
  const [existingConfig, setExistingConfig] = useState<SepaConfig | null>(null)
  const [originalFormData, setOriginalFormData] = useState<SepaConfigFormData>({
    creditor_id: '',
    creditor_name: '',
    creditor_iban: '',
    creditor_address_street: '',
    creditor_address_city: '',
    creditor_address_country: '',
    payment_reference_prefix: '',
  })
  const [formData, setFormData] = useState<SepaConfigFormData>({
    creditor_id: '',
    creditor_name: '',
    creditor_iban: '',
    creditor_address_street: '',
    creditor_address_city: '',
    creditor_address_country: '',
    payment_reference_prefix: '',
  })
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  // Tracks the pending "clear the success message" timer so a later save
  // can cancel a still-pending one instead of having it clear a newer
  // message, and so unmounting the page cancels it instead of setting state
  // on an unmounted component (#136).
  const successMessageTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null)

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
  const [reset2faConfirm, setReset2faConfirm] = useState<string | null>(null)
  const [resetPasswordConfirm, setResetPasswordConfirm] = useState<string | null>(null)
  // Whether the *caller* (not the target being acted on) has 2FA enabled —
  // decides whether the step-up dialog asks for a TOTP code (#337).
  const [callerTotpEnabled, setCallerTotpEnabled] = useState(false)
  // Failure from the last step-up attempt (wrong password/code); shown inside
  // the step-up dialog itself, which stays open so the admin can retry.
  const [stepUpError, setStepUpError] = useState<string | null>(null)

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

  // Instance Branding State (ADR-0034 / UC-A64) — own load/save state, not
  // shared with the SEPA tab's, matching how every other tab keeps its own.
  const [instanceName, setInstanceName] = useState('')
  const [instanceOriginalName, setInstanceOriginalName] = useState('')
  const [instanceLoading, setInstanceLoading] = useState(false)
  const [instanceSaving, setInstanceSaving] = useState(false)
  const [instanceSuccessMessage, setInstanceSuccessMessage] = useState<string | null>(null)
  const [instanceFieldErrors, setInstanceFieldErrors] = useState<Record<string, string>>({})
  // Same purpose as `successMessageTimeoutRef`, for the Instance tab's own
  // success message (#136).
  const instanceSuccessTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null)

  // Load SEPA config on mount
  useEffect(() => {
    const loadConfig = async () => {
      try {
        setLoading(true)
        let config: SepaConfig | null = null
        try {
          const result = await getSepaConfiguration().getSepaConfig()
          config = result as SepaConfig
        } catch (err: unknown) {
          if (axios.isAxiosError(err) && err.response?.status === 404) {
            config = null
          } else {
            throw err
          }
        }

        if (config) {
          setExistingConfig(config)
          // Pre-fill form with full unmasked values (admin-only page, no need to mask)
          const formValues: SepaConfigFormData = {
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
      } catch (err: unknown) {
        setError(getApiErrorMessage(err, t('settings.errors.loadSettings')))
      } finally {
        setLoading(false)
      }
    }

    loadConfig()
    // `t` is deliberately not a dependency: re-running this on a language
    // switch would refetch the config and discard unsaved edits.
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  // Cancel any pending "clear success message" timers on unmount so they
  // cannot set state after the page is gone (#136).
  useEffect(() => {
    return () => {
      if (successMessageTimeoutRef.current) clearTimeout(successMessageTimeoutRef.current)
      if (instanceSuccessTimeoutRef.current) clearTimeout(instanceSuccessTimeoutRef.current)
    }
  }, [])

  // Load admin users when admin-users tab is active
  useEffect(() => {
    if (activeTab === 'admin-users') {
      loadAdminUsers()
    }
  }, [activeTab])

  // The step-up dialogs (2FA reset, password reset, #337) need to know
  // whether the *signed-in* admin has 2FA enabled, to decide whether to ask
  // for a TOTP code alongside the password. Fetched once — it does not
  // change over the life of the page.
  useEffect(() => {
    getProfile()
      .then((profile) => setCallerTotpEnabled(!!profile.totp_enabled))
      .catch(() => setCallerTotpEnabled(false))
  }, [])

  // Load terminals when terminals tab is active
  useEffect(() => {
    if (activeTab === 'terminals') {
      loadTerminals()
    }
  }, [activeTab])

  // Load instance branding when the Instance tab is active
  useEffect(() => {
    if (activeTab === 'instance') {
      loadInstanceConfig()
    }
  }, [activeTab])

  /**
   * Report a failure on the page banner, preferring what the API said over the
   * generic fallback.
   */
  const reportError = (err: unknown, fallbackKey: string) => {
    setActionSuccess(null)
    setError(getApiErrorMessage(err, t(fallbackKey)))
  }

  /** Confirm an action that leaves no visible trace of its own. */
  const reportSuccess = (messageKey: string) => {
    setError(null)
    setActionSuccess(t(messageKey))
  }

  /**
   * Report a failure raised from an open modal. It is shown inside that modal,
   * next to the input the admin is about to retry — the page banner is behind
   * the overlay and would only resurface later, on whichever tab is open then.
   */
  const reportModalError = (err: unknown, fallbackKey: string) => {
    setModalError(getApiErrorMessage(err, t(fallbackKey)))
    setModalFieldErrors(getApiFieldErrors(err))
  }

  const clearModalError = () => {
    setModalError(null)
    setModalFieldErrors({})
  }

  const switchTab = (tab: 'sepa' | 'admin-users' | 'terminals' | 'security' | 'instance') => {
    // The banner reports what failed on the tab that is being left behind.
    setError(null)
    setActionSuccess(null)
    setActiveTab(tab)
  }

  const loadAdminUsers = async () => {
    try {
      setAdminUsersLoading(true)
      setAdminUsers(
        (await loadAllPages(async (page) =>
          await getAdminUsers().listAdminUsers({ page, per_page: MAX_PER_PAGE }),
        )) as AdminUser[],
      )
    } catch (err: unknown) {
      reportError(err, 'settings.errors.loadAdminUsers')
    } finally {
      setAdminUsersLoading(false)
    }
  }

  const loadTerminals = async () => {
    try {
      setTerminalsLoading(true)
      setTerminals(
        (await loadAllPages(async (page) =>
          await getTerminals().listTerminals({ page, per_page: MAX_PER_PAGE }),
        )) as Terminal[],
      )
    } catch (err: unknown) {
      reportError(err, 'settings.errors.loadTerminals')
    } finally {
      setTerminalsLoading(false)
    }
  }

  const loadInstanceConfig = async () => {
    try {
      setInstanceLoading(true)
      const result = await getInstanceBranding().getInstanceConfig()
      const name = result.instance_name ?? ''
      setInstanceName(name)
      setInstanceOriginalName(name)
    } catch (err: unknown) {
      reportError(err, 'settings.instance.errors.load')
    } finally {
      setInstanceLoading(false)
    }
  }

  const handleCreateAdmin = async () => {
    try {
      const result = await getAdminUsers().createAdminUser({
        email: createAdminFormData.email,
        display_name: createAdminFormData.display_name,
        locale: createAdminFormData.locale,
      })
      setGeneratedPassword(result.password ?? null)
      setShowPasswordModal(true)
      setShowCreateAdminModal(false)
      clearModalError()
      setCreateAdminFormData({ email: '', display_name: '', locale: 'de' })
      await loadAdminUsers()
    } catch (err: unknown) {
      reportModalError(err, 'settings.errors.createAdminUser')
    }
  }

  const handleUpdateAdmin = async () => {
    if (!editingAdmin) return
    try {
      await getAdminUsers().updateAdminUser(editingAdmin.id!, {
        email: editAdminFormData.email || undefined,
        display_name: editAdminFormData.display_name || undefined,
        locale: editAdminFormData.locale || undefined,
      })
      setShowEditAdminModal(false)
      setEditingAdmin(null)
      clearModalError()
      setEditAdminFormData({ email: '', display_name: '', locale: 'de' })
      await loadAdminUsers()
    } catch (err: unknown) {
      reportModalError(err, 'settings.errors.updateAdminUser')
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
      await getAdminUsers().updateAdminUser(id, { is_active: false })
      await loadAdminUsers()
    } catch (err: unknown) {
      reportError(err, 'settings.errors.deactivateAdminUser')
    }
  }

  const handleReactivateAdmin = async (id: string) => {
    try {
      await getAdminUsers().updateAdminUser(id, { is_active: true })
      await loadAdminUsers()
    } catch (err: unknown) {
      reportError(err, 'settings.errors.reactivateAdminUser')
    }
  }

  // Resetting a password invalidates a colleague's current one the moment it is
  // clicked, and the trigger is a bare icon button — so it is asked first, like
  // deactivate and reset-2FA already were (#130). As of #337 the confirmation
  // also collects the caller's own step-up credential — the target's password
  // does not change until the caller re-proves who they are.
  const handleResetPassword = (id: string) => {
    setStepUpError(null)
    setResetPasswordConfirm(id)
  }

  const handleResetPasswordConfirmed = async (credentials: StepUpCredentials) => {
    if (!resetPasswordConfirm) return
    try {
      const result = await getAdminUsers().resetAdminPassword(resetPasswordConfirm, credentials)
      setResetPasswordConfirm(null)
      setStepUpError(null)
      setGeneratedPassword(result.password ?? null)
      setShowPasswordModal(true)
    } catch (err: unknown) {
      // A wrong step-up credential keeps the dialog open so the admin can
      // retry, rather than reporting it on the page banner behind it.
      setStepUpError(getApiErrorMessage(err, t('settings.errors.resetPassword')))
    }
  }

  const handleReset2fa = (id: string) => {
    setStepUpError(null)
    setReset2faConfirm(id)
  }

  const handleReset2faConfirmed = async (credentials: StepUpCredentials) => {
    if (!reset2faConfirm) return
    try {
      await getAuthentication().resetTotp({ userId: reset2faConfirm, ...credentials })
      setReset2faConfirm(null)
      setStepUpError(null)
      // Nothing in the table changes, so without this the admin cannot tell a
      // reset that worked from one that failed (#130).
      reportSuccess('settings.reset2faSuccess')
    } catch (err: unknown) {
      // A wrong step-up credential keeps the dialog open so the admin can
      // retry, rather than reporting it on the page banner behind it.
      setStepUpError(getApiErrorMessage(err, t('settings.errors.reset2fa')))
    }
  }

  const handleCreateTerminal = async () => {
    try {
      const result = await getTerminals().createTerminal(createTerminalFormData)
      setGeneratedToken(result.api_token ?? null)
      setShowTokenModal(true)
      setShowCreateTerminalModal(false)
      clearModalError()
      setCreateTerminalFormData({ name: '', device_id: '' })
      await loadTerminals()
    } catch (err: unknown) {
      reportModalError(err, 'settings.errors.createTerminal')
    }
  }

  const handleUpdateTerminal = async () => {
    if (!editingTerminal) return
    try {
      await getTerminals().updateTerminal(editingTerminal.id!, { name: editTerminalFormData.name })
      setShowEditTerminalModal(false)
      setEditingTerminal(null)
      clearModalError()
      setEditTerminalFormData({ name: '' })
      await loadTerminals()
    } catch (err: unknown) {
      reportModalError(err, 'settings.errors.updateTerminal')
    }
  }

  const handleDeactivateTerminal = (id: string) => {
    setTerminalConfirmAction({ type: 'deactivate', id })
  }

  const handleReactivateTerminal = async (id: string) => {
    try {
      await getTerminals().updateTerminal(id, { is_active: true })
      await loadTerminals()
    } catch (err: unknown) {
      reportError(err, 'settings.errors.reactivateTerminal')
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
        await getTerminals().updateTerminal(id, { is_active: false })
      } else if (type === 'rotate') {
        const result = await getTerminals().rotateTerminalToken(id)
        setGeneratedToken(result.api_token ?? null)
        setShowTokenModal(true)
      } else if (type === 'revoke') {
        await getTerminals().revokeTerminalAccess(id)
      }
      await loadTerminals()
    } catch (err: unknown) {
      reportError(err, `settings.errors.${type}Terminal`)
    }
  }

  // The creditor ID is immutable once set (ADR-0007): it is required and
  // editable during initial setup, then locked and left out of every update.
  const creditorIdLocked = isCreditorIdSet(existingConfig)

  // Validate form
  const validateForm = (): boolean => {
    const newErrors: Record<string, string> = {}

    if (!formData.creditor_name?.trim()) {
      newErrors.creditor_name = t('settings.validation.creditorNameRequired')
    } else if (formData.creditor_name.length > 70) {
      newErrors.creditor_name = t('settings.validation.creditorNameTooLong')
    }

    if (!formData.creditor_iban?.trim()) {
      newErrors.creditor_iban = t('settings.validation.creditorIbanRequired')
    } else if (!validateIban(formData.creditor_iban)) {
      newErrors.creditor_iban = t('settings.validation.creditorIbanInvalid')
    }

    if (!formData.creditor_address_street?.trim()) {
      newErrors.creditor_address_street = t('settings.validation.streetRequired')
    } else if (formData.creditor_address_street.length > 70) {
      newErrors.creditor_address_street = t('settings.validation.streetTooLong')
    }

    if (!formData.creditor_address_city?.trim()) {
      newErrors.creditor_address_city = t('settings.validation.cityRequired')
    } else if (formData.creditor_address_city.length > 70) {
      newErrors.creditor_address_city = t('settings.validation.cityTooLong')
    }

    if (!formData.creditor_address_country?.trim()) {
      newErrors.creditor_address_country = t('settings.validation.countryRequired')
    } else if (!/^[A-Z]{2}$/.test(formData.creditor_address_country)) {
      newErrors.creditor_address_country = t('settings.validation.countryInvalid')
    }

    if (formData.payment_reference_prefix && formData.payment_reference_prefix.length > 100) {
      newErrors.payment_reference_prefix = t('settings.validation.paymentReferencePrefixTooLong')
    }

    if (!creditorIdLocked && !formData.creditor_id?.trim()) {
      newErrors.creditor_id = t('settings.validation.creditorIdRequired')
    } else if (formData.creditor_id && formData.creditor_id.length > 35) {
      newErrors.creditor_id = t('settings.validation.creditorIdTooLong')
    }

    setFieldErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  // Handle field changes
  const handleFieldChange = (field: keyof SepaConfigFormData, value: string) => {
    let finalValue = value

    if (field === 'creditor_iban') {
      finalValue = value.replace(/\s/g, '').toUpperCase()
    } else if (field === 'creditor_address_country') {
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

      // Initial setup goes through POST (the only method that accepts the
      // creditor_id); every later edit is a PATCH without it.
      const result = creditorIdLocked
        ? await getSepaConfiguration().updateSepaConfig(buildUpdateSepaConfigRequest(formData))
        : await getSepaConfiguration().createSepaConfig(buildCreateSepaConfigRequest(formData))
      setExistingConfig(result)
      setOriginalFormData(formData)
      setFieldErrors({})
      setSuccessMessage(t('settings.sepaSaved'))

      // Clear success message after 5 seconds. Cancel a still-pending timer
      // from an earlier save first, so it cannot clear this newer message.
      if (successMessageTimeoutRef.current) clearTimeout(successMessageTimeoutRef.current)
      successMessageTimeoutRef.current = setTimeout(() => {
        setSuccessMessage(null)
      }, 5000)
    } catch (err: unknown) {
      // A rejected field is named on the field itself; the banner says why the
      // save did not happen.
      const apiFieldErrors = getApiFieldErrors(err)
      if (Object.keys(apiFieldErrors).length > 0) {
        setFieldErrors(apiFieldErrors)
        setError(t('settings.errors.sepaValidation'))
      } else {
        setError(getApiErrorMessage(err, t('settings.errors.saveSepa')))
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

  // Handle instance name field change
  const handleInstanceNameChange = (value: string) => {
    setInstanceName(value)

    if (instanceFieldErrors.instance_name) {
      setInstanceFieldErrors((prev) => {
        const newErrors = { ...prev }
        delete newErrors.instance_name
        return newErrors
      })
    }

    if (instanceSuccessMessage) {
      setInstanceSuccessMessage(null)
    }
  }

  // Validate instance branding form (UC-A64 E1/E2)
  const validateInstanceForm = (): boolean => {
    const newErrors: Record<string, string> = {}

    if (!instanceName.trim()) {
      newErrors.instance_name = t('settings.instance.validation.required')
    } else if (instanceName.length > 100) {
      newErrors.instance_name = t('settings.instance.validation.tooLong')
    }

    setInstanceFieldErrors(newErrors)
    return Object.keys(newErrors).length === 0
  }

  // Handle instance branding save
  const handleSaveInstance = async () => {
    if (!validateInstanceForm()) {
      return
    }

    try {
      setInstanceSaving(true)
      setError(null)
      setInstanceSuccessMessage(null)

      const result = await getInstanceBranding().updateInstanceConfig({ instance_name: instanceName })
      const savedName = result.instance_name ?? instanceName
      setInstanceName(savedName)
      setInstanceOriginalName(savedName)
      setInstanceFieldErrors({})
      setInstanceSuccessMessage(t('settings.instance.saved'))

      // The header, browser tab title and login page must reflect the new
      // name immediately in this session, without a reload (UC-A64
      // postconditions) — refetch the shared context that feeds them.
      await refetchInstanceConfig()

      // Clear success message after 5 seconds, matching the SEPA tab. Cancel
      // a still-pending timer from an earlier save first, so it cannot clear
      // this newer message.
      if (instanceSuccessTimeoutRef.current) clearTimeout(instanceSuccessTimeoutRef.current)
      instanceSuccessTimeoutRef.current = setTimeout(() => {
        setInstanceSuccessMessage(null)
      }, 5000)
    } catch (err: unknown) {
      const apiFieldErrors = getApiFieldErrors(err)
      if (Object.keys(apiFieldErrors).length > 0) {
        setInstanceFieldErrors(apiFieldErrors)
        setError(t('settings.instance.errors.validation'))
      } else {
        setError(getApiErrorMessage(err, t('settings.instance.errors.save')))
      }
    } finally {
      setInstanceSaving(false)
    }
  }

  // Handle instance branding cancel
  const handleCancelInstance = () => {
    setInstanceName(instanceOriginalName)
    setInstanceFieldErrors({})
    setError(null)
  }

  // Tab styles (prototype styling: button group container)
  const tabContainerStyle: React.CSSProperties = {
    display: 'flex',
    background: theme.colors.bg.card,
    borderRadius: '12px',
    padding: '4px',
    gap: '4px',
    border: '1px solid rgba(71,85,105,0.3)',
    maxWidth: '100%',
  }

  const tabStyle = (isActive: boolean) => ({
    flex: isMobile ? 1 : undefined,
    padding: isMobile ? `${theme.spacing.sm} 0` : `${theme.spacing.md} ${theme.spacing.lg}`,
    borderRadius: '8px',
    background: isActive ? theme.colors.semantic.primary : 'transparent',
    color: isActive ? 'white' : theme.colors.text.secondary,
    cursor: 'pointer',
    fontSize: isMobile ? theme.typography.fontSize.xs : theme.typography.fontSize.sm,
    fontWeight: isActive ? theme.typography.fontWeight.semibold : theme.typography.fontWeight.medium,
    transition: `all ${theme.transitions.default}`,
    border: 'none',
    display: 'flex',
    alignItems: 'center',
    justifyContent: 'center',
    gap: isMobile ? '4px' : theme.spacing.sm,
    whiteSpace: 'nowrap' as const,
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
            onClick={() => switchTab('admin-users')}
            style={tabStyle(activeTab === 'admin-users') as any}
          >
            {!isMobile && (
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
                <circle cx="8.5" cy="7" r="4" />
                <circle cx="18.5" cy="7" r="4" />
                <path d="M23 21v-2a4 4 0 0 0-3-3.87" />
              </svg>
            )}
            {t('settings.adminUsers')}
          </button>
          <button
            data-testid="settings-tab-sepa"
            onClick={() => switchTab('sepa')}
            style={tabStyle(activeTab === 'sepa') as any}
          >
            {!isMobile && (
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <rect x="2" y="5" width="20" height="14" rx="2" />
                <line x1="2" y1="10" x2="22" y2="10" />
              </svg>
            )}
            {isMobile ? 'SEPA' : t('settings.sepaConfig')}
          </button>
          <button
            data-testid="settings-tab-terminals"
            onClick={() => switchTab('terminals')}
            style={tabStyle(activeTab === 'terminals') as any}
          >
            {!isMobile && (
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <rect x="2" y="3" width="20" height="14" rx="2" ry="2" />
                <line x1="8" y1="21" x2="16" y2="21" />
                <line x1="12" y1="17" x2="12" y2="21" />
              </svg>
            )}
            {t('settings.terminals')}
          </button>
          <button
            data-testid="settings-tab-security"
            onClick={() => switchTab('security')}
            style={tabStyle(activeTab === 'security') as any}
          >
            {!isMobile && (
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" />
              </svg>
            )}
            {t('settings.security.tab')}
          </button>
          <button
            data-testid="settings-tab-instance"
            onClick={() => switchTab('instance')}
            style={tabStyle(activeTab === 'instance') as any}
          >
            {!isMobile && (
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                <path d="M4 4h16v4H4z" />
                <path d="M9 8v12" />
                <path d="M15 8v12" />
              </svg>
            )}
            {t('settings.instance.tab')}
          </button>
        </div>
      </div>

      {/* Failures from every tab land here, above the tab content, so the
          message stays with the action that caused it (#91). */}
      {error && <Alert variant="danger" message={error} testId="settings-error-message" />}

      {/* An action that changed nothing on screen says so here (#130). */}
      {actionSuccess && (
        <Alert variant="success" message={actionSuccess} dismissible testId="settings-success-message" />
      )}

      {/* SEPA Configuration Tab */}
      {activeTab === 'sepa' && (
        <SepaConfigTab
          creditorIdLocked={creditorIdLocked}
          loading={false}
          saving={saving}
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
          onCreateUser={() => {
            clearModalError()
            setShowCreateAdminModal(true)
          }}
          onEditUser={(admin) => {
            clearModalError()
            setEditingAdmin(admin)
            setEditAdminFormData({
              email: admin.email,
              display_name: admin.display_name,
              locale: (admin.locale === 'en' ? 'en' : 'de') as 'de' | 'en',
            })
            setShowEditAdminModal(true)
          }}
          onResetPassword={handleResetPassword}
          onReset2fa={handleReset2fa}
          onDeactivateUser={handleDeactivateAdmin}
          onReactivateUser={handleReactivateAdmin}
        />
      )}

      {/* Terminals Tab */}
      {activeTab === 'terminals' && (
        <TerminalsTab
          terminals={terminals}
          loading={terminalsLoading}
          onCreateTerminal={() => {
            clearModalError()
            setShowCreateTerminalModal(true)
          }}
          onEditTerminal={(terminal) => {
            clearModalError()
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

      {/* Security self-check (#247): measured, never assumed — so it is fetched
          when the tab is opened rather than cached with the rest of the page. */}
      {activeTab === 'security' && <SecurityCheckTab />}

      {/* Instance Branding Tab (ADR-0034 / UC-A64) */}
      {activeTab === 'instance' && (
        <InstanceBrandingTab
          loading={instanceLoading}
          saving={instanceSaving}
          successMessage={instanceSuccessMessage}
          value={instanceName}
          fieldErrors={instanceFieldErrors}
          onChange={handleInstanceNameChange}
          onSave={handleSaveInstance}
          onCancel={handleCancelInstance}
        />
      )}

      {/* Modals */}
      <CreateAdminModal
        isOpen={showCreateAdminModal}
        formData={createAdminFormData}
        error={modalError}
        fieldErrors={modalFieldErrors}
        onFormChange={(field, value) => {
          clearModalError()
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
        onClose={() => {
          clearModalError()
          setShowCreateAdminModal(false)
          // The form was only cleared after a *successful* create, so a
          // cancelled entry greeted whoever opened the modal next (#131).
          setCreateAdminFormData({ email: '', display_name: '', locale: 'de' })
        }}
      />

      <EditAdminModal
        isOpen={showEditAdminModal}
        formData={editAdminFormData}
        error={modalError}
        fieldErrors={modalFieldErrors}
        onFormChange={(field, value) => {
          clearModalError()
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
        onClose={() => {
          clearModalError()
          setShowEditAdminModal(false)
        }}
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

      <StepUpConfirmDialog
        isOpen={!!resetPasswordConfirm}
        message={t('settings.resetPasswordConfirm')}
        confirmLabel={t('settings.resetPassword')}
        requiresTotp={callerTotpEnabled}
        error={stepUpError}
        onConfirm={handleResetPasswordConfirmed}
        onCancel={() => {
          setResetPasswordConfirm(null)
          setStepUpError(null)
        }}
      />

      <StepUpConfirmDialog
        isOpen={!!reset2faConfirm}
        message={t('settings.reset2faConfirm')}
        confirmLabel={t('common.confirm')}
        requiresTotp={callerTotpEnabled}
        error={stepUpError}
        onConfirm={handleReset2faConfirmed}
        onCancel={() => {
          setReset2faConfirm(null)
          setStepUpError(null)
        }}
      />

      <CreateTerminalModal
        isOpen={showCreateTerminalModal}
        formData={createTerminalFormData}
        error={modalError}
        fieldErrors={modalFieldErrors}
        onFormChange={(field, value) => {
          clearModalError()
          setCreateTerminalFormData((prev) => ({ ...prev, [field]: value }))
        }}
        onSubmit={handleCreateTerminal}
        onClose={() => {
          clearModalError()
          setShowCreateTerminalModal(false)
          // Same as the admin create form: cancelling now discards the entry
          // instead of holding it until the next open (#131).
          setCreateTerminalFormData({ name: '', device_id: '' })
        }}
      />

      <EditTerminalModal
        isOpen={showEditTerminalModal}
        formData={editTerminalFormData}
        error={modalError}
        fieldErrors={modalFieldErrors}
        onFormChange={(field, value) => {
          clearModalError()
          setEditTerminalFormData((prev) => ({ ...prev, [field]: value }))
        }}
        onSubmit={handleUpdateTerminal}
        onClose={() => {
          clearModalError()
          setShowEditTerminalModal(false)
        }}
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
