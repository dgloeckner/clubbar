/**
 * What a run collected, member by member, with the returned ones marked
 * (epic #433 §7).
 *
 * `reversals[]` is returned only by `GET /admin/settlements/{id}`, which no UI
 * called — and the settlement detail *page* was deliberately deleted ("no
 * additional value beyond list view"). So this is a disclosure inside the list
 * row rather than a route: the detail page stays deleted, and what failed to
 * justify a page can still justify an expand.
 *
 * It shows the **full** member breakdown, not the reversals alone. A treasurer
 * expanding *"1 von 40 zurückgebucht"* is asking *which one*, and a reversal
 * shown without the other thirty-nine loses the denominator. One fetch covers
 * it — the single read returns `items[]` and `reversals[]` together.
 *
 * Test IDs: `settlement-breakdown-*`.
 */

import { useEffect, useState, type CSSProperties } from 'react'
import { useTranslation } from 'react-i18next'
import axios from 'axios'
import { theme } from '../../styles/design-system'
import { useFormatters } from '../../hooks/useFormatters'
import { useLatestRequest } from '../../hooks/useLatestRequest'
import { settlementMemberLines, type SettlementMemberLine } from '../../utils/settlementMembers'
import { getSettlements } from '../../api/generated/settlements/settlements'

export interface SettlementMemberBreakdownProps {
  settlementId: string
}

export function SettlementMemberBreakdown({ settlementId }: SettlementMemberBreakdownProps) {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const request = useLatestRequest()

  const [lines, setLines] = useState<SettlementMemberLine[] | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)

  // Fetched on expand, not with the list: the list endpoint deliberately does
  // not join items or reversals, and forty rows' worth of them would be paid
  // for on every page load to serve the rows nobody opens.
  useEffect(() => {
    const signal = request.next()
    setLoading(true)
    setError(null)

    getSettlements()
      .getSettlement(settlementId, { signal })
      .then((detail) => {
        if (signal.aborted) return
        setLines(settlementMemberLines(detail.items, detail.reversals))
      })
      .catch((err: unknown) => {
        if (signal.aborted) return
        setError(
          axios.isAxiosError(err)
            ? (err.response?.data?.message ?? err.message)
            : err instanceof Error
              ? err.message
              : t('settlements.errors.load')
        )
      })
      .finally(() => {
        if (!signal.aborted) setLoading(false)
      })
  }, [settlementId, request, t])

  if (loading) {
    return (
      <div data-testid="settlement-breakdown-loading" style={messageStyle}>
        {t('common.loading')}
      </div>
    )
  }

  if (error) {
    return (
      <div data-testid="settlement-breakdown-error" style={{ ...messageStyle, color: theme.colors.banner.dangerText }}>
        {error}
      </div>
    )
  }

  if (!lines || lines.length === 0) {
    return (
      <div data-testid="settlement-breakdown-empty" style={messageStyle}>
        {t('settlements.breakdownEmpty')}
      </div>
    )
  }

  return (
    <div data-testid="settlement-breakdown" style={{ padding: theme.spacing.md }}>
      {lines.map((line) => (
        <div
          key={line.memberId}
          data-testid={`settlement-breakdown-member-${line.memberId}`}
          style={rowStyle}
        >
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: theme.spacing.md }}>
            <span style={{ fontWeight: theme.typography.fontWeight.semibold }}>
              {line.memberName ?? line.memberId}
              <span style={mutedStyle}>
                {' '}
                &middot; {line.transactionCount} {t('settlements.transactionCount')}
              </span>
            </span>
            <span
              data-testid={`settlement-breakdown-amount-${line.memberId}`}
              style={{
                ...amountStyle,
                textDecoration: line.reversal ? 'line-through' : 'none',
                color: line.reversal ? theme.colors.text.muted : theme.colors.text.primary,
              }}
            >
              {formatters.formatPrice(line.amountCents)}
            </span>
          </div>

          {/* The reversal in full: how much came back, why, the bank's
              reference, and who recorded it when. This is what makes the
              expand answer "did someone already do this?" (§3). */}
          {line.reversal && (
            <div data-testid={`settlement-breakdown-reversal-${line.memberId}`} style={reversalStyle}>
              {t('settlements.breakdownReversed', {
                amount: formatters.formatPrice(line.reversal.amount_cents ?? 0),
                reason: t(`settlements.reversalReasons.${line.reversal.reason ?? 'bank_return'}`),
                who: line.reversal.created_by_admin_name ?? t('settlements.lookupUnknownAdmin'),
                when: line.reversal.created_at ? formatters.formatDate(line.reversal.created_at) : '—',
              })}
              {line.reversal.bank_reference && (
                <span data-testid={`settlement-breakdown-reference-${line.memberId}`} style={referenceStyle}>
                  {' '}
                  {line.reversal.bank_reference}
                </span>
              )}
            </div>
          )}
        </div>
      ))}
    </div>
  )
}

const messageStyle: CSSProperties = {
  padding: theme.spacing.lg,
  color: theme.colors.text.secondary,
  fontSize: theme.typography.fontSize.sm,
}

const rowStyle: CSSProperties = {
  display: 'flex',
  flexDirection: 'column',
  gap: '2px',
  padding: `${theme.spacing.sm} 0`,
  borderBottom: `1px solid ${theme.colors.border.light}`,
  color: theme.colors.text.primary,
  fontSize: theme.typography.fontSize.sm,
}

const mutedStyle: CSSProperties = {
  color: theme.colors.text.muted,
  fontWeight: theme.typography.fontWeight.normal,
  fontSize: theme.typography.fontSize.xs,
}

const amountStyle: CSSProperties = {
  fontFamily: 'JetBrains Mono, monospace',
  fontWeight: theme.typography.fontWeight.bold,
  whiteSpace: 'nowrap',
}

const reversalStyle: CSSProperties = {
  color: theme.colors.semantic.amber,
  fontSize: theme.typography.fontSize.xs,
}

const referenceStyle: CSSProperties = {
  fontFamily: 'JetBrains Mono, monospace',
}
