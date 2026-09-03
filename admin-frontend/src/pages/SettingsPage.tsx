/**
 * Settings Page
 * Configuration management for system settings (SEPA configuration, admin users)
 */

import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useApiError } from '../hooks/useApiError'
import { PageHeader } from '../components/layout/PageHeader'
import { theme } from '../styles/design-system'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { getSepaConfiguration } from '../api/generated/sepa-configuration/sepa-configuration'
import { getAdminUsers } from '../api/generated/admin-users/admin-users'
import { getTerminals } from '../api/generated/terminals/terminals'
import { getAuthentication } from '../api/generated/authentication/authentication'
import { getInstanceBranding } from '../api/generated/instance-branding/instance-branding'
import { getCreditLimits } from '../api/generated/credit-limits/credit-limits'
import { getProfile } from '../auth/session'
import { useInstanceConfig } from '../context/InstanceConfigContext'
import { useAuth } from '../context/AuthContext'
import { settingsTabsFor, firstSettingsTab } from '../utils/adminRoles'
import type { SepaConfig, AdminUser as GeneratedAdminUser, Terminal as GeneratedTerminal } from '../api/generated'
import { AdminRole } from '../api/generated/adminRole'

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
import { InvitationSentModal } from '../components/modals/InvitationSentModal'
import { ConfirmDialog } from '../components/modals/ConfirmDialog'
import { StepUpConfirmDialog, type StepUpCredentials } from '../components/modals/StepUpConfirmDialog'
import { TerminalsTab } from '../components/settings/TerminalsTab'
import { SecurityCheckTab } from '../components/settings/SecurityCheckTab'
import { CredentialsTab } from '../components/settings/CredentialsTab'
import { InstanceBrandingTab } from '../components/settings/InstanceBrandingTab'
import { MailSettingsTab } from '../components/settings/MailSettingsTab'
import {
  CreditLimitsTab,
  centsToEuros,
  eurosToCents,
} from '../components/settings/CreditLimitsTab'
import { CreateTerminalModal } from '../components/modals/CreateTerminalModal'
import { EditTerminalModal } from '../components/modals/EditTerminalModal'
import { TokenDisplayModal } from '../components/modals/TokenDisplayModal'
import { validateIban } from '../utils/iban'
import { getApiFieldErrors } from '../utils/apiErrors'
import { MAX_PER_PAGE, loadAllPages } from '../utils/pagination'
import {
  buildCreateSepaConfigRequest,
  buildUpdateSepaConfigRequest,
  isCreditorIdSet,
  type SepaConfigFormData,
} from '../utils/sepaConfig'

/** The Settings tabs, named as `SETTINGS_TAB_ROLES` and the test IDs name them. */
type SettingsTab =
  | 'admin-users'
  | 'sepa'
  | 'terminals'
  | 'security'
  | 'credentials'
  | 'instance'
  | 'mail'
  | 'limits'

export function SettingsPage() {
  const { t } = useTranslation()
  const { apiErrorMessage } = useApiError()
  const breakpoint = useBreakpoint()
  const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'
  const { refetch: refetchInstanceConfig } = useInstanceConfig()

  // State management
  // Which tabs this caller may see (ADR-0047, #562). `/settings` is TREASURY
  // now, and the boundary moved one level down: a Kassenwart reaches the page
  // for the Limits tab and finds nothing else on it. Default-deny lives in
  // SETTINGS_TAB_ROLES, so a tab added without a classification is invisible
  // to the lesser roles until somebody grants it in a diff a reviewer sees.
  const { roles } = useAuth()
  const visibleTabs = useMemo(() => settingsTabsFor(roles) as SettingsTab[], [roles])

  // Null until the roles are known: the landing tab is the first one the
  // caller may actually open, not a hardcoded default that answers 403 for
  // everybody but `admin`. Every tab-keyed load below is therefore also held
  // until then, which is what keeps a Kassenwart from firing an admin-only
  // request on mount.
  const [activeTab, setActiveTab] = useState<SettingsTab | null>(null)
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
    mandate_template_url: '',
  })
  const [formData, setFormData] = useState<SepaConfigFormData>({
    creditor_id: '',
    creditor_name: '',
    creditor_iban: '',
    creditor_address_street: '',
    creditor_address_city: '',
    creditor_address_country: '',
    payment_reference_prefix: '',
    mandate_template_url: '',
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
    roles: AdminRole[]
  }>({
    email: '',
    display_name: '',
    locale: 'de',
    roles: [],
  })
  const [editAdminFormData, setEditAdminFormData] = useState<{
    email: string
    display_name: string
    locale: 'de' | 'en'
    roles: AdminRole[]
  }>({
    email: '',
    display_name: '',
    locale: 'de',
    roles: [],
  })
  // The role set the account held when the Edit modal opened — the step-up
  // fields fire only once `editAdminFormData.roles` has moved away from this.
  const [editAdminInitialRoles, setEditAdminInitialRoles] = useState<AdminRole[]>([])
  const [generatedPassword, setGeneratedPassword] = useState<string | null>(null)
  const [showPasswordModal, setShowPasswordModal] = useState(false)
  // The invitation an account creation or a resend just produced (migration
  // 058). Shown once, with the link, and then dropped — there is no endpoint
  // that can return it again.
  const [sentInvitation, setSentInvitation] = useState<
    { url: string | null; email: string | null; expiresAt: string | null } | null
  >(null)
  // Which pending account a resend is being confirmed for; the dialog collects
  // the caller's own step-up credential, as the password reset does.
  const [resendInvitationConfirm, setResendInvitationConfirm] = useState<string | null>(null)
  const [deactivateConfirm, setDeactivateConfirm] = useState<string | null>(null)
  // The whole account, not just its id: the confirmation names the email, and
  // once the row is gone there is nothing left to look it up from.
  const [deleteAdminConfirm, setDeleteAdminConfirm] = useState<AdminUser | null>(null)
  const [reset2faConfirm, setReset2faConfirm] = useState<string | null>(null)
  const [resetPasswordConfirm, setResetPasswordConfirm] = useState<string | null>(null)
  // Whether the *caller* (not the target being acted on) has 2FA enabled —
  // decides whether the step-up dialog asks for a TOTP code (#337).
  const [callerTotpEnabled, setCallerTotpEnabled] = useState(false)
  // Who is signed in. Read from the API rather than localStorage so the
  // Administrators tab can tell which row is the caller's own account and
  // withhold the one action that would sign them out (#382).
  const [callerAdminId, setCallerAdminId] = useState<string | null>(null)
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
  const [terminalConfirmAction, setTerminalConfirmAction] = useState<{ type: 'deactivate' | 'revoke'; id: string } | null>(null)
  // Rotation mints a credential, so it asks for a step-up rather than a plain
  // yes/no (#395) — and keeps its own dialog error, so a wrong password can be
  // corrected without losing which terminal was being rotated.
  const [rotateTokenTarget, setRotateTokenTarget] = useState<string | null>(null)

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

  // Credit limits (ADR-0047 / UC-A65) — the club's ceiling and warning band,
  // the Kassenwart's own slice of this page. Held as the strings the inputs
  // carry; the euro/cent conversion is the tab's, so a half-typed amount is
  // not rounded under the treasurer's cursor.
  const [limitsForm, setLimitsForm] = useState({ limitEuros: '', warnPercent: '' })
  const [limitsOriginal, setLimitsOriginal] = useState({ limitEuros: '', warnPercent: '' })
  const [limitsLoading, setLimitsLoading] = useState(false)
  const [limitsSuccess, setLimitsSuccess] = useState<string | null>(null)
  const [limitsFieldErrors, setLimitsFieldErrors] = useState<Record<string, string>>({})
  const limitsSuccessTimeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null)

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
          // The IBAN is deliberately not prefilled: the GET masks it (#392), so
          // the only value available here is `DE89****3000` — round-tripping
          // that on save would be refused. Blank means "keep the stored one";
          // the masked value is shown beside the field instead.
          const formValues: SepaConfigFormData = {
            creditor_id: config.creditor_id,
            creditor_name: config.creditor_name,
            creditor_iban: '',
            creditor_address_street: config.creditor_address_street,
            creditor_address_city: config.creditor_address_city,
            creditor_address_country: config.creditor_address_country,
            payment_reference_prefix: config.payment_reference_prefix,
            mandate_template_url: config.mandate_template_url ?? undefined,
          }
          setFormData(formValues)
          setOriginalFormData(formValues)
        }

        setError(null)
      } catch (err: unknown) {
        setError(apiErrorMessage(err, t('settings.errors.loadSettings')))
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
      if (limitsSuccessTimeoutRef.current) clearTimeout(limitsSuccessTimeoutRef.current)
    }
  }, [])

  // The tab the caller lands on, once the roles are known. Also the correction
  // when a role change takes the open tab away — leaving it selected would
  // render a panel whose requests the server refuses.
  useEffect(() => {
    if (visibleTabs.length === 0) return
    if (activeTab === null || !visibleTabs.includes(activeTab)) {
      setActiveTab((firstSettingsTab(roles) as SettingsTab) ?? null)
    }
  }, [visibleTabs, activeTab, roles])

  // Load the club's credit limits when the Limits tab is active
  useEffect(() => {
    if (activeTab === 'limits') {
      loadCreditLimits()
    }
  }, [activeTab])

  // Load admin users when admin-users tab is active
  useEffect(() => {
    if (activeTab === 'admin-users') {
      loadAdminUsers()
    }
  }, [activeTab])

  // The step-up dialogs (2FA reset, password reset, #337) need to know
  // whether the *signed-in* admin has 2FA enabled, to decide whether to ask
  // for a TOTP code alongside the password. The same profile also names the
  // caller, which the Administrators tab needs to mark their own row (#382).
  // Fetched once — neither changes over the life of the page.
  useEffect(() => {
    getProfile()
      .then((profile) => {
        setCallerTotpEnabled(!!profile.totp_enabled)
        setCallerAdminId(profile.id)
      })
      .catch(() => {
        setCallerTotpEnabled(false)
        setCallerAdminId(null)
      })
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
    setError(apiErrorMessage(err, t(fallbackKey)))
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
    setModalError(apiErrorMessage(err, t(fallbackKey)))
    setModalFieldErrors(getApiFieldErrors(err))
  }

  const clearModalError = () => {
    setModalError(null)
    setModalFieldErrors({})
  }

  const switchTab = (tab: SettingsTab) => {
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

  const loadCreditLimits = async () => {
    try {
      setLimitsLoading(true)
      const config = await getCreditLimits().getCreditLimitConfig()
      const loaded = {
        limitEuros: centsToEuros(config.default_limit_cents ?? 0),
        warnPercent: String(config.warn_threshold_percent ?? 80),
      }
      setLimitsForm(loaded)
      setLimitsOriginal(loaded)
      setLimitsFieldErrors({})
    } catch (err: unknown) {
      reportError(err, 'settings.limits.errors.load')
    } finally {
      setLimitsLoading(false)
    }
  }

  const handleSaveCreditLimits = async () => {
    const cents = eurosToCents(limitsForm.limitEuros)
    const percent = Number(limitsForm.warnPercent)

    // Shape checked here, bounds left to the server: it owns the rule (a
    // negative ceiling is refused rather than read as unlimited), and its
    // message is what the field then shows.
    const localErrors: Record<string, string> = {}
    if (!Number.isFinite(cents)) localErrors.default_limit_cents = t('settings.limits.errors.validation')
    if (!Number.isInteger(percent)) localErrors.warn_threshold_percent = t('settings.limits.errors.validation')
    if (Object.keys(localErrors).length > 0) {
      setLimitsFieldErrors(localErrors)
      return
    }

    try {
      setSaving(true)
      const saved = await getCreditLimits().updateCreditLimitConfig({
        default_limit_cents: cents,
        warn_threshold_percent: percent,
      })
      const applied = {
        limitEuros: centsToEuros(saved.default_limit_cents ?? cents),
        warnPercent: String(saved.warn_threshold_percent ?? percent),
      }
      setLimitsForm(applied)
      setLimitsOriginal(applied)
      setLimitsFieldErrors({})
      setError(null)
      setLimitsSuccess(t('settings.limits.saved'))
      if (limitsSuccessTimeoutRef.current) clearTimeout(limitsSuccessTimeoutRef.current)
      limitsSuccessTimeoutRef.current = setTimeout(() => setLimitsSuccess(null), 5000)
    } catch (err: unknown) {
      setLimitsFieldErrors(getApiFieldErrors(err))
      reportError(err, 'settings.limits.errors.save')
    } finally {
      setSaving(false)
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

  const handleCreateAdmin = async (credentials: StepUpCredentials) => {
    try {
      const result = await getAdminUsers().createAdminUser({
        email: createAdminFormData.email,
        display_name: createAdminFormData.display_name,
        locale: createAdminFormData.locale,
        roles: createAdminFormData.roles,
        ...credentials,
      })
      // The invitation, never a password: the account is created with none,
      // and the link in the mail is what gives it one (migration 058).
      setSentInvitation({
        url: result.invitation?.url ?? null,
        email: result.invitation?.email ?? result.admin?.email ?? null,
        expiresAt: result.invitation?.expires_at ?? null,
      })
      setShowCreateAdminModal(false)
      clearModalError()
      setCreateAdminFormData({ email: '', display_name: '', locale: 'de', roles: [] })
      await loadAdminUsers()
    } catch (err: unknown) {
      reportModalError(err, 'settings.errors.createAdminUser')
    }
  }

  const handleUpdateAdmin = async (credentials?: StepUpCredentials) => {
    if (!editingAdmin) return
    try {
      // No account can change its own roles (CONTEXT.md's Role entry) — the
      // backend refuses `roles` outright on a self-edit, so it is left out of
      // the request entirely rather than resent unchanged.
      const isSelf = editingAdmin.id === callerAdminId
      await getAdminUsers().updateAdminUser(editingAdmin.id!, {
        email: editAdminFormData.email || undefined,
        display_name: editAdminFormData.display_name || undefined,
        locale: editAdminFormData.locale || undefined,
        roles: isSelf ? undefined : editAdminFormData.roles,
        ...credentials,
      })
      setShowEditAdminModal(false)
      setEditingAdmin(null)
      clearModalError()
      setEditAdminFormData({ email: '', display_name: '', locale: 'de', roles: [] })
      setEditAdminInitialRoles([])
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

  const handleDeleteAdmin = (admin: AdminUser) => {
    setDeleteAdminConfirm(admin)
  }

  /**
   * Deletion is irreversible and the backend's rule is stricter than the one
   * the button can see (it also refuses an account that authored an audit
   * row), so a refusal here is expected rather than exceptional — `reportError`
   * resolves the reason code to a sentence telling the admin to deactivate
   * instead.
   */
  const handleDeleteAdminConfirmed = async () => {
    if (!deleteAdminConfirm) return
    const id = deleteAdminConfirm.id
    setDeleteAdminConfirm(null)
    try {
      await getAdminUsers().deleteAdminUser(id)
      await loadAdminUsers()
    } catch (err: unknown) {
      reportError(err, 'settings.errors.deleteAdminUser')
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
      setStepUpError(apiErrorMessage(err, t('settings.errors.resetPassword')))
    }
  }

  /**
   * Sending a colleague a fresh invitation revokes the one they already have,
   * so it is confirmed rather than fired from a bare icon — and, like the
   * password reset above, it collects the caller's own step-up credential
   * first: this mints a way into somebody else's account.
   */
  const handleResendInvitation = (id: string) => {
    setStepUpError(null)
    setResendInvitationConfirm(id)
  }

  const handleResendInvitationConfirmed = async (credentials: StepUpCredentials) => {
    if (!resendInvitationConfirm) return
    try {
      const result = await getAdminUsers().resendAdminInvitation(resendInvitationConfirm, credentials)
      setResendInvitationConfirm(null)
      setStepUpError(null)
      setSentInvitation({
        url: result.invitation?.url ?? null,
        email: result.invitation?.email ?? null,
        expiresAt: result.invitation?.expires_at ?? null,
      })
      await loadAdminUsers()
    } catch (err: unknown) {
      setStepUpError(apiErrorMessage(err, t('settings.errors.resendInvitation')))
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
      setStepUpError(apiErrorMessage(err, t('settings.errors.reset2fa')))
    }
  }

  const handleCreateTerminal = async (credentials: StepUpCredentials) => {
    try {
      const result = await getTerminals().createTerminal({ ...createTerminalFormData, ...credentials })
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
    setStepUpError(null)
    setRotateTokenTarget(id)
  }

  const handleRotateTokenConfirmed = async (credentials: StepUpCredentials) => {
    if (!rotateTokenTarget) return
    try {
      const result = await getTerminals().rotateTerminalToken(rotateTokenTarget, credentials)
      setRotateTokenTarget(null)
      setStepUpError(null)
      setGeneratedToken(result.api_token ?? null)
      setShowTokenModal(true)
      await loadTerminals()
    } catch (err: unknown) {
      setStepUpError(apiErrorMessage(err, t('settings.errors.rotateTerminal')))
    }
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

    // Overwrite-only (#392): a blank field keeps the stored IBAN, so it is only
    // required when there is nothing stored to keep, and only checked when the
    // admin actually typed a replacement.
    if (!formData.creditor_iban?.trim()) {
      if (!existingConfig?.creditor_iban?.trim()) {
        newErrors.creditor_iban = t('settings.validation.creditorIbanRequired')
      }
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

    // Not required at save time (#456): a club can save creditor details
    // before the externally hosted form exists. SepaExportService blocks
    // export until this is set, and the dashboard warns in the meantime.
    if (formData.mandate_template_url && formData.mandate_template_url.length > 255) {
      newErrors.mandate_template_url = t('settings.validation.mandateTemplateUrlTooLong')
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
        setError(apiErrorMessage(err, t('settings.errors.saveSepa')))
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
        setError(apiErrorMessage(err, t('settings.instance.errors.save')))
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

  // Tab styles (prototype styling: button group container). Seven tabs don't
  // fit a phone-width screen, so the row scrolls horizontally instead of
  // being silently clipped by MainLayout's `overflowX: hidden`.
  const tabContainerStyle: React.CSSProperties = {
    display: 'flex',
    background: theme.colors.bg.card,
    borderRadius: '12px',
    padding: '4px',
    gap: '4px',
    border: '1px solid rgba(71,85,105,0.3)',
    maxWidth: '100%',
    overflowX: 'auto',
    WebkitOverflowScrolling: 'touch',
    scrollbarWidth: 'none',
    msOverflowStyle: 'none',
  }

  const tabStyle = (isActive: boolean) => ({
    // On mobile the row scrolls instead of squeezing seven tabs to fit, so
    // each tab keeps its natural width rather than sharing the row via flex:1.
    flexShrink: isMobile ? 0 : undefined,
    padding: isMobile ? `${theme.spacing.sm} ${theme.spacing.md}` : `${theme.spacing.md} ${theme.spacing.lg}`,
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
    <div data-testid="settings-page">
      <PageHeader title={t('settings.title')} />

      {/* Tabs Navigation */}
      <div style={{ marginBottom: theme.spacing.xl }}>
        <style>{`[data-testid="settings-tabs"]::-webkit-scrollbar { display: none; }`}</style>
        <div
          data-testid="settings-tabs"
          style={tabContainerStyle}
        >
          {visibleTabs.includes('admin-users') && (
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
          )}
          {visibleTabs.includes('sepa') && (
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
          )}
          {visibleTabs.includes('terminals') && (
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
          )}
          {visibleTabs.includes('security') && (
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
          )}
          {visibleTabs.includes('credentials') && (
            <button
              data-testid="settings-tab-credentials"
              onClick={() => switchTab('credentials')}
              style={tabStyle(activeTab === 'credentials') as any}
            >
              {!isMobile && (
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <path d="M21 2l-2 2m-3.5 3.5a5.5 5.5 0 1 1-7.78 7.78 5.5 5.5 0 0 1 7.78-7.78z" />
                  <path d="M15.5 7.5L19 4l3 3-3.5 3.5" />
                </svg>
              )}
              {t('settings.credentials.tab')}
            </button>
          )}
          {visibleTabs.includes('instance') && (
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
          )}
          {visibleTabs.includes('mail') && (
            <button
              data-testid="settings-tab-mail"
              onClick={() => switchTab('mail')}
              style={tabStyle(activeTab === 'mail') as any}
            >
              {!isMobile && (
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <rect x="2" y="4" width="20" height="16" rx="2" />
                  <polyline points="2 7 12 14 22 7" />
                </svg>
              )}
              {t('settings.mail.tab')}
            </button>
          )}
          {visibleTabs.includes('limits') && (
            <button
              data-testid="settings-tab-limits"
              onClick={() => switchTab('limits')}
              style={tabStyle(activeTab === 'limits') as any}
            >
              {!isMobile && (
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
                  <path d="M12 2v20" />
                  <path d="M5 7h9a3 3 0 0 1 0 6H5" />
                  <path d="M19 17H8" />
                </svg>
              )}
              {t('settings.limits.tab')}
            </button>
          )}
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
          storedCreditorIban={existingConfig?.creditor_iban}
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
          currentAdminId={callerAdminId}
          onCreateUser={() => {
            clearModalError()
            setShowCreateAdminModal(true)
          }}
          onEditUser={(admin) => {
            clearModalError()
            setEditingAdmin(admin)
            const roles = admin.roles ?? []
            setEditAdminFormData({
              email: admin.email,
              display_name: admin.display_name,
              locale: (admin.locale === 'en' ? 'en' : 'de') as 'de' | 'en',
              roles,
            })
            setEditAdminInitialRoles(roles)
            setShowEditAdminModal(true)
          }}
          onResetPassword={handleResetPassword}
          onResendInvitation={handleResendInvitation}
          onReset2fa={handleReset2fa}
          onDeactivateUser={handleDeactivateAdmin}
          onReactivateUser={handleReactivateAdmin}
          onDeleteUser={handleDeleteAdmin}
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
          onAnomalyAcknowledged={loadTerminals}
        />
      )}

      {/* Security self-check (#247): measured, never assumed — so it is fetched
          when the tab is opened rather than cached with the rest of the page. */}
      {activeTab === 'security' && <SecurityCheckTab />}

      {/* IBAN encryption keys and their lifecycle (#394). Like the self-check
          it fetches when its tab is opened: warning tiers are computed at
          request time, so a cached list would be a stale warning. */}
      {activeTab === 'credentials' && <CredentialsTab callerTotpEnabled={callerTotpEnabled} />}

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

      {/* Mail settings (#407, ADR-0038). Self-contained like SecurityCheckTab:
          it owns a form, a measured transport panel and the test-mail action.
          Nothing is threaded in: it stopped needing callerTotpEnabled when the
          cron-secret rotation left it for the installer (#744). */}
      {activeTab === 'mail' && <MailSettingsTab />}

      {/* Credit Limits Tab (ADR-0047, UC-A65) */}
      {activeTab === 'limits' && (
        <CreditLimitsTab
          loading={limitsLoading}
          saving={saving}
          successMessage={limitsSuccess}
          limitEuros={limitsForm.limitEuros}
          warnPercent={limitsForm.warnPercent}
          fieldErrors={limitsFieldErrors}
          onLimitChange={(limitEuros) => setLimitsForm((form) => ({ ...form, limitEuros }))}
          onWarnPercentChange={(warnPercent) => setLimitsForm((form) => ({ ...form, warnPercent }))}
          onSave={handleSaveCreditLimits}
          onCancel={() => {
            setLimitsForm(limitsOriginal)
            setLimitsFieldErrors({})
          }}
        />
      )}

      {/* Modals */}
      <CreateAdminModal
        isOpen={showCreateAdminModal}
        formData={createAdminFormData}
        error={modalError}
        fieldErrors={modalFieldErrors}
        requiresTotp={callerTotpEnabled}
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
        onRolesChange={(roles) => {
          clearModalError()
          setCreateAdminFormData((prev) => ({ ...prev, roles }))
        }}
        onSubmit={handleCreateAdmin}
        onClose={() => {
          clearModalError()
          setShowCreateAdminModal(false)
          // The form was only cleared after a *successful* create, so a
          // cancelled entry greeted whoever opened the modal next (#131).
          setCreateAdminFormData({ email: '', display_name: '', locale: 'de', roles: [] })
        }}
      />

      <EditAdminModal
        isOpen={showEditAdminModal}
        formData={editAdminFormData}
        initialRoles={editAdminInitialRoles}
        isSelf={editingAdmin?.id === callerAdminId}
        requiresTotp={callerTotpEnabled}
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
        onRolesChange={(roles) => {
          clearModalError()
          setEditAdminFormData((prev) => ({ ...prev, roles }))
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

      <InvitationSentModal
        isOpen={sentInvitation !== null}
        url={sentInvitation?.url ?? null}
        email={sentInvitation?.email ?? null}
        expiresAt={sentInvitation?.expiresAt ?? null}
        onClose={() => setSentInvitation(null)}
      />

      <StepUpConfirmDialog
        isOpen={!!resendInvitationConfirm}
        message={t('settings.resendInvitationConfirm')}
        confirmLabel={t('settings.resendInvitation')}
        requiresTotp={callerTotpEnabled}
        error={stepUpError}
        onConfirm={handleResendInvitationConfirmed}
        onCancel={() => {
          setResendInvitationConfirm(null)
          setStepUpError(null)
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

      <ConfirmDialog
        isOpen={!!deleteAdminConfirm}
        message={t('settings.deleteAdminConfirm', { email: deleteAdminConfirm?.email ?? '' })}
        confirmLabel={t('common.delete')}
        variant="danger"
        onConfirm={handleDeleteAdminConfirmed}
        onCancel={() => setDeleteAdminConfirm(null)}
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
        requiresTotp={callerTotpEnabled}
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
            : t('settings.revokeTerminalConfirm')
        }
        confirmLabel={
          terminalConfirmAction?.type === 'deactivate' ? t('common.deactivate') : t('common.confirm')
        }
        variant="danger"
        onConfirm={handleTerminalConfirmAction}
        onCancel={() => setTerminalConfirmAction(null)}
      />

      <StepUpConfirmDialog
        isOpen={rotateTokenTarget !== null}
        title={t('settings.rotateToken')}
        message={t('settings.rotateTokenConfirm')}
        confirmLabel={t('common.confirm')}
        requiresTotp={callerTotpEnabled}
        error={stepUpError}
        onConfirm={handleRotateTokenConfirmed}
        onCancel={() => {
          setRotateTokenTarget(null)
          setStepUpError(null)
        }}
      />
    </div>
  )
}
