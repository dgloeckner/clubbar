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
 * it — the single read returns `items[]`, `reversals[]` and, since #407, the
 * queued announcements together.
 *
 * The announcement state rides along for the same reason the reversals do:
 * *"was this member actually told?"* is part of "what happened to this member",
 * and ADR-0038 rule 6 makes that question the club's to answer — delivery is
 * best effort, and best effort has to be visible. A member with no email
 * address has no queued row at all, which is why they are named here rather
 * than left as a gap.
 *
 * Test IDs: `settlement-breakdown-*`.
 */

import { useEffect, useState, type CSSProperties } from 'react'
import { useTranslation } from 'react-i18next'
import { useApiError } from '../../hooks/useApiError'
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
  const { apiErrorMessage } = useApiError()
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
        setLines(
          settlementMemberLines(
            detail.items,
            detail.reversals,
            detail.notifications,
            detail.announcements
          )
        )
      })
      .catch((err: unknown) => {
        if (signal.aborted) return
        setError(apiErrorMessage(err, t('settlements.errors.load')))
      })
      .finally(() => {
        if (!signal.aborted) setLoading(false)
      })
  }, [settlementId, request, t, apiErrorMessage])

  /**
   * What the announcement line says, in the member's row.
   *
   * A missing row is not "unknown" — it is a member the announcement could not
   * be addressed to, and saying so is the point: they are the ones somebody has
   * to ring up.
   *
   * Except when it was pruned. Ninety days after delivery the queue row is
   * deleted, address and all (ADR-0029), and reading only `notification` would
   * turn every settled collection older than a quarter into a wall of *"no
   * address"* — the worst possible way to be wrong, since that line is the one
   * that means somebody has to be rung up. `announcedAt` is the durable record
   * the drain wrote beside the send, and it is what answers for those.
   */
  const announcementLabel = (line: SettlementMemberLine): string => {
    if (!line.notification) {
      return line.announcedAt
        ? t('settlements.announcement.sent', { when: formatters.formatDate(line.announcedAt) })
        : t('settlements.announcement.noAddress')
    }

    switch (line.notification.status) {
      case 'sent':
        return t('settlements.announcement.sent', {
          when: line.notification.sent_at ? formatters.formatDate(line.notification.sent_at) : '',
        })
      case 'failed':
        return t('settlements.announcement.failed', {
          reason: line.notification.last_error ?? t('settlements.announcement.unknownError'),
        })
      case 'superseded':
        return t('settlements.announcement.superseded')
      default:
        return t('settlements.announcement.queued')
    }
  }

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

          {/* Was this member told? Queued, sent, or failed with the reason
              the receiving server gave — the same words the Notifications page
              shows, because they are the ones that point at a fix. */}
          <div data-testid={`settlement-breakdown-announcement-${line.memberId}`} style={announcementStyle(line)}>
            {announcementLabel(line)}
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

/**
 * Red means somebody has to act. A pruned announcement that did go out is
 * neither a failure nor an unreachable member, so `announcedAt` clears the
 * warning colour the same way a live `sent` row does.
 */
const announcementStyle = (line: SettlementMemberLine): CSSProperties => ({
  fontSize: theme.typography.fontSize.xs,
  color:
    line.notification?.status === 'failed' || (!line.notification && !line.announcedAt)
      ? theme.colors.semantic.danger
      : theme.colors.text.muted,
})

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
