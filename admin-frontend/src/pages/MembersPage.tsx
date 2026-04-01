/**
 * Members Page
 * Member management (list, create, edit, delete)
 */

import React, { useEffect, useState, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { StatCard } from '../components/common/StatCard'
import { theme } from '../styles/design-system'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { MobileToolbar } from '../components/layout/MobileToolbar'
import { useLoading } from '../context/LoadingContext'
import { useFormatters } from '../hooks/useFormatters'
import { UsersIcon, BankIcon, CalendarIcon, EditIcon, PlusIcon, DownloadIcon } from '../components/icons'
import { getMembers as getMembersFactory } from '../api/generated/members/members'
import { getDashboard } from '../api/generated/dashboard/dashboard'
import type { Member, MemberListItem, ListMembersParams, ListMembersStatus, ListMembersSepaStatus, ListMembersHasCardUid, ListMembersSortBy, MemberCreateRequest, MemberUpdateRequest } from '../api/generated'
// TableSearchToolbar is available but not currently used
// import { TableSearchToolbar } from '../components/tables/TableSearchToolbar'
import { PaginationToolbar } from '../components/tables/PaginationToolbar'
import { SortableTableHeader } from '../components/tables/SortableTableHeader'
import { StatusToggleCell } from '../components/tables/StatusToggleCell'
import { Toggle } from '../components/forms/Toggle'
import { TableCell } from '../components/tables/TableCell'
import { LanguageSelector } from '../components/forms/LanguageSelector'
import { validateIban } from '../utils/iban'
import { ValidationIndicator } from '../components/forms/ValidationIndicator'
import { downloadFile } from '../api/client'
import { MandateDocumentSection } from '../components/MandateDocumentSection'
import { extractMandateDocument, ExtractionResult } from '../api/extractMandateDocument'
import { uploadMandateDocument } from '../api/mandateDocument'
import {
  tableWrapperStyles,
  tableElementStyles,
  headerRowStyle,
  headerCellBaseStyle,
  tableColors,
  getRowStyle,
} from '../styles/tableTokens'

function normalizeCardUid(raw: string | null | undefined): string {
  if (!raw) return ''
  // Strip common separators (hyphens, spaces, colons), then remove non-hex chars
  const cleaned = raw.toUpperCase().replace(/[\s\-:]/g, '').replace(/[^0-9A-F]/g, '')
  // Only use the result if it has enough hex chars to be a plausible UID (≥4)
  return cleaned.length >= 4 ? cleaned : ''
}

export function MembersPage() {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const breakpoint = useBreakpoint()
  const { setIsLoading } = useLoading()
  const [members, setMembers] = useState<MemberListItem[]>([])
  const [totalMembers, setTotalMembers] = useState(0)
  const [activeMembersCount, setActiveMembersCount] = useState(0)
  const [totalBalance, setTotalBalance] = useState(0)
  const [lastSettlementDate, setLastSettlementDate] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [showModal, setShowModal] = useState(false)
  const [editingMember, setEditingMember] = useState<Member | null>(null)
  const [sortKey, setSortKey] = useState<'first_name' | 'last_name' | 'created_at' | 'card_uid'>('created_at')
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('desc')
  const [filterIsActive, setFilterIsActive] = useState<'all' | 'active' | 'inactive'>('all')
  const [filterCardUid, setFilterCardUid] = useState<'all' | 'with' | 'without'>('all')
  const [filterSepaStatus, setFilterSepaStatus] = useState<'all' | 'valid' | 'invalid'>('all')
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
  const [extractedFields, setExtractedFields] = useState<ExtractionResult | null>(null)
  const [preExtractionFormData, setPreExtractionFormData] = useState<typeof formData | null>(null)
  const [scanFile, setScanFile] = useState<File | null>(null)
  const [scanExtracting, setScanExtracting] = useState(false)
  const scanInputRef = useRef<HTMLInputElement>(null)

  const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'
  const [showMobileFilters, setShowMobileFilters] = useState(false)
  const [exporting, setExporting] = useState(false)
  const [anonymizeConfirm, setAnonymizeConfirm] = useState<MemberListItem | null>(null)

  const mobileFilterCount = [
    filterIsActive !== 'all' ? 1 : 0,
    filterCardUid !== 'all' ? 1 : 0,
    filterSepaStatus !== 'all' ? 1 : 0,
  ].reduce((a, b) => a + b, 0)

  const mobileSortOptions = [
    { value: 'last_name_asc', label: t('members.sortName', 'Name A–Z'), direction: 'asc' as const },
    { value: 'last_name_desc', label: t('members.sortNameDesc', 'Name Z–A'), direction: 'desc' as const },
    { value: 'card_uid_asc', label: t('members.sortCard', 'Card-UID ↑'), direction: 'asc' as const },
    { value: 'created_at_desc', label: t('members.sortNewest', 'Newest first'), direction: 'desc' as const },
    { value: 'created_at_asc', label: t('members.sortOldest', 'Oldest first'), direction: 'asc' as const },
  ]

  const mobileSortValue = `${sortKey}_${sortDirection}`

  const handleMobileSortChange = (value: string) => {
    const lastUnderscore = value.lastIndexOf('_')
    const key = value.substring(0, lastUnderscore) as typeof sortKey
    const dir = value.substring(lastUnderscore + 1) as 'asc' | 'desc'
    setSortKey(key)
    setSortDirection(dir)
    setPage(1)
  }

  const MobileFilterRow = ({ label, options, value, onChange, testId }: {
    label: string
    options: { value: string; label: string }[]
    value: string
    onChange: (v: string) => void
    testId: string
  }) => (
    <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
      <span style={{ fontSize: '12px', color: 'rgba(255,255,255,0.35)', fontWeight: 500, textTransform: 'uppercase', minWidth: '50px' }}>
        {label}
      </span>
      {options.map((opt) => (
        <button
          key={opt.value}
          data-testid={`${testId}-${opt.value}`}
          onClick={() => { onChange(opt.value); setPage(1) }}
          style={{
            padding: '4px 10px',
            borderRadius: '6px',
            border: 'none',
            background: value === opt.value ? 'rgba(59,130,246,0.2)' : 'rgba(255,255,255,0.04)',
            color: value === opt.value ? '#3b82f6' : 'rgba(255,255,255,0.5)',
            fontSize: '12px',
            fontWeight: value === opt.value ? 600 : 400,
            cursor: 'pointer',
          }}
        >
          {opt.label}
        </button>
      ))}
    </div>
  )

  // Build sort_by param from sortKey + sortDirection
  const buildSortBy = (key: string, dir: 'asc' | 'desc'): ListMembersSortBy => {
    if (key === 'first_name' || key === 'last_name') return dir === 'asc' ? 'name_asc' : 'name_desc'
    return 'created_at_desc' // API only supports created_at_desc for date sorting
  }

  // Load members
  useEffect(() => {
    const loadMembers = async () => {
      try {
        setLoading(true)
        setIsLoading(true)

        const params: ListMembersParams = {
          page,
          per_page: 20,
          sort_by: buildSortBy(sortKey, sortDirection),
        }

        if (search) params.search = search
        if (filterIsActive !== 'all') params.status = filterIsActive as ListMembersStatus
        if (filterSepaStatus !== 'all') params.sepa_status = filterSepaStatus as ListMembersSepaStatus
        if (filterCardUid !== 'all') params.has_card_uid = filterCardUid as ListMembersHasCardUid

        const response = await getMembersFactory().listMembers(params)

        setMembers(response.data ?? [])
        setTotalMembers(response.pagination?.total ?? 0)

        setError(null)
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Failed to load members')
      } finally {
        setLoading(false)
        setIsLoading(false)
      }
    }

    const timer = setTimeout(loadMembers, search ? 500 : 0) // Debounce search
    return () => clearTimeout(timer)
  }, [page, search, filterIsActive, filterCardUid, filterSepaStatus, sortKey, sortDirection, setIsLoading])

  // Load dashboard metrics (active members count, outstanding balance, last settlement date)
  useEffect(() => {
    const loadDashboardMetrics = async () => {
      try {
        const dashboard = await getDashboard().getDashboardMetrics()
        setActiveMembersCount(dashboard.metrics?.active_members ?? 0)
        setTotalBalance(dashboard.metrics?.outstanding_balance_cents ?? 0)
        setLastSettlementDate(dashboard.system_status?.last_settlement_date ?? null)
      } catch {
        // Silently fail - stats are not critical
      }
    }

    loadDashboardMetrics()
  }, [])

  // Handle GDPR data export — downloads a JSON file with the member's personal data
  const handleExportData = async () => {
    if (!editingMember?.id) return
    setExporting(true)
    try {
      const data = await getMembersFactory().exportMemberData(editingMember.id, { format: 'json', export_type: 'gdpr_access' })
      const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `gdpr-export-${editingMember.id}.json`
      document.body.appendChild(a)
      a.click()
      document.body.removeChild(a)
      URL.revokeObjectURL(url)
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to export member data')
    } finally {
      setExporting(false)
    }
  }

  // Handle form submission
  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()

    try {
      setIsLoading(true)
      // Clear previous form errors
      setFormErrors({})

      // Client-side IBAN validation
      if (formData.iban && !validateIban(formData.iban)) {
        setFormErrors({ iban: t('members.validation.invalidIban') })
        setIsLoading(false)
        return
      }

      if (editingMember) {
        if (!editingMember.id) throw new Error('Missing member id')
        const updatePayload: MemberUpdateRequest = {
          first_name: formData.first_name,
          last_name: formData.last_name,
          email: formData.email || null,
          iban: formData.iban,
          account_holder_name: formData.account_holder_name || null,
          mandate_reference: formData.mandate_reference || undefined,
          mandate_signed_at: formData.mandate_signed_at,
          preferred_language: formData.preferred_language,
          card_uid: formData.card_uid || null,
        }
        await getMembersFactory().updateMember(editingMember.id, updatePayload)
      } else {
        const createPayload: MemberCreateRequest = {
          first_name: formData.first_name,
          last_name: formData.last_name,
          email: formData.email || undefined,
          iban: formData.iban,
          account_holder_name: formData.account_holder_name || undefined,
          mandate_reference: formData.mandate_reference || undefined,
          mandate_signed_at: formData.mandate_signed_at,
          preferred_language: formData.preferred_language,
          card_uid: formData.card_uid || undefined,
        }
        const newMember = await getMembersFactory().createMember(createPayload)
        const newMemberId = newMember?.id
        if (scanFile && newMemberId) {
          try {
            await uploadMandateDocument(newMemberId, scanFile)
          } catch {
            // Document upload failed but member was created — non-fatal
          }
          setScanFile(null)
        }
      }

      // Reset form
      setShowModal(false)
      setEditingMember(null)
      setExtractedFields(null)
      setPreExtractionFormData(null)
      setScanFile(null)
      setFormData({ first_name: '', last_name: '', email: '', iban: '', account_holder_name: '', mandate_reference: '', mandate_signed_at: '', preferred_language: 'de', card_uid: '' })

      // Reload members list
      const reloadParams: ListMembersParams = {
        page,
        per_page: 20,
        sort_by: buildSortBy(sortKey, sortDirection),
      }
      if (search) reloadParams.search = search
      if (filterIsActive !== 'all') reloadParams.status = filterIsActive as ListMembersStatus

      const reloadResponse = await getMembersFactory().listMembers(reloadParams)
      setMembers(reloadResponse.data ?? [])
      setTotalMembers(reloadResponse.pagination?.total ?? 0)

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
          setError('Validation failed')
        }
      } else {
        setError(err instanceof Error ? err.message : 'Failed to save member')
      }
    } finally {
      setIsLoading(false)
    }
  }

  // Handle status toggle (activate/deactivate)
  const handleStatusToggle = async (member: MemberListItem) => {
    try {
      setIsLoading(true)
      if (!member.id) return
      // Only send the field that needs to be updated
      await getMembersFactory().updateMember(member.id, { is_active: !member.is_active })

      // Reload members list
      const reloadParams: ListMembersParams = {
        page,
        per_page: 20,
        sort_by: buildSortBy(sortKey, sortDirection),
      }
      if (search) reloadParams.search = search
      if (filterIsActive !== 'all') reloadParams.status = filterIsActive as ListMembersStatus

      const reloadResponse = await getMembersFactory().listMembers(reloadParams)
      setMembers(reloadResponse.data ?? [])
      setTotalMembers(reloadResponse.pagination?.total ?? 0)

      setError(null)
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to update member status')
    } finally {
      setIsLoading(false)
    }
  }

  // Handle anonymize member (GDPR Art. 17)
  const handleAnonymize = async (member: MemberListItem) => {
    try {
      setIsLoading(true)
      if (!member.id) return
      await getMembersFactory().anonymizeMember(member.id, {})

      // Reload members list
      const reloadParams: ListMembersParams = {
        page,
        per_page: 20,
        sort_by: buildSortBy(sortKey, sortDirection),
      }
      if (search) reloadParams.search = search
      if (filterIsActive !== 'all') reloadParams.status = filterIsActive as ListMembersStatus

      const reloadResponse = await getMembersFactory().listMembers(reloadParams)
      setMembers(reloadResponse.data ?? [])
      setTotalMembers(reloadResponse.pagination?.total ?? 0)

      setAnonymizeConfirm(null)
      setError(null)
    } catch (err: unknown) {
      const axiosErr = err as { response?: { status?: number; data?: unknown } }
      if (axiosErr.response?.status === 409) {
        const data = axiosErr.response.data as { message?: string } | null
        setError(data?.message || 'Cannot anonymize member')
      } else {
        setError(err instanceof Error ? err.message : 'Failed to anonymize member')
      }
      setAnonymizeConfirm(null)
    } finally {
      setIsLoading(false)
    }
  }

  // Handle edit member — fetch full member details first
  const handleEdit = async (member: MemberListItem) => {
    if (!member.id) return
    try {
      const fullMember = await getMembersFactory().getMember(member.id)
      setEditingMember(fullMember)
      setFormData({
        first_name: fullMember.first_name ?? '',
        last_name: fullMember.last_name ?? '',
        email: fullMember.email ?? '',
        iban: fullMember.iban ?? '',
        account_holder_name: fullMember.account_holder_name ?? '',
        mandate_reference: fullMember.mandate_reference ?? '',
        mandate_signed_at: fullMember.mandate_signed_at ?? '',
        preferred_language: fullMember.preferred_language ?? 'de',
        card_uid: fullMember.card_uid ?? '',
      })
      setShowModal(true)
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Failed to load member details')
    }
  }

  const handleDownloadSepaTemplate = async () => {
    try {
      await downloadFile('/admin/sepa-mandate-template', 'sepa-mandate-template.pdf')
    } catch {
      setError(t('members.sepaTemplateError', 'SEPA configuration is incomplete. Please configure creditor details in Settings first.'))
    }
  }

  function handleExtractionComplete(extraction: ExtractionResult) {
    setPreExtractionFormData({ ...formData })
    setExtractedFields(extraction)

    const updates: Partial<typeof formData> = {}
    const f = extraction.fields
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
    setScanFile(file)
    setEditingMember(null)
    setExtractedFields(null)
    setFormErrors({})
    setShowModal(true)
    try {
      const result = await extractMandateDocument(file)
      const initialForm = { first_name: '', last_name: '', email: '', iban: '', account_holder_name: '', mandate_reference: '', mandate_signed_at: '', preferred_language: 'de', card_uid: '' }
      setFormData({
        ...initialForm,
        first_name:           result.fields.first_name?.value           ?? '',
        last_name:            result.fields.last_name?.value            ?? '',
        email:                result.fields.email?.value                ?? '',
        iban:                 (result.fields.iban?.value ?? '').replace(/\s/g, '').toUpperCase(),
        account_holder_name:  result.fields.account_holder_name?.value  ?? '',
        mandate_signed_at:    result.fields.mandate_signed_at?.value    ?? '',
        card_uid:             normalizeCardUid(result.fields.card_uid?.value),
      })
      setExtractedFields(result)
      setPreExtractionFormData(null)
    } catch {
      setError(t('mandateDocument.extractionFailed'))
      setScanFile(null)
      setShowModal(false)
    } finally {
      setScanExtracting(false)
    }
  }

  function confidenceBadge(fieldName: keyof ExtractionResult['fields']) {
    if (!extractedFields) return null
    const field = extractedFields.fields[fieldName]
    if (!field?.confidence) return null
    const styles: Record<string, React.CSSProperties> = {
      high:   { background: 'rgba(34,197,94,0.15)',  color: '#86efac', border: '1px solid rgba(34,197,94,0.3)'  },
      medium: { background: 'rgba(234,179,8,0.15)',  color: '#fde047', border: '1px solid rgba(234,179,8,0.3)'  },
      low:    { background: 'rgba(239,68,68,0.15)',   color: '#fca5a5', border: '1px solid rgba(239,68,68,0.3)'  },
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
    <div data-testid="members-page" style={{ padding: '20px' }}>
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
          value={activeMembersCount}
          color="green"
        />
        <StatCard
          icon={<BankIcon />}
          label={t('members.stats.openItems')}
          value={formatters.formatPrice(totalBalance)}
          color="blue"
        />
        <StatCard
          icon={<CalendarIcon />}
          label={t('members.stats.lastSettlementDate')}
          value={lastSettlementDate ? formatters.formatDate(lastSettlementDate.split('T')[0]) : '—'}
          color="blue"
        />
      </div>

      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '28px', gap: '12px' }}>
        <div style={{ minWidth: 0 }}>
          <h1 style={{ fontSize: '26px', fontWeight: 700, margin: 0, letterSpacing: '-0.02em' }}>{t('members.title')}</h1>
          <p data-testid="members-count-summary" style={{ margin: '4px 0 0', fontSize: '14px', color: 'rgba(255,255,255,0.4)' }}>
            {totalMembers} {t('members.title')} {t('common.found')}
          </p>
        </div>
        <div style={{ display: 'flex', gap: isMobile ? '6px' : '8px', flexShrink: 0 }}>
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
          <button
            data-testid="members-new-from-scan-button"
            onClick={() => scanInputRef.current?.click()}
            disabled={scanExtracting}
            title={scanExtracting ? t('mandateDocument.uploadingAndExtracting') : t('members.newFromScan')}
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: '7px',
              padding: isMobile ? '10px' : '10px 20px',
              borderRadius: '8px',
              border: '1px solid rgba(255,255,255,0.15)',
              background: 'transparent',
              color: scanExtracting ? 'rgba(255,255,255,0.4)' : '#fff',
              fontSize: '14px',
              fontWeight: 600,
              cursor: scanExtracting ? 'wait' : 'pointer',
            }}
          >
            <DownloadIcon size={18} />
            {!isMobile && (scanExtracting ? t('mandateDocument.uploadingAndExtracting') : t('members.newFromScan'))}
          </button>
          <button
            data-testid="members-sepa-template-download-button"
            onClick={handleDownloadSepaTemplate}
            title={t('members.downloadSepaTemplate', 'SEPA Template')}
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: '7px',
              padding: isMobile ? '10px' : '10px 20px',
              borderRadius: '8px',
              border: '1px solid rgba(255,255,255,0.15)',
              background: 'transparent',
              color: '#fff',
              fontSize: '14px',
              fontWeight: 600,
              cursor: 'pointer',
              transition: 'background 0.15s',
            }}
          >
            <DownloadIcon size={18} />
            {!isMobile && t('members.downloadSepaTemplate', 'SEPA Template')}
          </button>
          <button
            data-testid="members-create-button"
            onClick={() => {
              setEditingMember(null)
              setFormData({ first_name: '', last_name: '', email: '', iban: '', account_holder_name: '', mandate_reference: '', mandate_signed_at: '', preferred_language: 'de', card_uid: '' })
              setFormErrors({})
              setShowModal(true)
            }}
            title={t('common.add')}
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: '7px',
              padding: isMobile ? '10px' : '10px 20px',
              borderRadius: '8px',
              border: 'none',
              background: '#3b82f6',
              color: '#fff',
              fontSize: '14px',
              fontWeight: 600,
              cursor: 'pointer',
              transition: 'background 0.15s',
            }}
          >
            <PlusIcon size={18} />
            {!isMobile && t('common.add')}
          </button>
        </div>
      </div>

      {isMobile ? (
        <>
          <MobileToolbar
            testId="members-mobile-toolbar"
            search={{
              value: search,
              onChange: (v) => { setSearch(v); setPage(1) },
              testId: 'members-search-input',
            }}
            sort={{
              options: mobileSortOptions,
              value: mobileSortValue,
              onChange: handleMobileSortChange,
            }}
            filterCount={mobileFilterCount}
            onFilterToggle={() => setShowMobileFilters(!showMobileFilters)}
            showFilters={showMobileFilters}
            filterContent={
              <>
                <MobileFilterRow
                  label={t('members.filterStatus', 'Status')}
                  options={[
                    { value: 'all', label: t('common.all') },
                    { value: 'active', label: t('common.active') },
                    { value: 'inactive', label: t('common.inactive') },
                  ]}
                  value={filterIsActive}
                  onChange={(v) => setFilterIsActive(v as typeof filterIsActive)}
                  testId="members-mobile-filter-status"
                />
                <MobileFilterRow
                  label={t('members.filterCard', 'Card')}
                  options={[
                    { value: 'all', label: t('common.all') },
                    { value: 'with', label: t('members.filterWithCard', 'With') },
                    { value: 'without', label: t('members.filterWithoutCard', 'Without') },
                  ]}
                  value={filterCardUid}
                  onChange={(v) => setFilterCardUid(v as typeof filterCardUid)}
                  testId="members-mobile-filter-card"
                />
                <MobileFilterRow
                  label="SEPA"
                  options={[
                    { value: 'all', label: t('common.all') },
                    { value: 'valid', label: t('members.filterSepaValid', 'Valid') },
                    { value: 'invalid', label: t('members.filterSepaMissing', 'Missing') },
                  ]}
                  value={filterSepaStatus}
                  onChange={(v) => setFilterSepaStatus(v as typeof filterSepaStatus)}
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
                    background: 'rgba(255,255,255,0.03)',
                    border: '1px solid rgba(255,255,255,0.06)',
                    borderRadius: '10px',
                    padding: '14px 16px',
                  }}
                >
                  {/* Row 1: toggle + name */}
                  <div style={{ display: 'flex', alignItems: 'center', gap: '10px', marginBottom: '8px' }}>
                    <Toggle
                      enabled={member.is_active ?? false}
                      onChange={() => handleStatusToggle(member)}
                      size="small"
                      testId={`members-status-toggle-${member.id}`}
                    />
                    <span style={{ flex: 1, fontWeight: 600, color: theme.colors.text.primary, fontSize: '14px' }}>
                      {member.first_name} {member.last_name}
                    </span>
                  </div>
                  {/* Row 2: SEPA + Card info */}
                  <div style={{ display: 'flex', gap: '12px', fontSize: '12px', color: theme.colors.text.secondary, marginBottom: '6px' }}>
                    <span>
                      SEPA: {member.is_sepa_valid ? (
                        <span style={{ color: theme.colors.semantic.success }}>{t('members.sepaValid', 'Valid')}</span>
                      ) : (
                        <span style={{ color: theme.colors.text.muted }}>{t('members.sepaMissing', 'Missing')}</span>
                      )}
                    </span>
                    {member.card_uid && <span>Card: {member.card_uid}</span>}
                  </div>
                  {/* Row 3: member since */}
                  <div style={{ fontSize: '12px', color: theme.colors.text.muted, marginBottom: '10px' }}>
                    {t('members.memberSince', 'Since')}: {member.created_at ? formatters.formatDate(member.created_at.split('T')[0]) : '—'}
                  </div>
                  {/* Row 4: actions */}
                  <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '8px' }}>
                    <button
                      data-testid={`member-edit-${member.id}`}
                      onClick={() => handleEdit(member)}
                      style={{
                        display: 'flex', alignItems: 'center', gap: '4px',
                        padding: '6px 12px', borderRadius: '6px', border: 'none',
                        background: 'rgba(59,130,246,0.1)', color: theme.colors.semantic.primary,
                        fontSize: '12px', cursor: 'pointer',
                      }}
                    >
                      <EditIcon size={14} /> {t('common.edit')}
                    </button>
                    <button
                      data-testid={`member-anonymize-${member.id}`}
                      onClick={() => setAnonymizeConfirm(member)}
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
              currentPage={page}
              totalPages={Math.ceil(totalMembers / 20)}
              totalItems={totalMembers}
              pageSize={20}
              onPageChange={setPage}
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
          background: 'rgba(255,255,255,0.03)',
          borderRadius: '10px',
          border: '1px solid rgba(255,255,255,0.06)',
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
            <circle cx="7" cy="7" r="5.5" stroke="#fff" strokeWidth="1.5" />
            <path d="M11 11l3.5 3.5" stroke="#fff" strokeWidth="1.5" strokeLinecap="round" />
          </svg>
          <input
            type="text"
            value={search}
            onChange={(e) => {
              setSearch(e.target.value)
              setPage(1)
            }}
            placeholder={t('common.searchPlaceholder')}
            data-testid="members-search-input"
            style={{
              width: '100%',
              padding: '8px 12px 8px 32px',
              borderRadius: '7px',
              border: '1px solid rgba(255,255,255,0.08)',
              background: 'rgba(255,255,255,0.04)',
              color: '#e2e8f0',
              fontSize: '13px',
              outline: 'none',
            }}
          />
        </div>

        {/* Divider */}
        <div style={{ width: '1px', height: '28px', background: 'rgba(255,255,255,0.08)' }} />

        {/* Status filter group */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
          <span
            style={{
              fontSize: '12px',
              color: 'rgba(255,255,255,0.35)',
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
              setFilterIsActive('all')
              setPage(1)
            }}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: 'none',
              fontSize: '13px',
              fontWeight: 500,
              cursor: 'pointer',
              transition: 'all 0.15s ease',
              background: filterIsActive === 'all' ? '#3b82f6' : 'rgba(255,255,255,0.06)',
              color: filterIsActive === 'all' ? '#fff' : 'rgba(255,255,255,0.55)',
            }}
          >
            {t('members.filters.status.all')}
          </button>
          <button
            data-testid="members-filter-status-active"
            aria-pressed={filterIsActive === 'active'}
            onClick={() => {
              setFilterIsActive('active')
              setPage(1)
            }}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: 'none',
              fontSize: '13px',
              fontWeight: 500,
              cursor: 'pointer',
              transition: 'all 0.15s ease',
              background: filterIsActive === 'active' ? '#3b82f6' : 'rgba(255,255,255,0.06)',
              color: filterIsActive === 'active' ? '#fff' : 'rgba(255,255,255,0.55)',
            }}
          >
            {t('members.filters.status.active')}
          </button>
          <button
            data-testid="members-filter-status-inactive"
            aria-pressed={filterIsActive === 'inactive'}
            onClick={() => {
              setFilterIsActive('inactive')
              setPage(1)
            }}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: 'none',
              fontSize: '13px',
              fontWeight: 500,
              cursor: 'pointer',
              transition: 'all 0.15s ease',
              background: filterIsActive === 'inactive' ? '#3b82f6' : 'rgba(255,255,255,0.06)',
              color: filterIsActive === 'inactive' ? '#fff' : 'rgba(255,255,255,0.55)',
            }}
          >
            {t('members.filters.status.inactive')}
          </button>
        </div>

        {/* Divider */}
        <div style={{ width: '1px', height: '28px', background: 'rgba(255,255,255,0.08)' }} />

        {/* Card filter group */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
          <span
            style={{
              fontSize: '12px',
              color: 'rgba(255,255,255,0.35)',
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
              setFilterCardUid('all')
              setPage(1)
            }}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: 'none',
              fontSize: '13px',
              fontWeight: 500,
              cursor: 'pointer',
              transition: 'all 0.15s ease',
              background: filterCardUid === 'all' ? '#3b82f6' : 'rgba(255,255,255,0.06)',
              color: filterCardUid === 'all' ? '#fff' : 'rgba(255,255,255,0.55)',
            }}
          >
            {t('members.filters.card.all')}
          </button>
          <button
            data-testid="filter-card-with"
            onClick={() => {
              setFilterCardUid('with')
              setPage(1)
            }}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: 'none',
              fontSize: '13px',
              fontWeight: 500,
              cursor: 'pointer',
              transition: 'all 0.15s ease',
              background: filterCardUid === 'with' ? '#3b82f6' : 'rgba(255,255,255,0.06)',
              color: filterCardUid === 'with' ? '#fff' : 'rgba(255,255,255,0.55)',
            }}
          >
            {t('members.filters.card.withCard')}
          </button>
          <button
            data-testid="filter-card-without"
            onClick={() => {
              setFilterCardUid('without')
              setPage(1)
            }}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: 'none',
              fontSize: '13px',
              fontWeight: 500,
              cursor: 'pointer',
              transition: 'all 0.15s ease',
              background: filterCardUid === 'without' ? '#3b82f6' : 'rgba(255,255,255,0.06)',
              color: filterCardUid === 'without' ? '#fff' : 'rgba(255,255,255,0.55)',
            }}
          >
            {t('members.filters.card.withoutCard')}
          </button>
        </div>

        {/* Divider */}
        <div style={{ width: '1px', height: '28px', background: 'rgba(255,255,255,0.08)' }} />

        {/* SEPA filter group */}
        <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
          <span
            style={{
              fontSize: '12px',
              color: 'rgba(255,255,255,0.35)',
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
              setFilterSepaStatus('all')
              setPage(1)
            }}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: 'none',
              fontSize: '13px',
              fontWeight: 500,
              cursor: 'pointer',
              transition: 'all 0.15s ease',
              background: filterSepaStatus === 'all' ? '#3b82f6' : 'rgba(255,255,255,0.06)',
              color: filterSepaStatus === 'all' ? '#fff' : 'rgba(255,255,255,0.55)',
            }}
          >
            {t('members.filters.sepa.all')}
          </button>
          <button
            data-testid="filter-sepa-valid"
            onClick={() => {
              setFilterSepaStatus('valid')
              setPage(1)
            }}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: 'none',
              fontSize: '13px',
              fontWeight: 500,
              cursor: 'pointer',
              transition: 'all 0.15s ease',
              background: filterSepaStatus === 'valid' ? '#3b82f6' : 'rgba(255,255,255,0.06)',
              color: filterSepaStatus === 'valid' ? '#fff' : 'rgba(255,255,255,0.55)',
            }}
          >
            {t('members.filters.sepa.valid')}
          </button>
          <button
            data-testid="filter-sepa-missing"
            onClick={() => {
              setFilterSepaStatus('invalid')
              setPage(1)
            }}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: 'none',
              fontSize: '13px',
              fontWeight: 500,
              cursor: 'pointer',
              transition: 'all 0.15s ease',
              background: filterSepaStatus === 'invalid' ? '#3b82f6' : 'rgba(255,255,255,0.06)',
              color: filterSepaStatus === 'invalid' ? '#fff' : 'rgba(255,255,255,0.55)',
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
                setSearch('')
                setFilterIsActive('all')
                setFilterCardUid('all')
                setFilterSepaStatus('all')
                setPage(1)
              }}
              data-testid="members-clear-filters"
              style={{
                padding: '6px 12px',
                borderRadius: '6px',
                border: '1px solid rgba(255,255,255,0.08)',
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
        {error && (
          <div
            data-testid="members-error-message"
            style={{
              padding: theme.spacing.lg,
              background: `${theme.colors.semantic.danger}20`,
              borderBottom: `1px solid ${theme.colors.semantic.danger}`,
              color: theme.colors.semantic.danger,
              fontSize: theme.typography.fontSize.sm,
            }}
          >
            {error}
          </div>
        )}

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
                      sortKey="first_name"
                      currentSort={{ key: sortKey, direction: sortDirection }}
                      onSort={(key: string, direction: 'asc' | 'desc') => {
                        setSortKey(key as 'first_name' | 'last_name' | 'created_at' | 'card_uid')
                        setSortDirection(direction)
                        setPage(1)
                      }}
                    />
                  </th>
                  <th style={{ ...headerCellBaseStyle, width: '150px' }}>
                    <SortableTableHeader
                      label={t('members.table.cardUid')}
                      sortKey="card_uid"
                      currentSort={{ key: sortKey, direction: sortDirection }}
                      onSort={(key: string, direction: 'asc' | 'desc') => {
                        setSortKey(key as 'first_name' | 'last_name' | 'created_at' | 'card_uid')
                        setSortDirection(direction)
                        setPage(1)
                      }}
                    />
                  </th>
                  <th style={{ ...headerCellBaseStyle, width: '120px' }}>
                    <SortableTableHeader
                      label={t('members.memberSince')}
                      sortKey="created_at"
                      currentSort={{ key: sortKey, direction: sortDirection }}
                      onSort={(key: string, direction: 'asc' | 'desc') => {
                        setSortKey(key as 'first_name' | 'last_name' | 'created_at' | 'card_uid')
                        setSortDirection(direction)
                        setPage(1)
                      }}
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
                          color: '#ffffff',
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
                        title="Edit"
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
            currentPage={page}
            totalPages={Math.ceil(totalMembers / 20)}
            totalItems={totalMembers}
            pageSize={20}
            onPageChange={setPage}
            onPageSizeChange={() => {}} // Not implemented - always use 20
            variant="default"
            showPageSize={false}
            showInfo={true}
            testId="members-pagination"
          />
        )}
        </>
      )}

      {/* Create/Edit Modal */}
      {showModal && (
        <div
          data-testid="members-form-modal"
          style={{
            position: 'fixed',
            top: 0,
            left: 0,
            right: 0,
            bottom: 0,
            background: 'rgba(0, 0, 0, 0.5)',
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'center',
            zIndex: 1100,
          }}
          onClick={() => { setShowModal(false); setExtractedFields(null); setPreExtractionFormData(null); setScanFile(null) }}
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
              boxShadow: isMobile ? 'none' : '0 25px 50px rgba(0, 0, 0, 0.5)',
              maxHeight: isMobile ? '100%' : '90vh',
              overflowY: 'auto',
            }}
            onClick={(e) => e.stopPropagation()}
          >
            <style>{`@keyframes members-spin { to { transform: rotate(360deg); } }`}</style>

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
                  borderTopColor: '#c4b5fd',
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
                    ? 'rgba(34, 197, 94, 0.1)'
                    : 'rgba(239, 68, 68, 0.1)',
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
                <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
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
                <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
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
                <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
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
                      border: `1px solid ${theme.colors.border.light}`,
                      borderRadius: theme.borderRadius.md,
                      color: theme.colors.text.primary,
                      boxSizing: 'border-box',
                    }}
                  />
                  {confidenceBadge('email')}
                </div>
              </div>

              <div>
                <label style={{ display: 'block', marginBottom: theme.spacing.sm, fontSize: theme.typography.fontSize.sm, fontWeight: 600 }}>
                  {t('members.form.cardUid')}
                  <span style={{ color: theme.colors.text.secondary, marginLeft: theme.spacing.xs, fontWeight: 400 }}>
                    ({t('common.requiredForTerminal')})
                  </span>
                </label>
                <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
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
                {formData.card_uid && !/^[0-9A-F]{8,20}$/.test(formData.card_uid) && (
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
                  {t('members.iban')} <span style={{ color: theme.colors.semantic.danger }}>*</span> <span style={{ color: theme.colors.text.secondary, marginLeft: theme.spacing.xs, fontWeight: 400 }}>({t('common.sepa')})</span>
                  <ValidationIndicator
                    isValid={validateIban(formData.iban)}
                    show={formData.iban.length > 0}
                    testId="members-form-iban-validation"
                  />
                </label>
                <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                  <input
                    data-testid="members-form-iban-input"
                    type="text"
                    required
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
                    <div style={{ fontSize: '11px', fontWeight: 700, color: '#fde047', marginBottom: '8px' }}>
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
                        <span style={{ fontSize: '10px', color: formData.iban === candidate ? '#93c5fd' : theme.colors.text.secondary }}>
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
                <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
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
                  {t('members.mandateSignedAt')} <span style={{ color: theme.colors.semantic.danger }}>*</span> <span style={{ color: theme.colors.text.secondary, marginLeft: theme.spacing.xs, fontWeight: 400 }}>({t('common.sepa')})</span>
                </label>
                <div style={{ display: 'flex', alignItems: 'center', gap: '6px' }}>
                  <input
                    data-testid="members-form-mandate-date-input"
                    type="date"
                    required
                    value={formData.mandate_signed_at}
                    onChange={(e) => setFormData({ ...formData, mandate_signed_at: e.target.value })}
                    max={new Date().toISOString().split('T')[0]}
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
                    color: '#c4b5fd',
                    marginBottom: '12px',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
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
                      color: '#c4b5fd',
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
                    onClick={() => { setShowModal(false); setExtractedFields(null); setPreExtractionFormData(null); setScanFile(null) }}
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
              <MandateDocumentSection
                memberId={editingMember.id}
                initialDocument={editingMember.mandate_document ?? null}
                onExtractionComplete={handleExtractionComplete}
              />
            )}
          </div>
        </div>
      )}


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
            background: 'rgba(0, 0, 0, 0.5)',
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
              boxShadow: '0 25px 50px rgba(0, 0, 0, 0.5)',
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
