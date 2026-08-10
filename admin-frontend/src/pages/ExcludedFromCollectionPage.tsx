import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { MembersTabs } from '../components/members/MembersTabs'
import { ConfirmDialog } from '../components/modals/ConfirmDialog'
import { Alert } from '../components/common/Alert'
import { useExcludedFromCollection } from '../hooks/useExcludedFromCollection'
import { useFormatters } from '../hooks/useFormatters'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { getMembers } from '../api/generated/members/members'
import type { CollectionHold, CreditBalance, MandateMissing } from '../api/generated'
import { theme } from '../styles/design-system'
import { tableColors, tableSpacing } from '../styles/tableTokens'

const memberName = (m: { first_name?: string; last_name?: string }) =>
  [m.first_name, m.last_name].filter(Boolean).join(' ')

const sectionTitleStyle: React.CSSProperties = {
  fontSize: theme.typography.fontSize.lg,
  fontWeight: theme.typography.fontWeight.semibold,
  color: theme.colors.text.primary,
  margin: 0,
}

const noteStyle: React.CSSProperties = {
  fontSize: theme.typography.fontSize.sm,
  color: theme.colors.text.muted,
  margin: 0,
  maxWidth: '76ch',
  lineHeight: theme.typography.lineHeight.normal,
}

const headerCellStyle: React.CSSProperties = {
  textAlign: 'left',
  background: tableColors.headerBg,
  borderBottom: tableColors.headerBorder,
  color: tableColors.headerText,
  fontSize: tableColors.headerFontSize,
  fontWeight: tableColors.headerFontWeight,
  letterSpacing: tableColors.headerLetterSpacing,
  textTransform: 'uppercase',
  padding: tableSpacing.cellPadding,
  whiteSpace: 'nowrap',
}

const cellStyle: React.CSSProperties = {
  padding: tableSpacing.cellPadding,
  borderBottom: tableColors.border,
  color: tableColors.cellText,
  verticalAlign: 'middle',
}

const numericCellStyle: React.CSSProperties = {
  ...cellStyle,
  textAlign: 'right',
  fontVariantNumeric: 'tabular-nums',
  whiteSpace: 'nowrap',
}

const footerCellStyle: React.CSSProperties = {
  padding: tableSpacing.cellPadding,
  background: 'rgba(15, 29, 50, 0.5)',
  borderTop: tableColors.headerBorder,
  fontWeight: theme.typography.fontWeight.semibold,
  color: tableColors.cellText,
  fontVariantNumeric: 'tabular-nums',
}

const tableWrapStyle: React.CSSProperties = {
  background: theme.colors.bg.secondary,
  border: tableColors.rowActiveBorder,
  borderRadius: tableSpacing.tableWrapperRadius,
  overflowX: 'auto',
}

const emptyStyle: React.CSSProperties = {
  background: theme.colors.bg.secondary,
  border: `1px dashed rgba(71, 85, 105, 0.4)`,
  borderRadius: tableSpacing.tableWrapperRadius,
  padding: '26px 20px',
  textAlign: 'center',
  color: theme.colors.text.muted,
  fontSize: theme.typography.fontSize.sm,
}

const cardStyle: React.CSSProperties = {
  background: theme.colors.bg.card,
  border: '1px solid rgba(71, 85, 105, 0.28)',
  borderRadius: theme.borderRadius.md,
  padding: '13px 14px',
  display: 'flex',
  flexDirection: 'column',
  gap: theme.spacing.sm,
}

const clearButtonStyle: React.CSSProperties = {
  padding: '6px 12px',
  borderRadius: tableColors.buttonBorderRadius,
  fontSize: theme.typography.fontSize.sm,
  fontWeight: theme.typography.fontWeight.semibold,
  border: `1px solid ${tableColors.buttonPrimaryBorder}`,
  background: tableColors.buttonPrimaryBg,
  color: tableColors.buttonPrimaryText,
  cursor: 'pointer',
  whiteSpace: 'nowrap',
}

type Tone = 'credit' | 'held' | 'nomandate' | 'ok'

interface TileProps {
  label: string
  value: string
  sub: string
  tone: Tone
  testId: string
}

function Tile({ label, value, sub, tone, testId }: TileProps) {
  const toneColor = {
    credit: theme.colors.semantic.warning,
    held: theme.colors.semantic.danger,
    // A third exclusion needs a third reading, not a reused one: this is money
    // owed in that no run can reach, distinct from owed-out and from
    // owed-in-but-skipped.
    nomandate: theme.colors.semantic.info,
    ok: theme.colors.semantic.success,
  }[tone]

  return (
    <div
      data-testid={testId}
      style={{
        background: theme.colors.bg.card,
        border: tableColors.headerBorder,
        borderRadius: theme.borderRadius.md,
        padding: '15px 17px',
        display: 'flex',
        flexDirection: 'column',
        gap: theme.spacing.xs,
      }}
    >
      <span
        style={{
          fontSize: theme.typography.fontSize.xs,
          letterSpacing: '0.06em',
          textTransform: 'uppercase',
          color: theme.colors.text.secondary,
          fontWeight: theme.typography.fontWeight.semibold,
        }}
      >
        {label}
      </span>
      <span
        data-testid={`${testId}-value`}
        style={{
          fontSize: theme.typography.fontSize['3xl'],
          fontWeight: theme.typography.fontWeight.semibold,
          letterSpacing: '-0.02em',
          fontVariantNumeric: 'tabular-nums',
          color: toneColor,
        }}
      >
        {value}
      </span>
      <span style={{ fontSize: theme.typography.fontSize.sm, color: theme.colors.text.muted }}>
        {sub}
      </span>
    </div>
  )
}

function SectionHeader({ title, chip, chipTone }: { title: string; chip: string; chipTone: Tone }) {
  const chipStyles = {
    credit: { background: 'rgba(249, 115, 22, 0.15)', color: '#fdba74' },
    held: { background: 'rgba(239, 68, 68, 0.15)', color: '#fca5a5' },
    nomandate: { background: 'rgba(14, 165, 233, 0.15)', color: '#7dd3fc' },
    ok: { background: 'rgba(34, 197, 94, 0.15)', color: '#86efac' },
  }[chipTone]

  return (
    <div style={{ display: 'flex', alignItems: 'baseline', gap: theme.spacing.md, flexWrap: 'wrap' }}>
      <h2 style={sectionTitleStyle}>{title}</h2>
      <span
        style={{
          fontFamily: theme.typography.fontFamily.mono,
          fontSize: theme.typography.fontSize.xs,
          fontWeight: theme.typography.fontWeight.semibold,
          padding: '2px 8px',
          borderRadius: theme.borderRadius.full,
          ...chipStyles,
        }}
      >
        {chip}
      </span>
    </div>
  )
}

export function ExcludedFromCollectionPage() {
  const { t } = useTranslation()
  const { formatPrice, formatDate } = useFormatters()
  const breakpoint = useBreakpoint()
  const isNarrow = breakpoint === 'mobile' || breakpoint === 'smallMobile'

  const { credit, holds, noMandate, loading, excludedCount, reload } = useExcludedFromCollection()

  const [pendingHold, setPendingHold] = useState<CollectionHold | null>(null)
  const [clearing, setClearing] = useState(false)
  const [clearError, setClearError] = useState<string | null>(null)

  const holdProvenance = (hold: CollectionHold): string => {
    const reason = hold.collection_hold_reason || t('excluded.holds.noReason')
    const date = hold.held_at ? formatDate(hold.held_at) : t('excluded.holds.unknownAdmin')
    return hold.held_by_admin_name
      ? t('excluded.clearDialog.provenance', { reason, date, admin: hold.held_by_admin_name })
      : t('excluded.clearDialog.provenanceNoAdmin', { reason, date })
  }

  const confirmClear = async () => {
    if (!pendingHold?.member_id) return

    setClearing(true)
    setClearError(null)
    try {
      await getMembers().clearCollectionHold(pendingHold.member_id)
      setPendingHold(null)
    } catch (err) {
      // A 409 means somebody else cleared it first. Say so, and still reload —
      // the page must stop disagreeing with the server either way.
      setClearError(err instanceof Error ? err.message : t('excluded.errors.clearFailed'))
      setPendingHold(null)
    } finally {
      setClearing(false)
      reload()
    }
  }

  const creditRows = (items: CreditBalance[]) =>
    items.map((m) => (
      <tr key={m.member_id} data-testid={`excluded-credit-row-${m.member_id}`}>
        <td style={{ ...cellStyle, fontWeight: theme.typography.fontWeight.medium }}>
          {memberName(m)}
        </td>
        <td
          data-testid={`excluded-credit-amount-${m.member_id}`}
          style={{ ...numericCellStyle, color: theme.colors.semantic.warning }}
        >
          {formatPrice(m.balance_cents ?? 0)}
        </td>
      </tr>
    ))

  const holdRows = (items: CollectionHold[]) =>
    items.map((h) => (
      <tr key={h.member_id} data-testid={`excluded-hold-row-${h.member_id}`}>
        <td style={{ ...cellStyle, fontWeight: theme.typography.fontWeight.medium }}>
          {memberName(h)}
        </td>
        <td
          data-testid={`excluded-hold-amount-${h.member_id}`}
          style={{ ...numericCellStyle, color: theme.colors.semantic.danger }}
        >
          {formatPrice(h.balance_cents ?? 0)}
        </td>
        <td
          style={{
            ...cellStyle,
            fontSize: theme.typography.fontSize.sm,
            color: tableColors.cellSecondaryText,
            maxWidth: '42ch',
          }}
        >
          {h.collection_hold_reason || t('excluded.holds.noReason')}
        </td>
        <td style={{ ...cellStyle, color: tableColors.cellSecondaryText, whiteSpace: 'nowrap' }}>
          {h.held_at ? formatDate(h.held_at) : t('excluded.holds.unknownAdmin')}
        </td>
        <td style={{ ...cellStyle, color: tableColors.cellSecondaryText, whiteSpace: 'nowrap' }}>
          {h.held_by_admin_name || t('excluded.holds.unknownAdmin')}
        </td>
        <td style={cellStyle}>
          <button
            type="button"
            data-testid={`excluded-hold-clear-btn-${h.member_id}`}
            aria-label={t('excluded.holds.clearAria', { name: memberName(h) })}
            onClick={() => setPendingHold(h)}
            style={clearButtonStyle}
          >
            {t('excluded.holds.clear')}
          </button>
        </td>
      </tr>
    ))

  const noMandateRows = (items: MandateMissing[]) =>
    items.map((m) => (
      <tr key={m.member_id} data-testid={`excluded-nomandate-row-${m.member_id}`}>
        <td style={{ ...cellStyle, fontWeight: theme.typography.fontWeight.medium }}>
          {memberName(m)}
        </td>
        <td
          data-testid={`excluded-nomandate-amount-${m.member_id}`}
          style={{ ...numericCellStyle, color: theme.colors.semantic.info }}
        >
          {formatPrice(m.balance_cents ?? 0)}
        </td>
      </tr>
    ))

  const creditCards = (items: CreditBalance[]) =>
    items.map((m) => (
      <div key={m.member_id} data-testid={`excluded-credit-row-${m.member_id}`} style={cardStyle}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: theme.spacing.md }}>
          <span style={{ fontWeight: theme.typography.fontWeight.semibold, color: theme.colors.text.primary }}>
            {memberName(m)}
          </span>
          <span
            data-testid={`excluded-credit-amount-${m.member_id}`}
            style={{
              fontWeight: theme.typography.fontWeight.semibold,
              fontVariantNumeric: 'tabular-nums',
              color: theme.colors.semantic.warning,
            }}
          >
            {formatPrice(m.balance_cents ?? 0)}
          </span>
        </div>
      </div>
    ))

  const holdCards = (items: CollectionHold[]) =>
    items.map((h) => (
      <div key={h.member_id} data-testid={`excluded-hold-row-${h.member_id}`} style={cardStyle}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: theme.spacing.md }}>
          <span style={{ fontWeight: theme.typography.fontWeight.semibold, color: theme.colors.text.primary }}>
            {memberName(h)}
          </span>
          <span
            data-testid={`excluded-hold-amount-${h.member_id}`}
            style={{
              fontWeight: theme.typography.fontWeight.semibold,
              fontVariantNumeric: 'tabular-nums',
              color: theme.colors.semantic.danger,
            }}
          >
            {formatPrice(h.balance_cents ?? 0)}
          </span>
        </div>
        <div
          style={{
            display: 'flex',
            flexDirection: 'column',
            gap: '3px',
            fontSize: theme.typography.fontSize.xs,
            color: theme.colors.text.muted,
          }}
        >
          <span>{h.collection_hold_reason || t('excluded.holds.noReason')}</span>
          <span>
            {h.held_at ? formatDate(h.held_at) : t('excluded.holds.unknownAdmin')}
            {h.held_by_admin_name ? ` · ${h.held_by_admin_name}` : ''}
          </span>
        </div>
        <button
          type="button"
          data-testid={`excluded-hold-clear-btn-${h.member_id}`}
          aria-label={t('excluded.holds.clearAria', { name: memberName(h) })}
          onClick={() => setPendingHold(h)}
          style={{ ...clearButtonStyle, width: '100%' }}
        >
          {t('excluded.holds.clear')}
        </button>
      </div>
    ))

  const noMandateCards = (items: MandateMissing[]) =>
    items.map((m) => (
      <div key={m.member_id} data-testid={`excluded-nomandate-row-${m.member_id}`} style={cardStyle}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', gap: theme.spacing.md }}>
          <span style={{ fontWeight: theme.typography.fontWeight.semibold, color: theme.colors.text.primary }}>
            {memberName(m)}
          </span>
          <span
            data-testid={`excluded-nomandate-amount-${m.member_id}`}
            style={{
              fontWeight: theme.typography.fontWeight.semibold,
              fontVariantNumeric: 'tabular-nums',
              color: theme.colors.semantic.info,
            }}
          >
            {formatPrice(m.balance_cents ?? 0)}
          </span>
        </div>
      </div>
    ))

  return (
    <>
      <div data-testid="excluded-page" style={{ padding: '20px' }}>
        <h1
          style={{
            fontSize: theme.typography.fontSize['3xl'],
            fontWeight: theme.typography.fontWeight.bold,
            color: theme.colors.text.primary,
            margin: `0 0 ${theme.spacing.xl} 0`,
          }}
        >
          {t('members.title')}
        </h1>

        <MembersTabs excludedCount={loading ? undefined : excludedCount} />

        {clearError && (
          <div style={{ marginBottom: theme.spacing.lg }}>
            <Alert variant="danger" message={clearError} testId="excluded-clear-error" />
          </div>
        )}

        {loading ? (
          <div data-testid="excluded-loading" style={{ padding: tableSpacing.cellPadding, color: theme.colors.text.secondary }}>
            {t('common.loading')}
          </div>
        ) : (
          <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing['3xl'] }}>
            {/* Summary — the three figures the treasurer is here for. Each is a
                different kind of money, so none of them may be summed with
                another: owed out, owed in but skipped, owed in and unreachable. */}
            <div
              style={{
                display: 'grid',
                gridTemplateColumns: isNarrow ? 'minmax(0, 1fr)' : 'repeat(3, minmax(0, 1fr))',
                gap: theme.spacing.lg,
              }}
            >
              <Tile
                testId="excluded-credit-total"
                tone={credit.error ? 'ok' : credit.items.length > 0 ? 'credit' : 'ok'}
                label={t('excluded.tiles.creditLabel')}
                value={credit.error ? t('excluded.tiles.unavailable') : formatPrice(credit.totalCents)}
                sub={
                  credit.error
                    ? credit.error
                    : credit.items.length > 0
                      ? t('excluded.tiles.creditSub', { count: credit.items.length })
                      : t('excluded.tiles.creditNobody')
                }
              />
              <Tile
                testId="excluded-hold-total"
                tone={holds.error ? 'ok' : holds.items.length > 0 ? 'held' : 'ok'}
                label={t('excluded.tiles.heldLabel')}
                value={holds.error ? t('excluded.tiles.unavailable') : formatPrice(holds.totalCents)}
                sub={
                  holds.error
                    ? holds.error
                    : holds.items.length > 0
                      ? t('excluded.tiles.heldSub', { count: holds.items.length })
                      : t('excluded.tiles.heldNobody')
                }
              />
              <Tile
                testId="excluded-nomandate-total"
                tone={noMandate.error ? 'ok' : noMandate.items.length > 0 ? 'nomandate' : 'ok'}
                label={t('excluded.tiles.noMandateLabel')}
                value={
                  noMandate.error ? t('excluded.tiles.unavailable') : formatPrice(noMandate.totalCents)
                }
                sub={
                  noMandate.error
                    ? noMandate.error
                    : noMandate.items.length > 0
                      ? t('excluded.tiles.noMandateSub', { count: noMandate.items.length })
                      : t('excluded.tiles.noMandateNobody')
                }
              />
            </div>

            {/* ── In credit ───────────────────────────────────────── */}
            <section style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.md }}>
              <SectionHeader
                title={t('excluded.credit.title')}
                chip={credit.items.length > 0 ? t('excluded.count', { count: credit.items.length }) : t('excluded.none')}
                chipTone={credit.items.length > 0 ? 'credit' : 'ok'}
              />
              <p style={noteStyle}>{t('excluded.credit.note')}</p>

              {credit.error ? (
                <Alert variant="danger" message={credit.error} testId="excluded-credit-error" />
              ) : credit.items.length === 0 ? (
                <div data-testid="excluded-credit-empty" style={emptyStyle}>
                  {t('excluded.credit.empty')}
                </div>
              ) : isNarrow ? (
                <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.md }}>
                  {creditCards(credit.items)}
                </div>
              ) : (
                <div style={tableWrapStyle}>
                  <table data-testid="excluded-credit-table" style={{ width: '100%', borderCollapse: 'collapse' }}>
                    <thead>
                      <tr>
                        <th style={headerCellStyle}>{t('excluded.credit.columns.member')}</th>
                        <th style={{ ...headerCellStyle, textAlign: 'right' }}>
                          {t('excluded.credit.columns.balance')}
                        </th>
                      </tr>
                    </thead>
                    <tbody>{creditRows(credit.items)}</tbody>
                    <tfoot>
                      <tr>
                        <td style={footerCellStyle}>{t('excluded.credit.total')}</td>
                        <td
                          style={{
                            ...footerCellStyle,
                            textAlign: 'right',
                            color: theme.colors.semantic.warning,
                          }}
                        >
                          {formatPrice(credit.totalCents)}
                        </td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              )}
            </section>

            {/* ── On collection hold ──────────────────────────────── */}
            <section style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.md }}>
              <SectionHeader
                title={t('excluded.holds.title')}
                chip={holds.items.length > 0 ? t('excluded.count', { count: holds.items.length }) : t('excluded.none')}
                chipTone={holds.items.length > 0 ? 'held' : 'ok'}
              />
              <p style={noteStyle}>{t('excluded.holds.note')}</p>

              {holds.error ? (
                <Alert variant="danger" message={holds.error} testId="excluded-hold-error" />
              ) : holds.items.length === 0 ? (
                <div data-testid="excluded-hold-empty" style={emptyStyle}>
                  {t('excluded.holds.empty')}
                </div>
              ) : isNarrow ? (
                <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.md }}>
                  {holdCards(holds.items)}
                </div>
              ) : (
                <div style={tableWrapStyle}>
                  <table data-testid="excluded-hold-table" style={{ width: '100%', borderCollapse: 'collapse' }}>
                    <thead>
                      <tr>
                        <th style={headerCellStyle}>{t('excluded.holds.columns.member')}</th>
                        <th style={{ ...headerCellStyle, textAlign: 'right' }}>
                          {t('excluded.holds.columns.balance')}
                        </th>
                        <th style={headerCellStyle}>{t('excluded.holds.columns.reason')}</th>
                        <th style={headerCellStyle}>{t('excluded.holds.columns.heldSince')}</th>
                        <th style={headerCellStyle}>{t('excluded.holds.columns.heldBy')}</th>
                        <th style={headerCellStyle} aria-label={t('excluded.holds.clear')} />
                      </tr>
                    </thead>
                    <tbody>{holdRows(holds.items)}</tbody>
                    <tfoot>
                      <tr>
                        <td style={footerCellStyle}>{t('excluded.holds.total')}</td>
                        <td
                          style={{
                            ...footerCellStyle,
                            textAlign: 'right',
                            color: theme.colors.semantic.danger,
                          }}
                        >
                          {formatPrice(holds.totalCents)}
                        </td>
                        <td style={footerCellStyle} colSpan={4} />
                      </tr>
                    </tfoot>
                  </table>
                </div>
              )}
            </section>

            {/* ── No usable mandate ───────────────────────────────── */}
            <section style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.md }}>
              <SectionHeader
                title={t('excluded.noMandate.title')}
                chip={
                  noMandate.items.length > 0
                    ? t('excluded.count', { count: noMandate.items.length })
                    : t('excluded.none')
                }
                chipTone={noMandate.items.length > 0 ? 'nomandate' : 'ok'}
              />
              <p style={noteStyle}>{t('excluded.noMandate.note')}</p>

              {noMandate.error ? (
                <Alert variant="danger" message={noMandate.error} testId="excluded-nomandate-error" />
              ) : noMandate.items.length === 0 ? (
                <div data-testid="excluded-nomandate-empty" style={emptyStyle}>
                  {t('excluded.noMandate.empty')}
                </div>
              ) : isNarrow ? (
                <div style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.md }}>
                  {noMandateCards(noMandate.items)}
                </div>
              ) : (
                <div style={tableWrapStyle}>
                  <table
                    data-testid="excluded-nomandate-table"
                    style={{ width: '100%', borderCollapse: 'collapse' }}
                  >
                    <thead>
                      <tr>
                        <th style={headerCellStyle}>{t('excluded.noMandate.columns.member')}</th>
                        <th style={{ ...headerCellStyle, textAlign: 'right' }}>
                          {t('excluded.noMandate.columns.balance')}
                        </th>
                      </tr>
                    </thead>
                    <tbody>{noMandateRows(noMandate.items)}</tbody>
                    <tfoot>
                      <tr>
                        <td style={footerCellStyle}>{t('excluded.noMandate.total')}</td>
                        <td
                          style={{
                            ...footerCellStyle,
                            textAlign: 'right',
                            color: theme.colors.semantic.info,
                          }}
                        >
                          {formatPrice(noMandate.totalCents)}
                        </td>
                      </tr>
                    </tfoot>
                  </table>
                </div>
              )}
            </section>
          </div>
        )}
      </div>

      <ConfirmDialog
        isOpen={pendingHold !== null}
        variant="danger"
        title={t('excluded.clearDialog.title', { name: pendingHold ? memberName(pendingHold) : '' })}
        // Naming the hold, not just asking "are you sure" — the member goes back
        // into the next sweep for the amount that just bounced, and clicking
        // again does not undo it (#127).
        message={
          <span data-testid="excluded-clear-dialog-message">
            {t('excluded.clearDialog.consequence', {
              amount: formatPrice(pendingHold?.balance_cents ?? 0),
            })}
            <span
              data-testid="excluded-clear-dialog-provenance"
              style={{
                display: 'block',
                marginTop: theme.spacing.md,
                fontFamily: theme.typography.fontFamily.mono,
                fontSize: theme.typography.fontSize.xs,
                lineHeight: theme.typography.lineHeight.normal,
                background: theme.colors.bg.input,
                borderLeft: `2px solid rgba(239, 68, 68, 0.5)`,
                borderRadius: `0 ${theme.borderRadius.sm} ${theme.borderRadius.sm} 0`,
                padding: '10px 12px',
                color: tableColors.cellSecondaryText,
              }}
            >
              {pendingHold ? holdProvenance(pendingHold) : ''}
            </span>
            <span style={{ display: 'block', marginTop: theme.spacing.md }}>
              {t('excluded.clearDialog.warning')}
            </span>
          </span>
        }
        confirmLabel={t('excluded.clearDialog.confirm')}
        confirmDisabled={clearing}
        onConfirm={confirmClear}
        onCancel={() => setPendingHold(null)}
      />
    </>
  )
}
