/**
 * The mail queue (#407, ADR-0038 rule 6).
 *
 * Announcements are queued inside the settlement's transaction and sent by a
 * scheduler that nobody watches. That makes delivery best effort — and the
 * price of best effort is that it must never be *invisible*. This page is the
 * whole of that visibility: what was queued, for whom, whether it left, and the
 * receiving server's own words when it did not.
 *
 * ### The one rule this page must never break
 *
 * ADR-0038 rule 4: **the UI may change state, it may never orchestrate time.**
 * The retry button sets one row back to `pending` — a state change, and fine.
 * What is deliberately absent, and must stay absent: any `setInterval`, any
 * polling until the queue empties, any loop that requests batches and counts
 * progress. A browser tab is not allowed to become a second sending path, and a
 * page that quietly refreshed itself would be the first step towards one.
 *
 * That is also why nothing here says when a retried message will go out. The
 * honest answer is "at the next tick", and a countdown would be a promise this
 * page cannot keep.
 *
 * ### Why a queue page rather than per-settlement status
 *
 * The settlement detail shows its own announcements (the expandable breakdown),
 * but the periodic Deckelauszug (ADR-0039) has no settlement at all — its
 * subject is the member and its key is the period. A page keyed on settlements
 * would make an entire message kind invisible by construction.
 *
 * Test IDs: `notifications-*`.
 */

import { useState, type CSSProperties } from 'react'
import { useTranslation } from 'react-i18next'
import axios from 'axios'
import { PageHeader } from '../components/layout/PageHeader'
import { theme, formatDateTime } from '../styles/design-system'
import { useBreakpoint } from '../hooks/useBreakpoint'
import { useListQuery } from '../hooks/useListQuery'
import { PaginationToolbar } from '../components/tables/PaginationToolbar'
import { SortableTableHeader } from '../components/tables/SortableTableHeader'
import {
  tableWrapperStyles,
  tableElementStyles,
  headerRowStyle,
  headerCellBaseStyle,
  tableColors,
  getRowStyle,
} from '../styles/tableTokens'
import { getNotifications } from '../api/generated/notifications/notifications'
import type { QueuedMail, ListNotificationsParams, ListNotificationsSort } from '../api/generated'

type SortKey = 'queued_at' | 'sent_at' | 'status' | 'kind' | 'recipient'

interface NotificationFilters {
  kind: string
  status: string
}

/**
 * Status colours, chosen so the two that need action are the two that stand
 * out. `superseded` is deliberately muted: nothing went wrong and nothing is
 * expected of anybody — the collection was called off before the member was
 * told.
 */
function statusColor(status: string): { bg: string; fg: string } {
  switch (status) {
    case 'sent':
      return { bg: 'rgba(34, 197, 94, 0.2)', fg: theme.colors.semantic.success }
    case 'failed':
      return { bg: theme.badges.danger.strong, fg: theme.colors.semantic.danger }
    case 'pending':
      return { bg: theme.activeTint.primaryStrong, fg: theme.colors.semantic.primary }
    default:
      return { bg: theme.colors.bg.input, fg: theme.colors.text.muted }
  }
}

export function NotificationsPage() {
  const { t } = useTranslation()
  const breakpoint = useBreakpoint()
  const isMobile = breakpoint === 'mobile' || breakpoint === 'smallMobile'

  const [retryingId, setRetryingId] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  // Page, page size, sort, filters, the search debounce and request aborting
  // all belong to useListQuery — hand-rolling any of it on a list page is what
  // `admin-frontend/patterns/table-implementation.md` exists to stop.
  const list = useListQuery<QueuedMail, NotificationFilters, SortKey>({
    initialFilters: { kind: '', status: '' },
    initialSortKey: 'queued_at',
    initialSortDirection: 'desc',
    initialPageSize: 25,
    fetcher: async ({ page, pageSize, sortKey, sortDirection, search, filters, signal }) => {
      const params: ListNotificationsParams = {
        page,
        per_page: pageSize,
        sort: sortKey as ListNotificationsSort,
        order: sortDirection,
        kind: (filters.kind as ListNotificationsParams['kind']) || undefined,
        status: (filters.status as ListNotificationsParams['status']) || undefined,
        search: search || undefined,
      }
      const response = await getNotifications().listNotifications(params, { signal })
      return { items: response.data ?? [], total: response.pagination?.total ?? 0 }
    },
    parseError: (err) =>
      axios.isAxiosError(err)
        ? (err.response?.data?.message ?? err.message)
        : err instanceof Error
          ? err.message
          : t('notifications.errors.load'),
  })

  const { items, total, totalPages, loading, hasLoaded, error } = list

  /**
   * The only mutation on this page.
   *
   * `reload()` afterwards rather than patching the row in place: the row's new
   * state is the server's to state, and a locally-invented "pending" would be
   * the first lie a page tells about a queue it does not drive.
   */
  const retry = async (id: string) => {
    setRetryingId(id)
    setNotice(null)
    list.setError(null)

    try {
      await getNotifications().retryNotification(id)
      await list.reload()
      setNotice(t('notifications.retryQueued'))
    } catch (err: unknown) {
      list.setError(
        axios.isAxiosError(err)
          ? (err.response?.data?.message ?? err.message)
          : t('notifications.errors.retry')
      )
    } finally {
      setRetryingId(null)
    }
  }

  const kindLabel = (kind: string) => t(`notifications.kinds.${kind}`, { defaultValue: kind })
  const statusLabel = (status: string) => t(`notifications.statuses.${status}`, { defaultValue: status })

  const renderFilters = (stacked: boolean) => (
    <div
      style={
        stacked
          ? { display: 'flex', flexDirection: 'column', gap: theme.spacing.md }
          : {
              display: 'flex',
              gap: theme.spacing.md,
              padding: `${theme.spacing.md} ${theme.spacing.lg}`,
              borderBottom: `1px solid ${tableColors.border}`,
              alignItems: 'flex-end',
              flexWrap: 'wrap',
            }
      }
    >
      <div style={stacked ? undefined : { minWidth: 200 }}>
        <label style={labelStyle} htmlFor="notifications-filter-kind">
          {t('notifications.filterKind')}
        </label>
        <select
          id="notifications-filter-kind"
          data-testid="notifications-filter-kind"
          value={list.filters.kind}
          onChange={(e) => list.setFilter('kind', e.target.value)}
          style={selectStyle}
        >
          <option value="">{t('notifications.allKinds')}</option>
          {KINDS.map((kind) => (
            <option key={kind} value={kind}>
              {kindLabel(kind)}
            </option>
          ))}
        </select>
      </div>

      <div style={stacked ? undefined : { minWidth: 180 }}>
        <label style={labelStyle} htmlFor="notifications-filter-status">
          {t('notifications.filterStatus')}
        </label>
        <select
          id="notifications-filter-status"
          data-testid="notifications-filter-status"
          value={list.filters.status}
          onChange={(e) => list.setFilter('status', e.target.value)}
          style={selectStyle}
        >
          <option value="">{t('notifications.allStatuses')}</option>
          {STATUSES.map((status) => (
            <option key={status} value={status}>
              {statusLabel(status)}
            </option>
          ))}
        </select>
      </div>

      <div style={stacked ? undefined : { flex: 1, minWidth: 220 }}>
        <label style={labelStyle} htmlFor="notifications-search">
          {t('notifications.search')}
        </label>
        <input
          id="notifications-search"
          data-testid="notifications-search"
          type="text"
          value={list.search}
          onChange={(e) => list.setSearch(e.target.value)}
          placeholder={t('notifications.searchPlaceholder')}
          style={inputStyle}
        />
      </div>
    </div>
  )

  const renderRetryButton = (row: QueuedMail) =>
    row.is_retryable ? (
      <button
        data-testid={`notifications-retry-${row.id}`}
        onClick={() => retry(row.id ?? '')}
        disabled={retryingId === row.id}
        style={retryButtonStyle}
      >
        {retryingId === row.id ? t('common.loading') : t('notifications.retry')}
      </button>
    ) : null

  const renderRecipient = (row: QueuedMail) => (
    <>
      <div data-testid={`notifications-member-${row.id}`}>
        {row.member_name ?? row.admin_name ?? t('notifications.unknownRecipient')}
      </div>
      {/* The snapshot address, not a join: it is the proof of who was written
          to, and "it went to the old address" is a diagnosis the name alone
          cannot give. */}
      <div data-testid={`notifications-recipient-${row.id}`} style={mutedStyle}>
        {row.recipient}
      </div>
    </>
  )

  const renderStatusBadge = (row: QueuedMail) => {
    const colors = statusColor(row.status ?? '')
    return (
      <span
        data-testid={`notifications-status-${row.id}`}
        data-status={row.status}
        style={{ ...badgeStyle, backgroundColor: colors.bg, color: colors.fg }}
      >
        {statusLabel(row.status ?? '')}
      </span>
    )
  }

  const renderDesktopTable = () => (
    <div data-testid="notifications-table-wrapper" style={tableWrapperStyles}>
      <table data-testid="notifications-table" style={tableElementStyles}>
        <thead>
          <tr style={headerRowStyle}>
            <th style={{ ...headerCellBaseStyle, width: '170px' }}>
              <SortableTableHeader
                label={t('notifications.queuedAt')}
                sortKey="queued_at"
                currentSort={{ key: list.sortKey, direction: list.sortDirection }}
                onSort={() => list.toggleSort('queued_at', 'desc')}
              />
            </th>
            <th style={headerCellBaseStyle}>
              <SortableTableHeader
                label={t('notifications.recipient')}
                sortKey="recipient"
                currentSort={{ key: list.sortKey, direction: list.sortDirection }}
                onSort={() => list.toggleSort('recipient', 'asc')}
              />
            </th>
            <th style={{ ...headerCellBaseStyle, width: '190px' }}>
              <SortableTableHeader
                label={t('notifications.kind')}
                sortKey="kind"
                currentSort={{ key: list.sortKey, direction: list.sortDirection }}
                onSort={() => list.toggleSort('kind', 'asc')}
              />
            </th>
            <th style={{ ...headerCellBaseStyle, width: '130px' }}>
              <SortableTableHeader
                label={t('notifications.status')}
                sortKey="status"
                currentSort={{ key: list.sortKey, direction: list.sortDirection }}
                onSort={() => list.toggleSort('status', 'asc')}
              />
            </th>
            <th style={headerCellBaseStyle}>{t('notifications.detail')}</th>
            <th style={{ ...headerCellBaseStyle, width: '120px', textAlign: 'right' }}>
              {t('notifications.action')}
            </th>
          </tr>
        </thead>
        <tbody>
          {items.map((row) => (
            <tr key={row.id} data-testid={`notifications-row-${row.id}`} style={getRowStyle(true)}>
              <td style={cellStyle}>{formatDateTime(row.queued_at ?? '')}</td>
              <td style={cellStyle}>{renderRecipient(row)}</td>
              <td style={cellStyle}>{kindLabel(row.kind ?? '')}</td>
              <td style={cellStyle}>{renderStatusBadge(row)}</td>
              <td style={cellStyle}>{renderDetail(row)}</td>
              <td style={{ ...cellStyle, textAlign: 'right' }}>{renderRetryButton(row)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )

  /**
   * The right-hand column: whichever fact matters for this row's status.
   *
   * A failure shows the server's words verbatim — "authentication failed",
   * "mailbox full" and "relay access denied" point at three different fixes,
   * and a friendly summary would erase the difference.
   */
  const renderDetail = (row: QueuedMail) => {
    if (row.status === 'failed') {
      return (
        <span data-testid={`notifications-error-${row.id}`} style={errorTextStyle}>
          {row.last_error ?? t('notifications.unknownError')}
        </span>
      )
    }
    if (row.status === 'sent') {
      return (
        <span data-testid={`notifications-sent-at-${row.id}`} style={mutedStyle}>
          {formatDateTime(row.sent_at ?? '')}
        </span>
      )
    }
    if (row.status === 'pending') {
      return (
        <span data-testid={`notifications-pending-${row.id}`} style={mutedStyle}>
          {t('notifications.waitingForScheduler')}
        </span>
      )
    }
    return <span style={mutedStyle}>{'—'}</span>
  }

  const renderMobileCards = () => (
    <div data-testid="notifications-cards" style={{ display: 'flex', flexDirection: 'column', gap: theme.spacing.sm }}>
      {items.map((row) => (
        <div key={row.id} data-testid={`notifications-card-${row.id}`} style={cardStyle}>
          {/* minWidth: 0 lets a long recipient address wrap instead of
              refusing to shrink (the flex default) and pushing the status
              badge off the card. */}
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: theme.spacing.sm }}>
            <div style={{ minWidth: 0, flex: 1, wordBreak: 'break-word' }}>{renderRecipient(row)}</div>
            <div style={{ flexShrink: 0 }}>{renderStatusBadge(row)}</div>
          </div>
          <div style={mutedStyle}>
            {kindLabel(row.kind ?? '')} &middot; {formatDateTime(row.queued_at ?? '')}
          </div>
          <div>{renderDetail(row)}</div>
          {renderRetryButton(row)}
        </div>
      ))}
    </div>
  )

  return (
    <div data-testid="notifications-page">
      <PageHeader title={t('notifications.title')} subtitle={t('notifications.subtitle')} />

      {error && (
        <div data-testid="notifications-error" style={bannerStyle(theme.colors.semantic.danger)}>
          {error}
        </div>
      )}

      {notice && (
        <div data-testid="notifications-notice" style={bannerStyle(theme.colors.semantic.primary)}>
          {notice}
        </div>
      )}

      {renderFilters(isMobile)}

      {!hasLoaded && loading && (
        <div data-testid="notifications-loading" style={messageStyle}>
          {t('common.loading')}
        </div>
      )}

      {hasLoaded && items.length === 0 && (
        <div data-testid="notifications-empty" style={messageStyle}>
          {t('notifications.empty')}
        </div>
      )}

      {items.length > 0 && (isMobile ? renderMobileCards() : renderDesktopTable())}

      {items.length > 0 && (
        <PaginationToolbar
          currentPage={list.page}
          totalPages={totalPages}
          totalItems={total}
          pageSize={list.pageSize}
          onPageChange={list.setPage}
          onPageSizeChange={list.setPageSize}
          testId="notifications-pagination"
        />
      )}
    </div>
  )
}

/**
 * The kinds and statuses the filters offer.
 *
 * The response also carries them (`filters.kinds` / `filters.statuses`) from
 * the server that enforces them; these are the labels' anchor and the order
 * they are shown in. The two agreeing is what the API test asserts.
 */
const KINDS = [
  'sepa_prenotification',
  'cancellation_notice',
  'key_expiry_warning',
  'terminal_token_expiry_warning',
  'terminal_anomaly_warning',
  'admin_email_changed',
  'deckel_statement',
]

const STATUSES = ['pending', 'sent', 'failed', 'superseded']

const cellStyle: CSSProperties = { padding: '12px 16px', verticalAlign: 'top' }

const mutedStyle: CSSProperties = {
  color: theme.colors.text.muted,
  fontSize: theme.typography.fontSize.xs,
}

const errorTextStyle: CSSProperties = {
  color: theme.colors.semantic.danger,
  fontSize: theme.typography.fontSize.xs,
  fontFamily: 'JetBrains Mono, monospace',
  wordBreak: 'break-word',
}

const badgeStyle: CSSProperties = {
  padding: '4px 8px',
  borderRadius: 4,
  fontSize: 12,
  fontWeight: 500,
  display: 'inline-block',
  whiteSpace: 'nowrap',
}

const retryButtonStyle: CSSProperties = {
  padding: `${theme.spacing.xs} ${theme.spacing.md}`,
  background: 'transparent',
  border: `1px solid ${theme.colors.semantic.primary}`,
  borderRadius: theme.borderRadius.md,
  color: theme.colors.semantic.primary,
  fontSize: theme.typography.fontSize.xs,
  cursor: 'pointer',
}

const messageStyle: CSSProperties = {
  padding: theme.spacing.xl,
  color: theme.colors.text.secondary,
  textAlign: 'center',
}

const cardStyle: CSSProperties = {
  display: 'flex',
  flexDirection: 'column',
  gap: theme.spacing.xs,
  padding: theme.spacing.md,
  background: theme.colors.bg.card,
  border: `1px solid ${theme.colors.border.light}`,
  borderRadius: theme.borderRadius.md,
}

const bannerStyle = (color: string): CSSProperties => ({
  padding: theme.spacing.md,
  marginBottom: theme.spacing.md,
  borderRadius: theme.borderRadius.md,
  border: `1px solid ${color}`,
  color,
  fontSize: theme.typography.fontSize.sm,
})

const labelStyle: CSSProperties = {
  display: 'block',
  fontSize: theme.typography.fontSize.xs,
  color: theme.colors.text.secondary,
  marginBottom: theme.spacing.xs,
}

const selectStyle: CSSProperties = {
  width: '100%',
  padding: `${theme.spacing.sm} 28px ${theme.spacing.sm} ${theme.spacing.md}`,
  background: theme.colors.bg.input,
  border: `1px solid ${theme.colors.border.light}`,
  borderRadius: theme.borderRadius.md,
  color: theme.colors.text.primary,
  fontSize: theme.typography.fontSize.sm,
  cursor: 'pointer',
  outline: 'none',
  appearance: 'none',
  WebkitAppearance: 'none',
  backgroundImage: `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E")`,
  backgroundRepeat: 'no-repeat',
  backgroundPosition: 'right 8px center',
  backgroundSize: '12px',
  boxSizing: 'border-box',
}

const inputStyle: CSSProperties = {
  width: '100%',
  padding: `${theme.spacing.sm} ${theme.spacing.md}`,
  background: theme.colors.bg.input,
  border: `1px solid ${theme.colors.border.light}`,
  borderRadius: theme.borderRadius.md,
  color: theme.colors.text.primary,
  fontSize: theme.typography.fontSize.sm,
  boxSizing: 'border-box',
}
