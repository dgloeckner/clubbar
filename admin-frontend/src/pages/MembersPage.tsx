/**
 * Members Page
 * Member management (list, create, edit, delete)
 */

import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react'
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
import { useApiError } from '../hooks/useApiError'
import { UsersIcon, BankIcon, CalendarIcon, EditIcon, PlusIcon, DownloadIcon, ExternalLinkIcon } from '../components/icons'
import { downloadBlob } from '../api/client'
import { getMembers as getMembersFactory } from '../api/generated/members/members'
import { getDashboard } from '../api/generated/dashboard/dashboard'
import { getSepaConfiguration } from '../api/generated/sepa-configuration/sepa-configuration'
import { getCreditLimits } from '../api/generated/credit-limits/credit-limits'
import type { Member, MemberListItem, ListMembersParams, ListMembersStatus, ListMembersSepaStatus, ListMembersHasCardUid, ListMembersHasEmail, ListMembersHasDateOfBirth, ListMembersDataStatus, MemberCreateRequest, MemberUpdateRequest, MemberDataCompleteness } from '../api/generated'
// TableSearchToolbar is available but not currently used
// import { TableSearchToolbar } from '../components/tables/TableSearchToolbar'
import { MobileFilterRow } from '../components/tables/MobileFilterRow'
import { PaginationToolbar } from '../components/tables/PaginationToolbar'
import { SortableTableHeader } from '../components/tables/SortableTableHeader'
import { StatusToggleCell } from '../components/tables/StatusToggleCell'
import { Toggle } from '../components/common/Toggle'
import { TableCell } from '../components/tables/TableCell'
import { LanguageSelector } from '../components/forms/LanguageSelector'
import { DateField } from '../components/forms/DateField'
import { validateIban } from '../utils/iban'
import {
  creditLimitToInput,
  creditLimitFromInput,
  isValidCreditLimitInput,
  MAX_CREDIT_LIMIT_CENTS,
} from '../utils/creditLimit'
import { toIsoDate } from '../utils/dates'
import { buildMemberSortBy, type MemberSortKey } from '../utils/memberSort'
import { getBalanceColor } from '../utils/transactions'
import { useLatestRequest } from '../hooks/useLatestRequest'
import { useListQuery } from '../hooks/useListQuery'
import { MemberIbanField } from '../components/members/MemberIbanField'
import { ClearedValueNotice } from '../components/members/ClearedValueNotice'
import { MemberDataQualityPanel } from '../components/members/MemberDataQualityPanel'
import { MemberGapChips } from '../components/members/MemberGapChips'
import { MemberFormRequirements } from '../components/members/MemberFormRequirements'
import { FieldLabel } from '../components/forms/FieldLabel'
import {
  MEMBER_REQUIRED_FIELDS,
  clearedFields,
  isPlausibleEmail,
  missingRequiredFields,
  type MemberClearableField,
  type MemberRequiredField,
} from '../utils/memberFormRequirements'
import { MemberMandateReferenceField } from '../components/members/MemberMandateReferenceField'
import { deriveSepaFormStatus } from '../utils/sepaStatus'
import { Alert } from '../components/common/Alert'
import { ConfirmDialog } from '../components/modals/ConfirmDialog'
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

/**
 * The member form's input styling, in one place because ten copies of it is
 * how the danger border came to be applied to four of the fields and not the
 * other six. `invalid` is what a field with a complaint under it looks like.
 *
 * The two date fields do not use it: they render through `DateField`, which
 * owns its own styling and takes `invalid` as a prop (#631).
 */
function formInputStyle(invalid: boolean): React.CSSProperties {
  return {
    width: '100%',
    padding: `${theme.spacing.md} ${theme.spacing.lg}`,
    background: theme.colors.bg.input,
    border: `1px solid ${invalid ? theme.colors.semantic.danger : theme.colors.border.light}`,
    borderRadius: theme.borderRadius.md,
    color: theme.colors.text.primary,
    boxSizing: 'border-box',
  }
}

const formFieldErrorStyle: React.CSSProperties = {
  color: theme.colors.semantic.danger,
  fontSize: theme.typography.fontSize.sm,
  marginTop: theme.spacing.xs,
  marginBottom: 0,
}

/**
 * Floor for the birth-date field. Nobody in a rowing club was born in 1723, so
 * the calendar's year view has somewhere to stop paging and a typo that turns
 * 1979 into 179 is refused by the field rather than by the API.
 */
const EARLIEST_BIRTH_DATE = toIsoDate(new Date(new Date().getFullYear() - 120, 0, 1))

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
  email: 'all' | 'with' | 'without'
  /**
   * The two filters the Datenqualität panel drives (#629). They have no pills
   * of their own — the panel is their control surface, and "Filter
   * zurücksetzen" clears them along with the rest.
   */
  dateOfBirth: 'all' | 'with' | 'without'
  dataStatus: 'all' | 'complete' | 'incomplete'
}

/** Every filter back to "all" — the reset button and the panel both need it. */
const NO_MEMBER_FILTERS: MemberFilters = {
  status: 'all',
  cardUid: 'all',
  sepaStatus: 'all',
  email: 'all',
  dateOfBirth: 'all',
  dataStatus: 'all',
}

export function MembersPage() {
  const { t } = useTranslation()
  const { apiErrorMessage } = useApiError()
  const formatters = useFormatters()
  const breakpoint = useBreakpoint()
  // The dashboard metrics are a second, independent stream, so they get their
  // own abort slot — the member list's lives inside useListQuery (#96).
  const metricsRequest = useLatestRequest()
  // The SEPA-Vorlage link is a third, independent stream for the same reason.
  const sepaConfigRequest = useLatestRequest()
  // The club's credit ceiling is a fifth: it fills the override field's
  // placeholder, so an empty box reads as "inherits €100.00" rather than as
  // nothing at all (ADR-0047).
  const creditLimitConfigRequest = useLatestRequest()
  // The Datenqualität panel's counts are a fourth (#629).
  const completenessRequest = useLatestRequest()
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
  // The externally hosted registration form's link (#360/#456). Null while
  // loading or genuinely unset — either way the button that opens it stays
  // disabled, since there's nothing to send an admin to.
  const [mandateTemplateUrl, setMandateTemplateUrl] = useState<string | null>(null)
  // Null means "not known" — a failed load hides the panel rather than
  // claiming every member is complete, which is the one wrong answer here.
  const [completeness, setCompleteness] = useState<MemberDataCompleteness | null>(null)
  const [showModal, setShowModal] = useState(false)
  const [editingMember, setEditingMember] = useState<Member | null>(null)

  // One query, one fetch path: the loader effect and every post-mutation reload
  // go through the same state, so a reload can no longer drop the filters (#121).
  const list = useListQuery<MemberListItem, MemberFilters, MemberSortKey>({
    initialFilters: NO_MEMBER_FILTERS,
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
      if (filters.email !== 'all') params.has_email = filters.email as ListMembersHasEmail
      if (filters.dateOfBirth !== 'all') params.has_date_of_birth = filters.dateOfBirth as ListMembersHasDateOfBirth
      if (filters.dataStatus !== 'all') params.data_status = filters.dataStatus as ListMembersDataStatus

      const response = await getMembersFactory().listMembers(params, { signal })
      return { items: response.data ?? [], total: response.pagination?.total ?? 0 }
    },
    parseError: (err) => (err instanceof Error ? err.message : t('members.errors.load')),
  })

  /**
   * Seed the search box from `?search=`, once (#782).
   *
   * The registrations inbox sends an admin here straight after approving a
   * submission, and an unfiltered roster of several hundred members is a poor
   * confirmation that the one person they just created exists. There is no
   * member *detail* route in this app — members are this list plus a modal — so
   * a deep link into the search is the closest true thing.
   *
   * `list.setSearch` and not a re-render loop: the ref guard means typing in the
   * box afterwards is never overwritten by the parameter that put it there.
   *
   * `window.location` rather than `useLocation`: this reads once on mount, and
   * an implicit bare `location` here would be the DOM global by accident rather
   * than by decision.
   */
  const seededSearch = useRef(false)
  useEffect(() => {
    if (seededSearch.current) return
    seededSearch.current = true

    const seed = new URLSearchParams(window.location.search).get('search')
    if (seed) list.setSearch(seed)
    // Deliberately empty: this runs once, on mount, and re-running it when the
    // list identity changes would fight whatever the admin has typed since.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const { items: members, total: totalMembers, totalPages, loading, error, setError, search } = list
  const { status: filterIsActive, cardUid: filterCardUid, sepaStatus: filterSepaStatus, email: filterEmail, dateOfBirth: filterDateOfBirth, dataStatus: filterDataStatus } = list.filters
  const [formData, setFormData] = useState({
    first_name: '',
    last_name: '',
    email: '',
    date_of_birth: '',
    iban: '',
    account_holder_name: '',
    mandate_reference: '',
    mandate_signed_at: '',
    preferred_language: 'de',
    card_uid: '',
    // The member's own credit ceiling, in euros as typed (ADR-0047). Empty is
    // the ordinary case and means "follow the club default"; see
    // `utils/creditLimit.ts` for why empty and `0` must never collapse.
    credit_limit: '',
  })
  const [formErrors, setFormErrors] = useState<Record<string, string>>({})
  const [clubDefaultLimitCents, setClubDefaultLimitCents] = useState<number | null>(null)
  // Removing the stored account is its own act, because a blank IBAN field now
  // means "keep" (#392). Reset wherever the form is opened or cleared.
  const [removeStoredIban, setRemoveStoredIban] = useState(false)
  // Overwrite-only (ADR-0036): for a member who has bank details, the IBAN
  // input appears only on request. While it is hidden `formData.iban` cannot
  // hold anything, which is what makes "leave it alone to keep the account"
  // true by construction rather than by instruction.
  const [isReplacingIban, setIsReplacingIban] = useState(false)
  // The mandate reference is minted by the server (ADR-0006); typing one is
  // the migration case, so it too is opt-in rather than the default state.
  const [isEnteringMandateReference, setIsEnteringMandateReference] = useState(false)

  // Which of its three states each banking field is in. Derived rather than
  // stored, so there is no way for the flags and the rendering to disagree.
  const storedIbanMasked = editingMember?.iban_last4
    ? (editingMember.iban_masked ?? `****${editingMember.iban_last4}`)
    : null
  const ibanFieldMode = removeStoredIban
    ? 'removing'
    : storedIbanMasked && !isReplacingIban
      ? 'stored'
      : 'entry'

  const assignedMandateReference = editingMember?.mandate_reference ?? null
  const mandateReferenceMode = isEnteringMandateReference
    ? 'entry'
    : assignedMandateReference
      ? 'assigned'
      : 'auto'

  const sepaFormStatus = deriveSepaFormStatus({
    savedIsValid: Boolean(editingMember?.is_sepa_valid),
    hasStoredIban: Boolean(storedIbanMasked),
    removalPending: removeStoredIban,
    typedIban: formData.iban,
    mandateReference: formData.mandate_reference,
    mandateSignedAt: formData.mandate_signed_at,
  })

  /** Clears the banking fields' opt-in states — every path that opens or closes the form. */
  const resetBankingFieldModes = () => {
    setRemoveStoredIban(false)
    setIsReplacingIban(false)
    setIsEnteringMandateReference(false)
  }

  // ── What the form still needs, and what it would delete (#629) ───────────
  //
  // A `*` on five labels answered neither question. `submitAttempted` is what
  // turns the summary from a running count into a refusal: until a save has
  // actually been blocked, shouting about an empty field the admin has not
  // reached yet is noise.
  const [submitAttempted, setSubmitAttempted] = useState(false)

  // Focus targets for the summary's "jump to the field" buttons. Callback refs
  // rather than one ref per field: the map is keyed by the same field names the
  // requirement rules use, so a new required field cannot be added to one and
  // forgotten in the other.
  const fieldRefs = useRef<Record<string, HTMLElement | null>>({})
  const registerField = useCallback(
    (field: string) => (element: HTMLElement | null) => {
      fieldRefs.current[field] = element
    },
    [],
  )

  const jumpToField = useCallback((field: string) => {
    const element = fieldRefs.current[field]
    if (!element) return
    element.scrollIntoView({ block: 'center', behavior: 'smooth' })
    // Most fields register the input itself. The two date fields register a
    // wrapper instead, because `DateField` (#631) exposes neither an `id` nor
    // a ref — so descend to the first thing that can actually take the caret.
    const target = element.matches('input, select, textarea, button')
      ? element
      : element.querySelector<HTMLElement>('input, select, textarea, button')
    ;(target ?? element).focus({ preventScroll: true })
  }, [])

  const requiredValues = useMemo(
    () => ({
      first_name: formData.first_name,
      last_name: formData.last_name,
      email: formData.email,
      date_of_birth: formData.date_of_birth,
      preferred_language: formData.preferred_language,
    }),
    [formData.first_name, formData.last_name, formData.email, formData.date_of_birth, formData.preferred_language],
  )

  const missingRequired = missingRequiredFields(requiredValues)
  const isRequiredSatisfied = (field: MemberRequiredField) => !missingRequired.includes(field)

  /** The label each field carries, so the summary names it the way the form does. */
  const requiredFieldLabels: Record<MemberRequiredField, string> = {
    first_name: t('members.firstName'),
    last_name: t('members.lastName'),
    email: t('members.email'),
    date_of_birth: t('members.dateOfBirth'),
    preferred_language: t('members.preferredLanguage'),
  }

  // Stored values this submit would delete. `editingMember` is the member as
  // the API returned it, so this is a comparison against what is actually on
  // file rather than against a copy that could drift.
  const clearedStoredValues = clearedFields(editingMember, {
    card_uid: formData.card_uid,
    account_holder_name: formData.account_holder_name,
    mandate_signed_at: formData.mandate_signed_at,
  })
  const clearedPrevious = Object.fromEntries(
    clearedStoredValues.map((entry) => [entry.field, entry.previous]),
  ) as Partial<Record<MemberClearableField, string>>

  /** Puts a value the admin has just emptied back, from the notice under it. */
  const restoreClearedValue = (field: MemberClearableField) => {
    const previous = clearedPrevious[field]
    if (previous === undefined) return
    setFormData((prev) => ({ ...prev, [field]: previous }))
  }

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
    filterEmail !== 'all' ? 1 : 0,
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

  // The Datenqualität counts (#629). Reloaded after every mutation, because
  // filling in a card UID is exactly the moment the headline should drop by
  // one — a panel that keeps quoting a number the admin has just fixed is
  // worse than one that is absent.
  const loadCompleteness = useCallback(async (signal: AbortSignal) => {
    try {
      const summary = await getMembersFactory().getMemberDataCompleteness({ signal })
      if (signal.aborted) return
      setCompleteness(summary)
    } catch {
      if (signal.aborted) return
      setCompleteness(null)
    }
  }, [])

  useEffect(() => {
    loadCompleteness(completenessRequest.next())
    return () => completenessRequest.abort()
  }, [loadCompleteness, completenessRequest])

  // Load the SEPA-Vorlage link (#360/#456). Any failure — no config saved
  // yet, or a genuine error — leaves it null, which is exactly the state the
  // button already treats as "nothing to open".
  useEffect(() => {
    const signal = sepaConfigRequest.next()
    getSepaConfiguration()
      .getSepaConfig({ signal })
      .then((config) => {
        if (signal.aborted) return
        setMandateTemplateUrl(config.mandate_template_url ?? null)
      })
      .catch(() => {
        if (signal.aborted) return
        setMandateTemplateUrl(null)
      })
    return () => sepaConfigRequest.abort()
  }, [sepaConfigRequest])

  // The club default, for the override field's placeholder and helper. Null
  // when it could not be read, which the field renders as no placeholder at
  // all rather than as a figure nobody confirmed.
  useEffect(() => {
    const signal = creditLimitConfigRequest.next()
    getCreditLimits()
      .getCreditLimitConfig({ signal })
      .then((config) => {
        if (signal.aborted) return
        setClubDefaultLimitCents(config.default_limit_cents ?? null)
      })
      .catch(() => {
        if (signal.aborted) return
        setClubDefaultLimitCents(null)
      })
    return () => creditLimitConfigRequest.abort()
  }, [creditLimitConfigRequest])

  // Handle GDPR data export — downloads a JSON file with the member's personal data
  const handleExportData = async () => {
    if (!editingMember?.id) return
    setExporting(true)
    try {
      const blob = await getMembersFactory().exportMemberData(editingMember.id, { format: 'json', export_type: 'gdpr_access' })
      downloadBlob(blob as unknown as Blob, `gdpr-export-${editingMember.id}.json`)
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
      // then reveal a card UID complaint that was true all along. Since #629
      // the required fields are checked here too rather than by the browser:
      // the form carries `noValidate`, because native validation surfaces one
      // bubble at a time, at the first offending field, and says nothing at
      // all about the other four. The inputs keep their `required` attribute
      // for the semantics — it is what makes them required to a screen reader.
      const validationErrors: Record<string, string> = {}
      const missing = missingRequiredFields(requiredValues)
      for (const field of missing) {
        validationErrors[field] = t('members.requirements.emptyRequired')
      }
      // Only worth saying about an address that is actually there; a blank one
      // is already reported above, and two complaints about one field read as
      // two problems.
      if (missing.length === 0 && !isPlausibleEmail(formData.email)) {
        validationErrors.email = t('members.requirements.invalidEmail')
      }
      if (formData.iban && !validateIban(formData.iban)) {
        validationErrors.iban = t('members.validation.invalidIban')
      }
      if (formData.card_uid && !CARD_UID_PATTERN.test(formData.card_uid)) {
        validationErrors.card_uid = t('members.validation.invalidCardUid')
      }
      // Checked here rather than left to the 422: the volunteer finds out
      // before the save, beside the field, like every other rule in this form.
      if (!isValidCreditLimitInput(formData.credit_limit)) {
        validationErrors.credit_limit_cents = t('members.validation.invalidCreditLimit', {
          max: MAX_CREDIT_LIMIT_CENTS / 100,
        })
      }
      if (Object.keys(validationErrors).length > 0) {
        setFormErrors(validationErrors)
        setSubmitAttempted(true)
        // The summary at the top of the modal has just changed tone; put the
        // caret in the first field it names so the fix can start immediately.
        if (missing.length > 0) jumpToField(missing[0])
        return
      }

      if (editingMember) {
        if (!editingMember.id) throw new Error('Missing member id')
        const updatePayload: MemberUpdateRequest = {
          first_name: formData.first_name,
          last_name: formData.last_name,
          // Required by the form validation above, so this is never blank —
          // the backend also refuses to clear it for an active member (#362).
          email: formData.email,
          // Sent as a plain value, never as null: the birth date may be
          // corrected but not cleared (ADR-0045). Erasure is the anonymize
          // action's alone, and the API answers 422 to a blank one here.
          date_of_birth: formData.date_of_birth,
          // Now that these fields can be left empty, clearing one has to reach
          // the backend as an explicit null — `undefined` would drop the key
          // and silently keep the old value (#131).
          //
          // The IBAN is the one field where that rule inverts (#392). It cannot
          // be prefilled, so a blank input is the normal state of a save that
          // was about something else; sending null there would revoke the
          // mandate of every member whose name was corrected. Blank
          // therefore drops the key, and removing the account is its own
          // deliberate action.
          iban: removeStoredIban ? null : formData.iban || undefined,
          account_holder_name: formData.account_holder_name || null,
          mandate_reference: formData.mandate_reference || undefined,
          mandate_signed_at: formData.mandate_signed_at || null,
          preferred_language: formData.preferred_language,
          card_uid: formData.card_uid || null,
          // Explicit null clears the override back to the club default; 0 is a
          // deliberate "no ceiling for this member" and is sent as 0. The one
          // thing this must never do is send 0 for an emptied field.
          credit_limit_cents: creditLimitFromInput(formData.credit_limit),
        }
        await getMembersFactory().updateMember(editingMember.id, updatePayload)
      } else {
        const createPayload: MemberCreateRequest = {
          first_name: formData.first_name,
          last_name: formData.last_name,
          // Required by the form validation above, so this is never blank.
          email: formData.email,
          date_of_birth: formData.date_of_birth,
          // A member who has not brought their bank details yet is a state the
          // list already shows as "SEPA: Missing" — the form no longer refuses
          // to create one (#131).
          iban: formData.iban || undefined,
          account_holder_name: formData.account_holder_name || undefined,
          mandate_reference: formData.mandate_reference || undefined,
          mandate_signed_at: formData.mandate_signed_at || undefined,
          preferred_language: formData.preferred_language,
          card_uid: formData.card_uid || undefined,
          // Omitted rather than nulled on create: a member who follows the club
          // default is the ordinary case, and the column's default is NULL.
          credit_limit_cents: creditLimitFromInput(formData.credit_limit) ?? undefined,
        }
        await getMembersFactory().createMember(createPayload)
      }

      // Reset form
      setShowModal(false)
      setEditingMember(null)
      setFormData({ first_name: '', last_name: '', email: '', date_of_birth: '', iban: '', account_holder_name: '', mandate_reference: '', mandate_signed_at: '', preferred_language: 'de', card_uid: '', credit_limit: '' })
      resetBankingFieldModes()
      setSubmitAttempted(false)

      // Reload members list with the active filters still applied
      await list.reload()
      await loadCompleteness(completenessRequest.next())

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
      await loadCompleteness(completenessRequest.next())

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
      await loadCompleteness(completenessRequest.next())

      setAnonymizeConfirm(null)
      setError(null)
    } catch (err: unknown) {
      // The 409 says *why* — an outstanding tab, an open settlement — and the
      // admin needs that detail, in their own language. Reading `message` here
      // put the backend's English sentence on a German screen (#757).
      const axiosErr = err as { response?: { status?: number } }
      setError(apiErrorMessage(
        err,
        t(axiosErr.response?.status === 409 ? 'members.errors.cannotAnonymize' : 'members.errors.anonymize'),
      ))
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
      resetBankingFieldModes()
      setSubmitAttempted(false)
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
        date_of_birth: fullMember.date_of_birth ?? '',
        mandate_signed_at: fullMember.mandate_signed_at ?? '',
        preferred_language: fullMember.preferred_language ?? 'de',
        card_uid: fullMember.card_uid ?? '',
        // null → empty (inherited), 0 → "0.00" (uncapped). Showing an uncapped
        // member a blank box would re-cap them on the next save.
        credit_limit: creditLimitToInput(fullMember.credit_limit_cents),
      })
      setShowModal(true)
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : t('members.errors.loadDetails'))
    }
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
            <PageActionButton
              variant="secondary"
              data-testid="members-sepa-template-link-button"
              onClick={() => {
                if (mandateTemplateUrl) {
                  window.open(mandateTemplateUrl, '_blank', 'noopener,noreferrer')
                }
              }}
              disabled={!mandateTemplateUrl}
              iconOnly={isMobile}
              icon={<ExternalLinkIcon size={18} />}
              title={mandateTemplateUrl ? t('members.openSepaTemplate') : t('members.sepaTemplateNotConfigured')}
            >
              {t('members.openSepaTemplate')}
            </PageActionButton>
            <PageActionButton
              data-testid="members-create-button"
              onClick={() => {
                setEditingMember(null)
                setFormData({ first_name: '', last_name: '', email: '', date_of_birth: '', iban: '', account_holder_name: '', mandate_reference: '', mandate_signed_at: '', preferred_language: 'de', card_uid: '', credit_limit: '' })
                setFormErrors({})
                resetBankingFieldModes()
                setSubmitAttempted(false)
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
        {/* The last settlement is an *instant*, so it is formatted whole: the
            `.split('T')[0]` this replaces kept the UTC calendar day, which is
            not the reader's day for anything settled late in the evening. */}
        <StatCard
          icon={<CalendarIcon />}
          label={t('members.stats.lastSettlementDate')}
          value={lastSettlementDate ? formatters.formatDate(lastSettlementDate) : '—'}
          color="blue"
        />
      </div>

      {/*
        Datenqualität (#629). Between the figures and the filter row, because
        it is the bridge between them: the cards say how many members there
        are, this says how many of them the club cannot actually serve, and
        each of its buttons drives the filters below.
      */}
      <MemberDataQualityPanel
        completeness={completeness}
        isMobile={isMobile}
        onShowGap={(gapFilters) => {
          // `status: 'active'` on every one of these: the counts are over
          // active members, so a list that also held inactive ones would not
          // be the members the number just claimed.
          list.setFilters({
            ...NO_MEMBER_FILTERS,
            status: 'active',
            ...(gapFilters.cardUid ? { cardUid: gapFilters.cardUid } : {}),
            ...(gapFilters.sepaStatus ? { sepaStatus: gapFilters.sepaStatus } : {}),
            ...(gapFilters.email ? { email: gapFilters.email } : {}),
            ...(gapFilters.dateOfBirth ? { dateOfBirth: gapFilters.dateOfBirth } : {}),
          })
        }}
        onShowAllIncomplete={() => {
          list.setFilters({ ...NO_MEMBER_FILTERS, status: 'active', dataStatus: 'incomplete' })
        }}
      />

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
                <MobileFilterRow
                  label={t('members.filterEmail')}
                  options={[
                    { value: 'all', label: t('common.all') },
                    { value: 'with', label: t('members.filterWithEmail') },
                    { value: 'without', label: t('members.filterWithoutEmail') },
                  ]}
                  value={filterEmail}
                  onChange={(v) => list.setFilter('email', v as MemberFilters['email'])}
                  testId="members-mobile-filter-email"
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
                    {/* minWidth: 0 lets a long, unbreakable name shrink and
                        ellipsize instead of pushing the balance off the card
                        — flex items default to min-width: auto, which
                        refuses to shrink below the text's intrinsic width. */}
                    <span style={{ flex: 1, minWidth: 0, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap', fontWeight: 600, color: theme.colors.text.primary, fontSize: '14px' }}>
                      {member.first_name} {member.last_name}
                    </span>
                    {/* The Deckel rides on the name row rather than down with
                        the metadata: on a phone it is the one figure worth
                        reading without opening anything. */}
                    <span
                      data-testid={`member-card-balance-${member.id}`}
                      style={{
                        flexShrink: 0,
                        whiteSpace: 'nowrap',
                        fontFamily: 'JetBrains Mono, monospace',
                        fontSize: '13px',
                        fontWeight: 700,
                        fontVariantNumeric: 'tabular-nums',
                        color: (member.balance_cents ?? 0) === 0
                          ? theme.colors.text.muted
                          : getBalanceColor(member.balance_cents ?? 0),
                      }}
                    >
                      {formatters.formatPrice(member.balance_cents ?? 0)}
                    </span>
                  </div>
                  {/* Row 2: what this member is missing (#629). The card used
                      to spell out SEPA and email separately and say nothing at
                      all about a missing card UID or birth date; the chips are
                      the same four gaps the desktop column and the panel
                      above use. */}
                  <div style={{ marginBottom: '6px' }}>
                    <MemberGapChips member={member} />
                  </div>
                  {member.card_uid && (
                    <div style={{ fontSize: '12px', color: theme.colors.text.secondary, marginBottom: '6px' }}>
                      Card: {member.card_uid}
                    </div>
                  )}
                  {/* Row 3: member since */}
                  <div style={{ fontSize: '12px', color: theme.colors.text.muted, marginBottom: '10px' }}>
                    {t('members.memberSince')}: {member.created_at ? formatters.formatDate(member.created_at) : '—'}
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

        {/* Divider */}
        <div style={{ width: '1px', height: '28px', background: theme.colors.border.subtle }} />

        {/* Email filter group */}
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
            {t('members.filters.email.label')}
          </span>
          <button
            data-testid="filter-email-all"
            aria-pressed={filterEmail === 'all'}
            onClick={() => {
              list.setFilter('email', 'all')
            }}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: 'none',
              fontSize: '13px',
              fontWeight: 500,
              cursor: 'pointer',
              transition: 'all 0.15s ease',
              background: filterEmail === 'all' ? theme.colors.semantic.primary : theme.pillButton.idleBg,
              color: filterEmail === 'all' ? 'white' : theme.pillButton.idleText,
            }}
          >
            {t('members.filters.email.all')}
          </button>
          <button
            data-testid="filter-email-with"
            aria-pressed={filterEmail === 'with'}
            onClick={() => {
              list.setFilter('email', 'with')
            }}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: 'none',
              fontSize: '13px',
              fontWeight: 500,
              cursor: 'pointer',
              transition: 'all 0.15s ease',
              background: filterEmail === 'with' ? theme.colors.semantic.primary : theme.pillButton.idleBg,
              color: filterEmail === 'with' ? 'white' : theme.pillButton.idleText,
            }}
          >
            {t('members.filters.email.withEmail')}
          </button>
          <button
            data-testid="filter-email-without"
            aria-pressed={filterEmail === 'without'}
            onClick={() => {
              list.setFilter('email', 'without')
            }}
            style={{
              padding: '6px 14px',
              borderRadius: '6px',
              border: 'none',
              fontSize: '13px',
              fontWeight: 500,
              cursor: 'pointer',
              transition: 'all 0.15s ease',
              background: filterEmail === 'without' ? theme.colors.semantic.primary : theme.pillButton.idleBg,
              color: filterEmail === 'without' ? 'white' : theme.pillButton.idleText,
            }}
          >
            {t('members.filters.email.withoutEmail')}
          </button>
        </div>

        {/* Clear filters */}
        {((filterIsActive !== 'all') || (filterCardUid !== 'all') || (filterSepaStatus !== 'all') || (filterEmail !== 'all') || (filterDateOfBirth !== 'all') || (filterDataStatus !== 'all') || search) && (
          <>
            <div style={{ flex: 1 }} />
            <button
              onClick={() => {
                list.setSearch('')
                list.setFilters(NO_MEMBER_FILTERS)
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
              style={{
                ...tableElementStyles,
                // `table-layout: fixed` held the Email column to its declared
                // pixel width regardless of content, so a long email address
                // (no break opportunity) overflowed the cell and visually
                // overlapped the next column instead of wrapping or being
                // clipped (same class of bug as the audit log table, #373 /
                // #545). `auto` lets the browser size each column to what its
                // content needs.
                tableLayout: 'auto',
              }}
            >
              <thead>
                <tr style={headerRowStyle}>
                  <th style={{ ...headerCellBaseStyle, width: '80px', textAlign: 'center' }}>{t('common.status')}</th>
                  {/*
                    Was a SEPA-only column. SEPA is one of four things a member
                    can be missing, and the other three were invisible from the
                    roster — so the column reports all four and the panel above
                    counts the same four (#629). One definition of "incomplete",
                    in both places: a count whose list holds different members
                    teaches an admin to distrust the count.
                  */}
                  <th style={{ ...headerCellBaseStyle, width: '190px' }} data-testid="members-table-header-data">
                    {t('members.completeness.column')}
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
                  <th style={{ ...headerCellBaseStyle, width: '180px' }} data-testid="members-table-header-email">
                    {t('members.table.email')}
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
                    <td style={{ padding: tableSpacing.cellPadding }} data-testid={`members-table-cell-data-${member.id}`}>
                      <MemberGapChips member={member} />
                    </td>
                    <TableCell testId={`members-table-cell-name-${member.id}`}>
                      {member.first_name} {member.last_name}
                    </TableCell>
                    <td data-testid={`members-table-cell-email-${member.id}`} style={{ padding: tableSpacing.cellPadding }}>
                      {member.email ?? (
                        <span
                          data-testid={`members-table-cell-email-missing-${member.id}`}
                          style={{
                            padding: '4px 8px',
                            borderRadius: 4,
                            fontSize: 12,
                            fontWeight: 500,
                            backgroundColor: theme.colors.semantic.danger,
                            color: 'white',
                            display: 'inline-block',
                          }}
                        >
                          {t('members.missing')}
                        </span>
                      )}
                    </td>
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
                          : getBalanceColor(member.balance_cents ?? 0),
                      }}
                    >
                      {formatters.formatPrice(member.balance_cents ?? 0)}
                    </td>
                    <TableCell testId={`members-table-cell-created-${member.id}`}>
                      {member.created_at ? formatters.formatDate(member.created_at) : '—'}
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
            <h2 data-testid="members-form-title" style={{ margin: 0, marginBottom: theme.spacing.lg, fontSize: theme.typography.fontSize.xl }}>
              {editingMember ? t('members.editMember') : t('members.createMember')}
            </h2>

            {/*
              What the form still needs, and what saving it would delete. It
              opens the modal because those are the two things an admin wants
              to know before reading a single field (#629).
            */}
            <MemberFormRequirements
              satisfied={MEMBER_REQUIRED_FIELDS.length - missingRequired.length}
              total={MEMBER_REQUIRED_FIELDS.length}
              missing={missingRequired.map((field) => ({ field, label: requiredFieldLabels[field] }))}
              clearingCount={clearedStoredValues.length}
              blocked={submitAttempted && missingRequired.length > 0}
              onJumpTo={jumpToField}
            />

            {/*
              SEPA status. It used to report `is_sepa_valid` as loaded, which
              left "SEPA-Mandat gültig" standing above the line announcing that
              the mandate is about to be revoked. It now previews what this
              submit would do, and says so where that differs from what is
              saved (#392).
            */}
            {editingMember && (
              <div aria-live="polite">
                <Alert
                  testId="members-form-sepa-status"
                  variant={
                    sepaFormStatus === 'valid'
                      ? 'success'
                      : sepaFormStatus === 'willBecomeValid'
                        ? 'info'
                        : sepaFormStatus === 'willBecomeInvalid'
                          ? 'warning'
                          : 'danger'
                  }
                  icon={
                    <span style={{ fontSize: theme.typography.fontSize.lg }}>
                      {sepaFormStatus === 'valid' ? '✓' : sepaFormStatus === 'willBecomeValid' ? 'ⓘ' : '⚠'}
                    </span>
                  }
                  title={
                    sepaFormStatus === 'valid'
                      ? t('members.sepaValid')
                      : sepaFormStatus === 'willBecomeValid'
                        ? t('members.sepaWillBecomeValid')
                        : sepaFormStatus === 'willBecomeInvalid'
                          ? t('members.sepaWillBecomeInvalid')
                          : t('members.sepaMissing')
                  }
                  message={
                    sepaFormStatus === 'valid'
                      ? ''
                      : sepaFormStatus === 'missing'
                        ? t('members.sepaMissingHint')
                        : t('members.sepaUnsavedHint')
                  }
                />
              </div>
            )}

            {/* `noValidate`: the required checks live in `handleSubmit`, which
                reports every gap at once — see the comment there (#629). */}
            <form onSubmit={handleSubmit} noValidate style={{ display: 'grid', gridTemplateColumns: isMobile ? '1fr' : '1fr 1fr', gap: theme.spacing.lg, columnGap: theme.spacing.xl }}>
              <div>
                <FieldLabel
                  htmlFor="members-form-first-name-input"
                  label={t('members.firstName')}
                  requirement="required"
                  satisfied={isRequiredSatisfied('first_name')}
                  testId="members-form-first-name-label"
                />
                <input
                  id="members-form-first-name-input"
                  ref={registerField('first_name')}
                  data-testid="members-form-first-name-input"
                  type="text"
                  required
                  aria-invalid={Boolean(formErrors.first_name)}
                  value={formData.first_name}
                  onChange={(e) => setFormData({ ...formData, first_name: e.target.value })}
                  placeholder="Max"
                  maxLength={100}
                  style={formInputStyle(Boolean(formErrors.first_name))}
                />
                {formErrors.first_name && (
                  <p data-testid="members-form-first-name-error" style={formFieldErrorStyle}>
                    {formErrors.first_name}
                  </p>
                )}
              </div>

              <div>
                <FieldLabel
                  htmlFor="members-form-last-name-input"
                  label={t('members.lastName')}
                  requirement="required"
                  satisfied={isRequiredSatisfied('last_name')}
                  testId="members-form-last-name-label"
                />
                <input
                  id="members-form-last-name-input"
                  ref={registerField('last_name')}
                  data-testid="members-form-last-name-input"
                  type="text"
                  required
                  aria-invalid={Boolean(formErrors.last_name)}
                  value={formData.last_name}
                  onChange={(e) => setFormData({ ...formData, last_name: e.target.value })}
                  placeholder="Mustermann"
                  maxLength={100}
                  style={formInputStyle(Boolean(formErrors.last_name))}
                />
                {formErrors.last_name && (
                  <p data-testid="members-form-last-name-error" style={formFieldErrorStyle}>
                    {formErrors.last_name}
                  </p>
                )}
              </div>

              <div>
                <FieldLabel
                  htmlFor="members-form-email-input"
                  label={t('members.email')}
                  requirement="required"
                  satisfied={isRequiredSatisfied('email')}
                  testId="members-form-email-label"
                />
                <input
                  id="members-form-email-input"
                  ref={registerField('email')}
                  data-testid="members-form-email-input"
                  type="email"
                  required
                  aria-invalid={Boolean(formErrors.email)}
                  value={formData.email}
                  onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                  placeholder="max@example.com"
                  style={formInputStyle(Boolean(formErrors.email))}
                />
                {/* The API requires an email on create; without this the 422 it
                    answers with was mapped into formErrors and never rendered. */}
                {formErrors.email && (
                  <p data-testid="members-form-email-error" style={formFieldErrorStyle}>
                    {formErrors.email}
                  </p>
                )}
              </div>

              {/* Jugendschutz (ADR-0045): the terminal computes the member's age
                  from this date at checkout, so a product with a `min_age` can be
                  refused offline. Required, and never in the future — the same
                  control and the same `max` guard as the mandate date below. */}
              <div>
                {/* `DateField` (#631) owns the control; the marker is the
                    label's job (#629). It exposes no `id`, so the wrapper is
                    what `registerField` holds and `jumpToField` descends into
                    to find the input. */}
                <FieldLabel
                  label={t('members.dateOfBirth')}
                  requirement="required"
                  satisfied={isRequiredSatisfied('date_of_birth')}
                  testId="members-form-dob-label"
                />
                <div ref={registerField('date_of_birth')}>
                  <DateField
                    testId="members-form-dob-input"
                    mode="birthdate"
                    required
                    value={formData.date_of_birth}
                    onChange={(iso) => setFormData({ ...formData, date_of_birth: iso })}
                    min={EARLIEST_BIRTH_DATE}
                    max={toIsoDate(new Date())}
                    invalid={Boolean(formErrors.date_of_birth)}
                    describedBy={formErrors.date_of_birth ? 'members-form-dob-error' : undefined}
                  />
                </div>
                {formErrors.date_of_birth && (
                  <p id="members-form-dob-error" data-testid="members-form-dob-error" style={formFieldErrorStyle}>
                    {formErrors.date_of_birth}
                  </p>
                )}
              </div>

              {/* Not required to store, but nothing works at the till without
                  it — so the marker names the capability instead of calling the
                  field optional, which understates it (#629). */}
              <div>
                <FieldLabel
                  htmlFor="member-form-card-uid"
                  label={t('members.form.cardUid')}
                  requirement="conditional"
                  unlocks={t('common.requiredForTerminal')}
                  testId="members-form-card-uid-label"
                />
                <input
                  id="member-form-card-uid"
                  ref={registerField('card_uid')}
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
                  style={{ ...formInputStyle(Boolean(formErrors.card_uid)), fontFamily: 'monospace' }}
                />
                {clearedPrevious.card_uid && (
                  <ClearedValueNotice
                    previous={clearedPrevious.card_uid}
                    onRestore={() => restoreClearedValue('card_uid')}
                    testId="members-form-card-uid-cleared"
                  />
                )}
                {formData.card_uid && !CARD_UID_PATTERN.test(formData.card_uid) && (
                  <p data-testid="member-form-card-uid-format-error" style={formFieldErrorStyle}>
                    {t('members.validation.invalidCardUid')}
                  </p>
                )}
                {formErrors.card_uid && (
                  <p data-testid="member-form-card-uid-error" style={formFieldErrorStyle}>
                    {formErrors.card_uid}
                  </p>
                )}
              </div>

              <MemberIbanField
                mode={ibanFieldMode}
                value={formData.iban}
                onChange={(iban) => {
                  setFormData((prev) => ({ ...prev, iban }))
                  if (formErrors.iban) {
                    setFormErrors((prev) => Object.fromEntries(Object.entries(prev).filter(([k]) => k !== 'iban')))
                  }
                }}
                storedMasked={storedIbanMasked}
                storedBankName={editingMember?.bank_name}
                isReplacing={isReplacingIban}
                onBeginChange={() => setIsReplacingIban(true)}
                onCancelChange={() => {
                  // Clearing the field matters as much as hiding it: a half-typed
                  // IBAN left behind would reach the payload invisibly.
                  setIsReplacingIban(false)
                  setFormData((prev) => ({ ...prev, iban: '' }))
                  setFormErrors((prev) => Object.fromEntries(Object.entries(prev).filter(([k]) => k !== 'iban')))
                }}
                onRemove={() => {
                  setRemoveStoredIban(true)
                  setIsReplacingIban(false)
                  setFormData((prev) => ({ ...prev, iban: '' }))
                }}
                onUndoRemove={() => setRemoveStoredIban(false)}
                error={formErrors.iban}
              />

              <div>
                <FieldLabel
                  htmlFor="members-form-account-holder-name-input"
                  label={t('members.accountHolderName')}
                  requirement="optional"
                  optionalNote={`${t('common.sepa')}, ${t('common.optional')}`}
                  testId="members-form-account-holder-label"
                />
                <input
                  id="members-form-account-holder-name-input"
                  ref={registerField('account_holder_name')}
                  data-testid="members-form-account-holder-name-input"
                  type="text"
                  value={formData.account_holder_name}
                  onChange={(e) => setFormData({ ...formData, account_holder_name: e.target.value })}
                  placeholder={t('members.accountHolderPlaceholder')}
                  maxLength={70}
                  style={formInputStyle(false)}
                />
                {clearedPrevious.account_holder_name && (
                  <ClearedValueNotice
                    previous={clearedPrevious.account_holder_name}
                    onRestore={() => restoreClearedValue('account_holder_name')}
                    testId="members-form-account-holder-cleared"
                  />
                )}
                <span style={{ fontSize: theme.typography.fontSize.xs, color: theme.colors.text.secondary, marginTop: theme.spacing.xs, display: 'block' }}>
                  {t('members.accountHolderHint')}
                </span>
              </div>

              <MemberMandateReferenceField
                mode={mandateReferenceMode}
                value={formData.mandate_reference}
                onChange={(mandate_reference) => {
                  setFormData((prev) => ({ ...prev, mandate_reference }))
                  if (formErrors.mandate_reference) {
                    setFormErrors((prev) => Object.fromEntries(Object.entries(prev).filter(([k]) => k !== 'mandate_reference')))
                  }
                }}
                assignedReference={assignedMandateReference}
                onBeginEntry={() => setIsEnteringMandateReference(true)}
                onCancelEntry={() => {
                  // Back to whatever the server would do on its own: keep the
                  // assigned reference, or let it mint one.
                  setIsEnteringMandateReference(false)
                  setFormData((prev) => ({ ...prev, mandate_reference: assignedMandateReference ?? '' }))
                  setFormErrors((prev) => Object.fromEntries(Object.entries(prev).filter(([k]) => k !== 'mandate_reference')))
                }}
                error={formErrors.mandate_reference}
              />

              {/* Not "SEPA, optional", which is what it said while the export
                  quietly filled `DtOfSgntr` with the settlement date when this
                  was blank. The date the member signed is the third part of a
                  mandate (ADR-0020, #164): without it there is no collection,
                  and the marker has to say so rather than understate the field
                  the way the two above it do not. */}
              <div>
                <FieldLabel
                  label={t('members.mandateSignedAt')}
                  requirement="conditional"
                  unlocks={t('common.requiredForSepa')}
                  testId="members-form-mandate-date-label"
                />
                <div ref={registerField('mandate_signed_at')}>
                  <DateField
                    testId="members-form-mandate-date-input"
                    clearable
                    value={formData.mandate_signed_at}
                    onChange={(iso) => setFormData({ ...formData, mandate_signed_at: iso })}
                    max={toIsoDate(new Date())}
                  />
                </div>
                {clearedPrevious.mandate_signed_at && (
                  <ClearedValueNotice
                    previous={formatters.formatDate(clearedPrevious.mandate_signed_at)}
                    onRestore={() => restoreClearedValue('mandate_signed_at')}
                    testId="members-form-mandate-date-cleared"
                  />
                )}
              </div>

              <div style={{ gridColumn: '1 / -1' }}>
                <FieldLabel
                  label={t('members.preferredLanguage')}
                  requirement="required"
                  satisfied={isRequiredSatisfied('preferred_language')}
                  testId="members-form-language-label"
                />
                <LanguageSelector
                  value={formData.preferred_language as 'de' | 'en'}
                  onChange={(language) => setFormData({ ...formData, preferred_language: language })}
                  testId="members-form-language-select"
                  required
                />
              </div>

              {/* The member's own credit ceiling (ADR-0047). Optional, and the
                  empty state is the ordinary one — which is why the placeholder
                  names the club figure the member inherits rather than leaving
                  a blank box that reads as unset-and-broken. */}
              <div style={{ gridColumn: '1 / -1' }}>
                <FieldLabel
                  label={t('members.creditLimit')}
                  requirement="optional"
                  testId="members-form-credit-limit-label"
                />
                <input
                  type="text"
                  inputMode="decimal"
                  data-testid="members-form-credit-limit-input"
                  value={formData.credit_limit}
                  onChange={(e) => {
                    setFormData({ ...formData, credit_limit: e.target.value })
                    setFormErrors((prev) =>
                      Object.fromEntries(Object.entries(prev).filter(([k]) => k !== 'credit_limit_cents')),
                    )
                  }}
                  placeholder={
                    clubDefaultLimitCents === null
                      ? ''
                      : t('members.creditLimitInheritedPlaceholder', {
                          amount: formatters.formatPrice(clubDefaultLimitCents),
                        })
                  }
                  aria-invalid={Boolean(formErrors.credit_limit_cents)}
                  style={formInputStyle(Boolean(formErrors.credit_limit_cents))}
                />
                <div
                  data-testid="members-form-credit-limit-helper"
                  style={{
                    marginTop: theme.spacing.xs,
                    fontSize: theme.typography.fontSize.xs,
                    color: theme.colors.text.muted,
                  }}
                >
                  {creditLimitFromInput(formData.credit_limit) === 0
                    ? t('members.creditLimitZeroHelper')
                    : formData.credit_limit.trim() === '' && clubDefaultLimitCents !== null
                      ? t('members.creditLimitInheritedHelper', {
                          amount: formatters.formatPrice(clubDefaultLimitCents),
                        })
                      : t('members.creditLimitHelper')}
                </div>
                {formErrors.credit_limit_cents && (
                  <p data-testid="members-form-credit-limit-error" style={formFieldErrorStyle}>
                    {formErrors.credit_limit_cents}
                  </p>
                )}
              </div>

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
                    onClick={() => { setShowModal(false); resetBankingFieldModes(); setSubmitAttempted(false) }}
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
