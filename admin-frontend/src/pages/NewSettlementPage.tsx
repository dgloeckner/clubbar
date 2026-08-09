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
import { theme } from '../styles/design-system'
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
  const hasIban = !!m.iban
  const hasMandate = !!m.mandate_reference
  if (!hasIban && !hasMandate) return 'both'
  return hasIban ? 'mandate' : 'iban'
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
    if (!executionDateInfo) {
      setSubmitError(executionDateError ?? t('newSettlement.executionDateUnavailable'))
      return
    }

    setSubmitting(true)
    setSubmitError(null)
    try {
      await getSettlements().createSettlement(
        ({
          method: 'direct_debit',
          // The run names its members. Both dates come from the server's clock,
          // never this browser's.
          member_ids: Array.from(selected),
          settlement_date: executionDateInfo.today,
          execution_date: executionDateInfo.minimum_date,
        } as unknown) as Parameters<ReturnType<typeof getSettlements>['createSettlement']>[0],
      )
      navigate('/settlements')
    } catch (err) {
      setSubmitError(err instanceof Error ? err.message : t('newSettlement.errors.create'))
    } finally {
      setSubmitting(false)
    }
  }

  const sectionTitleStyle = {
    margin: `${theme.spacing.xl} 0 ${theme.spacing.sm} 0`,
    fontSize: 16,
    fontWeight: 600,
  } as const

  const readOnlyNoteStyle = {
    margin: `0 0 ${theme.spacing.sm} 0`,
    fontSize: 13,
    color: theme.colors.text.secondary,
  } as const

  return (
    <div data-testid="new-settlement-page">
      <h1 style={{ margin: '0 0 8px 0' }}>{t('newSettlement.title')}</h1>
      <p data-testid="new-settlement-sweep-note" style={{ ...readOnlyNoteStyle, maxWidth: 720 }}>
        {t('newSettlement.sweepNote')}
      </p>

      {error && (
        <div
          data-testid="new-settlement-error-message"
          style={{
            padding: tableSpacing.cellPadding,
            backgroundColor: '#7f1d1d',
            color: '#fca5a5',
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
          {/* The run, always visible while choosing */}
          <div
            data-testid="new-settlement-summary"
            style={{
              display: 'flex',
              flexWrap: 'wrap',
              gap: theme.spacing.xl,
              alignItems: 'center',
              padding: tableSpacing.cellPadding,
              border: `1px solid ${tableColors.rowActiveBorder}`,
              borderRadius: 8,
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
            />
            <Figure
              testId="new-settlement-summary-execution-date"
              label={t('newSettlement.summary.executionDate')}
              value={executionDateInfo ? formatDate(executionDateInfo.minimum_date) : '—'}
            />

            <button
              data-testid="new-settlement-create-btn"
              onClick={handleCreate}
              disabled={submitting || selected.size === 0 || !executionDateInfo}
              style={{
                marginLeft: 'auto',
                padding: '10px 20px',
                backgroundColor: selected.size > 0 && executionDateInfo ? '#10b981' : '#6b7280',
                color: '#ffffff',
                border: 'none',
                borderRadius: 6,
                fontSize: 14,
                fontWeight: 500,
                cursor: submitting || selected.size === 0 || !executionDateInfo ? 'not-allowed' : 'pointer',
              }}
            >
              {submitting ? t('common.loading') : t('newSettlement.create')}
            </button>
          </div>

          {(submitError || executionDateError) && (
            <p
              data-testid="new-settlement-submit-error"
              style={{ color: theme.colors.semantic.danger, fontSize: 14 }}
            >
              {submitError ?? executionDateError}
            </p>
          )}

          {/* ── Eligible ─────────────────────────────────────────── */}
          <h2 style={sectionTitleStyle}>{t('newSettlement.eligible.title')}</h2>

          <div style={{ display: 'flex', gap: theme.spacing.md, alignItems: 'center', marginBottom: theme.spacing.sm }}>
            <input
              data-testid="new-settlement-search-input"
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={t('newSettlement.searchPlaceholder')}
              style={{
                padding: '8px 12px',
                borderRadius: 6,
                border: `1px solid ${tableColors.rowActiveBorder}`,
                background: theme.colors.bg.secondary,
                color: theme.colors.text.primary,
                minWidth: 240,
              }}
            />
            <button
              data-testid="new-settlement-select-all-btn"
              onClick={toggleAllVisible}
              disabled={visibleIds.length === 0}
              style={{
                padding: '8px 14px',
                borderRadius: 6,
                border: `1px solid ${tableColors.rowActiveBorder}`,
                background: 'transparent',
                color: theme.colors.text.secondary,
                cursor: visibleIds.length === 0 ? 'not-allowed' : 'pointer',
                fontSize: 13,
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
                  <th style={headerCellBaseStyle}>{t('newSettlement.columns.transactions')}</th>
                  <th style={headerCellBaseStyle}>{t('newSettlement.columns.balance')}</th>
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
                          backgroundColor: isSelected ? 'rgba(59, 130, 246, 0.1)' : tableColors.rowActiveBg,
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
                          style={{ padding: tableSpacing.cellPadding }}
                        >
                          {m.transaction_count ?? 0}
                        </td>
                        <td
                          data-testid={`new-settlement-member-balance-${id}`}
                          style={{ padding: tableSpacing.cellPadding }}
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
          )}

          {/* ── No active mandate ────────────────────────────────── */}
          {ineligible.length > 0 && (
            <section data-testid="new-settlement-ineligible-section">
              <h2 style={sectionTitleStyle}>{t('newSettlement.ineligible.title')}</h2>
              <p style={readOnlyNoteStyle}>{t('newSettlement.ineligible.note')}</p>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead>
                  <tr style={headerRowStyle}>
                    <th style={headerCellBaseStyle}>{t('newSettlement.columns.member')}</th>
                    <th style={headerCellBaseStyle}>{t('newSettlement.columns.balance')}</th>
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
                      <td style={{ padding: tableSpacing.cellPadding }}>{formatPrice(m.balance_cents ?? 0)}</td>
                      <td style={{ padding: tableSpacing.cellPadding }}>
                        {t(`newSettlement.ineligible.issue.${mandateIssue(m)}`)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </section>
          )}

          {/* ── On collection hold ───────────────────────────────── */}
          {held.length > 0 && (
            <section data-testid="new-settlement-held-section">
              <h2 style={sectionTitleStyle}>{t('newSettlement.held.title')}</h2>
              <p style={readOnlyNoteStyle}>{t('newSettlement.held.note')}</p>
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead>
                  <tr style={headerRowStyle}>
                    <th style={headerCellBaseStyle}>{t('newSettlement.columns.member')}</th>
                    <th style={headerCellBaseStyle}>{t('newSettlement.columns.balance')}</th>
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
                      <td style={{ padding: tableSpacing.cellPadding }}>{formatPrice(m.balance_cents ?? 0)}</td>
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
              <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                <thead>
                  <tr style={headerRowStyle}>
                    <th style={headerCellBaseStyle}>{t('newSettlement.columns.member')}</th>
                    <th style={headerCellBaseStyle}>{t('newSettlement.columns.balance')}</th>
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
                      <td style={{ padding: tableSpacing.cellPadding }}>{formatPrice(m.balance_cents ?? 0)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </section>
          )}
        </>
      )}
    </div>
  )
}

function Figure({ testId, label, value }: { testId: string; label: string; value: string }) {
  return (
    <div>
      <div style={{ fontSize: 12, color: theme.colors.text.secondary }}>{label}</div>
      <div data-testid={testId} style={{ fontSize: 18, fontWeight: 600 }}>
        {value}
      </div>
    </div>
  )
}
