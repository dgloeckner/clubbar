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
 *
 * ## Two layouts, one queue
 *
 * Below 768px the six-column table becomes one card per registration, the way
 * Backups, Members and the settings tabs already narrow (#849). The table did
 * not merely look cramped on a phone: the header ran off the right edge, and
 * the empty state — the state this inbox is in most days — was laid out inside
 * a `colSpan` cell as wide as those six columns, so the sentence explaining
 * where registrations come from was clipped mid-word and the send-link button
 * under it sat past the viewport. Sorting moves into `MobileToolbar` with the
 * search box, because the sort controls live in the header cells the narrow
 * layout drops.
 */

import { useCallback, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'

import { getRegistrationReview } from '../api/generated/registration-review/registration-review'
import type { PendingRegistration } from '../api/generated/pendingRegistration'
import { PageActionButton } from '../components/common/PageActionButton'
import { MobileToolbar } from '../components/layout/MobileToolbar'
import { PageHeader } from '../components/layout/PageHeader'
import { PaginationToolbar } from '../components/tables/PaginationToolbar'
import { SortableTableHeader } from '../components/tables/SortableTableHeader'
import { RegistrationReviewPanel } from '../components/registrations/RegistrationReviewPanel'
import { SendRegistrationLinkModal } from '../components/registrations/SendRegistrationLinkModal'
import { useApiError } from '../hooks/useApiError'
import { useBreakpoint } from '../hooks/useBreakpoint'
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

  // The same threshold the rest of the panel narrows at, so a phone never gets
  // cards on one page and a six-column table on the next.
  const breakpoint = useBreakpoint()
  const isNarrow = breakpoint === 'mobile' || breakpoint === 'smallMobile'

  const [selected, setSelected] = useState<PendingRegistration | null>(null)
  /**
   * The one outbound verb on this inbox (#821, UC-A70). It is page state rather
   * than route state because the send is a modal over the queue: an admin who
   * sends a link is still looking at the same list afterwards, and nothing in
   * it changes — the person they wrote to has no row here until they fill in
   * the form.
   */
  const [sendingLink, setSendingLink] = useState(false)

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

  const isEmpty = list.hasLoaded && list.items.length === 0

  /**
   * The narrow layout's sort control. The three sortable columns are the same
   * ones the desktop header carries; each direction is a separate entry because
   * a dropdown cannot express "click again to flip".
   */
  const mobileSortOptions = [
    { value: 'submitted_at_desc', label: t('registrations.sort.newest'), direction: 'desc' as const },
    { value: 'submitted_at_asc', label: t('registrations.sort.oldest'), direction: 'asc' as const },
    { value: 'last_name_asc', label: t('registrations.sort.name'), direction: 'asc' as const },
    { value: 'email_asc', label: t('registrations.sort.email'), direction: 'asc' as const },
  ]

  /**
   * The two duplicate warnings, shared by both layouts so a phone cannot
   * quietly lose the one thing an admin must not approve on autopilot.
   */
  const flags = (registration: PendingRegistration) => (
    <>
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
    </>
  )

  /**
   * The empty state, in both layouts. Not "no results": an empty queue is the
   * normal state, and the useful thing to say is where the full one comes from.
   *
   * The button under it is not the two-surfaces duplication that was rejected
   * in design — that was one action on two screens with different role sets.
   * This is a primary control plus a contextual prompt on one page, and the
   * empty state is precisely where a Kassenwart is looking when they would want
   * it. It is also the feature's only discovery point: an admin who never
   * noticed the header button meets it at the moment they are wondering why
   * nothing has arrived.
   */
  const emptyState = (
    <>
      <p style={{ margin: 0, fontWeight: 600 }}>{t('registrations.empty.title')}</p>
      <p style={{ margin: `${theme.spacing.sm} 0 0`, color: theme.colors.text.secondary }}>
        {t('registrations.empty.body')}
      </p>
      <div style={{ marginTop: theme.spacing.lg }}>
        <PageActionButton
          variant="secondary"
          data-testid="registrations-empty-send-link-button"
          onClick={() => setSendingLink(true)}
        >
          {t('registrations.sendLink.action')}
        </PageActionButton>
      </div>
    </>
  )

  return (
    <div data-testid="registrations-page">
      {/* The send control lives on this page and not in Settings beside the
          poster, which is where it conceptually belongs: that tab is ADMIN_ONLY
          and holding one button there would lock out the Kassenwart, whose
          queue this is. Splitting the tab's role set for one control is exactly
          the drift ADR-0044's default-deny exists to prevent (#821 decision
          5a). The cost is real and accepted — an outbound verb on an inbox. */}
      <PageHeader
        title={t('registrations.title')}
        subtitle={t('registrations.subtitle')}
        actions={
          <PageActionButton
            data-testid="registrations-send-link-button"
            onClick={() => setSendingLink(true)}
          >
            {t('registrations.sendLink.action')}
          </PageActionButton>
        }
      />

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

      {isNarrow ? (
        <>
          {/* Search and sort in one bar. The search box keeps its test id, so
              a spec that types into the queue does not care which layout it
              got. */}
          <MobileToolbar
            testId="registrations-mobile-toolbar"
            search={{
              value: list.search,
              onChange: list.setSearch,
              placeholder: t('registrations.searchPlaceholder'),
              testId: 'registrations-search',
            }}
            sort={{
              options: mobileSortOptions,
              value: list.sortValue,
              onChange: list.setSortValue,
              testId: 'registrations-mobile-sort',
            }}
          />

          <div data-testid="registrations-cards" style={cardListStyle}>
            {!list.hasLoaded && (
              <div style={messageCardStyle} data-testid="registrations-loading">
                {t('common.loading')}
              </div>
            )}

            {isEmpty && (
              <div style={{ ...messageCardStyle, textAlign: 'center' }} data-testid="registrations-empty">
                {emptyState}
              </div>
            )}

            {list.items.map((registration) => (
              // The whole card opens the panel, the way the whole desktop row
              // does — a phone's tap target is the card, not a word in it. The
              // name stays a real <button> beside that, because a click
              // handler on a div is reachable by finger and by mouse and by
              // nothing else. It is not the card itself for the reason a
              // <button> cannot be: its content is a heading row, a
              // description list and a badge row, none of which is phrasing
              // content.
              <div
                key={registration.id}
                data-testid={`registration-row-${registration.id}`}
                onClick={() => setSelected(registration)}
                style={cardStyle}
              >
                <div style={cardTitleRowStyle}>
                  <button
                    type="button"
                    data-testid={`registration-open-${registration.id}`}
                    onClick={(event) => {
                      event.stopPropagation()
                      setSelected(registration)
                    }}
                    // minWidth: 0 lets a long, unbreakable name shrink and
                    // ellipsize instead of pushing the date off the card —
                    // flex items default to min-width: auto, which refuses to
                    // shrink below the text's intrinsic width.
                    style={cardNameButtonStyle}
                  >
                    {registration.first_name} {registration.last_name}
                  </button>
                  <span style={cardDateStyle}>
                    {registration.submitted_at ? formatDate(registration.submitted_at) : '—'}
                  </span>
                </div>

                {/* An address is one unbreakable token more often than not,
                    so it wraps mid-token rather than widening the card. */}
                <div style={cardEmailStyle}>{registration.email}</div>

                <dl style={fieldListStyle}>
                  <div style={fieldRowStyle}>
                    <dt style={fieldLabelStyle}>{t('registrations.columns.iban')}</dt>
                    <dd style={{ ...fieldValueStyle, fontVariantNumeric: 'tabular-nums' }}>
                      {registration.iban_masked}
                    </dd>
                  </div>
                  <div style={fieldRowStyle}>
                    <dt style={fieldLabelStyle}>{t('registrations.columns.bank')}</dt>
                    <dd style={fieldValueStyle}>{registration.bank_name ?? '—'}</dd>
                  </div>
                </dl>

                {(registration.duplicate_email || registration.duplicate_iban) && (
                  <div style={badgeRowStyle}>{flags(registration)}</div>
                )}
              </div>
            ))}
          </div>
        </>
      ) : (
        <>
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
                {/* Each `SortableTableHeader` renders a bare <button>, so every one
                    of them needs a <th> of its own — the shape the other five list
                    pages use. Without it CSS table fixup collects the three
                    consecutive non-cell children into a *single* anonymous cell,
                    and since the button is `display: flex` they stack vertically
                    inside it: a header row with four cells against the body's six
                    columns. It was misaligned with data too; an empty table merely
                    removed the row widths that were disguising it (#821). */}
                <tr>
                  <th style={headerStyle}>
                    <SortableTableHeader
                      label={t('registrations.columns.name')}
                      sortKey="last_name"
                      currentSort={{ key: list.sortKey, direction: list.sortDirection }}
                      onSort={(key, direction) => list.setSort(key as RegistrationSortKey, direction)}
                      testId="sort-last-name"
                    />
                  </th>
                  <th style={headerStyle}>
                    <SortableTableHeader
                      label={t('registrations.columns.email')}
                      sortKey="email"
                      currentSort={{ key: list.sortKey, direction: list.sortDirection }}
                      onSort={(key, direction) => list.setSort(key as RegistrationSortKey, direction)}
                      testId="sort-email"
                    />
                  </th>
                  <th style={headerStyle}>
                    <SortableTableHeader
                      label={t('registrations.columns.submittedAt')}
                      sortKey="submitted_at"
                      currentSort={{ key: list.sortKey, direction: list.sortDirection }}
                      onSort={(key, direction) => list.setSort(key as RegistrationSortKey, direction)}
                      testId="sort-submitted-at"
                    />
                  </th>
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

                {isEmpty && (
                  <tr>
                    <td colSpan={columns} style={emptyCellStyle} data-testid="registrations-empty">
                      {emptyState}
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
                    <td style={cellStyle}>{flags(registration)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}

      {/* Nothing to page through is not a page. The toolbar used to render for
          an empty queue too, which read "showing 1-0 of 0" beside a next and a
          last button that were enabled — `currentPage === totalPages` is false
          when `totalPages` is 0 — so the emptiest screen in the panel offered
          two controls that went nowhere. */}
      {list.total > 0 && (
        <PaginationToolbar
          currentPage={list.page}
          totalPages={list.totalPages}
          totalItems={list.total}
          pageSize={list.pageSize}
          onPageChange={list.setPage}
          onPageSizeChange={list.setPageSize}
          // A page-size select next to five page buttons does not fit a phone,
          // and it is not what somebody emptying a queue on one reaches for.
          showPageSize={!isNarrow}
          testId="registrations-pagination"
        />
      )}

      <SendRegistrationLinkModal isOpen={sendingLink} onClose={() => setSendingLink(false)} />

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

/* ------------------------------------------------------------------ *
 * Narrow layout: one card per registration.                           *
 * ------------------------------------------------------------------ */

const cardListStyle: React.CSSProperties = {
  display: 'flex',
  flexDirection: 'column',
  gap: theme.spacing.sm,
}

const cardStyle: React.CSSProperties = {
  display: 'flex',
  flexDirection: 'column',
  gap: theme.spacing.sm,
  background: theme.mobileCard.bg,
  border: `1px solid ${theme.mobileCard.border}`,
  borderRadius: theme.borderRadius.md,
  padding: '14px 16px',
  cursor: 'pointer',
}

/**
 * Loading and the empty queue: the card's shape, laid out by the prose inside
 * it. `display: block` rather than the card's column flex, because the empty
 * state's own margins already space its two paragraphs and a button.
 */
const messageCardStyle: React.CSSProperties = {
  ...cardStyle,
  display: 'block',
  padding: theme.spacing.xl,
  cursor: 'default',
  color: theme.colors.text.primary,
}

const cardTitleRowStyle: React.CSSProperties = {
  display: 'flex',
  alignItems: 'baseline',
  justifyContent: 'space-between',
  gap: theme.spacing.md,
}

const cardNameButtonStyle: React.CSSProperties = {
  flex: 1,
  minWidth: 0,
  overflow: 'hidden',
  textOverflow: 'ellipsis',
  whiteSpace: 'nowrap',
  background: 'none',
  border: 'none',
  padding: 0,
  font: 'inherit',
  fontWeight: theme.typography.fontWeight.semibold,
  color: theme.colors.text.primary,
  textAlign: 'left',
  cursor: 'pointer',
}

const cardDateStyle: React.CSSProperties = {
  flexShrink: 0,
  whiteSpace: 'nowrap',
  fontSize: theme.typography.fontSize.sm,
  color: theme.colors.text.secondary,
}

const cardEmailStyle: React.CSSProperties = {
  fontSize: theme.typography.fontSize.sm,
  color: theme.colors.text.secondary,
  overflowWrap: 'anywhere',
}

const fieldListStyle: React.CSSProperties = {
  display: 'flex',
  flexDirection: 'column',
  gap: theme.spacing.xs,
  margin: 0,
}

// Label and value share a line: both values are short — a masked IBAN and a
// bank name — so stacking them would double the card's height for nothing.
const fieldRowStyle: React.CSSProperties = {
  display: 'flex',
  alignItems: 'baseline',
  justifyContent: 'space-between',
  gap: theme.spacing.md,
}

const fieldLabelStyle: React.CSSProperties = {
  flexShrink: 0,
  fontSize: theme.typography.fontSize.xs,
  fontWeight: theme.typography.fontWeight.semibold,
  textTransform: 'uppercase',
  letterSpacing: '0.04em',
  color: theme.colors.text.label,
}

const fieldValueStyle: React.CSSProperties = {
  margin: 0,
  minWidth: 0,
  fontSize: theme.typography.fontSize.sm,
  color: theme.colors.text.primary,
  textAlign: 'right',
  overflowWrap: 'anywhere',
}

const badgeRowStyle: React.CSSProperties = {
  display: 'flex',
  flexWrap: 'wrap',
  gap: theme.spacing.xs,
}
