/**
 * New Settlement Page
 *
 * Implements UC-A30 and ADR-0030: **the unit of selection is the member.**
 *
 * A settlement sweeps every unsettled transaction of every member it includes
 * (ruling #141, #161 §2), so a screen that asks the admin to tick transactions
 * describes something the system does not do. This one asks for members, shows
 * each member's whole position, and shows the two excluded groups while the
 * admin is still choosing rather than as a 422 afterwards.
 *
 * There is no date filter, deliberately: the run ignores date bounds, so
 * offering one would misdescribe what is about to happen.
 *
 * Test IDs pattern: admin-frontend/patterns/test-ids.md
 * E2E: e2etests/tests/admin/new-settlement.spec.ts
 */

import { Fragment, useEffect, useMemo, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { PageActionButton } from '../components/common/PageActionButton'
import { PageHeader } from '../components/layout/PageHeader'
import { theme } from '../styles/design-system'
import { SearchIcon } from '../components/icons'
import { useFormatters } from '../hooks/useFormatters'
import { useExecutionDateInfo } from '../hooks/useExecutionDateInfo'
import { useLatestRequest } from '../hooks/useLatestRequest'
import { getSettlements } from '../api/generated/settlements/settlements'
import { getTransactions } from '../api/generated/transactions/transactions'
import { tableColors, tableSpacing, headerCellBaseStyle, headerRowStyle } from '../styles/tableTokens'
import type { SettlementPreview, SettlementPreviewMember } from '../api/generated'

interface MemberTransaction {
  id: string
  created_at: string
  product_name: string | null
  description: string
  amount_cents: number
}

const memberName = (m: SettlementPreviewMember) =>
  [m.first_name, m.last_name].filter(Boolean).join(' ')

/** "Missing IBAN" / "Missing Mandate" / "Both" — UC-A30's issue column. */
function mandateIssue(m: SettlementPreviewMember): 'iban' | 'mandate' | 'both' {
  // The preview carries only the last four characters of a sealed IBAN
  // (ADR-0036); their presence is what "has an account on file" means here.
  const hasIban = !!m.iban_last4
  const hasMandate = !!m.mandate_reference
  if (!hasIban && !hasMandate) return 'both'
  return hasIban ? 'mandate' : 'iban'
}

const sectionTitleStyle: React.CSSProperties = {
  margin: `${theme.spacing['2xl']} 0 ${theme.spacing.sm} 0`,
  fontSize: theme.typography.fontSize.lg,
  fontWeight: theme.typography.fontWeight.semibold,
  color: theme.colors.text.primary,
}

const readOnlyNoteStyle: React.CSSProperties = {
  margin: `0 0 ${theme.spacing.sm} 0`,
  fontSize: theme.typography.fontSize.sm,
  color: theme.colors.text.secondary,
  maxWidth: '76ch',
  lineHeight: theme.typography.lineHeight.normal,
}

/** Money and counts read as columns of digits, so they align on the right. */
const numericHeaderStyle: React.CSSProperties = {
  ...headerCellBaseStyle,
  textAlign: 'right',
}

const numericCellStyle: React.CSSProperties = {
  padding: tableSpacing.cellPadding,
  textAlign: 'right',
  fontVariantNumeric: 'tabular-nums',
  whiteSpace: 'nowrap',
}

/** The toolbar above the eligible-members table — same shape as the members tab. */
const toolbarStyle: React.CSSProperties = {
  display: 'flex',
  alignItems: 'center',
  gap: theme.spacing.lg,
  flexWrap: 'wrap',
  padding: '14px 18px',
  background: theme.mobileCard.bg,
  border: `1px solid ${theme.mobileCard.border}`,
  borderRadius: '10px',
  marginBottom: theme.spacing.md,
}

export function NewSettlementPage() {
  const { t } = useTranslation()
  const { formatPrice, formatDate } = useFormatters()
  const navigate = useNavigate()

  const [preview, setPreview] = useState<SettlementPreview | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  // The selection. Member ids, because that is what a run is made of.
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [search, setSearch] = useState('')
  const [expanded, setExpanded] = useState<string | null>(null)
  const [detail, setDetail] = useState<Record<string, MemberTransaction[]>>({})
  const [detailLoading, setDetailLoading] = useState(false)

  const [submitting, setSubmitting] = useState(false)
  const [submitError, setSubmitError] = useState<string | null>(null)

  const previewRequest = useLatestRequest()
  const detailRequest = useLatestRequest()

  // The backend owns the execution-date rule (ADR-0009); the date shown is the
  // date submitted, which is what #11 was about.
  const { info: executionDateInfo, error: executionDateError } = useExecutionDateInfo(true)

  const eligible = useMemo(() => preview?.eligible_members ?? [], [preview])
  const ineligible = preview?.ineligible_members ?? []
  const credit = preview?.credit_members ?? []
  // A hold outranks a missing mandate (ruling #148 §4): the member's last
  // collection came back. It is the exclusion that must never be silent,
  // because a held member is skipped run after run until somebody clears it.
  const held = preview?.held_members ?? []
  // #405: a subset of `eligible`, not a fifth bucket. These members are
  // collected from like everybody else; what the run cannot do is announce it
  // to them, and Nutzungsordnung § 7 Abs. 3 promises that announcement.
  const withoutEmail = preview?.members_without_email ?? []

  useEffect(() => {
    const signal = previewRequest.next()
    loadPreview(signal)
    return () => previewRequest.abort()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function loadPreview(signal: AbortSignal = previewRequest.next()) {
    try {
      setLoading(true)
      setError(null)

      // One read. It returns every member with an open position, in three
      // buckets, each carrying their whole position and their own row count.
      const result = await getSettlements().previewSettlement({}, { signal })
      if (signal.aborted) return

      setPreview(result)
      // UC-A30: every eligible member starts selected, so opening this screen
      // and confirming is the whole-club run.
      setSelected(new Set((result.eligible_members ?? []).map((m) => m.member_id!).filter(Boolean)))
    } catch (err) {
      if (signal.aborted) return
      setError(err instanceof Error ? err.message : t('newSettlement.errors.loadPreview'))
    } finally {
      if (!signal.aborted) setLoading(false)
    }
  }

  async function toggleExpanded(memberId: string) {
    if (expanded === memberId) {
      setExpanded(null)
      return
    }

    setExpanded(memberId)
    if (detail[memberId]) return

    const signal = detailRequest.next()
    try {
      setDetailLoading(true)
      const result = await getTransactions().listTransactions(
        { member_id: memberId, settlement_status: 'open', per_page: 100 },
        { signal },
      )
      if (signal.aborted) return

      setDetail((prev) => ({
        ...prev,
        [memberId]: (result.data ?? []).map((tx) => ({
          id: tx.id ?? '',
          created_at: tx.created_at ?? '',
          product_name: tx.product_name ?? null,
          description: tx.description ?? '',
          amount_cents: tx.amount_cents ?? 0,
        })),
      }))
    } catch {
      if (signal.aborted) return
      // The detail is informational; a failure here must not block the run.
      setDetail((prev) => ({ ...prev, [memberId]: [] }))
    } finally {
      if (!signal.aborted) setDetailLoading(false)
    }
  }

  const visibleEligible = useMemo(() => {
    const term = search.trim().toLowerCase()
    if (!term) return eligible
    return eligible.filter((m) => memberName(m).toLowerCase().includes(term))
  }, [eligible, search])

  /**
   * The run, summed over the selected members' server-provided figures.
   *
   * This is not the client computing a position out of transactions it holds —
   * the thing ADR-0030 forbids, and the mistake #128 was about. Each member's
   * `balance_cents` and `transaction_count` are the server's answer for that
   * member's whole unsettled position, and the run total is their sum by the
   * same definition the backend uses.
   */
  const runSummary = useMemo(() => {
    let members = 0
    let transactions = 0
    let totalCents = 0

    for (const m of eligible) {
      if (!m.member_id || !selected.has(m.member_id)) continue
      members += 1
      transactions += m.transaction_count ?? 0
      totalCents += m.balance_cents ?? 0
    }

    return { members, transactions, totalCents }
  }, [eligible, selected])

  /**
   * #372: a run that adds up to 0.00 € is refused by the backend, because a
   * settlement collecting nothing produces a SEPA file with no direct debit in
   * it. A zero-balance member — every sale stornoed — is still shown and still
   * selectable, since they close out perfectly well alongside somebody who owes
   * money. It is the *run's* total that has to be positive, so the button says
   * so here rather than letting the admin find out via a 409.
   */
  const nothingToCollect = selected.size > 0 && runSummary.totalCents <= 0
  const canCreate = selected.size > 0 && !nothingToCollect && !!executionDateInfo

  const toggleMember = (memberId: string) => {
    setSubmitError(null)
    setSelected((prev) => {
      const next = new Set(prev)
      if (next.has(memberId)) next.delete(memberId)
      else next.add(memberId)
      return next
    })
  }

  // Select-all acts on what is on screen, so it composes with the search
  // instead of silently reaching past it.
  const visibleIds = visibleEligible.map((m) => m.member_id!).filter(Boolean)
  const allVisibleSelected = visibleIds.length > 0 && visibleIds.every((id) => selected.has(id))

  const toggleAllVisible = () => {
    setSubmitError(null)
    setSelected((prev) => {
      const next = new Set(prev)
      if (allVisibleSelected) visibleIds.forEach((id) => next.delete(id))
      else visibleIds.forEach((id) => next.add(id))
      return next
    })
  }

  async function handleCreate() {
    if (selected.size === 0) {
      setSubmitError(t('newSettlement.selectAtLeastOneMember'))
      return
    }
    if (nothingToCollect) {
      setSubmitError(t('newSettlement.nothingToCollect'))
      return
    }
    if (!executionDateInfo) {
      setSubmitError(executionDateError ?? t('newSettlement.executionDateUnavailable'))
      return
    }

    setSubmitting(true)
    setSubmitError(null)
    try {
      const created = await getSettlements().createSettlement(
        ({
          method: 'direct_debit',
          // The run names its members. The only date it sends is the one the
          // server just suggested — there is no `settlement_date` to send, and
          // no clock of this browser's takes part in the rule (issue #113).
          member_ids: Array.from(selected),
          execution_date: executionDateInfo.minimum_date,
        } as unknown) as Parameters<ReturnType<typeof getSettlements>['createSettlement']>[0],
      )

      // "N announcements queued, sending" — never "N sent" (#407). At this
      // moment nothing has been sent and nothing could have been: the queue is
      // written inside the settlement's transaction and the scheduler is the
      // only sender (ADR-0038 rule 3). The count is what the server just
      // committed to, which is the only claim this screen can back.
      const queued = (created.notifications ?? []).filter(
        (message) => message.kind === 'sepa_prenotification'
      ).length

      navigate('/settlements', { state: { announcementsQueued: queued } })
    } catch (err) {
      setSubmitError(err instanceof Error ? err.message : t('newSettlement.errors.create'))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div data-testid="new-settlement-page">
      {/*
        Same header shape as the list this screen is reached from: title left,
        primary action right (#378). It used to sit inside the summary card
        with `marginLeft: auto`, which put the run's one irreversible button
        somewhere no other tab keeps its primary action. #375 turned that
        argument into `PageHeader`, so this page states the shape by using it.
      */}
      <PageHeader
        title={t('newSettlement.title')}
        subtitle={
          <span data-testid="new-settlement-sweep-note">{t('newSettlement.sweepNote')}</span>
        }
        actions={
          !loading && (
            <PageActionButton
              variant="success"
              data-testid="new-settlement-create-btn"
              onClick={handleCreate}
              disabled={submitting || !canCreate}
            >
              {submitting ? t('common.loading') : t('newSettlement.create')}
            </PageActionButton>
          )
        }
      />

      {error && (
        <div
          data-testid="new-settlement-error-message"
          style={{
            padding: tableSpacing.cellPadding,
            backgroundColor: theme.colors.banner.dangerBg,
            color: theme.colors.banner.dangerText,
            borderRadius: 6,
            marginBottom: theme.spacing.md,
          }}
        >
          {error}
        </div>
      )}

      {loading ? (
        <div data-testid="new-settlement-loading" style={{ padding: tableSpacing.cellPadding }}>
          {t('common.loading')}
        </div>
      ) : (
        <>
          {/* The run, always visible while choosing. Four equal tiles rather
              than four label/value pairs crammed against the button they used
              to share a row with (#378). */}
          <div
            data-testid="new-settlement-summary"
            style={{
              display: 'grid',
              gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))',
              gap: theme.spacing.md,
            }}
          >
            <Figure
              testId="new-settlement-summary-members"
              label={t('newSettlement.summary.members')}
              value={String(runSummary.members)}
            />
            <Figure
              testId="new-settlement-summary-transactions"
              label={t('newSettlement.summary.transactions')}
              value={String(runSummary.transactions)}
            />
            <Figure
              testId="new-settlement-summary-total"
              label={t('newSettlement.summary.total')}
              value={formatPrice(runSummary.totalCents)}
              accent={theme.colors.semantic.emerald}
            />
            <Figure
              testId="new-settlement-summary-execution-date"
              label={t('newSettlement.summary.executionDate')}
              value={executionDateInfo ? formatDate(executionDateInfo.minimum_date) : '—'}
            />
          </div>

          {nothingToCollect && (
            <p
              data-testid="new-settlement-nothing-to-collect"
              style={{
                margin: `${theme.spacing.md} 0 0 0`,
                color: theme.colors.semantic.danger,
                fontSize: theme.typography.fontSize.base,
              }}
            >
              {t('newSettlement.nothingToCollect')}
            </p>
          )}

          {(submitError || executionDateError) && (
            <p
              data-testid="new-settlement-submit-error"
              style={{
                margin: `${theme.spacing.md} 0 0 0`,
                color: theme.colors.semantic.danger,
                fontSize: theme.typography.fontSize.base,
              }}
            >
              {submitError ?? executionDateError}
            </p>
          )}

          {/* ── Eligible ─────────────────────────────────────────── */}
          <h2 style={sectionTitleStyle}>{t('newSettlement.eligible.title')}</h2>

          {/* Toolbar, built like the members tab's: the search field carries
              the magnifier and the surface tokens the rest of the app uses,
              instead of the bare bordered box it was (#378). */}
          <div style={toolbarStyle}>
            <div style={{ position: 'relative', flex: '0 1 260px', minWidth: '180px' }}>
              <SearchIcon
                size={16}
                style={{
                  position: 'absolute',
                  left: '10px',
                  top: '50%',
                  transform: 'translateY(-50%)',
                  color: theme.colors.text.secondary,
                  opacity: 0.6,
                  pointerEvents: 'none',
                }}
              />
              <input
                data-testid="new-settlement-search-input"
                type="search"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder={t('newSettlement.searchPlaceholder')}
                style={{
                  width: '100%',
                  padding: '8px 12px 8px 32px',
                  borderRadius: '7px',
                  border: `1px solid ${theme.colors.border.subtle}`,
                  background: theme.colors.bg.surfaceSubtle,
                  color: tableColors.cellText,
                  fontSize: theme.typography.fontSize.sm,
                  outline: 'none',
                  boxSizing: 'border-box',
                }}
              />
            </div>

            <div style={{ width: '1px', height: '28px', background: theme.colors.border.subtle }} />

            {/* How much of the list the two controls above are acting on —
                select-all only ever reaches what the search left visible. */}
            <span
              data-testid="new-settlement-selection-summary"
              style={{ fontSize: theme.typography.fontSize.sm, color: theme.colors.text.secondary }}
            >
              {t('newSettlement.selectionSummary', {
                selected: runSummary.members,
                total: eligible.length,
              })}
            </span>

            <button
              data-testid="new-settlement-select-all-btn"
              onClick={toggleAllVisible}
              disabled={visibleIds.length === 0}
              style={{
                marginLeft: 'auto',
                padding: '6px 14px',
                borderRadius: '6px',
                border: 'none',
                background: theme.pillButton.idleBg,
                color: theme.pillButton.idleText,
                cursor: visibleIds.length === 0 ? 'not-allowed' : 'pointer',
                fontSize: theme.typography.fontSize.sm,
                fontWeight: theme.typography.fontWeight.medium,
                whiteSpace: 'nowrap',
              }}
            >
              {allVisibleSelected ? t('newSettlement.selectNone') : t('newSettlement.selectAll')}
            </button>
          </div>

          {eligible.length === 0 ? (
            <p data-testid="new-settlement-eligible-empty" style={readOnlyNoteStyle}>
              {t('newSettlement.eligible.empty')}
            </p>
          ) : (
            <div style={{ overflowX: 'auto' }}>
            <table data-testid="new-settlement-eligible-table" style={{ width: '100%', borderCollapse: 'collapse' }}>
              <thead>
                <tr style={headerRowStyle}>
                  <th style={{ ...headerCellBaseStyle, width: 50 }}>
                    <input
                      type="checkbox"
                      data-testid="new-settlement-select-all-checkbox"
                      checked={allVisibleSelected}
                      onChange={toggleAllVisible}
                      aria-label={t('newSettlement.selectAll')}
                    />
                  </th>
                  <th style={headerCellBaseStyle}>{t('newSettlement.columns.member')}</th>
                  <th style={numericHeaderStyle}>{t('newSettlement.columns.transactions')}</th>
                  <th style={numericHeaderStyle}>{t('newSettlement.columns.balance')}</th>
                  <th style={{ ...headerCellBaseStyle, width: 60 }} />
                </tr>
              </thead>
              <tbody>
                {visibleEligible.map((m) => {
                  const id = m.member_id!
                  const isSelected = selected.has(id)
                  const isOpen = expanded === id

                  return (
                    // The key belongs on the fragment: a bare <> returned from
                    // a map cannot carry one, and keys on its children do not
                    // substitute.
                    <Fragment key={id}>
                      <tr
                        data-testid={`new-settlement-member-row-${id}`}
                        style={{
                          borderBottom: tableColors.rowActiveBorder,
                          backgroundColor: isSelected ? theme.badges.info.bg : tableColors.rowActiveBg,
                        }}
                      >
                        <td style={{ padding: tableSpacing.cellPadding }}>
                          <input
                            type="checkbox"
                            data-testid={`new-settlement-member-checkbox-${id}`}
                            checked={isSelected}
                            onChange={() => toggleMember(id)}
                          />
                        </td>
                        <td style={{ padding: tableSpacing.cellPadding }}>{memberName(m)}</td>
                        <td
                          data-testid={`new-settlement-member-transactions-${id}`}
                          style={numericCellStyle}
                        >
                          {m.transaction_count ?? 0}
                        </td>
                        <td
                          data-testid={`new-settlement-member-balance-${id}`}
                          style={numericCellStyle}
                        >
                          {formatPrice(m.balance_cents ?? 0)}
                        </td>
                        <td style={{ padding: tableSpacing.cellPadding }}>
                          <button
                            data-testid={`new-settlement-member-expand-${id}`}
                            onClick={() => toggleExpanded(id)}
                            aria-expanded={isOpen}
                            style={{
                              background: 'transparent',
                              border: 'none',
                              color: theme.colors.text.secondary,
                              cursor: 'pointer',
                              fontSize: 14,
                            }}
                          >
                            {isOpen ? '▾' : '▸'}
                          </button>
                        </td>
                      </tr>

                      {isOpen && (
                        <tr data-testid={`new-settlement-member-detail-${id}`}>
                          <td colSpan={5} style={{ padding: tableSpacing.cellPadding, background: theme.colors.bg.secondary }}>
                            {/*
                              Read-only on purpose. Under the sweep a
                              per-transaction choice has no meaning, so showing
                              a checkbox here would re-introduce exactly the
                              affordance ADR-0030 removed.
                            */}
                            {detailLoading && !detail[id] ? (
                              <span
                                data-testid={`new-settlement-member-detail-loading-${id}`}
                                style={{ fontSize: 13 }}
                              >
                                {t('common.loading')}
                              </span>
                            ) : (detail[id] ?? []).length === 0 ? (
                              <span
                                data-testid={`new-settlement-member-detail-empty-${id}`}
                                style={{ fontSize: 13 }}
                              >
                                {t('newSettlement.detail.empty')}
                              </span>
                            ) : (
                              <ul
                                data-testid={`new-settlement-member-detail-list-${id}`}
                                style={{ margin: 0, paddingLeft: 18, fontSize: 13 }}
                              >
                                {(detail[id] ?? []).map((tx) => (
                                  <li key={tx.id}>
                                    {formatDate(tx.created_at)} — {tx.product_name || tx.description || '—'} —{' '}
                                    {formatPrice(tx.amount_cents)}
                                  </li>
                                ))}
                              </ul>
                            )}
                          </td>
                        </tr>
                      )}
                    </Fragment>
                  )
                })}
              </tbody>
            </table>
            </div>
          )}

          {/* ── No active mandate ────────────────────────────────── */}
          {ineligible.length > 0 && (
            <section data-testid="new-settlement-ineligible-section">
              <h2 style={sectionTitleStyle}>{t('newSettlement.ineligible.title')}</h2>
              <p style={readOnlyNoteStyle}>{t('newSettlement.ineligible.note')}</p>
              <div style={{ overflowX: 'auto' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead>
                  <tr style={headerRowStyle}>
                    <th style={headerCellBaseStyle}>{t('newSettlement.columns.member')}</th>
                    <th style={numericHeaderStyle}>{t('newSettlement.columns.balance')}</th>
                    <th style={headerCellBaseStyle}>{t('newSettlement.columns.issue')}</th>
                  </tr>
                </thead>
                <tbody>
                  {ineligible.map((m) => (
                    <tr
                      key={m.member_id}
                      data-testid={`new-settlement-ineligible-row-${m.member_id}`}
                      style={{ borderBottom: tableColors.rowActiveBorder }}
                    >
                      <td style={{ padding: tableSpacing.cellPadding }}>{memberName(m)}</td>
                      <td style={numericCellStyle}>{formatPrice(m.balance_cents ?? 0)}</td>
                      <td style={{ padding: tableSpacing.cellPadding }}>
                        {t(`newSettlement.ineligible.issue.${mandateIssue(m)}`)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              </div>
            </section>
          )}

          {/* ── On collection hold ───────────────────────────────── */}
          {held.length > 0 && (
            <section data-testid="new-settlement-held-section">
              <h2 style={sectionTitleStyle}>{t('newSettlement.held.title')}</h2>
              <p style={readOnlyNoteStyle}>{t('newSettlement.held.note')}</p>
              <div style={{ overflowX: 'auto' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead>
                  <tr style={headerRowStyle}>
                    <th style={headerCellBaseStyle}>{t('newSettlement.columns.member')}</th>
                    <th style={numericHeaderStyle}>{t('newSettlement.columns.balance')}</th>
                    <th style={headerCellBaseStyle}>{t('newSettlement.columns.holdReason')}</th>
                  </tr>
                </thead>
                <tbody>
                  {held.map((m) => (
                    <tr
                      key={m.member_id}
                      data-testid={`new-settlement-held-row-${m.member_id}`}
                      style={{ borderBottom: tableColors.rowActiveBorder }}
                    >
                      <td style={{ padding: tableSpacing.cellPadding }}>{memberName(m)}</td>
                      <td style={numericCellStyle}>{formatPrice(m.balance_cents ?? 0)}</td>
                      <td
                        data-testid={`new-settlement-held-reason-${m.member_id}`}
                        style={{ padding: tableSpacing.cellPadding }}
                      >
                        {m.collection_hold_reason || t('newSettlement.held.noReason')}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              </div>
            </section>
          )}

          {/* ── Collected, but unreachable ───────────────────────── */}
          {withoutEmail.length > 0 && (
            <section data-testid="new-settlement-without-email-section">
              <h2 style={sectionTitleStyle}>{t('newSettlement.withoutEmail.title')}</h2>
              {/*
                Deliberately not an exclusion: these members appear in the
                selectable list above as well, and nothing here removes them
                from the run. The section exists so that "they will not be
                told" is visible before the run is posted rather than after.
              */}
              <p style={readOnlyNoteStyle}>{t('newSettlement.withoutEmail.note')}</p>
              <div style={{ overflowX: 'auto' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead>
                  <tr style={headerRowStyle}>
                    <th style={headerCellBaseStyle}>{t('newSettlement.columns.member')}</th>
                    <th style={numericHeaderStyle}>{t('newSettlement.columns.balance')}</th>
                  </tr>
                </thead>
                <tbody>
                  {withoutEmail.map((m) => (
                    <tr
                      key={m.member_id}
                      data-testid={`new-settlement-without-email-row-${m.member_id}`}
                      style={{ borderBottom: tableColors.rowActiveBorder }}
                    >
                      <td style={{ padding: tableSpacing.cellPadding }}>{memberName(m)}</td>
                      <td style={numericCellStyle}>{formatPrice(m.balance_cents ?? 0)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              </div>
            </section>
          )}

          {/* ── In credit ────────────────────────────────────────── */}
          {credit.length > 0 && (
            <section data-testid="new-settlement-credit-section">
              <h2 style={sectionTitleStyle}>{t('newSettlement.credit.title')}</h2>
              {/*
                Kept apart from the section above because the remedy is the
                opposite one: pay them back, do not chase their bank details
                (ruling #141, § 812 BGB).
              */}
              <p style={readOnlyNoteStyle}>{t('newSettlement.credit.note')}</p>
              <div style={{ overflowX: 'auto' }}>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead>
                  <tr style={headerRowStyle}>
                    <th style={headerCellBaseStyle}>{t('newSettlement.columns.member')}</th>
                    <th style={numericHeaderStyle}>{t('newSettlement.columns.balance')}</th>
                  </tr>
                </thead>
                <tbody>
                  {credit.map((m) => (
                    <tr
                      key={m.member_id}
                      data-testid={`new-settlement-credit-row-${m.member_id}`}
                      style={{ borderBottom: tableColors.rowActiveBorder }}
                    >
                      <td style={{ padding: tableSpacing.cellPadding }}>{memberName(m)}</td>
                      <td style={numericCellStyle}>{formatPrice(m.balance_cents ?? 0)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              </div>
            </section>
          )}
        </>
      )}
    </div>
  )
}

function Figure({
  testId,
  label,
  value,
  accent,
}: {
  testId: string
  label: string
  value: string
  accent?: string
}) {
  return (
    <div
      style={{
        background: theme.colors.bg.secondary,
        border: `1px solid ${theme.colors.border.slate}`,
        borderRadius: theme.borderRadius.md,
        padding: '14px 16px',
      }}
    >
      <div
        style={{
          fontSize: theme.typography.fontSize.xs,
          color: theme.colors.text.label,
          textTransform: 'uppercase',
          letterSpacing: '0.04em',
          fontWeight: theme.typography.fontWeight.medium,
        }}
      >
        {label}
      </div>
      <div
        data-testid={testId}
        style={{
          marginTop: theme.spacing.xs,
          fontSize: theme.typography.fontSize['2xl'],
          fontWeight: theme.typography.fontWeight.semibold,
          fontVariantNumeric: 'tabular-nums',
          color: accent ?? theme.colors.text.primary,
        }}
      >
        {value}
      </div>
    </div>
  )
}
