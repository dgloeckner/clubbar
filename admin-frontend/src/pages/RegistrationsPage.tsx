/**
 * The self-registration inbox (#782, UC-A17, ADR-0052).
 *
 * A queue a treasurer empties, not a table they browse — which is why it is
 * newest-first, why the row opens straight into the review panel, and why the
 * empty state explains the QR flow rather than saying "no results".
 *
 * ## The full IBAN is not here, and cannot be
 *
 * Every row and the detail panel carry `iban_masked` only. That is not a
 * display choice this page makes: the server never sends anything else, because
 * the number was sealed at submission under a key it does not hold (ADR-0036).
 * The fingerprint is withheld too — it is a stable identifier for a bank
 * account, so the duplicate check that uses it runs server-side and only its
 * boolean answer travels.
 */

import { useCallback, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'

import { getRegistrationReview } from '../api/generated/registration-review/registration-review'
import type { PendingRegistration } from '../api/generated/pendingRegistration'
import { PageHeader } from '../components/layout/PageHeader'
import { PaginationToolbar } from '../components/tables/PaginationToolbar'
import { SortableTableHeader } from '../components/tables/SortableTableHeader'
import { RegistrationReviewPanel } from '../components/registrations/RegistrationReviewPanel'
import { useApiError } from '../hooks/useApiError'
import { useFormatters } from '../hooks/useFormatters'
import { useListQuery } from '../hooks/useListQuery'
import { theme } from '../styles/design-system'

type RegistrationSortKey = 'submitted_at' | 'last_name' | 'email' | 'expires_at'

/** No filters: a queue with four states to slice by is a queue nobody empties. */
type RegistrationFilters = Record<never, never>

export function RegistrationsPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const { apiErrorMessage } = useApiError()
  const { formatDate } = useFormatters()

  const [selected, setSelected] = useState<PendingRegistration | null>(null)

  const list = useListQuery<PendingRegistration, RegistrationFilters, RegistrationSortKey>({
    // `useListQuery` owns page, page size, sort, search, the debounce, request
    // aborting and the post-mutation page clamp. Hand-rolling any of it here is
    // what `table-implementation.md` exists to prevent.
    fetcher: async ({ page, pageSize, sortKey, sortDirection, search, signal }) => {
      const response = await getRegistrationReview().listRegistrations(
        {
          page,
          per_page: pageSize,
          sort: sortKey,
          order: sortDirection,
          ...(search ? { search } : {}),
        },
        { signal }
      )

      return { items: response.data ?? [], total: response.pagination?.total ?? 0 }
    },
    initialFilters: {},
    initialSortKey: 'submitted_at',
    initialSortDirection: 'desc',
    parseError: (error) => apiErrorMessage(error, t('registrations.errors.load')),
  })

  /**
   * After approve, reject or an edit.
   *
   * Reloading before closing the panel, not after: the row is gone from the
   * server the moment an approval lands, and a panel still showing it while the
   * list refreshes underneath invites a second click on something that no
   * longer exists.
   */
  const afterAction = useCallback(
    async (email?: string) => {
      setSelected(null)
      await list.reload()

      // The approval's whole point is a member, so land on them rather than on
      // the emptier queue. There is no member *detail* route in this app —
      // members are a list plus a modal — so the closest true thing is the
      // roster, searched down to the one just created.
      if (email) navigate(`/members?search=${encodeURIComponent(email)}`)
    },
    [list, navigate]
  )

  const columns = 6

  return (
    <div data-testid="registrations-page">
      <PageHeader title={t('registrations.title')} subtitle={t('registrations.subtitle')} />

      {list.error && (
        <div
          data-testid="registrations-error"
          role="alert"
          style={{
            background: theme.badges.danger.bg,
            border: `1px solid ${theme.badges.danger.border}`,
            color: theme.badges.danger.text,
            borderRadius: theme.borderRadius.md,
            padding: theme.spacing.md,
            marginBottom: theme.spacing.lg,
          }}
        >
          {list.error}
        </div>
      )}

      <div style={{ marginBottom: theme.spacing.md }}>
        <input
          type="search"
          data-testid="registrations-search"
          value={list.search}
          onChange={(event) => list.setSearch(event.target.value)}
          placeholder={t('registrations.searchPlaceholder')}
          aria-label={t('registrations.searchPlaceholder')}
          style={{
            width: '100%',
            maxWidth: 380,
            minHeight: 44,
            padding: `0 ${theme.spacing.md}`,
            borderRadius: theme.borderRadius.md,
            border: `1px solid ${theme.colors.border.light}`,
            background: theme.colors.bg.secondary,
            color: theme.colors.text.primary,
          }}
        />
      </div>

      <div style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse' }} data-testid="registrations-table">
          <thead>
            <tr>
              <SortableTableHeader
                label={t('registrations.columns.name')}
                sortKey="last_name"
                currentSort={{ key: list.sortKey, direction: list.sortDirection }}
                onSort={(key, direction) => list.setSort(key as RegistrationSortKey, direction)}
                testId="sort-last-name"
              />
              <SortableTableHeader
                label={t('registrations.columns.email')}
                sortKey="email"
                currentSort={{ key: list.sortKey, direction: list.sortDirection }}
                onSort={(key, direction) => list.setSort(key as RegistrationSortKey, direction)}
                testId="sort-email"
              />
              <SortableTableHeader
                label={t('registrations.columns.submittedAt')}
                sortKey="submitted_at"
                currentSort={{ key: list.sortKey, direction: list.sortDirection }}
                onSort={(key, direction) => list.setSort(key as RegistrationSortKey, direction)}
                testId="sort-submitted-at"
              />
              <th style={headerStyle}>{t('registrations.columns.iban')}</th>
              <th style={headerStyle}>{t('registrations.columns.bank')}</th>
              <th style={headerStyle}>{t('registrations.columns.flags')}</th>
            </tr>
          </thead>

          <tbody>
            {!list.hasLoaded && (
              <tr>
                <td colSpan={columns} style={emptyCellStyle} data-testid="registrations-loading">
                  {t('common.loading')}
                </td>
              </tr>
            )}

            {list.hasLoaded && list.items.length === 0 && (
              <tr>
                <td colSpan={columns} style={emptyCellStyle} data-testid="registrations-empty">
                  {/* Not "no results": an empty queue is the normal state, and
                      the useful thing to say is where the full one comes from. */}
                  <p style={{ margin: 0, fontWeight: 600 }}>{t('registrations.empty.title')}</p>
                  <p style={{ margin: `${theme.spacing.sm} 0 0`, color: theme.colors.text.secondary }}>
                    {t('registrations.empty.body')}
                  </p>
                </td>
              </tr>
            )}

            {list.items.map((registration) => (
              <tr
                key={registration.id}
                data-testid={`registration-row-${registration.id}`}
                onClick={() => setSelected(registration)}
                style={{ cursor: 'pointer', borderTop: `1px solid ${theme.colors.border.light}` }}
              >
                <td style={cellStyle}>
                  <button
                    type="button"
                    data-testid={`registration-open-${registration.id}`}
                    onClick={(event) => {
                      event.stopPropagation()
                      setSelected(registration)
                    }}
                    style={{
                      background: 'none',
                      border: 'none',
                      padding: 0,
                      minHeight: 44,
                      color: theme.colors.text.primary,
                      font: 'inherit',
                      fontWeight: 600,
                      cursor: 'pointer',
                      textAlign: 'left',
                    }}
                  >
                    {registration.first_name} {registration.last_name}
                  </button>
                </td>
                <td style={cellStyle}>{registration.email}</td>
                <td style={cellStyle}>{registration.submitted_at ? formatDate(registration.submitted_at) : '—'}</td>
                <td style={{ ...cellStyle, fontVariantNumeric: 'tabular-nums' }}>
                  {registration.iban_masked}
                </td>
                <td style={cellStyle}>{registration.bank_name ?? '—'}</td>
                <td style={cellStyle}>
                  {/* The one thing an admin must not approve on autopilot. */}
                  {registration.duplicate_email && (
                    <span data-testid={`duplicate-email-${registration.id}`} style={flagStyle}>
                      {t('registrations.flags.email')}
                    </span>
                  )}
                  {registration.duplicate_iban && (
                    <span data-testid={`duplicate-iban-${registration.id}`} style={flagStyle}>
                      {t('registrations.flags.iban')}
                    </span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <PaginationToolbar
        currentPage={list.page}
        totalPages={list.totalPages}
        totalItems={list.total}
        pageSize={list.pageSize}
        onPageChange={list.setPage}
        onPageSizeChange={list.setPageSize}
        testId="registrations-pagination"
      />

      {selected && (
        <RegistrationReviewPanel
          registration={selected}
          onClose={() => setSelected(null)}
          onDone={afterAction}
          onError={list.setError}
        />
      )}
    </div>
  )
}

const headerStyle: React.CSSProperties = {
  textAlign: 'left',
  padding: theme.spacing.sm,
  color: theme.colors.text.secondary,
  fontSize: theme.typography.fontSize.sm,
  fontWeight: 600,
}

const cellStyle: React.CSSProperties = {
  padding: theme.spacing.sm,
  color: theme.colors.text.primary,
}

const emptyCellStyle: React.CSSProperties = {
  padding: theme.spacing.xl,
  textAlign: 'center',
  color: theme.colors.text.primary,
}

const flagStyle: React.CSSProperties = {
  display: 'inline-block',
  marginRight: 6,
  padding: '2px 8px',
  borderRadius: 10,
  background: theme.badges.warning.bg,
  border: `1px solid ${theme.badges.warning.border}`,
  color: theme.badges.warning.text,
  fontSize: theme.typography.fontSize.xs,
  fontWeight: 600,
}
