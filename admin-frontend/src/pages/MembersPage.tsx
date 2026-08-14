/**
 * Members Page
 * Member management (list, create, edit, delete)
 */

import React, { useCallback, useEffect, useState, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { StatCard } from '../components/common/StatCard'
import { PageActionButton } from '../components/common/PageActionButton'
import { PageHeader } from '../components/layout/PageHeader'
import { theme } from '../styles/design-system'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { MobileToolbar } from '../components/layout/MobileToolbar'
import { MembersTabs } from '../components/members/MembersTabs'
import { useExcludedFromCollection } from '../hooks/useExcludedFromCollection'
import { useFormatters } from '../hooks/useFormatters'
import { UsersIcon, BankIcon, CalendarIcon, EditIcon, PlusIcon, DownloadIcon, ScanIcon } from '../components/icons'
import { downloadBlob } from '../api/client'
import { getMembers as getMembersFactory } from '../api/generated/members/members'
import { getDashboard } from '../api/generated/dashboard/dashboard'
import type { Member, MemberListItem, ListMembersParams, ListMembersStatus, ListMembersSepaStatus, ListMembersHasCardUid, MemberCreateRequest, MemberUpdateRequest } from '../api/generated'
// TableSearchToolbar is available but not currently used
// import { TableSearchToolbar } from '../components/tables/TableSearchToolbar'
import { MobileFilterRow } from '../components/tables/MobileFilterRow'
import { PaginationToolbar } from '../components/tables/PaginationToolbar'
import { SortableTableHeader } from '../components/tables/SortableTableHeader'
import { StatusToggleCell } from '../components/tables/StatusToggleCell'
import { Toggle } from '../components/common/Toggle'
import { TableCell } from '../components/tables/TableCell'
import { LanguageSelector } from '../components/forms/LanguageSelector'
import { validateIban } from '../utils/iban'
import { toIsoDate } from '../utils/dates'
import { buildMemberSortBy, type MemberSortKey } from '../utils/memberSort'
import { useBankName } from '../hooks/useBankName'
import { useLatestRequest } from '../hooks/useLatestRequest'
import { useListQuery } from '../hooks/useListQuery'
import { ValidationIndicator } from '../components/forms/ValidationIndicator'
import { MandateDocumentSection } from '../components/MandateDocumentSection'
import { ConfirmDialog } from '../components/modals/ConfirmDialog'
import { getMandateDocument } from '../api/generated/mandate-document/mandate-document'
import type { ExtractionResult } from '../api/generated'
import {
  tableWrapperStyles,
  tableElementStyles,
  headerRowStyle,
  headerCellBaseStyle,
  tableColors,
  tableSpacing,
  getRowStyle,
} from '../styles/tableTokens'

const PER_PAGE = 20

/**
 * A card UID is 8–20 hex digits, the same bounds the backend enforces
 * (`min:8`, `max:20`, `regex:/^[0-9A-F]+$/`). The field rendered the complaint
 * long before `handleSubmit` checked for it, so a four-character UID went to
 * the API anyway (#131).
 */
const CARD_UID_PATTERN = /^[0-9A-F]{8,20}$/

function normalizeCardUid(raw: string | null | undefined): string {
  if (!raw) return ''
  // Strip common separators (hyphens, spaces, colons), then remove non-hex chars
  const cleaned = raw.toUpperCase().replace(/[\s\-:]/g, '').replace(/[^0-9A-F]/g, '')
  // Only use the result if it has enough hex chars to be a plausible UID (≥4)
  return cleaned.length >= 4 ? cleaned : ''
}

/**
 * Name used to tell one row's actions apart from another's. Icon-only buttons
 * repeat once per row, so "Edit" alone leaves a screen reader with a list of
 * identical controls; the member's name is what makes each one addressable.
 */
function memberName(member: Pick<MemberListItem, 'first_name' | 'last_name'>): string {
  return `${member.first_name ?? ''} ${member.last_name ?? ''}`.trim()
}

interface MemberFilters {
  status: 'all' | 'active' | 'inactive'
  cardUid: 'all' | 'with' | 'without'
  sepaStatus: 'all' | 'valid' | 'invalid'
}

export function MembersPage() {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const breakpoint = useBreakpoint()
  // The dashboard metrics are a second, independent stream, so they get their
  // own abort slot — the member list's lives inside useListQuery (#96).
  const metricsRequest = useLatestRequest()
  // The excluded tab's badge is the figure that makes the tab worth clicking,
  // so it has to be readable from the roster — a count only visible once you
  // are already on the page it advertises is no invitation at all. The hook
  // owns its own abort slots, independent of this page's list query.
  const { excludedCount, loading: excludedLoading } = useExcludedFromCollection()
  // Null means "not known", which is not the same as zero: a treasurer reading
  // "0,00 €" concludes nothing is outstanding, so a failed metrics load has to
  // render "—" rather than a number nobody computed (#132).
  const [activeMembersCount, setActiveMembersCount] = useState<number | null>(null)
  const [totalBalance, setTotalBalance] = useState<number | null>(null)
  const [lastSettlementDate, setLastSettlementDate] = useState<string | null>(null)
  const [metricsFailed, setMetricsFailed] = useState(false)
  const [showModal, setShowModal] = useState(false)
  const [editingMember, setEditingMember] = useState<Member | null>(null)

  // One query, one fetch path: the loader effect and every post-mutation reload
  // go through the same state, so a reload can no longer drop the filters (#121).
  const list = useListQuery<MemberListItem, MemberFilters, MemberSortKey>({
    initialFilters: { status: 'all', cardUid: 'all', sepaStatus: 'all' },
    initialSortKey: 'created_at',
    initialSortDirection: 'desc',
    initialPageSize: PER_PAGE,
    fetcher: async ({ page, pageSize, sortKey, sortDirection, search, filters, signal }) => {
      const params: ListMembersParams = {
        page,
        per_page: pageSize,
        sort_by: buildMemberSortBy(sortKey, sortDirection),
      }
      if (search) params.search = search
      if (filters.status !== 'all') params.status = filters.status as ListMembersStatus
      if (filters.sepaStatus !== 'all') params.sepa_status = filters.sepaStatus as ListMembersSepaStatus
      if (filters.cardUid !== 'all') params.has_card_uid = filters.cardUid as ListMembersHasCardUid

      const response = await getMembersFactory().listMembers(params, { signal })
      return { items: response.data ?? [], total: response.pagination?.total ?? 0 }
    },
    parseError: (err) => (err instanceof Error ? err.message : t('members.errors.load')),
  })

  const { items: members, total: totalMembers, totalPages, loading, error, setError, search } = list
  const { status: filterIsActive, cardUid: filterCardUid, sepaStatus: filterSepaStatus } = list.filters
  const [formData, setFormData] = useState({
    first_name: '',
    last_name: '',
    email: '',
    iban: '',
    account_holder_name: '',
    mandate_reference: '',
    mandate_signed_at: '',
    preferred_language: 'de',
    card_uid: '',
  })
  const [formErrors, setFormErrors] = useState<Record<string, string>>({})
  // Removing the stored account is its own act, because a blank IBAN field now
  // means "keep" (#392). Reset wherever the form is opened or cleared.
  const [removeStoredIban, setRemoveStoredIban] = useState(false)
  const [extractedFields, setExtractedFields] = useState<ExtractionResult | null>(null)
  const [preExtractionFormData, setPreExtractionFormData] = useState<typeof formData | null>(null)
  const [scanExtracting, setScanExtracting] = useState(false)
  const scanInputRef = useRef<HTMLInputElement>(null)
  const { bankName, isLoading: isBankNameLoading } = useBankName(formData.iban)

  const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'
  const [showMobileFilters, setShowMobileFilters] = useState(false)
  const [exporting, setExporting] = useState(false)
  const [anonymizeConfirm, setAnonymizeConfirm] = useState<MemberListItem | null>(null)
  // The member the deactivation dialog is asking about. On the mobile card list
  // the toggle sits beside the name, so a mistap used to cut a member off from
  // the terminal with no way to notice it had happened (#130).
  const [deactivateConfirm, setDeactivateConfirm] = useState<MemberListItem | null>(null)

  const mobileFilterCount = [
    filterIsActive !== 'all' ? 1 : 0,
    filterCardUid !== 'all' ? 1 : 0,
    filterSepaStatus !== 'all' ? 1 : 0,
  ].reduce((a, b) => a + b, 0)

  const mobileSortOptions = [
    { value: 'last_name_asc', label: t('members.sortName'), direction: 'asc' as const },
    { value: 'last_name_desc', label: t('members.sortNameDesc'), direction: 'desc' as const },
    { value: 'card_uid_asc', label: t('members.sortCard'), direction: 'asc' as const },
    { value: 'balance_desc', label: t('members.sortBalanceDesc'), direction: 'desc' as const },
    { value: 'balance_asc', label: t('members.sortBalanceAsc'), direction: 'asc' as const },
    { value: 'created_at_desc', label: t('members.sortNewest'), direction: 'desc' as const },
    { value: 'created_at_asc', label: t('members.sortOldest'), direction: 'asc' as const },
  ]

  const mobileSortValue = list.sortValue

  // Load dashboard metrics (active members count, outstanding balance, last settlement date)
  const loadDashboardMetrics = useCallback(async (signal: AbortSignal) => {
    try {
      const dashboard = await getDashboard().getDashboardMetrics({ signal })
      if (signal.aborted) return
      setActiveMembersCount(dashboard.metrics?.active_members ?? null)
      setTotalBalance(dashboard.metrics?.outstanding_balance_cents ?? null)
      setLastSettlementDate(dashboard.system_status?.last_settlement_date ?? null)
      setMetricsFailed(false)
    } catch {
      if (signal.aborted) return
      // The cards keep their "—" and say so above; the member list below is a
      // separate stream and stays usable.
      setActiveMembersCount(null)
      setTotalBalance(null)
      setLastSettlementDate(null)
      setMetricsFailed(true)
    }
  }, [])

  useEffect(() => {
    loadDashboardMetrics(metricsRequest.next())
    return () => metricsRequest.abort()
  }, [loadDashboardMetrics, metricsRequest])

  // Handle GDPR data export — downloads a JSON file with the member's personal data
  const handleExportData = async () => {
    if (!editingMember?.id) return
    setExporting(true)
    try {
      const data = await getMembersFactory().exportMemberData(editingMember.id, { format: 'json', export_type: 'gdpr_access' })
      const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })
      downloadBlob(blob, `gdpr-export-${editingMember.id}.json`)
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : t('members.errors.exportData'))
    } finally {
      setExporting(false)
    }
  }

  // Handle form submission
  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    try {
      // Clear previous form errors
      setFormErrors({})

      // Every client-side rule reports at once, so fixing the IBAN does not
      // then reveal a card UID complaint that was true all along.
      const validationErrors: Record<string, string> = {}
      if (formData.iban && !validateIban(formData.iban)) {
        validationErrors.iban = t('members.validation.invalidIban')
      }
      if (formData.card_uid && !CARD_UID_PATTERN.test(formData.card_uid)) {
        validationErrors.card_uid = t('members.validation.invalidCardUid')
      }
      if (Object.keys(validationErrors).length > 0) {
        setFormErrors(validationErrors)
        return
      }

      if (editingMember) {
        if (!editingMember.id) throw new Error('Missing member id')
        const updatePayload: MemberUpdateRequest = {
          first_name: formData.first_name,
          last_name: formData.last_name,
          email: formData.email || null,
          // Now that these fields can be left empty, clearing one has to reach
          // the backend as an explicit null — `undefined` would drop the key
          // and silently keep the old value (#131).
          //
          // The IBAN is the one field where that rule inverts (#392). It cannot
          // be prefilled, so a blank input is the normal state of a save that
          // was about something else; sending null there would revoke the
          // mandate of every member whose phone number was corrected. Blank
          // therefore drops the key, and removing the account is its own
          // deliberate action.
          iban: removeStoredIban ? null : formData.iban || undefined,
          account_holder_name: formData.account_holder_name || null,
          mandate_reference: formData.mandate_reference || undefined,
          mandate_signed_at: formData.mandate_signed_at || null,
          preferred_language: formData.preferred_language,
          card_uid: formData.card_uid || null,
        }
        await getMembersFactory().updateMember(editingMember.id, updatePayload)
      } else {
        const createPayload: MemberCreateRequest = {
          first_name: formData.first_name,
          last_name: formData.last_name,
          email: formData.email || undefined,
          // A member who has not brought their bank details yet is a state the
          // list already shows as "SEPA: Missing" — the form no longer refuses
          // to create one (#131).
          iban: formData.iban || undefined,
          account_holder_name: formData.account_holder_name || undefined,
          mandate_reference: formData.mandate_reference || undefined,
          mandate_signed_at: formData.mandate_signed_at || undefined,
          preferred_language: formData.preferred_language,
          card_uid: formData.card_uid || undefined,
        }
        await getMembersFactory().createMember(createPayload)
      }

      // Reset form
      setShowModal(false)
      setEditingMember(null)
      setExtractedFields(null)
      setPreExtractionFormData(null)
      setFormData({ first_name: '', last_name: '', email: '', iban: '', account_holder_name: '', mandate_reference: '', mandate_signed_at: '', preferred_language: 'de', card_uid: '' })
      setRemoveStoredIban(false)

      // Reload members list with the active filters still applied
      await list.reload()

      setError(null)
    } catch (err: unknown) {
      // Handle validation errors (422)
      const axiosErr = err as { response?: { status?: number; data?: unknown } }
      if (axiosErr.response?.status === 422) {
        const data = axiosErr.response.data as { messages?: Record<string, unknown>; error?: string } | null
        // Backend returns { error: 'validation_failed', messages: { field: [errors] } }
        if (data?.messages && typeof data.messages === 'object') {
          // Map field errors and translate them
          const mappedErrors: Record<string, string> = {}
          for (const [field, messages] of Object.entries(data.messages)) {
            let errorMessage = ''
            if (Array.isArray(messages)) {
              errorMessage = String(messages[0])
            } else if (typeof messages === 'string') {
              errorMessage = messages
            }

            // Translate backend error messages to i18n
            if (field === 'card_uid' && errorMessage.includes('already been taken')) {
              mappedErrors[field] = t('members.errors.cardUidInUse')
            } else {
              // Use backend message as-is for other errors
              mappedErrors[field] = errorMessage
            }
          }
          setFormErrors(mappedErrors)
          // Don't close modal - keep it open so user can fix the error
        } else if (data?.error) {
          setError(data.error)
        } else {
          setError(t('members.errors.validationFailed'))
        }
      } else {
        setError(err instanceof Error ? err.message : t('members.errors.save'))
      }
    }
  }

  // Handle status toggle (activate/deactivate). Deactivating asks first;
  // reactivating only restores access, so it stays a single tap (#130).
  const handleStatusToggle = (member: MemberListItem) => {
    if (!member.id) return
    if (member.is_active) {
      setDeactivateConfirm(member)
      return
    }
    void applyStatusChange(member, true)
  }

  const applyStatusChange = async (member: MemberListItem, isActive: boolean) => {
    try {
      if (!member.id) return
      // Only send the field that needs to be updated
      await getMembersFactory().updateMember(member.id, { is_active: isActive })

      // Reload members list with the active filters still applied
      await list.reload()

      setError(null)
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : t('members.errors.updateStatus'))
    }
  }

  const handleDeactivateConfirmed = async () => {
    const member = deactivateConfirm
    if (!member) return
    setDeactivateConfirm(null)
    await applyStatusChange(member, false)
  }

  // Handle anonymize member (GDPR Art. 17)
  const handleAnonymize = async (member: MemberListItem) => {
    try {
      if (!member.id) return
      await getMembersFactory().anonymizeMember(member.id, {})

      // Reload members list with the active filters still applied
      await list.reload()

      setAnonymizeConfirm(null)
      setError(null)
    } catch (err: unknown) {
      const axiosErr = err as { response?: { status?: number; data?: unknown } }
      if (axiosErr.response?.status === 409) {
        const data = axiosErr.response.data as { message?: string } | null
        setError(data?.message || t('members.errors.cannotAnonymize'))
      } else {
        setError(err instanceof Error ? err.message : t('members.errors.anonymize'))
      }
      setAnonymizeConfirm(null)
    }
  }

  // Handle edit member — fetch full member details first
  const handleEdit = async (member: MemberListItem) => {
    if (!member.id) return
    try {
      const fullMember = await getMembersFactory().getMember(member.id)
      setEditingMember(fullMember)
      // A complaint from an earlier, abandoned submit belongs to the member that
      // was open then — leaving it behind renders it under this member's fields
      // (#131).
      setFormErrors({})
      setRemoveStoredIban(false)
      setFormData({
        first_name: fullMember.first_name ?? '',
        last_name: fullMember.last_name ?? '',
        email: fullMember.email ?? '',
        // Deliberately blank: the stored IBAN is sealed and the API returns
        // only its last four characters (ADR-0036), so there is nothing to
        // prefill. Blank means "keep" on save; the stored account is shown
        // beside the field instead (#392).
        iban: '',
        account_holder_name: fullMember.account_holder_name ?? '',
        mandate_reference: fullMember.mandate_reference ?? '',
        mandate_signed_at: fullMember.mandate_signed_at ?? '',
        preferred_language: fullMember.preferred_language ?? 'de',
        card_uid: fullMember.card_uid ?? '',
      })
      setShowModal(true)
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : t('members.errors.loadDetails'))
    }
  }

  const handleDownloadSepaTemplate = async () => {
    try {
      const blob = await getMembersFactory().getAdminSepaMandateTemplate()
      downloadBlob(blob, 'sepa-mandate-template.pdf')
    } catch {
      setError(t('members.sepaTemplateError'))
    }
  }

  function handleExtractionComplete(extraction: ExtractionResult) {
    setPreExtractionFormData({ ...formData })
    setExtractedFields(extraction)

    const updates: Partial<typeof formData> = {}
    const f = extraction.fields ?? {}
    if (f.first_name?.value)          updates.first_name           = f.first_name.value
    if (f.last_name?.value)           updates.last_name            = f.last_name.value
    if (f.email?.value)               updates.email                = f.email.value
    if (f.iban?.value)                updates.iban                 = f.iban.value.replace(/\s/g, '').toUpperCase()
    if (f.account_holder_name?.value) updates.account_holder_name  = f.account_holder_name.value
    if (f.mandate_signed_at?.value)   updates.mandate_signed_at    = f.mandate_signed_at.value
    if (f.card_uid?.value) {
      const normalized = normalizeCardUid(f.card_uid.value)
      if (normalized) updates.card_uid = normalized
    }

    setFormData(prev => ({ ...prev, ...updates }))
  }

  function handleDiscardExtracted() {
    if (preExtractionFormData) {
      setFormData(preExtractionFormData)
    }
    setExtractedFields(null)
    setPreExtractionFormData(null)
  }

  async function handleNewFromScan(file: File) {
    setScanExtracting(true)
    setEditingMember(null)
    setExtractedFields(null)
    setFormErrors({})
    setShowModal(true)
    try {
      const result = await getMandateDocument().extractMandateDocument({ file })
      const rf = result.fields ?? {}
      const initialForm = { first_name: '', last_name: '', email: '', iban: '', account_holder_name: '', mandate_reference: '', mandate_signed_at: '', preferred_language: 'de', card_uid: '' }
      setFormData({
        ...initialForm,
        first_name:           rf.first_name?.value           ?? '',
        last_name:            rf.last_name?.value            ?? '',
        email:                rf.email?.value                ?? '',
        iban:                 (rf.iban?.value ?? '').replace(/\s/g, '').toUpperCase(),
        account_holder_name:  rf.account_holder_name?.value  ?? '',
        mandate_signed_at:    rf.mandate_signed_at?.value    ?? '',
        card_uid:             normalizeCardUid(rf.card_uid?.value),
      })
      setExtractedFields(result)
      setPreExtractionFormData(null)
    } catch {
      setError(t('mandateDocument.extractionFailed'))
      setShowModal(false)
    } finally {
      setScanExtracting(false)
    }
  }

  function confidenceBadge(fieldName: keyof NonNullable<ExtractionResult['fields']>) {
    if (!extractedFields) return null
    const field = extractedFields.fields?.[fieldName]
    if (!field?.confidence) return null
    const styles: Record<string, React.CSSProperties> = {
      high:   { background: theme.softTint.success,  color: theme.colors.pastel.green, border: `1px solid ${theme.badges.success.border}`  },
      medium: { background: 'rgba(234,179,8,0.15)',  color: theme.colors.pastel.yellow, border: '1px solid rgba(234,179,8,0.3)'  },
      low:    { background: theme.softTint.danger,   color: theme.colors.banner.dangerText, border: `1px solid ${theme.badges.danger.border}`  },
    }
    const labels: Record<string, string> = {
      high:   t('mandateDocument.confidenceHigh'),
      medium: t('mandateDocument.confidenceMedium'),
      low:    t('mandateDocument.confidenceLow'),
    }
    return (
      <span style={{
        ...styles[field.confidence],
        borderRadius: '4px',
        padding: '2px 6px',
        fontSize: '10px',
        fontWeight: 700,
        flexShrink: 0,
        whiteSpace: 'nowrap',
      }}>
        ● {labels[field.confidence]}
      </span>
    )
  }


  // Grid columns based on breakpoint
  const gridColumns =
    breakpoint === 'desktop' || breakpoint === 'tablet'
      ? 'repeat(3, 1fr)'
      : breakpoint === 'mobile'
        ? 'repeat(2, 1fr)'
        : '1fr'

  return (
    <div data-testid="members-page">
      {/*
        Title, then the section's tabs, then the section's content — the order
        `ExcludedFromCollectionPage` already uses, and since #375 the order
        here too. This page used to open with the tab strip and only name
        itself further down, past the stat cards, so the two halves of one
        section disagreed about where the section's name lives.
      */}
      <PageHeader
        title={t('members.title')}
        subtitle={
          <span data-testid="members-count-summary">
            {t('members.countFound', { count: totalMembers })}
          </span>
        }
        actions={
          <>
            <input
              ref={scanInputRef}
              type="file"
              accept="image/*,.pdf"
              style={{ display: 'none' }}
              data-testid="members-scan-input"
              onChange={async (e) => {
                const file = e.target.files?.[0]
                if (file) await handleNewFromScan(file)
                if (scanInputRef.current) scanInputRef.current.value = ''
              }}
            />
            <PageActionButton
              variant="secondary"
              data-testid="members-new-from-scan-button"
              onClick={() => scanInputRef.current?.click()}
              disabled={scanExtracting}
              iconOnly={isMobile}
              icon={<ScanIcon size={18} />}
              // The upload is in flight, not refused — `not-allowed` would say
              // the wrong thing about a button that works again in a second.
              style={scanExtracting ? { cursor: 'wait' } : undefined}
              title={scanExtracting ? t('mandateDocument.uploadingAndExtracting') : t('members.newFromScan')}
            >
              {scanExtracting ? t('mandateDocument.uploadingAndExtracting') : t('members.newFromScan')}
            </PageActionButton>
            <PageActionButton
              variant="secondary"
              data-testid="members-sepa-template-download-button"
              onClick={handleDownloadSepaTemplate}
              iconOnly={isMobile}
              icon={<DownloadIcon size={18} />}
              title={t('members.downloadSepaTemplate')}
            >
              {t('members.downloadSepaTemplate')}
            </PageActionButton>
            <PageActionButton
              data-testid="members-create-button"
              onClick={() => {
                setEditingMember(null)
                setFormData({ first_name: '', last_name: '', email: '', iban: '', account_holder_name: '', mandate_reference: '', mandate_signed_at: '', preferred_language: 'de', card_uid: '' })
                setFormErrors({})
                setRemoveStoredIban(false)
                setShowModal(true)
              }}
              iconOnly={isMobile}
              icon={<PlusIcon size={18} />}
              title={t('common.add')}
            >
              {t('common.add')}
            </PageActionButton>
          </>
        }
      />

      <MembersTabs excludedCount={excludedLoading ? undefined : excludedCount} />

      {/* Stats Grid */}
      <div
        data-testid="members-stats-grid"
        style={{
          display: 'grid',
          gridTemplateColumns: gridColumns,
          gap: theme.spacing.xl,
          marginBottom: theme.spacing['2xl'],
        }}
      >
        <StatCard
          icon={<UsersIcon />}
          label={t('members.stats.activeMembers')}
          value={activeMembersCount ?? '—'}
          color="green"
        />
        <StatCard
          icon={<BankIcon />}
          label={t('members.stats.openItems')}
          value={totalBalance === null ? '—' : formatters.formatPrice(totalBalance)}
          color="blue"
        />
        <StatCard
          icon={<CalendarIcon />}
          label={t('members.stats.lastSettlementDate')}
          value={lastSettlementDate ? formatters.formatDate(lastSettlementDate.split('T')[0]) : '—'}
          color="blue"
        />
      </div>

      {/* Why the cards read "—". Without it the dashes are indistinguishable
          from a club that has never settled anything. */}
      {metricsFailed && (
        <div
          data-testid="members-metrics-error"
          style={{
            padding: theme.spacing.md,
            marginBottom: theme.spacing.lg,
            background: `${theme.colors.semantic.warning}20`,
            border: `1px solid ${theme.colors.semantic.warning}`,
            borderRadius: theme.borderRadius.md,
            color: theme.colors.semantic.warning,
            fontSize: theme.typography.fontSize.sm,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: theme.spacing.md,
            flexWrap: 'wrap',
          }}
        >
          <span>{t('members.errors.loadMetrics')}</span>
          <button
            data-testid="members-metrics-retry"
            onClick={() => loadDashboardMetrics(metricsRequest.next())}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: `1px solid ${theme.colors.semantic.warning}`,
              background: 'transparent',
              color: theme.colors.semantic.warning,
              fontSize: '13px',
              fontWeight: 600,
              cursor: 'pointer',
            }}
          >
            {t('common.retry')}
          </button>
        </div>
      )}

      {/* Above the breakpoint split on purpose: rendered inside the desktop
          branch, load failures, anonymize 409s and status-toggle errors were
          invisible on mobile (#132). Same pattern as SettlementsPage. */}
      {error && (
        <div
          data-testid="members-error-message"
          style={{
            padding: theme.spacing.lg,
            marginBottom: theme.spacing.lg,
            background: `${theme.colors.semantic.danger}20`,
            border: `1px solid ${theme.colors.semantic.danger}`,
            borderRadius: theme.borderRadius.md,
            color: theme.colors.semantic.danger,
            fontSize: theme.typography.fontSize.sm,
          }}
        >
          {error}
        </div>
      )}

      {isMobile ? (
        <>
          <MobileToolbar
            testId="members-mobile-toolbar"
            search={{
              value: search,
              onChange: list.setSearch,
              testId: 'members-search-input',
            }}
            sort={{
              options: mobileSortOptions,
              value: mobileSortValue,
              onChange: list.setSortValue,
            }}
            filterCount={mobileFilterCount}
            onFilterToggle={() => setShowMobileFilters(!showMobileFilters)}
            showFilters={showMobileFilters}
            filterContent={
              <>
                <MobileFilterRow
                  label={t('members.filterStatus')}
                  options={[
                    { value: 'all', label: t('common.all') },
                    { value: 'active', label: t('common.active') },
                    { value: 'inactive', label: t('common.inactive') },
                  ]}
                  value={filterIsActive}
                  onChange={(v) => list.setFilter('status', v as MemberFilters['status'])}
                  testId="members-mobile-filter-status"
                />
                <MobileFilterRow
                  label={t('members.filterCard')}
                  options={[
                    { value: 'all', label: t('common.all') },
                    { value: 'with', label: t('members.filterWithCard') },
                    { value: 'without', label: t('members.filterWithoutCard') },
                  ]}
                  value={filterCardUid}
                  onChange={(v) => list.setFilter('cardUid', v as MemberFilters['cardUid'])}
                  testId="members-mobile-filter-card"
                />
                <MobileFilterRow
                  label="SEPA"
                  options={[
                    { value: 'all', label: t('common.all') },
                    { value: 'valid', label: t('members.filterSepaValid') },
                    { value: 'invalid', label: t('members.filterSepaMissing') },
                  ]}
                  value={filterSepaStatus}
                  onChange={(v) => list.setFilter('sepaStatus', v as MemberFilters['sepaStatus'])}
                  testId="members-mobile-filter-sepa"
                />
              </>
            }
          />

          {/* Mobile card list */}
          {loading ? (
            <div style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
              {t('common.loading')}
            </div>
          ) : members.length === 0 ? (
            <div data-testid="members-empty-state" style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
              {t('members.noMembers')}
            </div>
          ) : (
            <div data-testid="members-mobile-cards" style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
              {members.map((member) => (
                <div
                  key={member.id}
                  data-testid={`member-card-${member.id}`}
                  style={{
                    background: theme.mobileCard.bg,
                    border: `1px solid ${theme.mobileCard.border}`,
                    borderRadius: '10px',
                    padding: '14px 16px',
                  }}
                >
                  {/* Row 1: toggle + name */}
                  <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '8px' }}>
                    <Toggle
                      isEnabled={member.is_active ?? false}
                      onChange={() => handleStatusToggle(member)}
                      size="small"
                      testId={`members-status-toggle-${member.id}`}
                    />
                    <span style={{ flex: 1, fontWeight: 600, color: theme.colors.text.primary, fontSize: '14px' }}>
                      {member.first_name} {member.last_name}
                    </span>
                    {/* The Deckel rides on the name row rather than down with
                        the metadata: on a phone it is the one figure worth
                        reading without opening anything. */}
                    <span
                      data-testid={`member-card-balance-${member.id}`}
                      style={{
                        fontFamily: 'JetBrains Mono, monospace',
                        fontSize: '13px',
                        fontWeight: 700,
                        fontVariantNumeric: 'tabular-nums',
                        color: (member.balance_cents ?? 0) === 0
                          ? theme.colors.text.muted
                          : theme.colors.text.primary,
                      }}
                    >
                      {formatters.formatPrice(member.balance_cents ?? 0)}
                    </span>
                  </div>
                  {/* Row 2: SEPA + Card info */}
                  <div style={{ display: 'flex', gap: '12px', fontSize: '12px', color: theme.colors.text.secondary, marginBottom: '6px' }}>
                    <span>
                      SEPA: {member.is_sepa_valid ? (
                        <span style={{ color: theme.colors.semantic.success }}>{t('members.sepaValid')}</span>
                      ) : (
                        <span style={{ color: theme.colors.text.muted }}>{t('members.sepaMissing')}</span>
                      )}
                    </span>
                    {member.card_uid && <span>Card: {member.card_uid}</span>}
                  </div>
                  {/* Row 3: member since */}
                  <div style={{ fontSize: '12px', color: theme.colors.text.muted, marginBottom: '10px' }}>
                    {t('members.memberSince')}: {member.created_at ? formatters.formatDate(member.created_at.split('T')[0]) : '—'}
                  </div>
                  {/* Row 4: actions */}
                  <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '8px' }}>
                    <button
                      data-testid={`member-edit-${member.id}`}
                      onClick={() => handleEdit(member)}
                      aria-label={t('members.editMemberNamed', { name: memberName(member) })}
                      style={{
                        display: 'flex', alignItems: 'center', gap: '4px',
                        padding: '6px 12px', borderRadius: '6px', border: 'none',
                        background: theme.badges.info.bg, color: theme.colors.semantic.primary,
                        fontSize: '12px', cursor: 'pointer',
                      }}
                    >
                      <EditIcon size={14} /> {t('common.edit')}
                    </button>
                    <button
                      data-testid={`member-anonymize-${member.id}`}
                      onClick={() => setAnonymizeConfirm(member)}
                      aria-label={t('members.anonymizeMemberNamed', { name: memberName(member) })}
                      style={{
                        display: 'flex', alignItems: 'center', gap: '4px',
                        padding: '6px 12px', borderRadius: '6px', border: 'none',
                        background: 'rgba(249,115,22,0.1)', color: theme.colors.semantic.warning,
                        fontSize: '12px', cursor: 'pointer',
                      }}
                    >
                      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                        <line x1="9" y1="9" x2="15" y2="15"/>
                        <line x1="15" y1="9" x2="9" y2="15"/>
                      </svg>
                      {t('members.anonymizeButton')}
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}

          {/* Pagination (mobile) */}
          {!loading && members.length > 0 && (
            <PaginationToolbar
              currentPage={list.page}
              totalPages={totalPages}
              totalItems={totalMembers}
              pageSize={PER_PAGE}
              onPageChange={list.setPage}
              onPageSizeChange={() => {}}
              variant="default"
              showPageSize={false}
              showInfo={true}
              testId="members-pagination"
            />
          )}
        </>
      ) : (
        <>
      {/* Unified filter toolbar */}
      <div
        style={{
          display: 'flex',
          alignItems: 'center',
          gap: '16px',
          flexWrap: 'wrap',
          padding: '14px 18px',
          background: theme.mobileCard.bg,
          borderRadius: '10px',
          border: `1px solid ${theme.mobileCard.border}`,
          marginBottom: '20px',
        }}
      >
        {/* Search */}
        <div style={{ position: 'relative', flex: '0 1 260px', minWidth: '180px' }}>
          <svg
            width="16"
            height="16"
            viewBox="0 0 16 16"
            fill="none"
            style={{ position: 'absolute', left: '10px', top: '50%', transform: 'translateY(-50%)', opacity: 0.35 }}
          >
            <circle cx="7" cy="7" r="5.5" stroke="white" strokeWidth="1.5" />
            <path d="M11 11l3.5 3.5" stroke="white" strokeWidth="1.5" strokeLinecap="round" />
          </svg>
          <input
            type="text"
            value={search}
            onChange={(e) => {
              list.setSearch(e.target.value)
            }}
            placeholder={t('common.searchPlaceholder')}
            data-testid="members-search-input"
            style={{
              width: '100%',
              padding: '8px 12px 8px 32px',
              borderRadius: '7px',
              border: `1px solid ${theme.colors.border.subtle}`,
              background: theme.colors.bg.surfaceSubtle,
              color: tableColors.cellText,
              fontSize: '13px',
              outline: 'none',
            }}
          />
        </div>

        {/* Divider */}
        <div style={{ width: '1px', height: '28px', background: theme.colors.border.subtle }} />

        {/* Status filter group */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
          <span
            style={{
              fontSize: '12px',
              color: theme.colors.text.label,
              marginRight: '4px',
              fontWeight: 500,
              textTransform: 'uppercase',
              letterSpacing: '0.04em',
            }}
          >
            Status
          </span>
          <button
            data-testid="members-filter-status-all"
            aria-pressed={filterIsActive === 'all'}
            onClick={() => {
              list.setFilter('status', 'all')
            }}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: 'none',
              fontSize: '13px',
              fontWeight: 500,
              cursor: 'pointer',
              transition: 'all 0.15s ease',
              background: filterIsActive === 'all' ? theme.colors.semantic.primary : theme.pillButton.idleBg,
              color: filterIsActive === 'all' ? 'white' : theme.pillButton.idleText,
            }}
          >
            {t('members.filters.status.all')}
          </button>
          <button
            data-testid="members-filter-status-active"
            aria-pressed={filterIsActive === 'active'}
            onClick={() => {
              list.setFilter('status', 'active')
            }}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: 'none',
              fontSize: '13px',
              fontWeight: 500,
              cursor: 'pointer',
              transition: 'all 0.15s ease',
              background: filterIsActive === 'active' ? theme.colors.semantic.primary : theme.pillButton.idleBg,
              color: filterIsActive === 'active' ? 'white' : theme.pillButton.idleText,
            }}
          >
            {t('members.filters.status.active')}
          </button>
          <button
            data-testid="members-filter-status-inactive"
            aria-pressed={filterIsActive === 'inactive'}
            onClick={() => {
              list.setFilter('status', 'inactive')
            }}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: 'none',
              fontSize: '13px',
              fontWeight: 500,
              cursor: 'pointer',
              transition: 'all 0.15s ease',
              background: filterIsActive === 'inactive' ? theme.colors.semantic.primary : theme.pillButton.idleBg,
              color: filterIsActive === 'inactive' ? 'white' : theme.pillButton.idleText,
            }}
          >
            {t('members.filters.status.inactive')}
          </button>
        </div>

        {/* Divider */}
        <div style={{ width: '1px', height: '28px', background: theme.colors.border.subtle }} />

        {/* Card filter group */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
          <span
            style={{
              fontSize: '12px',
              color: theme.colors.text.label,
              marginRight: '4px',
              fontWeight: 500,
              textTransform: 'uppercase',
              letterSpacing: '0.04em',
            }}
          >
            {t('members.filters.card.label')}
          </span>
          <button
            data-testid="filter-card-all"
            onClick={() => {
              list.setFilter('cardUid', 'all')
            }}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: 'none',
              fontSize: '13px',
              fontWeight: 500,
              cursor: 'pointer',
              transition: 'all 0.15s ease',
              background: filterCardUid === 'all' ? theme.colors.semantic.primary : theme.pillButton.idleBg,
              color: filterCardUid === 'all' ? 'white' : theme.pillButton.idleText,
            }}
          >
            {t('members.filters.card.all')}
          </button>
          <button
            data-testid="filter-card-with"
            onClick={() => {
              list.setFilter('cardUid', 'with')
            }}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: 'none',
              fontSize: '13px',
              fontWeight: 500,
              cursor: 'pointer',
              transition: 'all 0.15s ease',
              background: filterCardUid === 'with' ? theme.colors.semantic.primary : theme.pillButton.idleBg,
              color: filterCardUid === 'with' ? 'white' : theme.pillButton.idleText,
            }}
          >
            {t('members.filters.card.withCard')}
          </button>
          <button
            data-testid="filter-card-without"
            onClick={() => {
              list.setFilter('cardUid', 'without')
            }}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: 'none',
              fontSize: '13px',
              fontWeight: 500,
              cursor: 'pointer',
              transition: 'all 0.15s ease',
              background: filterCardUid === 'without' ? theme.colors.semantic.primary : theme.pillButton.idleBg,
              color: filterCardUid === 'without' ? 'white' : theme.pillButton.idleText,
            }}
          >
            {t('members.filters.card.withoutCard')}
          </button>
        </div>

        {/* Divider */}
        <div style={{ width: '1px', height: '28px', background: theme.colors.border.subtle }} />

        {/* SEPA filter group */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
          <span
            style={{
              fontSize: '12px',
              color: theme.colors.text.label,
              marginRight: '4px',
              fontWeight: 500,
              textTransform: 'uppercase',
              letterSpacing: '0.04em',
            }}
          >
            SEPA
          </span>
          <button
            data-testid="filter-sepa-all"
            onClick={() => {
              list.setFilter('sepaStatus', 'all')
            }}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: 'none',
              fontSize: '13px',
              fontWeight: 500,
              cursor: 'pointer',
              transition: 'all 0.15s ease',
              background: filterSepaStatus === 'all' ? theme.colors.semantic.primary : theme.pillButton.idleBg,
              color: filterSepaStatus === 'all' ? 'white' : theme.pillButton.idleText,
            }}
          >
            {t('members.filters.sepa.all')}
          </button>
          <button
            data-testid="filter-sepa-valid"
            onClick={() => {
              list.setFilter('sepaStatus', 'valid')
            }}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: 'none',
              fontSize: '13px',
              fontWeight: 500,
              cursor: 'pointer',
              transition: 'all 0.15s ease',
              background: filterSepaStatus === 'valid' ? theme.colors.semantic.primary : theme.pillButton.idleBg,
              color: filterSepaStatus === 'valid' ? 'white' : theme.pillButton.idleText,
            }}
          >
            {t('members.filters.sepa.valid')}
          </button>
          <button
            data-testid="filter-sepa-missing"
            onClick={() => {
              list.setFilter('sepaStatus', 'invalid')
            }}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: 'none',
              fontSize: '13px',
              fontWeight: 500,
              cursor: 'pointer',
              transition: 'all 0.15s ease',
              background: filterSepaStatus === 'invalid' ? theme.colors.semantic.primary : theme.pillButton.idleBg,
              color: filterSepaStatus === 'invalid' ? 'white' : theme.pillButton.idleText,
            }}
          >
            {t('members.filters.sepa.missing')}
          </button>
        </div>

        {/* Clear filters */}
        {((filterIsActive !== 'all') || (filterCardUid !== 'all') || (filterSepaStatus !== 'all') || search) && (
          <>
            <div style={{ flex: 1 }} />
            <button
              onClick={() => {
                list.setSearch('')
                list.setFilters({ status: 'all', cardUid: 'all', sepaStatus: 'all' })
              }}
              data-testid="members-clear-filters"
              style={{
                padding: '6px 12px',
                borderRadius: '6px',
                border: `1px solid ${theme.colors.border.subtle}`,
                background: 'transparent',
                color: 'rgba(255,255,255,0.45)',
                fontSize: '12px',
                cursor: 'pointer',
                display: 'flex',
                alignItems: 'center',
                gap: '5px',
              }}
            >
              <span style={{ fontSize: '14px', lineHeight: 1 }}>×</span> {t('members.filters.resetFilters')}
            </button>
          </>
        )}
      </div>

        {/* Table */}
        {loading ? (
          <div data-testid="members-loading" style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
            {t('common.loading')}
          </div>
        ) : members.length === 0 ? (
          <div data-testid="members-empty-state" style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
            {t('members.noMembers')}
          </div>
        ) : (
          <div data-testid="members-table-wrapper" style={tableWrapperStyles}>
            <table
              data-testid="members-table"
              style={tableElementStyles}
            >
              <thead>
                <tr style={headerRowStyle}>
                  <th style={{ ...headerCellBaseStyle, width: '80px', textAlign: 'center' }}>{t('common.status')}</th>
                  <th style={{ ...headerCellBaseStyle, width: '100px', textAlign: 'center' }} data-testid="members-table-header-sepa">
                    SEPA
                  </th>
                  <th style={headerCellBaseStyle}>
                    <SortableTableHeader
                      label={t('common.name')}
                      sortKey="last_name"
                      currentSort={{ key: list.sortKey, direction: list.sortDirection }}
                      onSort={(key: string, direction: 'asc' | 'desc') => list.setSort(key as MemberSortKey, direction)}
                      testId="members-table-header-name"
                    />
                  </th>
                  <th style={{ ...headerCellBaseStyle, width: '150px' }}>
                    <SortableTableHeader
                      label={t('members.table.cardUid')}
                      sortKey="card_uid"
                      currentSort={{ key: list.sortKey, direction: list.sortDirection }}
                      onSort={(key: string, direction: 'asc' | 'desc') => list.setSort(key as MemberSortKey, direction)}
                      testId="members-table-header-card-uid"
                    />
                  </th>
                  <th style={{ ...headerCellBaseStyle, width: '130px' }}>
                    <SortableTableHeader
                      label={t('members.table.balance')}
                      sortKey="balance"
                      currentSort={{ key: list.sortKey, direction: list.sortDirection }}
                      onSort={(key: string, direction: 'asc' | 'desc') => list.setSort(key as MemberSortKey, direction)}
                      testId="members-table-header-balance"
                    />
                  </th>
                  <th style={{ ...headerCellBaseStyle, width: '120px' }}>
                    <SortableTableHeader
                      label={t('members.memberSince')}
                      sortKey="created_at"
                      currentSort={{ key: list.sortKey, direction: list.sortDirection }}
                      onSort={(key: string, direction: 'asc' | 'desc') => list.setSort(key as MemberSortKey, direction)}
                      testId="members-table-header-created"
                    />
                  </th>
                  <th style={{ ...headerCellBaseStyle, width: '200px', textAlign: 'center' }}>{t('common.actions')}</th>
                </tr>
              </thead>
              <tbody>
                {members.map((member) => (
                  <tr
                    key={member.id}
                    data-testid={`members-table-row-${member.id}`}
                    style={getRowStyle(member.is_active ?? false)}
                    onMouseEnter={(e: React.MouseEvent<HTMLTableRowElement>) => {
                      if (member.is_active) {
                        e.currentTarget.style.backgroundColor = tableColors.rowActiveHoverBg
                      }
                    }}
                    onMouseLeave={(e: React.MouseEvent<HTMLTableRowElement>) => {
                      e.currentTarget.style.backgroundColor = member.is_active
                        ? tableColors.rowActiveBg
                        : tableColors.rowInactiveBg
                    }}
                  >
                    <StatusToggleCell
                      enabled={member.is_active ?? false}
                      onChange={() => handleStatusToggle(member)}
                      size="small"
                      testId={`members-status-toggle-${member.id}`}
                      cellTestId={`members-table-cell-status-${member.id}`}
                    />
                    <td style={{ textAlign: 'center' }} data-testid={`members-table-cell-sepa-${member.id}`}>
                      <span
                        style={{
                          padding: '4px 8px',
                          borderRadius: 4,
                          fontSize: 12,
                          fontWeight: 500,
                          backgroundColor: member.is_sepa_valid ? theme.colors.semantic.success : theme.colors.semantic.danger,
                          color: 'white',
                          display: 'inline-block',
                        }}
                      >
                        {member.is_sepa_valid ? t('members.valid') : t('members.missing')}
                      </span>
                    </td>
                    <TableCell testId={`members-table-cell-name-${member.id}`}>
                      {member.first_name} {member.last_name}
                    </TableCell>
                    <TableCell testId="member-card-uid">
                      {member.card_uid || '—'}
                    </TableCell>
                    {/* The Deckel. Zero is greyed rather than hidden: the
                        treasurer scanning the column wants the settled rows to
                        fall away, but a blank cell would read as "not known"
                        instead of "owes nothing". */}
                    <td
                      data-testid={`members-table-cell-balance-${member.id}`}
                      style={{
                        padding: tableSpacing.cellPadding,
                        fontFamily: 'JetBrains Mono, monospace',
                        fontSize: '14px',
                        fontWeight: 700,
                        fontVariantNumeric: 'tabular-nums',
                        color: (member.balance_cents ?? 0) === 0
                          ? theme.colors.text.muted
                          : tableColors.cellText,
                      }}
                    >
                      {formatters.formatPrice(member.balance_cents ?? 0)}
                    </td>
                    <TableCell testId={`members-table-cell-created-${member.id}`}>
                      {member.created_at ? formatters.formatDate(member.created_at.split('T')[0]) : '—'}
                    </TableCell>
                    <TableCell align="center">
                      <button
                        data-testid={`members-table-action-edit-${member.id}`}
                        onClick={() => handleEdit(member)}
                        style={{
                          background: 'transparent',
                          border: 'none',
                          color: theme.colors.semantic.primary,
                          cursor: 'pointer',
                          padding: theme.spacing.sm,
                        }}
                        title={t('common.edit')}
                        aria-label={t('members.editMemberNamed', { name: memberName(member) })}
                      >
                        <EditIcon size={18} />
                      </button>
                      <button
                        data-testid={`members-table-action-anonymize-${member.id}`}
                        onClick={() => setAnonymizeConfirm(member)}
                        style={{
                          background: 'transparent',
                          border: 'none',
                          color: theme.colors.semantic.warning,
                          cursor: 'pointer',
                          padding: theme.spacing.sm,
                          marginLeft: theme.spacing.md,
                        }}
                        title={t('members.anonymizeMember')}
                        aria-label={t('members.anonymizeMemberNamed', { name: memberName(member) })}
                      >
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                          <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                          <line x1="9" y1="9" x2="15" y2="15"/>
                          <line x1="15" y1="9" x2="9" y2="15"/>
                        </svg>
                      </button>
                    </TableCell>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {/* Pagination */}
        {!loading && members.length > 0 && (
          <PaginationToolbar
            currentPage={list.page}
            totalPages={totalPages}
            totalItems={totalMembers}
            pageSize={PER_PAGE}
            onPageChange={list.setPage}
            onPageSizeChange={() => {}} // Not implemented - always use 20
            variant="default"
            showPageSize={false}
            showInfo={true}
            testId="members-pagination"
          />
        )}
        </>
      )}

      {/* Create/Edit Modal
          The backdrop deliberately carries no close handler: this form holds
          nine fields that may have come out of a scanned mandate, and a stray
          click beside the dialog used to throw all of it away (#131). It closes
          through Cancel or a successful save. */}
      {showModal && (
        <div
          data-testid="members-form-modal"
          style={{
            position: 'fixed',
            top: 0,
            left: 0,
            right: 0,
            bottom: 0,
            background: theme.overlay.backdrop,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            zIndex: 1100,
          }}
        >
          <div
            data-testid="members-form-modal-content"
            style={{
              background: theme.colors.bg.secondary,
              borderRadius: isMobile ? 0 : theme.borderRadius.lg,
              padding: isMobile ? theme.spacing.lg : theme.spacing.xl,
              maxWidth: isMobile ? '100%' : '900px',
              width: isMobile ? '100%' : '90%',
              height: isMobile ? '100%' : 'auto',
              boxShadow: isMobile ? 'none' : theme.shadows.modalStrong,
              maxHeight: isMobile ? '100%' : '90vh',
              overflowY: 'auto',
            }}
          >
            <style>{`
              @keyframes members-spin { to { transform: rotate(360deg); } }
              @media (max-width: 768px) {
                .field-with-badge { flex-wrap: wrap; }
                .field-with-badge > input,
                .field-with-badge > select { width: 100%; min-width: 0; }
              }
            `}</style>

            <h2 data-testid="members-form-title" style={{ margin: 0, marginBottom: theme.spacing.lg, fontSize: theme.typography.fontSize.xl }}>
              {editingMember ? t('members.editMember') : t('members.createMember')}
            </h2>

            {/* Scan loading pane */}
            {scanExtracting && (
              <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', padding: '48px 24px', gap: '16px' }}>
                <div style={{
                  width: '40px',
                  height: '40px',
                  border: '3px solid rgba(196,181,253,0.2)',
                  borderTopColor: theme.colors.pastel.purple,
                  borderRadius: '50%',
                  animation: 'members-spin 0.8s linear infinite',
                }} />
                <p style={{ margin: 0, color: theme.colors.text.secondary, fontSize: theme.typography.fontSize.sm }}>
                  {t('mandateDocument.uploadingAndExtracting')}
                </p>
              </div>
            )}

            {/* SEPA Status Indicator */}
            {editingMember && (
              <div
                style={{
                  padding: '12px 16px',
                  borderRadius: 6,
                  marginBottom: theme.spacing.md,
                  backgroundColor: editingMember.is_sepa_valid
                    ? theme.badges.success.bg
                    : theme.badges.danger.bg,
                  border: `1px solid ${editingMember.is_sepa_valid ? theme.colors.semantic.success : theme.colors.semantic.danger}`,
                  display: 'flex',
                  alignItems: 'center',
                  gap: theme.spacing.sm,
                }}
                data-testid="members-form-sepa-status"
              >
                <span style={{ fontSize: 18 }}>
                  {editingMember.is_sepa_valid ? '✓' : '⚠'}
                </span>
                <div>
                  <div style={{ fontWeight: 600, fontSize: 14, color: theme.colors.text.primary }}>
                    {editingMember.is_sepa_valid ? t('members.sepaValid') : t('members.sepaMissing')}
                  </div>
                  {!editingMember.is_sepa_valid && (
                    <div style={{ fontSize: 12, color: theme.colors.text.secondary, marginTop: 4 }}>
                      {t('members.sepaMissingHint')}
                    </div>
                  )}
                </div>
              </div>
            )}

            <form onSubmit={handleSubmit} style={{ display: scanExtracting ? 'none' : 'grid', gridTemplateColumns: isMobile ? '1fr' : '1fr 1fr', gap: theme.spacing.lg, columnGap: theme.spacing.xl }}>
              <div>
                <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                  {t('members.firstName')} *
                </label>
                <div className="field-with-badge" style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                  <input
                    data-testid="members-form-first-name-input"
                    type="text"
                    required
                    value={formData.first_name}
                    onChange={(e) => setFormData({ ...formData, first_name: e.target.value })}
                    placeholder="Max"
                    maxLength={100}
                    style={{
                      flex: 1,
                      padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                      background: theme.colors.bg.input,
                      border: `1px solid ${theme.colors.border.light}`,
                      borderRadius: theme.borderRadius.md,
                      color: theme.colors.text.primary,
                      boxSizing: 'border-box',
                    }}
                  />
                  {confidenceBadge('first_name')}
                </div>
              </div>

              <div>
                <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                  {t('members.lastName')} *
                </label>
                <div className="field-with-badge" style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                  <input
                    data-testid="members-form-last-name-input"
                    type="text"
                    required
                    value={formData.last_name}
                    onChange={(e) => setFormData({ ...formData, last_name: e.target.value })}
                    placeholder="Mustermann"
                    maxLength={100}
                    style={{
                      flex: 1,
                      padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                      background: theme.colors.bg.input,
                      border: `1px solid ${theme.colors.border.light}`,
                      borderRadius: theme.borderRadius.md,
                      color: theme.colors.text.primary,
                      boxSizing: 'border-box',
                    }}
                  />
                  {confidenceBadge('last_name')}
                </div>
              </div>

              <div>
                <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                  {t('members.email')}
                </label>
                <div className="field-with-badge" style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                  <input
                    data-testid="members-form-email-input"
                    type="email"
                    value={formData.email}
                    onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                    placeholder="max@example.com"
                    style={{
                      flex: 1,
                      padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                      background: theme.colors.bg.input,
                      border: `1px solid ${formErrors.email ? theme.colors.semantic.danger : theme.colors.border.light}`,
                      borderRadius: theme.borderRadius.md,
                      color: theme.colors.text.primary,
                      boxSizing: 'border-box',
                    }}
                  />
                  {confidenceBadge('email')}
                </div>
                {/* The API requires an email on create; without this the 422 it
                    answers with was mapped into formErrors and never rendered. */}
                {formErrors.email && (
                  <p data-testid="members-form-email-error" style={{ color: theme.colors.semantic.danger, fontSize: theme.typography.fontSize.sm, marginTop: theme.spacing.xs }}>
                    {formErrors.email}
                  </p>
                )}
              </div>

              <div>
                <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                  {t('members.form.cardUid')}
                  <span style={{ color: theme.colors.text.secondary, marginLeft: theme.spacing.xs, fontWeight: 400 }}>
                    ({t('common.requiredForTerminal')})
                  </span>
                </label>
                <div className="field-with-badge" style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                  <input
                    data-testid="member-form-card-uid"
                    type="text"
                    value={formData.card_uid}
                    onChange={(e) => {
                      const value = e.target.value.toUpperCase().replace(/[^0-9A-F]/g, '')
                      setFormData({ ...formData, card_uid: value })
                      // Clear error when user starts typing
                      if (formErrors.card_uid) {
                        setFormErrors({ ...formErrors, card_uid: '' })
                      }
                    }}
                    placeholder={t('members.form.cardUidPlaceholder')}
                    maxLength={20}
                    style={{
                      flex: 1,
                      padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                      background: theme.colors.bg.input,
                      border: `1px solid ${formErrors.card_uid ? theme.colors.semantic.danger : theme.colors.border.light}`,
                      borderRadius: theme.borderRadius.md,
                      color: theme.colors.text.primary,
                      boxSizing: 'border-box',
                      fontFamily: 'monospace',
                    }}
                  />
                  {confidenceBadge('card_uid')}
                </div>
                {formData.card_uid && !CARD_UID_PATTERN.test(formData.card_uid) && (
                  <p data-testid="member-form-card-uid-format-error" style={{ color: theme.colors.semantic.danger, fontSize: theme.typography.fontSize.sm, marginTop: theme.spacing.xs }}>
                    {t('members.validation.invalidCardUid')}
                  </p>
                )}
                {formErrors.card_uid && (
                  <p data-testid="member-form-card-uid-error" style={{ color: theme.colors.semantic.danger, fontSize: theme.typography.fontSize.sm, marginTop: theme.spacing.xs }}>
                    {formErrors.card_uid}
                  </p>
                )}
              </div>

              <div>
                <label style={{ display: 'flex', alignItems: 'center', gap: theme.spacing.sm, marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                  {t('members.iban')} <span style={{ color: theme.colors.text.secondary, marginLeft: theme.spacing.xs, fontWeight: 400 }}>({t('common.sepa')}, {t('common.optional')})</span>
                  <ValidationIndicator
                    isValid={validateIban(formData.iban)}
                    show={formData.iban.length > 0}
                    testId="members-form-iban-validation"
                  />
                </label>
                <div className="field-with-badge" style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                  <input
                    data-testid="members-form-iban-input"
                    type="text"
                    value={formData.iban}
                    onChange={(e) => { setFormData({ ...formData, iban: e.target.value.replace(/\s/g, '').toUpperCase() }); if (formErrors.iban) setFormErrors((prev) => Object.fromEntries(Object.entries(prev).filter(([k]) => k !== 'iban'))) }}
                    placeholder="DE89370400440532013000"
                    minLength={15}
                    maxLength={34}
                    style={{
                      flex: 1,
                      padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                      background: theme.colors.bg.input,
                      border: `1px solid ${formErrors.iban ? theme.colors.semantic.danger : theme.colors.border.light}`,
                      borderRadius: theme.borderRadius.md,
                      color: theme.colors.text.primary,
                      boxSizing: 'border-box',
                      fontFamily: 'monospace',
                    }}
                  />
                  {confidenceBadge('iban')}
                </div>
                {/*
                  The stored account, for a member who has one. The IBAN itself
                  is sealed (ADR-0036), so this is the whole of what the admin
                  panel can show — and it is why the input above is empty:
                  leaving it that way keeps this account, typing replaces it.
                */}
                {editingMember?.iban_last4 && !removeStoredIban && (
                  <p
                    data-testid="members-form-iban-stored"
                    style={{
                      fontSize: theme.typography.fontSize.sm,
                      color: theme.colors.text.secondary,
                      marginTop: theme.spacing.xs,
                    }}
                  >
                    {t('members.storedIban', {
                      masked: editingMember.iban_masked ?? `****${editingMember.iban_last4}`,
                    })}
                    {editingMember.bank_name ? ` · ${editingMember.bank_name}` : ''}
                    {' — '}
                    <button
                      type="button"
                      data-testid="members-form-iban-remove"
                      onClick={() => { setRemoveStoredIban(true); setFormData((prev) => ({ ...prev, iban: '' })) }}
                      style={{
                        background: 'none',
                        border: 'none',
                        padding: 0,
                        color: theme.colors.semantic.danger,
                        cursor: 'pointer',
                        font: 'inherit',
                        textDecoration: 'underline',
                      }}
                    >
                      {t('members.removeStoredIban')}
                    </button>
                  </p>
                )}
                {removeStoredIban && (
                  <p
                    data-testid="members-form-iban-removal-pending"
                    style={{
                      fontSize: theme.typography.fontSize.sm,
                      color: theme.colors.semantic.danger,
                      marginTop: theme.spacing.xs,
                    }}
                  >
                    {t('members.storedIbanWillBeRemoved')}
                    {' — '}
                    <button
                      type="button"
                      data-testid="members-form-iban-remove-undo"
                      onClick={() => setRemoveStoredIban(false)}
                      style={{
                        background: 'none',
                        border: 'none',
                        padding: 0,
                        color: theme.colors.text.secondary,
                        cursor: 'pointer',
                        font: 'inherit',
                        textDecoration: 'underline',
                      }}
                    >
                      {t('common.undo')}
                    </button>
                  </p>
                )}
                {formData.iban.length >= 12 && formData.iban.toUpperCase().startsWith('DE') && (
                  <p
                    data-testid="members-form-bank-name"
                    style={{
                      fontSize: theme.typography.fontSize.sm,
                      color: bankName ? theme.colors.text.secondary : theme.colors.text.muted,
                      marginTop: theme.spacing.xs,
                      fontStyle: bankName ? 'normal' : 'italic',
                    }}
                  >
                    {bankName ?? (isBankNameLoading ? t('members.bankNameLoading') : '')}
                  </p>
                )}
                {formErrors.iban && (
                  <p data-testid="members-form-iban-error" style={{ color: theme.colors.semantic.danger, fontSize: theme.typography.fontSize.sm, marginTop: theme.spacing.xs }}>
                    {formErrors.iban}
                  </p>
                )}
                {extractedFields?.ibanCandidates && extractedFields.ibanCandidates.length > 1 && (
                  <div
                    data-testid="members-form-iban-candidates"
                    style={{
                      marginTop: theme.spacing.sm,
                      padding: '10px 12px',
                      background: 'rgba(234,179,8,0.08)',
                      border: '1px solid rgba(234,179,8,0.35)',
                      borderRadius: theme.borderRadius.md,
                    }}
                  >
                    <div style={{ fontSize: '11px', fontWeight: 700, color: theme.colors.pastel.yellow, marginBottom: '8px' }}>
                      {t('mandateDocument.ibanCandidates')}
                    </div>
                    {extractedFields.ibanCandidates.map(candidate => (
                      <button
                        key={candidate}
                        type="button"
                        data-testid={`members-form-iban-candidate-${candidate}`}
                        onClick={() => {
                          setFormData(prev => ({ ...prev, iban: candidate }))
                          if (formErrors.iban) setFormErrors(prev => Object.fromEntries(Object.entries(prev).filter(([k]) => k !== 'iban')))
                        }}
                        style={{
                          display: 'flex',
                          alignItems: 'center',
                          gap: '8px',
                          width: '100%',
                          padding: '5px 8px',
                          marginBottom: '3px',
                          background: formData.iban === candidate ? 'rgba(37,99,235,0.2)' : 'transparent',
                          border: `1px solid ${formData.iban === candidate ? 'rgba(37,99,235,0.5)' : 'transparent'}`,
                          borderRadius: theme.borderRadius.sm,
                          color: theme.colors.text.primary,
                          fontFamily: 'monospace',
                          fontSize: '13px',
                          cursor: 'pointer',
                          textAlign: 'left',
                        }}
                      >
                        <span style={{ fontSize: '10px', color: formData.iban === candidate ? theme.colors.pastel.blue : theme.colors.text.secondary }}>
                          {formData.iban === candidate ? '●' : '○'}
                        </span>
                        {candidate}
                      </button>
                    ))}
                  </div>
                )}
              </div>

              <div>
                <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                  {t('members.accountHolderName')} <span style={{ color: theme.colors.text.secondary, marginLeft: theme.spacing.xs, fontWeight: 400 }}>({t('common.sepa')}, {t('common.optional')})</span>
                </label>
                <div className="field-with-badge" style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                  <input
                    data-testid="members-form-account-holder-name-input"
                    type="text"
                    value={formData.account_holder_name}
                    onChange={(e) => setFormData({ ...formData, account_holder_name: e.target.value })}
                    placeholder={t('members.accountHolderPlaceholder')}
                    maxLength={70}
                    style={{
                      flex: 1,
                      padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                      background: theme.colors.bg.input,
                      border: `1px solid ${theme.colors.border.light}`,
                      borderRadius: theme.borderRadius.md,
                      color: theme.colors.text.primary,
                      boxSizing: 'border-box',
                    }}
                  />
                  {confidenceBadge('account_holder_name')}
                </div>
                <span style={{ fontSize: '12px', color: theme.colors.text.secondary, marginTop: '4px', display: 'block' }}>
                  {t('members.accountHolderHint')}
                </span>
              </div>

              <div>
                <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                  {t('members.mandateReference')} <span style={{ color: theme.colors.text.secondary, marginLeft: theme.spacing.xs, fontWeight: 400 }}>({t('common.sepa')}, {t('common.optional')})</span>
                </label>
                <input
                  data-testid="members-form-mandate-reference-input"
                  type="text"
                  value={formData.mandate_reference}
                  onChange={(e) => setFormData({ ...formData, mandate_reference: e.target.value.toUpperCase() })}
                  placeholder={t('members.mandateReferencePlaceholder')}
                  maxLength={35}
                  style={{
                    width: '100%',
                    padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                    background: theme.colors.bg.input,
                    border: `1px solid ${theme.colors.border.light}`,
                    borderRadius: theme.borderRadius.md,
                    color: theme.colors.text.primary,
                    boxSizing: 'border-box',
                    fontFamily: 'monospace',
                  }}
                />
                <span style={{ fontSize: '12px', color: theme.colors.text.secondary, marginTop: '4px', display: 'block' }}>
                  {t('members.mandateReferenceHint')}
                </span>
              </div>

              <div>
                <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                  {t('members.mandateSignedAt')} <span style={{ color: theme.colors.text.secondary, marginLeft: theme.spacing.xs, fontWeight: 400 }}>({t('common.sepa')}, {t('common.optional')})</span>
                </label>
                <div className="field-with-badge" style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                  <input
                    data-testid="members-form-mandate-date-input"
                    type="date"
                    value={formData.mandate_signed_at}
                    onChange={(e) => setFormData({ ...formData, mandate_signed_at: e.target.value })}
                    max={toIsoDate(new Date())}
                    style={{
                      flex: 1,
                      padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                      background: theme.colors.bg.input,
                      border: `1px solid ${theme.colors.border.light}`,
                      borderRadius: theme.borderRadius.md,
                      color: theme.colors.text.primary,
                      boxSizing: 'border-box',
                    }}
                  />
                  {confidenceBadge('mandate_signed_at')}
                </div>
              </div>

              <div style={{ gridColumn: '1 / -1' }}>
                <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                  {t('members.preferredLanguage')} *
                </label>
                <LanguageSelector
                  value={formData.preferred_language as 'de' | 'en'}
                  onChange={(language) => setFormData({ ...formData, preferred_language: language })}
                  testId="members-form-language-select"
                  required
                />
              </div>

              {extractedFields && (
                <div
                  style={{
                    gridColumn: '1 / -1',
                    background: 'rgba(124,58,237,0.1)',
                    border: '1px solid rgba(124,58,237,0.3)',
                    borderRadius: '6px',
                    padding: '8px 12px',
                    fontSize: '12px',
                    color: theme.colors.pastel.purple,
                    marginBottom: '12px',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                    flexWrap: 'wrap',
                    gap: '8px',
                  }}
                  data-testid="extraction-review-banner"
                >
                  <span>✦ {t('mandateDocument.extractionReviewHint')}</span>
                  <button
                    onClick={handleDiscardExtracted}
                    style={{
                      background: 'transparent',
                      border: '1px solid rgba(124,58,237,0.4)',
                      borderRadius: '4px',
                      color: theme.colors.pastel.purple,
                      fontSize: '11px',
                      padding: '3px 8px',
                      cursor: 'pointer',
                      flexShrink: 0,
                    }}
                    data-testid="discard-extracted-btn"
                  >
                    {t('mandateDocument.discardExtracted')}
                  </button>
                </div>
              )}

              <div style={{ gridColumn: '1 / -1', display: 'flex', gap: theme.spacing.lg, justifyContent: editingMember ? 'space-between' : 'flex-end', marginTop: theme.spacing.lg }}>
                {editingMember && (
                  <button
                    data-testid="members-form-export-button"
                    type="button"
                    onClick={handleExportData}
                    disabled={exporting}
                    style={{
                      display: 'flex',
                      alignItems: 'center',
                      gap: theme.spacing.sm,
                      padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                      background: 'transparent',
                      border: `1px solid ${theme.colors.border.light}`,
                      borderRadius: theme.borderRadius.md,
                      color: theme.colors.text.secondary,
                      cursor: exporting ? 'not-allowed' : 'pointer',
                      fontSize: theme.typography.fontSize.sm,
                      fontWeight: theme.typography.fontWeight.semibold,
                      opacity: exporting ? 0.6 : 1,
                    }}
                    title={t('common.export')}
                  >
                    <DownloadIcon size={16} />
                    {t('common.export')}
                  </button>
                )}
                <div style={{ display: 'flex', gap: theme.spacing.lg }}>
                  <button
                    data-testid="members-form-cancel-button"
                    type="button"
                    onClick={() => { setShowModal(false); setExtractedFields(null); setPreExtractionFormData(null) }}
                    style={{
                      padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                      background: 'transparent',
                      border: `1px solid ${theme.colors.border.light}`,
                      borderRadius: theme.borderRadius.md,
                      color: theme.colors.text.primary,
                      cursor: 'pointer',
                      fontSize: theme.typography.fontSize.sm,
                      fontWeight: theme.typography.fontWeight.semibold,
                    }}
                  >
                    {t('common.cancel')}
                  </button>
                  <button
                    data-testid="members-form-submit-button"
                    type="submit"
                    style={{
                      padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                      background: theme.colors.semantic.primary,
                      border: 'none',
                      borderRadius: theme.borderRadius.md,
                      color: 'white',
                      cursor: 'pointer',
                      fontSize: theme.typography.fontSize.sm,
                      fontWeight: theme.typography.fontWeight.semibold,
                    }}
                  >
                    {t('common.save')}
                  </button>
                </div>
              </div>
            </form>

            {editingMember && editingMember.id && (
              <MandateDocumentSection onExtractionComplete={handleExtractionComplete} />
            )}
          </div>
        </div>
      )}


      {/* Deactivation Confirmation Dialog (#130) */}
      <ConfirmDialog
        isOpen={!!deactivateConfirm}
        message={t('members.deactivateConfirm', {
          name: `${deactivateConfirm?.first_name ?? ''} ${deactivateConfirm?.last_name ?? ''}`.trim(),
        })}
        confirmLabel={t('common.deactivate')}
        variant="danger"
        onConfirm={handleDeactivateConfirmed}
        onCancel={() => setDeactivateConfirm(null)}
      />

      {/* Anonymize Confirmation Dialog */}
      {anonymizeConfirm && (
        <div
          data-testid="members-anonymize-confirm-modal"
          style={{
            position: 'fixed',
            top: 0,
            left: 0,
            right: 0,
            bottom: 0,
            background: theme.overlay.backdrop,
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            zIndex: 1001,
          }}
          onClick={() => setAnonymizeConfirm(null)}
        >
          <div
            data-testid="members-anonymize-confirm-content"
            style={{
              background: theme.colors.bg.secondary,
              borderRadius: theme.borderRadius.lg,
              padding: theme.spacing.xl,
              maxWidth: '440px',
              width: '90%',
              boxShadow: theme.shadows.modalStrong,
            }}
            onClick={(e) => e.stopPropagation()}
          >
            <h2 data-testid="members-anonymize-confirm-title" style={{ margin: 0, marginBottom: theme.spacing.lg, fontSize: theme.typography.fontSize.lg, color: theme.colors.semantic.warning }}>
              {t('members.anonymizeMember')}
            </h2>
            <p data-testid="members-anonymize-confirm-message" style={{ color: theme.colors.text.secondary, marginBottom: theme.spacing.lg, lineHeight: 1.5 }}>
              {t('members.anonymizeConfirm', { name: `${anonymizeConfirm.first_name} ${anonymizeConfirm.last_name}` })}
            </p>

            <div style={{ display: 'flex', gap: theme.spacing.lg, justifyContent: 'flex-end' }}>
              <button
                data-testid="members-anonymize-confirm-cancel"
                onClick={() => setAnonymizeConfirm(null)}
                style={{
                  padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                  background: 'transparent',
                  border: `1px solid ${theme.colors.border.light}`,
                  borderRadius: theme.borderRadius.md,
                  color: theme.colors.text.primary,
                  cursor: 'pointer',
                  fontSize: theme.typography.fontSize.sm,
                  fontWeight: theme.typography.fontWeight.semibold,
                }}
              >
                {t('common.cancel')}
              </button>
              <button
                data-testid="members-anonymize-confirm-ok"
                onClick={() => handleAnonymize(anonymizeConfirm)}
                style={{
                  padding: `${theme.spacing.md} ${theme.spacing.lg}`,
                  background: theme.colors.semantic.warning,
                  border: 'none',
                  borderRadius: theme.borderRadius.md,
                  color: 'white',
                  cursor: 'pointer',
                  fontSize: theme.typography.fontSize.sm,
                  fontWeight: theme.typography.fontWeight.semibold,
                }}
              >
                {t('members.anonymizeButton')}
              </button>
            </div>
          </div>
        </div>
      )}

    </div>
  )
}
