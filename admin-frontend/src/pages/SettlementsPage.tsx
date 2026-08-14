/**
 * Settlements Page
 * Settlement history viewing and management
 *
 * Implements:
 * - UC-A33: Settlement History (list view with table, filtering, sorting, pagination)
 * - Bulk actions: Export SEPA, Export CSV, Export Transactions, Undo Settlement
 *
 * Workflow:
 * Single page list view with filtering, sorting, and pagination.
 * All actions (exports, undo) accessible directly from list table.
 *
 * Pattern: Table implementation with filtering, sorting, and pagination
 * Uses TDD with E2E tests in e2etests/tests/admin/settlements.spec.ts
 *
 * Note: Settlement creation lives on the New Settlement screen this page links
 * to (UC-A30, ADR-0030). It used to be a transaction picker in the Journal,
 * which described something the system stopped doing when a run began sweeping
 * whole member positions.
 * Settlement details view was removed - no additional value beyond list view.
 */

import { Fragment, useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import axios from 'axios'
import { theme } from '../styles/design-system'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { MobileToolbar } from '../components/layout/MobileToolbar'
import { UndoSettlementDialog } from '../components/modals/UndoSettlementDialog'
import { ReverseSettlementDialog, type ReversalRequest } from '../components/modals/ReverseSettlementDialog'
import { RecordBankReturnDialog } from '../components/modals/RecordBankReturnDialog'
import { SettlementMemberBreakdown } from '../components/settlements/SettlementMemberBreakdown'
import { MarkSubmittedDialog } from '../components/modals/MarkSubmittedDialog'
import { StepUpConfirmDialog, type StepUpCredentials } from '../components/modals/StepUpConfirmDialog'
import { PrivateKeyInput } from '../components/modals/PrivateKeyInput'
import { getProfile } from '../auth/session'
import { useFormatters } from '../hooks/useFormatters'
import { PeriodPicker } from '../components/forms/PeriodPicker'
import { PillFilter, type PillFilterOption } from '../components/forms/PillFilter'
import { PaginationToolbar } from '../components/tables/PaginationToolbar'
import { PillActionButton } from '../components/common/PillActionButton'
import { useListQuery } from '../hooks/useListQuery'
import { downloadBlob, downloadFile } from '../api/client'
import { DEFAULT_PERIOD, getPeriodRange, type PeriodKey } from '../utils/periods'
import { formatTransactionPeriod } from '../utils/settlementPeriod'
import { awaitsBankConfirmation, canExportSepa, settlementStatusColor } from '../utils/settlementStatus'
import { SettlementStatusBadge } from '../components/common/SettlementStatusBadge'
import {
  tableWrapperStyles,
  tableElementStyles,
  headerRowStyle,
  headerCellBaseStyle,
  tableColors,
  tableSpacing,
  getRowStyle,
} from '../styles/tableTokens'
import { settlementRowUndoAction } from '../utils/settlementReversal'
import { getSettlements as getSettlementsFactory } from '../api/generated/settlements/settlements'
import type {
  SettlementListItem,
  ListSettlementsParams,
  ReversalCandidate,
  ReverseSettlementBodyReason,
} from '../api/generated'


/**
 * Extended settlement list item — includes runtime fields returned by the backend
 * that are not yet declared in the generated SettlementListItem schema.
 */
interface SettlementListItemExtended extends SettlementListItem {
  transaction_count?: number
  transaction_date_min?: string | null
  transaction_date_max?: string | null
  created_by_admin_name?: string | null
  created_by_admin_id?: string | null
}

/**
 * The undo slot's colour. Red says "this undoes a live run"; amber says "this
 * books money back", matching the reversed status badge; the muted stone says
 * "the gate is shut" — the button still opens the dialog, which states the
 * backend's reason, but it is not dressed as a destructive action (#127).
 */
function undoButtonColor(settlement: SettlementListItemExtended): string {
  if (settlement.is_cancelled) return theme.colors.semantic.neutral
  if (settlementRowUndoAction(settlement) === 'reverse') return theme.colors.semantic.amber
  return settlement.is_cancellable === false ? theme.colors.semantic.blocked : theme.colors.semantic.danger
}

function undoButtonHoverColor(settlement: SettlementListItemExtended): string {
  if (settlementRowUndoAction(settlement) === 'reverse') return theme.colors.semantic.amberHover
  return settlement.is_cancellable === false ? theme.colors.semantic.blockedHover : theme.colors.semantic.dangerHover
}

const defaultPageSize = 20

type SettlementSortKey = 'created_at' | 'created_by'

/**
 * The pills the status filter offers (#377). `draft`, `exported`, `submitted`,
 * `reversed` and `cancelled` partition the list exactly — the backend evaluates
 * them down the same ladder it derives each row's badge from, so no settlement
 * can be missing from all five or returned by two.
 *
 * `reversed` merges `partly_reversed` and `fully_reversed`: a treasurer asking
 * "where did money come back?" does not yet care how much of it did. This is
 * the only place the pill vocabulary departs from the badge vocabulary, and
 * the badge still shows the two apart.
 *
 * The old `active` pill is gone. The backend still accepts it as a deprecated
 * alias for "not cancelled" so bookmarked links keep working.
 */
type SettlementStatusFilterValue = 'all' | 'draft' | 'exported' | 'submitted' | 'reversed' | 'cancelled'

interface SettlementFilters {
  period: PeriodKey
  dateFrom: string | undefined
  dateTo: string | undefined
  status: SettlementStatusFilterValue
}

export function SettlementsPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const formatters = useFormatters()
  const breakpoint = useBreakpoint()
  // Pagination, filters and sorting share the list-query state (#121). The
  // initial range is derived from the default preset here rather than announced
  // by the PeriodPicker from an effect — that effect re-fired on every render
  // and reset the page, so paging was impossible (#89).
  const list = useListQuery<SettlementListItemExtended, SettlementFilters, SettlementSortKey>({
    initialFilters: {
      period: DEFAULT_PERIOD,
      dateFrom: getPeriodRange(DEFAULT_PERIOD).dateFrom,
      dateTo: getPeriodRange(DEFAULT_PERIOD).dateTo,
      status: 'all',
    },
    initialSortKey: 'created_at',
    initialSortDirection: 'desc',
    initialPageSize: defaultPageSize,
    fetcher: async ({ page, pageSize, sortKey, sortDirection, filters, signal }) => {
      // Both headers are real sorts now (#120). The key used to be dropped here
      // because the repository ignored it, so clicking "Created By" moved the
      // arrow onto a column the order had nothing to do with.
      const params: ListSettlementsParams = {
        page,
        per_page: pageSize,
        sort: sortKey,
        order: sortDirection,
      }

      if (filters.dateFrom) params.date_from = filters.dateFrom
      if (filters.dateTo) params.date_to = filters.dateTo
      if (filters.status !== 'all') params.status = filters.status

      const response = await getSettlementsFactory().listSettlements(params, { signal })
      return {
        items: (response.data ?? []) as SettlementListItemExtended[],
        total: response.pagination?.total ?? 0,
      }
    },
    parseError: (err) =>
      axios.isAxiosError(err)
        ? (err.response?.data?.message ?? err.message)
        : err instanceof Error
          ? err.message
          : t('settlements.errors.load'),
  })

  const { items: settlements, total: totalItems, totalPages, loading, error, setError } = list
  const { period, status: statusFilter } = list.filters
  const sortKey = list.sortKey
  const sortOrder = list.sortDirection

  // The settlement the undo dialog is asking about — the whole row, not its
  // id: the dialog states date, amount and member count before undoing (#127).
  const [undoTarget, setUndoTarget] = useState<SettlementListItemExtended | null>(null)

  // What the last SEPA export could not collect (#114). Not an error — the
  // file downloaded and is valid — but the treasurer has to be told it asks
  // the bank for less than the settlement records.
  const [exportWarning, setExportWarning] = useState<string | null>(null)
  // The SEPA export asks for the club's archived private key plus a fresh
  // step-up before it decrypts anything (#393). `exportTarget` is the
  // settlement the dialog is open for.
  const [exportTarget, setExportTarget] = useState<string | null>(null)
  const [exportPrivateKey, setExportPrivateKey] = useState('')
  const [exportStepUpError, setExportStepUpError] = useState<string | null>(null)
  const [callerTotpEnabled, setCallerTotpEnabled] = useState(false)

  // Undoing a settlement only flips a badge in one row. On a long list that is
  // easy to miss, so the outcome is stated outright (#130).
  const [undoSuccess, setUndoSuccess] = useState<string | null>(null)

  // The settlement the reversal dialog is asking about, and the reason it was
  // opened for (#433 §1). The reason is not a field inside the dialog: it is
  // the choice of entry point, made before the dialog opens, because it decides
  // whether a member gets frozen out of the next run.
  const [reverseTarget, setReverseTarget] = useState<SettlementListItemExtended | null>(null)
  const [reverseReason, setReverseReason] = useState<ReverseSettlementBodyReason>('club_error')
  // Set only on the lookup path, where the reference already named one member's
  // collection — reopening that choice in the dialog would undo the lookup.
  const [reverseLockedMembers, setReverseLockedMembers] =
    useState<Array<{ memberId: string; memberName: string | null; amountCents: number }> | undefined>(undefined)
  const [reverseBankReference, setReverseBankReference] = useState<string | undefined>(undefined)
  const [reverseSubmitting, setReverseSubmitting] = useState(false)
  const [reverseError, setReverseError] = useState<string | null>(null)
  // A hold nobody asked for is stated rather than discovered (§6).
  const [reverseSuccess, setReverseSuccess] = useState<{ message: string; showHoldLink: boolean } | null>(null)

  // The reference lookup (§3). Always reachable, never gated on a reversible
  // settlement existing: a missing button tells a treasurer holding a real bank
  // statement nothing about why.
  const [lookupOpen, setLookupOpen] = useState(false)

  // Which run's member breakdown is open (§7). One at a time: the expand is a
  // disclosure inside a list, not a second list.
  const [expandedSettlementId, setExpandedSettlementId] = useState<string | null>(null)

  // The settlement the "mark as submitted" dialog is asking about (#377). The
  // whole row again, because the dialog states its date, total and members
  // before closing off cancellation for good.
  const [submitTarget, setSubmitTarget] = useState<SettlementListItemExtended | null>(null)
  const [submitSuccess, setSubmitSuccess] = useState<string | null>(null)

  // Mobile responsive
  const isMobile = breakpoint === 'smallMobile' || breakpoint === 'mobile'
  const [showMobileFilters, setShowMobileFilters] = useState(false)

  const mobileFilterCount = [
    period !== DEFAULT_PERIOD ? 1 : 0,
    statusFilter !== 'all' ? 1 : 0,
  ].reduce((a, b) => a + b, 0)

  const statusOptions: ReadonlyArray<PillFilterOption<SettlementStatusFilterValue>> = [
    { value: 'all', label: t('common.all'), color: theme.colors.semantic.neutral },
    { value: 'draft', label: t('settlements.statusLabels.draft'), color: settlementStatusColor('draft') },
    { value: 'exported', label: t('settlements.statusLabels.exported'), color: settlementStatusColor('exported') },
    { value: 'submitted', label: t('settlements.statusLabels.submitted'), color: settlementStatusColor('submitted') },
    { value: 'reversed', label: t('settlements.reversed'), color: settlementStatusColor('partly_reversed') },
    { value: 'cancelled', label: t('settlements.statusLabels.cancelled'), color: settlementStatusColor('cancelled') },
  ]

  const mobileSortOptions = [
    { value: 'created_at_desc', label: t('settlements.sortNewest'), direction: 'desc' as const },
    { value: 'created_at_asc', label: t('settlements.sortOldest'), direction: 'asc' as const },
  ]

  const mobileSortValue = list.sortValue

  // Handle period change from PeriodPicker. Memoized so the picker sees a
  // stable handler identity across renders (#89).
  const setFilters = list.setFilters
  const handlePeriodChange = useCallback(
    (from: string | undefined, to: string | undefined, periodKey: PeriodKey) => {
      setFilters({ period: periodKey, dateFrom: from, dateTo: to })
    },
    [setFilters]
  )

  // Whether the signed-in admin has 2FA enrolled, which decides whether the
  // export dialog asks for a TOTP code alongside the password. Fetched once.
  useEffect(() => {
    getProfile()
      .then((profile) => setCallerTotpEnabled(!!profile.totp_enabled))
      .catch(() => setCallerTotpEnabled(false))
  }, [])

  const handleExportSepa = (settlementId: string) => {
    setExportWarning(null)
    setExportStepUpError(null)
    setExportPrivateKey('')
    setExportTarget(settlementId)
  }

  const handleExportSepaConfirmed = async (credentials: StepUpCredentials) => {
    const settlementId = exportTarget
    if (!settlementId) return

    try {
      setExportWarning(null)
      // Routed through downloadFile rather than the generated client because
      // the omissions ride on response headers, and the generated call returns
      // the blob alone (#114). The bank file itself cannot carry a warning.
      // A POST, because the private key rides in the body (ADR-0036).
      const headers = await downloadFile(
        `/admin/settlements/${settlementId}/export/sepa-xml`,
        `sepa-${settlementId}.xml`,
        { ...credentials, private_key: exportPrivateKey }
      )

      // Only now: a failed attempt keeps the dialog open with the key intact.
      setExportTarget(null)
      setExportPrivateKey('')

      const uncollectable = headers['x-uncollectable-members']
      if (uncollectable) {
        setExportWarning(
          t('settlements.exportShortfall', {
            count: uncollectable.split(',').length,
            shortfall: formatters.formatPrice(Number(headers['x-shortfall-amount-cents'] ?? 0)),
            collected: formatters.formatPrice(Number(headers['x-collected-amount-cents'] ?? 0)),
            total: formatters.formatPrice(Number(headers['x-settlement-amount-cents'] ?? 0)),
          })
        )
      }

      // Reload list so status updates to "Exported"
      await list.reload()
    } catch (err: unknown) {
      // A rejected credential or key keeps the dialog open so the admin can
      // retry without fetching the key sheet out of the safe again; anything
      // else is a page-level failure and the dialog has nothing to add.
      const status = axios.isAxiosError(err) ? err.response?.status : undefined
      const message = err instanceof Error ? err.message : t('settlements.errors.exportSepa')

      if (status === 401 || status === 422) {
        setExportStepUpError(message)
        return
      }

      setExportTarget(null)
      setExportPrivateKey('')
      setError(message)
    }
  }

  const handleExportCsv = async (settlementId: string) => {
    try {
      const blob = await getSettlementsFactory().downloadSettlementCsv(settlementId)
      downloadBlob(blob as unknown as Blob, `settlement-${settlementId}.csv`)
    } catch (err: unknown) {
      if (axios.isAxiosError(err)) {
        setError(err.response?.data?.message ?? err.message)
      } else if (err instanceof Error) {
        setError(err.message)
      } else {
        setError(t('settlements.errors.exportCsv'))
      }
    }
  }

  const handleExportTransactionsCsv = async (settlementId: string) => {
    try {
      const blob = await getSettlementsFactory().exportSettlementTransactions(settlementId)
      downloadBlob(blob, `transactions-${settlementId}.csv`)
    } catch (err: unknown) {
      if (axios.isAxiosError(err)) {
        setError(err.response?.data?.message ?? err.message)
      } else if (err instanceof Error) {
        setError(err.message)
      } else {
        setError(t('settlements.errors.exportTransactions'))
      }
    }
  }

  const handleUndoSettlementConfirmed = async () => {
    const settlementId = undoTarget?.id
    if (!settlementId) return
    setUndoTarget(null)
    try {
      setError(null)
      setUndoSuccess(null)
      setSubmitSuccess(null)
      setReverseSuccess(null)
      await getSettlementsFactory().cancelSettlement(settlementId)
      await list.reload()
      setUndoSuccess(t('settlements.undoSuccess'))
    } catch (err: unknown) {
      if (axios.isAxiosError(err)) {
        setError(err.response?.data?.message ?? err.message)
      } else if (err instanceof Error) {
        setError(err.message)
      } else {
        setError(t('settlements.errors.undo'))
      }
    }
  }

  /**
   * Open the row's single undo slot on whichever operation is available (§2).
   *
   * `ReversalGate` is the exact mirror of `CancellationGate`, so exactly one of
   * the two is open for any live settlement. The row therefore has one button,
   * and a treasurer reaching for "undo" on a submitted run lands on the
   * operation that actually exists rather than on a disabled control explaining
   * what they may not do — which is what #81 was asking for.
   */
  const handleRowUndo = (settlement: SettlementListItemExtended) => {
    if (settlementRowUndoAction(settlement) === 'reverse') {
      setReverseReason('club_error')
      setReverseLockedMembers(undefined)
      setReverseBankReference(undefined)
      setReverseError(null)
      setReverseTarget(settlement)
      return
    }

    setUndoTarget(settlement)
  }

  /**
   * The lookup resolved a reference to one member's collection in one run (§3).
   *
   * The candidate carries the settlement's own gate answer, so the dialog it
   * opens is built from the backend's fields rather than from a second lookup.
   * The reference the treasurer pasted becomes the prefilled `bank_reference`:
   * per #149 the value a German bank quotes on a return booking *is* our
   * EndToEndId echoed back, so we already hold it (§4).
   */
  const handleCandidateSelected = (candidate: ReversalCandidate, reference: string) => {
    setLookupOpen(false)
    setReverseReason('bank_return')
    setReverseLockedMembers([
      {
        memberId: candidate.member_id ?? '',
        memberName: candidate.member_name ?? null,
        amountCents: candidate.amount_cents ?? 0,
      },
    ])
    setReverseBankReference(candidate.end_to_end_id ?? reference)
    setReverseError(null)
    setReverseTarget({
      id: candidate.settlement_id,
      settlement_date: candidate.settlement_date,
      execution_date: candidate.execution_date,
      total_amount_cents: candidate.amount_cents,
      member_count: 1,
      status: candidate.status,
      status_label: candidate.status_label,
      is_reversible: candidate.is_reversible,
      reversal_blocked_reason: candidate.reversal_blocked_reason,
    } as SettlementListItemExtended)
  }

  /**
   * Record the reversal and stay put (§6).
   *
   * The endpoint returns the whole recomputed settlement, so the row updates in
   * place — status badge, reversed count. Never navigate away: a treasurer
   * working through a statement typically has two or three returns to record in
   * one sitting, and bouncing them elsewhere after each one punishes the common
   * case.
   */
  const handleReverseConfirmed = async (input: ReversalRequest) => {
    const settlementId = reverseTarget?.id
    if (!settlementId) return

    setReverseSubmitting(true)
    setReverseError(null)
    try {
      setError(null)
      setUndoSuccess(null)
      setSubmitSuccess(null)
      setReverseSuccess(null)
      await getSettlementsFactory().reverseSettlement(settlementId, {
        reason: input.reason,
        // Omitted entirely for the whole-settlement undo — that is the
        // endpoint's default call, not a select-all.
        ...(input.memberIds ? { member_ids: input.memberIds } : {}),
        ...(input.bankReference ? { bank_reference: input.bankReference } : {}),
        ...(input.notes ? { notes: input.notes } : {}),
      })

      setReverseTarget(null)
      await list.reload()
      setReverseSuccess({
        message: input.reason === 'bank_return'
          ? t('settlements.reverseSuccessHold')
          : t('settlements.reverseSuccess'),
        showHoldLink: input.reason === 'bank_return',
      })
    } catch (err: unknown) {
      // A refusal keeps the dialog open carrying the backend's own words: it
      // names the specific reason — already reversed, a member not part of this
      // run, nothing collected — and re-deriving any of that here is the second
      // rule set #81 was.
      setReverseError(
        axios.isAxiosError(err)
          ? (err.response?.data?.message ?? err.message)
          : err instanceof Error
            ? err.message
            : t('settlements.errors.reverse')
      )
    } finally {
      setReverseSubmitting(false)
    }
  }

  /**
   * Record that the exported file reached the bank (#377).
   *
   * The eligibility rule is not repeated here: the button is offered only for
   * a run the server says is `exported`, and the server 409s on everything
   * else. A refusal is shown as it arrives rather than translated, because it
   * names the specific reason — never exported, already submitted, not a
   * direct debit.
   */
  const handleMarkSubmittedConfirmed = async () => {
    const settlementId = submitTarget?.id
    if (!settlementId) return
    setSubmitTarget(null)
    try {
      setError(null)
      setUndoSuccess(null)
      setSubmitSuccess(null)
      setReverseSuccess(null)
      await getSettlementsFactory().submitSettlement(settlementId)
      await list.reload()
      setSubmitSuccess(t('settlements.markSubmittedSuccess'))
    } catch (err: unknown) {
      if (axios.isAxiosError(err)) {
        setError(err.response?.data?.message ?? err.message)
      } else if (err instanceof Error) {
        setError(err.message)
      } else {
        setError(t('settlements.errors.markSubmitted'))
      }
    }
  }

  const transactionPeriod = (settlement: SettlementListItemExtended) =>
    formatTransactionPeriod(
      settlement.transaction_date_min,
      settlement.transaction_date_max,
      settlement.created_at,
      formatters.intlLocale,
    )

    return (
      <div data-testid="settlements-page">
        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            gap: theme.spacing.md,
            flexWrap: 'wrap',
            margin: '0 0 20px 0',
          }}
        >
          <h1 style={{ margin: 0 }}>{t('settlements.title')}</h1>
          <div style={{ display: 'flex', gap: theme.spacing.sm, flexWrap: 'wrap' }}>
            {/*
              The way in for a treasurer holding a bank statement (§3). A page
              action rather than a row action, because a row action presumes the
              one fact they are missing: they do not know which run the return
              came out of.

              Always visible, never gated on a reversible settlement existing —
              a missing button tells someone holding a real statement nothing
              about why. The empty case belongs one step later, where the lookup
              can say something actionable.
            */}
            <button
              data-testid="settlements-record-bank-return-btn"
              onClick={() => setLookupOpen(true)}
              style={{
                padding: '10px 20px',
                backgroundColor: theme.colors.semantic.amber,
                color: 'white',
                border: 'none',
                borderRadius: 6,
                fontSize: 14,
                fontWeight: 500,
                cursor: 'pointer',
              }}
            >
              {t('settlements.recordBankReturn')}
            </button>
            {/* UC-A30's trigger, and since ADR-0030 the only way in. */}
            <button
              data-testid="settlements-new-btn"
              onClick={() => navigate('/settlements/new')}
              style={{
                padding: '10px 20px',
                backgroundColor: theme.colors.semantic.emerald,
                color: 'white',
                border: 'none',
                borderRadius: 6,
                fontSize: 14,
                fontWeight: 500,
                cursor: 'pointer',
              }}
            >
              {t('newSettlement.title')}
            </button>
          </div>
        </div>

          {/* Error state */}
          {error && (
            <div
              data-testid="settlements-error-message"
              style={{
                padding: tableSpacing.cellPadding,
                backgroundColor: theme.colors.banner.dangerBg,
                color: theme.colors.banner.dangerText,
                borderRadius: 6,
                margin: tableSpacing.cellPadding,
              }}
            >
              Error: {error}
            </div>
          )}

          {/* The SEPA file collects less than the settlement records (#114). */}
          {exportWarning && (
            <div
              data-testid="settlements-export-warning"
              style={{
                padding: tableSpacing.cellPadding,
                backgroundColor: theme.colors.banner.warningBg,
                color: theme.colors.banner.warningText,
                borderRadius: 6,
                margin: tableSpacing.cellPadding,
              }}
            >
              {exportWarning}
            </div>
          )}

          {/* The undo landed — the only other sign is a badge in one row (#130). */}
          {undoSuccess && (
            <div
              data-testid="settlements-undo-success"
              style={{
                padding: tableSpacing.cellPadding,
                backgroundColor: theme.colors.banner.successBg,
                color: theme.colors.banner.successText,
                borderRadius: 6,
                margin: tableSpacing.cellPadding,
              }}
            >
              {undoSuccess}
            </div>
          )}

          {/* The reversal landed. For a bank return the banner also names the
              hold and links to it — a consequence nobody asked for should be
              stated rather than discovered (§6). */}
          {reverseSuccess && (
            <div
              data-testid="settlements-reverse-success"
              style={{
                padding: tableSpacing.cellPadding,
                backgroundColor: theme.colors.banner.successBg,
                color: theme.colors.banner.successText,
                borderRadius: 6,
                margin: tableSpacing.cellPadding,
              }}
            >
              {reverseSuccess.message}
              {reverseSuccess.showHoldLink && (
                <>
                  {' '}
                  <button
                    data-testid="settlements-reverse-success-hold-link"
                    onClick={() => navigate('/members/excluded')}
                    style={{
                      background: 'none',
                      border: 'none',
                      padding: 0,
                      color: 'inherit',
                      textDecoration: 'underline',
                      cursor: 'pointer',
                      font: 'inherit',
                    }}
                  >
                    {t('settlements.reverseSuccessHoldLink')}
                  </button>
                </>
              )}
            </div>
          )}

          {/* The run is with the bank now — same reasoning as the undo banner:
              the only other sign is one badge in one row (#377). */}
          {submitSuccess && (
            <div
              data-testid="settlements-submit-success"
              style={{
                padding: tableSpacing.cellPadding,
                backgroundColor: theme.colors.banner.successBg,
                color: theme.colors.banner.successText,
                borderRadius: 6,
                margin: tableSpacing.cellPadding,
              }}
            >
              {submitSuccess}
            </div>
          )}

        {isMobile ? (
          <>
            <MobileToolbar
              testId="settlements-mobile-toolbar"
              sort={{
                options: mobileSortOptions,
                value: mobileSortValue,
                onChange: list.setSortValue,
              }}
              filterCount={mobileFilterCount}
              onFilterToggle={() => setShowMobileFilters(!showMobileFilters)}
              showFilters={showMobileFilters}
              filterContent={
                <div style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
                  <PeriodPicker
                    value={period}
                    onPeriodChange={handlePeriodChange}
                    testId="settlements-period-picker"
                  />
                  <PillFilter
                    value={statusFilter}
                    onChange={(newStatus) => list.setFilter('status', newStatus)}
                    options={statusOptions}
                    testId="settlements-status-filter"
                  />
                </div>
              }
            />

            {/* Mobile card list */}
            {loading ? (
              <div style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
                {t('common.loading')}
              </div>
            ) : settlements.length === 0 ? (
              <div data-testid="settlements-empty-state" style={{ padding: theme.spacing.xl, textAlign: 'center', color: theme.colors.text.secondary }}>
                {t('settlements.noSettlements')}
              </div>
            ) : (
              <div data-testid="settlements-mobile-cards" style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                {settlements.map((settlement) => (
                  <div
                    key={settlement.id}
                    data-testid={`settlement-card-${settlement.id}`}
                    style={{
                      background: theme.mobileCard.bg,
                      border: `1px solid ${theme.mobileCard.border}`,
                      borderRadius: '10px',
                      padding: '14px 16px',
                    }}
                  >
                    {/* Row 1: date + status badge */}
                    <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', marginBottom: '6px' }}>
                      <span style={{ fontWeight: 600, color: theme.colors.text.primary, fontSize: '14px' }}>
                        {formatters.formatDate(settlement.created_at ?? '')}
                        {transactionPeriod(settlement) && (
                          <span
                            data-testid={`settlements-transaction-period-${settlement.id}`}
                            style={{
                              display: 'block',
                              fontWeight: 400,
                              fontSize: '11px',
                              color: theme.colors.text.muted,
                              marginTop: 2,
                            }}
                          >
                            {transactionPeriod(settlement)}
                          </span>
                        )}
                      </span>
                      {/* The same badge the table renders. The card used to
                          know only cancelled vs active, so on a phone an
                          exported run looked exactly like a draft (#377). */}
                      <SettlementStatusBadge
                        settlement={settlement}
                        testId={`settlements-badge-status-${settlement.id}`}
                        compact
                      />
                    </div>

                    {/* Row 2: admin user */}
                    <div style={{ fontSize: '12px', color: theme.colors.text.secondary, marginBottom: '4px' }}>
                      {settlement.created_by_admin_name || '\u2014'}
                    </div>

                    {/* Row 3: summary */}
                    <div style={{ fontSize: '12px', color: theme.colors.text.muted, marginBottom: '8px' }}>
                      {settlement.member_count} {t('settlements.memberCount')} &middot; {settlement.transaction_count} {t('settlements.transactionCount')}
                      {(settlement.reversed_member_count ?? 0) > 0 && (
                        <span
                          data-testid={`settlements-reversed-count-${settlement.id}`}
                          style={{ display: 'block', color: theme.colors.semantic.amber }}
                        >
                          {t('settlements.reversedCount', {
                            count: settlement.reversed_member_count ?? 0,
                            total: settlement.member_count ?? 0,
                          })}
                        </span>
                      )}
                    </div>

                    {/* Row 4: total amount */}
                    <div
                      data-testid={`settlements-price-${settlement.id}`}
                      style={{
                        fontWeight: 700,
                        fontFamily: 'JetBrains Mono, monospace',
                        fontSize: '18px',
                        color: theme.colors.text.primary,
                        marginBottom: '10px',
                      }}
                    >
                      {formatters.formatPrice(settlement.total_amount_cents ?? 0)}
                    </div>

                    {/* Row 5: action buttons */}
                    <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap' }}>
                      {/* Gated on status, not on cancellation alone: a file
                          that is already with the bank must not be generated
                          a second time (#377). */}
                      <button
                        data-testid={`settlements-export-sepa-btn-${settlement.id}`}
                        onClick={() => handleExportSepa(settlement.id ?? '')}
                        disabled={!canExportSepa(settlement)}
                        title={t('settlements.exportSepaHint')}
                        aria-label={t('settlements.exportSepaHint')}
                        style={{
                          padding: '5px 10px',
                          backgroundColor: canExportSepa(settlement) ? theme.colors.semantic.primary : theme.colors.semantic.neutral,
                          color: 'white',
                          border: 'none',
                          borderRadius: 4,
                          fontSize: 11,
                          fontWeight: 500,
                          cursor: canExportSepa(settlement) ? 'pointer' : 'not-allowed',
                        }}
                      >
                        {t('settlements.exportSepa')}
                      </button>
                      {/* Offered only while the run is exported-and-unsent —
                          the one state the click is about (#377). */}
                      {awaitsBankConfirmation(settlement) && (
                        <button
                          data-testid={`settlements-mark-submitted-btn-${settlement.id}`}
                          onClick={() => setSubmitTarget(settlement)}
                          title={t('settlements.markSubmittedHint')}
                          aria-label={t('settlements.markSubmittedHint')}
                          style={{
                            padding: '5px 10px',
                            backgroundColor: theme.colors.semantic.emerald,
                            color: 'white',
                            border: 'none',
                            borderRadius: 4,
                            fontSize: 11,
                            fontWeight: 500,
                            cursor: 'pointer',
                          }}
                        >
                          {t('settlements.markSubmitted')}
                        </button>
                      )}
                      <button
                        data-testid={`settlements-export-csv-btn-${settlement.id}`}
                        onClick={() => handleExportCsv(settlement.id ?? '')}
                        disabled={settlement.is_cancelled}
                        title={t('settlements.exportCsvHint')}
                        aria-label={t('settlements.exportCsvHint')}
                        style={{
                          padding: '5px 10px',
                          backgroundColor: settlement.is_cancelled ? theme.colors.semantic.neutral : theme.colors.semantic.emerald,
                          color: 'white',
                          border: 'none',
                          borderRadius: 4,
                          fontSize: 11,
                          fontWeight: 500,
                          cursor: settlement.is_cancelled ? 'not-allowed' : 'pointer',
                        }}
                      >
                        {t('settlements.exportCsv')}
                      </button>
                      <button
                        data-testid={`settlements-export-transactions-btn-${settlement.id}`}
                        onClick={() => handleExportTransactionsCsv(settlement.id ?? '')}
                        disabled={settlement.is_cancelled}
                        title={t('settlements.exportTransactionsHint')}
                        aria-label={t('settlements.exportTransactionsHint')}
                        style={{
                          padding: '5px 10px',
                          backgroundColor: settlement.is_cancelled ? theme.colors.semantic.neutral : theme.colors.semantic.purple,
                          color: 'white',
                          border: 'none',
                          borderRadius: 4,
                          fontSize: 11,
                          fontWeight: 500,
                          cursor: settlement.is_cancelled ? 'not-allowed' : 'pointer',
                        }}
                      >
                        {t('settlements.exportTransactions')}
                      </button>
                      {/* The same single slot as the table (§2). Clickable
                          while a gate refuses: the dialog carries the reason,
                          which a phone can never hover to read. */}
                      <button
                        data-testid={
                          settlementRowUndoAction(settlement) === 'reverse'
                            ? `settlements-reverse-btn-${settlement.id}`
                            : `settlements-undo-btn-${settlement.id}`
                        }
                        onClick={() => handleRowUndo(settlement)}
                        disabled={settlement.is_cancelled}
                        title={settlement.cancellation_blocked_reason ?? undefined}
                        aria-label={
                          settlementRowUndoAction(settlement) === 'reverse'
                            ? t('settlements.reverseSettlement')
                            : t('settlements.undoSettlement')
                        }
                        style={{
                          padding: '5px 10px',
                          backgroundColor: undoButtonColor(settlement),
                          color: 'white',
                          border: 'none',
                          borderRadius: 4,
                          fontSize: 11,
                          fontWeight: 500,
                          cursor: settlement.is_cancelled ? 'not-allowed' : 'pointer',
                        }}
                      >
                        {settlementRowUndoAction(settlement) === 'reverse'
                          ? t('settlements.reverse')
                          : t('common.undo')}
                      </button>
                    </div>
                  </div>
                ))}
              </div>
            )}

            {/* Pagination (mobile) */}
            {!loading && settlements.length > 0 && (
              <PaginationToolbar
                currentPage={list.page}
                totalPages={totalPages}
                totalItems={totalItems}
                pageSize={list.pageSize}
                onPageChange={list.setPage}
                onPageSizeChange={() => {}}
                showPageSize={false}
                showInfo={true}
                testId="settlements"
              />
            )}
          </>
        ) : (
          <>
          {/* Desktop Toolbar */}
          <div
            data-testid="settlements-toolbar"
            style={{
              padding: tableSpacing.cellPadding,
              borderBottom: `1px solid ${tableColors.rowActiveBorder}`,
              display: 'flex',
              flexWrap: 'wrap',
              gap: tableSpacing.actionButtonGap,
              alignItems: 'center',
              justifyContent: 'space-between',
            }}
          >
            {/* Left: Count summary */}
            <div
              data-testid="settlements-count-summary"
              style={{
                fontSize: 14,
                color: tableColors.cellSecondaryText,
              }}
            >
              {t('settlements.countFound', { count: totalItems })}
            </div>

            {/* Right: Period picker + Status filter */}
            <div style={{ display: 'flex', gap: tableSpacing.actionButtonGap, alignItems: 'center' }}>
              <PeriodPicker
                value={period}
                onPeriodChange={handlePeriodChange}
                testId="settlements-period-picker"
              />

              <PillFilter
                value={statusFilter}
                onChange={(newStatus) => list.setFilter('status', newStatus)}
                options={statusOptions}
                testId="settlements-status-filter"
              />
            </div>
          </div>

          {/* Loading state */}
          {loading ? (
            <div
              data-testid="settlements-loading"
              style={{
                padding: tableSpacing.cellPadding,
                textAlign: 'center',
                color: tableColors.cellSecondaryText,
              }}
            >
              {t('common.loading')}
            </div>
          ) : settlements.length === 0 ? (
            /* Empty state */
            <div
              data-testid="settlements-empty-state"
              style={{
                padding: tableSpacing.cellPadding,
                textAlign: 'center',
                color: tableColors.cellSecondaryText,
              }}
            >
              {t('settlements.noSettlements')}
            </div>
          ) : (
            /* Table */
            <>
              <div data-testid="settlements-table-wrapper" style={tableWrapperStyles}>
                <table
                  data-testid="settlements-table"
                  style={{
                    ...tableElementStyles,
                    // `table-layout: fixed` gave every column an equal ~16%
                    // share regardless of content, so the actions column —
                    // which holds up to five buttons — was squeezed into the
                    // same width as "Date". That is what made the row wrap
                    // raggedly, one stray button per broken line (#373).
                    // `auto` lets the browser size each column to what its
                    // content actually needs.
                    tableLayout: 'auto',
                  }}
                >
                  <thead>
                    <tr style={headerRowStyle}>
                      <th
                        style={{
                          ...headerCellBaseStyle,
                          cursor: 'pointer',
                          userSelect: 'none',
                        }}
                        onClick={() => list.toggleSort('created_at', 'desc')}
                        title={t('common.sortByColumn', { column: t('common.date') })}
                        data-testid="settlements-header-date"
                      >
                        {t('common.date')} {sortKey === 'created_at' && (sortOrder === 'asc' ? '↑' : '↓')}
                      </th>
                      <th
                        style={{
                          ...headerCellBaseStyle,
                          cursor: 'pointer',
                          userSelect: 'none',
                        }}
                        onClick={() => list.toggleSort('created_by', 'asc')}
                        title={t('common.sortByColumn', { column: t('settlements.createdBy') })}
                        data-testid="settlements-header-created-by"
                      >
                        {t('settlements.createdBy')} {sortKey === 'created_by' && (sortOrder === 'asc' ? '↑' : '↓')}
                      </th>
                      <th style={{ ...headerCellBaseStyle, textAlign: 'right' }}>{t('settlements.summary')}</th>
                      <th style={{ ...headerCellBaseStyle, textAlign: 'right' }}>{t('common.amount')}</th>
                      <th style={headerCellBaseStyle}>{t('settlements.status')}</th>
                      <th style={{ ...headerCellBaseStyle, textAlign: 'center', whiteSpace: 'nowrap' }}>{t('common.actions')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {settlements.map((settlement) => (
                      <Fragment key={settlement.id}>
                      <tr
                        data-testid={`settlements-table-row-${settlement.id}`}
                        style={getRowStyle(!settlement.is_cancelled)}
                        onMouseEnter={(e: React.MouseEvent<HTMLTableRowElement>) => {
                          if (!settlement.is_cancelled) {
                            e.currentTarget.style.backgroundColor = tableColors.rowActiveHoverBg
                          }
                        }}
                        onMouseLeave={(e: React.MouseEvent<HTMLTableRowElement>) => {
                          e.currentTarget.style.backgroundColor = settlement.is_cancelled
                            ? tableColors.rowInactiveBg
                            : tableColors.rowActiveBg
                        }}
                      >
                        {/* Date. The run's own date leads; the period it swept
                            follows only when it is not the same day said
                            twice (#378). */}
                        <td
                          data-testid={`settlements-table-cell-date-${settlement.id}`}
                          style={{
                            padding: tableSpacing.cellPadding,
                            color: tableColors.cellText,
                            fontVariantNumeric: 'tabular-nums',
                          }}
                        >
                          {/* Only the settlement's own date is held on one
                              line — a period can wrap at its separator rather
                              than overflow the fixed-width column. */}
                          <div
                            style={{
                              fontWeight: theme.typography.fontWeight.semibold,
                              whiteSpace: 'nowrap',
                            }}
                          >
                            {formatters.formatDate(settlement.created_at ?? '')}
                          </div>
                          {transactionPeriod(settlement) && (
                            <div
                              data-testid={`settlements-transaction-period-${settlement.id}`}
                              title={t('settlements.transactionPeriodHint')}
                              style={{ fontSize: 12, color: tableColors.cellSecondaryText, marginTop: 2 }}
                            >
                              {transactionPeriod(settlement)}
                            </div>
                          )}
                        </td>

                        {/* Created By */}
                        <td
                          data-testid={`settlements-table-cell-created-by-${settlement.id}`}
                          style={{
                            padding: tableSpacing.cellPadding,
                            color: tableColors.cellText,
                          }}
                        >
                          {settlement.created_by_admin_name || '—'}
                        </td>

                        {/* Summary */}
                        <td
                          data-testid={`settlements-table-cell-members-${settlement.id}`}
                          style={{
                            padding: tableSpacing.cellPadding,
                            color: tableColors.cellText,
                            textAlign: 'right',
                          }}
                        >
                          <div data-testid={`settlements-member-count-${settlement.id}`}>
                            {settlement.member_count} {t('settlements.memberCount')}
                          </div>
                          <div data-testid={`settlements-transaction-count-${settlement.id}`} style={{ fontSize: 12, color: tableColors.cellSecondaryText }}>
                            {settlement.transaction_count} {t('settlements.transactionCount')}
                          </div>
                          {/* How much of the run came back, before it is
                              expanded — the display ruling #148 §3 sketched. */}
                          {(settlement.reversed_member_count ?? 0) > 0 && (
                            <div
                              data-testid={`settlements-reversed-count-${settlement.id}`}
                              style={{ fontSize: 12, color: theme.colors.semantic.amber }}
                            >
                              {t('settlements.reversedCount', {
                                count: settlement.reversed_member_count ?? 0,
                                total: settlement.member_count ?? 0,
                              })}
                            </div>
                          )}
                        </td>

                        {/* Amount */}
                        <td
                          data-testid={`settlements-table-cell-amount-${settlement.id}`}
                          style={{
                            padding: tableSpacing.cellPadding,
                            color: tableColors.cellText,
                            fontWeight: 700,
                            fontFamily: 'JetBrains Mono, monospace',
                            fontSize: '14px',
                            textAlign: 'right',
                          }}
                        >
                          <span data-testid={`settlements-price-${settlement.id}`}>
                            {formatters.formatPrice(settlement.total_amount_cents ?? 0)}
                          </span>
                        </td>

                        {/* Status */}
                        <td
                          data-testid={`settlements-table-cell-status-${settlement.id}`}
                          style={{
                            padding: tableSpacing.cellPadding,
                          }}
                        >
                          {/* All six server states, and the marker for the one
                              the server cannot distinguish from a forgotten
                              click. Nothing is derived here (#377). */}
                          <SettlementStatusBadge
                            settlement={settlement}
                            testId={`settlements-badge-status-${settlement.id}`}
                          />
                        </td>

                        {/* Actions */}
                        <td
                          data-testid={`settlements-table-cell-actions-${settlement.id}`}
                          style={{
                            padding: tableSpacing.cellPadding,
                            textAlign: 'center',
                          }}
                        >
                          {/* One line, not a wrap that breaks unevenly (#373):
                              the buttons stay `nowrap` and the table wrapper's
                              own `overflow-x: auto` scrolls horizontally on a
                              narrow viewport instead of the row reflowing into
                              a ragged second line. */}
                          <div style={{ display: 'flex', gap: 8, justifyContent: 'center', flexWrap: 'nowrap', whiteSpace: 'nowrap' }}>
                            {/* The member breakdown (§7). The settlement detail
                                page was deliberately deleted; what failed to
                                justify a page can still justify a disclosure,
                                and `reversals[]` is returned by nothing else. */}
                            <PillActionButton
                              data-testid={`settlements-expand-btn-${settlement.id}`}
                              onClick={() =>
                                setExpandedSettlementId((current) =>
                                  current === settlement.id ? null : (settlement.id ?? null)
                                )
                              }
                              color={theme.colors.semantic.neutral}
                              hoverColor={theme.colors.semantic.blockedHover}
                              title={
                                expandedSettlementId === settlement.id
                                  ? t('settlements.collapseDetails')
                                  : t('settlements.expandDetails')
                              }
                              aria-label={
                                expandedSettlementId === settlement.id
                                  ? t('settlements.collapseDetails')
                                  : t('settlements.expandDetails')
                              }
                              aria-expanded={expandedSettlementId === settlement.id}
                            >
                              <span aria-hidden="true">{expandedSettlementId === settlement.id ? '▾' : '▸'}</span>
                            </PillActionButton>

                            {/* Export SEPA XML. Gated on status: the button
                                used to stay live through submission and
                                reversal alike, which is how a file goes to the
                                bank twice (#377). */}
                            <PillActionButton
                              data-testid={`settlements-export-sepa-btn-${settlement.id}`}
                              onClick={() => handleExportSepa(settlement.id ?? '')}
                              disabled={!canExportSepa(settlement)}
                              color={theme.colors.semantic.primary}
                              hoverColor={theme.colors.semantic.primaryHover}
                              title={t('settlements.exportSepaHint')}
                              aria-label={t('settlements.exportSepaHint')}
                            >
                              {t('settlements.exportSepa')}
                            </PillActionButton>

                            {/* Mark as submitted to the bank. Offered only for
                                an exported direct debit — the one state the
                                backend accepts it in (#377). */}
                            {awaitsBankConfirmation(settlement) && (
                              <PillActionButton
                                data-testid={`settlements-mark-submitted-btn-${settlement.id}`}
                                onClick={() => setSubmitTarget(settlement)}
                                color={theme.colors.semantic.emerald}
                                hoverColor={theme.colors.semantic.emeraldHover}
                                title={t('settlements.markSubmittedHint')}
                                aria-label={t('settlements.markSubmittedHint')}
                              >
                                {t('settlements.markSubmitted')}
                              </PillActionButton>
                            )}

                            {/* Export CSV (aggregated) */}
                            <PillActionButton
                              data-testid={`settlements-export-csv-btn-${settlement.id}`}
                              onClick={() => handleExportCsv(settlement.id ?? '')}
                              disabled={settlement.is_cancelled}
                              color={theme.colors.semantic.emerald}
                              hoverColor={theme.colors.semantic.emeraldHover}
                              title={t('settlements.exportCsvHint')}
                              aria-label={t('settlements.exportCsvHint')}
                            >
                              {t('settlements.exportCsv')}
                            </PillActionButton>

                            {/* Export Transactions CSV (detailed) */}
                            <PillActionButton
                              data-testid={`settlements-export-transactions-btn-${settlement.id}`}
                              onClick={() => handleExportTransactionsCsv(settlement.id ?? '')}
                              disabled={settlement.is_cancelled}
                              color={theme.colors.semantic.purple}
                              hoverColor={theme.colors.semantic.purpleHover}
                              title={t('settlements.exportTransactionsHint')}
                              aria-label={t('settlements.exportTransactionsHint')}
                            >
                              {t('settlements.exportTransactions')}
                            </PillActionButton>

                            {/* The undo slot — one button, not two. It reads
                                "Rückgängig" while the run can still be
                                cancelled and becomes "Zurückbuchen" the moment
                                it cannot, because exactly one of the two gates
                                is open for any live settlement (§2). Disabled
                                only once cancelled; a settlement a gate refuses
                                still opens the dialog, which states why (#127). */}
                            <PillActionButton
                              data-testid={
                                settlementRowUndoAction(settlement) === 'reverse'
                                  ? `settlements-reverse-btn-${settlement.id}`
                                  : `settlements-undo-btn-${settlement.id}`
                              }
                              onClick={() => handleRowUndo(settlement)}
                              disabled={settlement.is_cancelled}
                              color={undoButtonColor(settlement)}
                              hoverColor={undoButtonHoverColor(settlement)}
                              title={
                                settlementRowUndoAction(settlement) === 'reverse'
                                  ? t('settlements.reverseSettlement')
                                  : (settlement.cancellation_blocked_reason ?? t('settlements.undoSettlement'))
                              }
                              aria-label={
                                settlementRowUndoAction(settlement) === 'reverse'
                                  ? t('settlements.reverseSettlement')
                                  : t('settlements.undoSettlement')
                              }
                            >
                              <span aria-hidden="true">
                                {settlementRowUndoAction(settlement) === 'reverse' ? '↺' : '↩'}
                              </span>
                            </PillActionButton>
                          </div>
                        </td>
                      </tr>

                      {expandedSettlementId === settlement.id && settlement.id && (
                        <tr data-testid={`settlements-detail-row-${settlement.id}`}>
                          <td colSpan={6} style={{ background: tableColors.rowInactiveBg, padding: 0 }}>
                            <SettlementMemberBreakdown settlementId={settlement.id} />
                          </td>
                        </tr>
                      )}
                      </Fragment>
                    ))}
                  </tbody>
                </table>
              </div>

              {/* Pagination */}
              {totalItems > 0 && (
                <PaginationToolbar
                  data-testid="settlements-pagination"
                  currentPage={list.page}
                  totalPages={totalPages}
                  totalItems={totalItems}
                  pageSize={list.pageSize}
                  onPageChange={list.setPage}
                  onPageSizeChange={list.setPageSize}
                  testId="settlements"
                  showInfo={true}
                  showPageSize={true}
                />
              )}
            </>
          )}
          </>
        )}

      <UndoSettlementDialog
        settlement={undoTarget}
        onConfirm={handleUndoSettlementConfirmed}
        onCancel={() => setUndoTarget(null)}
      />

      {/* The other end of #81 (§2, §5): a run whose money has moved is booked
          back, not cancelled — and the dialog says outright that a reversal
          cannot be taken back. */}
      <ReverseSettlementDialog
        settlement={reverseTarget}
        reason={reverseReason}
        lockedMembers={reverseLockedMembers}
        initialBankReference={reverseBankReference}
        submitting={reverseSubmitting}
        error={reverseError}
        onConfirm={handleReverseConfirmed}
        onCancel={() => {
          setReverseTarget(null)
          setReverseError(null)
        }}
      />

      {/* Recording a return is a lookup, not a form (§3, ADR-0032 §8). */}
      <RecordBankReturnDialog
        isOpen={lookupOpen}
        onSelect={handleCandidateSelected}
        onCancel={() => setLookupOpen(false)}
      />

      {/* The point of no return (#377): after this the run cannot be cancelled,
          only reversed, so the dialog names the run and says so. */}
      <MarkSubmittedDialog
        settlement={submitTarget}
        onConfirm={handleMarkSubmittedConfirmed}
        onCancel={() => setSubmitTarget(null)}
      />

      {/*
        Building the bank file is the one place member IBANs are decrypted in
        bulk (ADR-0036), so it asks for the club's archived private key on top
        of the usual step-up. The key is held in page state for the length of
        the request and dropped as soon as the download succeeds or is
        abandoned — never stored.
      */}
      <StepUpConfirmDialog
        isOpen={exportTarget !== null}
        title={t('settlements.export.title')}
        message={t('settlements.export.message')}
        confirmLabel={t('settlements.export.confirm')}
        requiresTotp={callerTotpEnabled}
        error={exportStepUpError}
        confirmDisabled={exportPrivateKey.trim() === ''}
        extraFields={<PrivateKeyInput value={exportPrivateKey} onChange={setExportPrivateKey} />}
        onConfirm={handleExportSepaConfirmed}
        onCancel={() => {
          setExportTarget(null)
          setExportPrivateKey('')
          setExportStepUpError(null)
        }}
      />
      </div>
    )
}
