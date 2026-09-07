/**
 * Backups (#693, ADR-0049)
 *
 * What this installation holds, which keys open it, and a way to fetch one.
 *
 * ## Local first, remote as enrichment
 *
 * Two fetches, deliberately, and they are not raced or combined. The archive
 * table comes from `GET /admin/backups` — a directory scan — and renders as soon
 * as it lands. Whether each archive is *also* off-site comes from
 * `GET /admin/backups/remote`, which may call the storage provider, and arrives
 * whenever it arrives.
 *
 * So a throttled tenant costs a club one column that says "checking", never the
 * list of what they have. Combining the two would give the slowest participant
 * a veto over the page.
 *
 * ## Read-only, on purpose
 *
 * No delete, no retry-upload, no key lifecycle. #703 removed the application's
 * key register along with the tracking tables: custody belongs in the club's own
 * register, on paper, where a restore cannot rewrite it. This page is the
 * checklist to walk that register against, and a button here that retired a key
 * would be the application quietly becoming the register again.
 *
 * ## Cards below the tablet breakpoint (#849)
 *
 * Both listings carry the two widest strings in the admin panel — an archive
 * filename with a timestamp and a hash in it, and a 64-character fingerprint —
 * and neither fits a phone-width table column. Five columns at 390px did not
 * merely look cramped: the headers ran together into one unreadable word and
 * the download button was clipped off the right edge, so the page's only action
 * could not be reached at all.
 *
 * So below 768px each row becomes a card: the identifier on its own line where
 * it has the full width to wrap into, the rest as labelled lines under it, and
 * the download button full-width at the bottom. The test ids are the row's,
 * unchanged, so a card and a row are the same thing to a test.
 */

import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { PageHeader } from '../components/layout/PageHeader'
import { theme, formatDateTime } from '../styles/design-system'
import { getBackups } from '../api/generated/backups/backups'
import type { BackupInventory, BackupRemoteState } from '../api/generated'
import { downloadFile } from '../api/client'
import { useLatestRequest } from '../hooks/useLatestRequest'
import { useBreakpoint } from '../hooks/useBreakpoint'
import {
  tableWrapperStyles,
  tableElementStyles,
  headerRowStyle,
  headerCellBaseStyle,
  getRowStyle,
} from '../styles/tableTokens'
import { DownloadIcon } from '../components/icons'

/** Bytes as a club would say them. */
function humanBytes(bytes: number): string {
  if (bytes >= 1_073_741_824) return `${(bytes / 1_073_741_824).toFixed(1)} GB`
  if (bytes >= 1_048_576) return `${Math.round(bytes / 1_048_576)} MB`
  if (bytes >= 1024) return `${Math.round(bytes / 1024)} KB`
  return `${bytes} B`
}

/**
 * The creation dates of the oldest and newest archive a key opens — the span a
 * withdrawn key's private half still has to be kept for.
 *
 * A key named by exactly one archive gets that one timestamp. Printing it twice
 * either side of a dash reads as a validity window of zero length, which is the
 * opposite of what one archive means.
 */
function archiveRange(firstSeen?: string | null, lastSeen?: string | null): string {
  if (!firstSeen || !lastSeen) return '—'
  if (firstSeen === lastSeen) return formatDateTime(firstSeen)
  return `${formatDateTime(firstSeen)} – ${formatDateTime(lastSeen)}`
}

export function BackupsPage() {
  const { t } = useTranslation()

  const [inventory, setInventory] = useState<BackupInventory | null>(null)
  const [inventoryError, setInventoryError] = useState(false)
  const [remote, setRemote] = useState<BackupRemoteState | null>(null)
  const [remoteSettled, setRemoteSettled] = useState(false)
  const [downloading, setDownloading] = useState<string | null>(null)

  // One slot per independent stream, per the data-fetching pattern. They are
  // separate because they finish at different times and neither may cancel the
  // other — that separation is the whole point of the page.
  const localRequest = useLatestRequest()
  const remoteRequest = useLatestRequest()

  useEffect(() => {
    const signal = localRequest.next()
    getBackups()
      .listBackups({ signal })
      .then((result) => {
        if (signal.aborted) return
        setInventory(result)
      })
      .catch(() => {
        if (signal.aborted) return
        setInventoryError(true)
      })
    return () => localRequest.abort()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    const signal = remoteRequest.next()
    getBackups()
      .getBackupRemote({ signal })
      .then((result) => {
        if (signal.aborted) return
        setRemote(result)
      })
      .catch(() => {
        // A store that cannot be reached is not a page error. The column says
        // so and the rest of the page is unaffected — which is the reason this
        // is a second request rather than part of the first.
      })
      .finally(() => {
        if (signal.aborted) return
        setRemoteSettled(true)
      })
    return () => remoteRequest.abort()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function download(name: string) {
    setDownloading(name)
    try {
      // Through the API client, never an <a download>: the session cookie and
      // the Content-Disposition the server sends are both needed.
      await downloadFile(`/admin/backups/${encodeURIComponent(name)}`, name)
    } finally {
      setDownloading(null)
    }
  }

  /** Three-valued on purpose: unknown is not the same as absent. */
  function offsite(name: string): 'yes' | 'no' | 'unknown' {
    if (!remote || remote.source === 'unavailable') return 'unknown'
    return (remote.names ?? []).includes(name) ? 'yes' : 'no'
  }

  const archives = inventory?.archives ?? []
  const keys = inventory?.keys ?? []

  // The same threshold the rest of the panel narrows at, so a phone never gets
  // cards on one page and a five-column table on the next.
  const breakpoint = useBreakpoint()
  const isNarrow = breakpoint === 'mobile' || breakpoint === 'smallMobile'

  /**
   * The badges an archive carries, shared by both layouts so a phone cannot
   * quietly lose the one that says a file will not open.
   */
  const archiveBadges = (archive: (typeof archives)[number], inline: boolean) => {
    // In the table the badges follow the filename on the same line and need
    // separating from it; in a card they open a line of their own.
    const warn = inline ? warnBadgeStyle : { ...warnBadgeStyle, marginLeft: 0 }
    const info = inline ? infoBadgeStyle : { ...infoBadgeStyle, marginLeft: 0 }
    return (
      <>
        {archive.readable === false && (
          <span style={warn} data-testid="backups-archive-unreadable">
            {t('backups.archives.unreadable')}
          </span>
        )}
        {archive.config_included && <span style={info}>{t('backups.archives.withConfig')}</span>}
      </>
    )
  }

  const downloadButton = (archive: (typeof archives)[number], fullWidth: boolean) => (
    <button
      type="button"
      onClick={() => download(archive.name ?? '')}
      disabled={downloading === archive.name}
      style={
        fullWidth
          ? // Full width and 44px tall: the touch target the panel uses
            // everywhere below 768px, and this is the page's only action.
            { ...downloadButtonStyle, width: '100%', justifyContent: 'center', minHeight: '44px' }
          : downloadButtonStyle
      }
      data-testid="backups-archive-download"
    >
      <DownloadIcon size={16} />
      {t('backups.archives.download')}
    </button>
  )

  return (
    <div data-testid="backups-page">
      <PageHeader title={t('backups.title')} subtitle={t('backups.subtitle')} />

      {inventoryError && (
        <div data-testid="backups-error" style={errorStyle}>
          {t('backups.loadFailed')}
        </div>
      )}

      <section style={{ marginBottom: theme.spacing.xl }}>
        <h2 style={sectionHeadingStyle}>{t('backups.archives.heading')}</h2>
        <p style={noteStyle} data-testid="backups-remote-note">
          {!remoteSettled
            ? t('backups.remote.checking')
            : remote?.source === 'live'
              ? t('backups.remote.live', { remote: remote.remote ?? '' })
              : remote?.source === 'snapshot'
                ? t('backups.remote.snapshot', {
                    when: remote.taken_at ? formatDateTime(new Date(remote.taken_at * 1000).toISOString()) : '',
                  })
                : t('backups.remote.unavailable')}
        </p>

        {isNarrow ? (
          <div style={cardListStyle} data-testid="backups-archives-cards">
            {archives.length === 0 ? (
              <div style={emptyCardStyle} data-testid="backups-archives-empty">
                {inventory ? t('backups.archives.empty') : t('backups.loading')}
              </div>
            ) : (
              archives.map((archive) => (
                <div key={archive.name} style={cardStyle} data-testid="backups-archive-row">
                  {/* The filename gets the card's full width and is allowed to
                      break mid-token. An ellipsis would hide the tail, and the
                      tail — timestamp and hash — is what tells two archives
                      apart. */}
                  <span style={archiveNameCardStyle} data-testid="backups-archive-name">
                    {archive.name}
                  </span>
                  {(archive.readable === false || archive.config_included) && (
                    <div style={badgeRowStyle}>{archiveBadges(archive, false)}</div>
                  )}
                  {/* Label and value share a line here: an archive's values
                      are all short ("56 KB", "ja", a joined label list), so
                      stacking them would double the card's height and put one
                      archive on a screen. */}
                  <dl style={fieldListStyle}>
                    <div style={fieldRowStyle}>
                      <dt style={fieldLabelStyle}>{t('backups.archives.size')}</dt>
                      <dd style={inlineValueStyle}>{humanBytes(archive.bytes ?? 0)}</dd>
                    </div>
                    <div style={fieldRowStyle}>
                      <dt style={fieldLabelStyle}>{t('backups.archives.keys')}</dt>
                      <dd style={inlineValueStyle}>
                        {(archive.recipients ?? []).map((r) => r.label).join(', ') || '—'}
                      </dd>
                    </div>
                    <div style={fieldRowStyle}>
                      <dt style={fieldLabelStyle}>{t('backups.archives.offsite')}</dt>
                      <dd style={inlineValueStyle} data-testid="backups-archive-offsite">
                        {t(`backups.offsite.${offsite(archive.name ?? '')}`)}
                      </dd>
                    </div>
                  </dl>
                  {downloadButton(archive, true)}
                </div>
              ))
            )}
          </div>
        ) : (
          <div style={tableWrapperStyles}>
            <table style={tableElementStyles} data-testid="backups-archives-table">
              <thead>
                <tr style={headerRowStyle}>
                  <th style={headerCellBaseStyle}>{t('backups.archives.name')}</th>
                  <th style={headerCellBaseStyle}>{t('backups.archives.size')}</th>
                  <th style={headerCellBaseStyle}>{t('backups.archives.keys')}</th>
                  <th style={headerCellBaseStyle}>{t('backups.archives.offsite')}</th>
                  <th style={headerCellBaseStyle} />
                </tr>
              </thead>
              <tbody>
                {archives.length === 0 && (
                  <tr>
                    <td colSpan={5} style={emptyCellStyle} data-testid="backups-archives-empty">
                      {inventory ? t('backups.archives.empty') : t('backups.loading')}
                    </td>
                  </tr>
                )}
                {archives.map((archive) => (
                  // Every row is a file that is present; `getRowStyle` is about
                  // active vs retired rows, not zebra striping.
                  <tr key={archive.name} style={getRowStyle(true)} data-testid="backups-archive-row">
                    <td style={cellStyle}>
                      <span data-testid="backups-archive-name">{archive.name}</span>
                      {archiveBadges(archive, true)}
                    </td>
                    <td style={cellStyle}>{humanBytes(archive.bytes ?? 0)}</td>
                    <td style={cellStyle}>
                      {(archive.recipients ?? []).map((r) => r.label).join(', ') || '—'}
                    </td>
                    <td style={cellStyle} data-testid="backups-archive-offsite">
                      {t(`backups.offsite.${offsite(archive.name ?? '')}`)}
                    </td>
                    <td style={cellStyle}>{downloadButton(archive, false)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <section>
        <h2 style={sectionHeadingStyle}>{t('backups.keys.heading')}</h2>
        <p style={noteStyle}>{t('backups.keys.note')}</p>

        {isNarrow ? (
          <div style={cardListStyle} data-testid="backups-keys-cards">
            {keys.length === 0 ? (
              <div style={emptyCardStyle} data-testid="backups-keys-empty">
                {inventory ? t('backups.keys.empty') : t('backups.loading')}
              </div>
            ) : (
              keys.map((key) => (
                <div key={key.fingerprint} style={cardStyle} data-testid="backups-key-row">
                  <div style={cardTitleRowStyle}>
                    <span style={cardTitleStyle} data-testid="backups-key-label">
                      {key.label}
                    </span>
                    <span style={countBadgeStyle}>
                      {key.archives} {t('backups.keys.archives')}
                    </span>
                  </div>
                  <dl style={fieldListStyle}>
                    <dt style={fieldLabelStyle}>{t('backups.keys.fingerprint')}</dt>
                    <dd style={{ ...fieldValueStyle, fontFamily: 'monospace' }}>
                      {/* The phone has the width the desktop cell does not, so
                          it shows the whole value: this is the string a key
                          holder compares against an envelope, and half of it
                          cannot be compared. `title` stays for parity. */}
                      <span
                        title={key.fingerprint}
                        data-testid="backups-key-fingerprint"
                        style={{ overflowWrap: 'anywhere' }}
                      >
                        {key.fingerprint}
                      </span>
                    </dd>

                    {/* Stacked, unlike the archive card: a 64-character
                        fingerprint and a two-timestamp range each need the
                        card's full width. */}
                    <dt style={fieldLabelStyle}>{t('backups.keys.archiveRange')}</dt>
                    <dd style={fieldValueStyle} data-testid="backups-key-archive-range">
                      {archiveRange(key.first_seen, key.last_seen)}
                    </dd>
                  </dl>
                </div>
              ))
            )}
          </div>
        ) : (
          <div style={tableWrapperStyles}>
            <table style={tableElementStyles} data-testid="backups-keys-table">
              <thead>
                <tr style={headerRowStyle}>
                  <th style={headerCellBaseStyle}>{t('backups.keys.label')}</th>
                  <th style={headerCellBaseStyle}>{t('backups.keys.fingerprint')}</th>
                  <th style={headerCellBaseStyle}>{t('backups.keys.archives')}</th>
                  <th style={headerCellBaseStyle}>{t('backups.keys.archiveRange')}</th>
                </tr>
              </thead>
              <tbody>
                {keys.length === 0 && (
                  <tr>
                    <td colSpan={4} style={emptyCellStyle} data-testid="backups-keys-empty">
                      {inventory ? t('backups.keys.empty') : t('backups.loading')}
                    </td>
                  </tr>
                )}
                {keys.map((key) => (
                  <tr key={key.fingerprint} style={getRowStyle(true)} data-testid="backups-key-row">
                    <td style={cellStyle} data-testid="backups-key-label">{key.label}</td>
                    <td style={{ ...cellStyle, fontFamily: 'monospace', fontSize: theme.typography.fontSize.xs }}>
                      {/* Truncated for reading, never for identity: the full value
                          is in the title so it can be compared with an envelope. */}
                      <span title={key.fingerprint} data-testid="backups-key-fingerprint">
                        {(key.fingerprint ?? '').slice(0, 16)}…
                      </span>
                    </td>
                    <td style={cellStyle}>{key.archives}</td>
                    <td style={cellStyle} data-testid="backups-key-archive-range">
                      {/* One archive is one date, not the same date twice: a
                          repeated timestamp reads as a zero-length window, which
                          is not what a single archive means. */}
                      {archiveRange(key.first_seen, key.last_seen)}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  )
}

const sectionHeadingStyle: React.CSSProperties = {
  fontSize: theme.typography.fontSize.lg,
  fontWeight: theme.typography.fontWeight.semibold,
  color: theme.colors.text.primary,
  marginBottom: theme.spacing.xs,
}

const noteStyle: React.CSSProperties = {
  fontSize: theme.typography.fontSize.sm,
  color: theme.colors.text.secondary,
  marginBottom: theme.spacing.md,
  // The live variant interpolates a storage URL — one unbroken token with no
  // space to wrap at, which widens the whole page on a phone unless it may
  // break mid-token.
  overflowWrap: 'anywhere',
}

/* ------------------------------------------------------------------ *
 * Narrow layout: one card per row.                                    *
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
}

const emptyCardStyle: React.CSSProperties = {
  ...cardStyle,
  alignItems: 'center',
  textAlign: 'center',
  color: theme.colors.text.secondary,
  fontSize: theme.typography.fontSize.sm,
}

// An archive filename is a single hyphenated token ending in a timestamp and a
// hash. Truncating it would cut off exactly the part that identifies it, so it
// wraps instead — `anywhere` because there is no space to break at.
const archiveNameCardStyle: React.CSSProperties = {
  fontSize: theme.typography.fontSize.sm,
  fontWeight: theme.typography.fontWeight.semibold,
  color: theme.colors.text.primary,
  overflowWrap: 'anywhere',
}

const badgeRowStyle: React.CSSProperties = {
  display: 'flex',
  flexWrap: 'wrap',
  gap: theme.spacing.xs,
}

const cardTitleRowStyle: React.CSSProperties = {
  display: 'flex',
  justifyContent: 'space-between',
  alignItems: 'baseline',
  gap: theme.spacing.sm,
}

const cardTitleStyle: React.CSSProperties = {
  minWidth: 0,
  overflowWrap: 'anywhere',
  fontSize: theme.typography.fontSize.sm,
  fontWeight: theme.typography.fontWeight.semibold,
  color: theme.colors.text.primary,
}

const countBadgeStyle: React.CSSProperties = {
  flexShrink: 0,
  fontSize: theme.typography.fontSize.xs,
  color: theme.colors.text.secondary,
}

// Label above value rather than beside it: the values here (a fingerprint, a
// date range, a comma-joined key list) are all longer than the label, so a
// two-column grid would give the value the narrower half.
const fieldListStyle: React.CSSProperties = {
  display: 'flex',
  flexDirection: 'column',
  gap: '2px',
  margin: 0,
}

const fieldLabelStyle: React.CSSProperties = {
  fontSize: theme.typography.fontSize.xs,
  color: theme.colors.text.secondary,
}

const fieldValueStyle: React.CSSProperties = {
  margin: `0 0 ${theme.spacing.xs} 0`,
  fontSize: theme.typography.fontSize.sm,
  color: theme.colors.text.primary,
  overflowWrap: 'anywhere',
}

// Label left, value right on one line — for the fields whose value is short
// enough to sit beside its label.
const fieldRowStyle: React.CSSProperties = {
  display: 'flex',
  justifyContent: 'space-between',
  alignItems: 'baseline',
  gap: theme.spacing.md,
  padding: '3px 0',
}

const inlineValueStyle: React.CSSProperties = {
  // `minWidth: 0` so a long joined key list wraps inside the card rather than
  // pushing the row wider than it.
  minWidth: 0,
  margin: 0,
  textAlign: 'right',
  fontSize: theme.typography.fontSize.sm,
  color: theme.colors.text.primary,
  overflowWrap: 'anywhere',
}

const cellStyle: React.CSSProperties = {
  padding: `${theme.spacing.sm} ${theme.spacing.md}`,
  fontSize: theme.typography.fontSize.sm,
  color: theme.colors.text.primary,
}

const emptyCellStyle: React.CSSProperties = {
  ...cellStyle,
  textAlign: 'center',
  color: theme.colors.text.secondary,
}

const errorStyle: React.CSSProperties = {
  background: theme.badges.danger.bg,
  color: theme.badges.danger.text,
  padding: theme.spacing.md,
  borderRadius: theme.borderRadius.md,
  marginBottom: theme.spacing.lg,
  fontSize: theme.typography.fontSize.sm,
}

const warnBadgeStyle: React.CSSProperties = {
  marginLeft: theme.spacing.sm,
  padding: `2px ${theme.spacing.xs}`,
  borderRadius: theme.borderRadius.sm,
  background: theme.badges.danger.bg,
  color: theme.badges.danger.text,
  fontSize: theme.typography.fontSize.xs,
}

const infoBadgeStyle: React.CSSProperties = {
  ...warnBadgeStyle,
  background: theme.badges.warning.bg,
  color: theme.badges.warning.text,
}

const downloadButtonStyle: React.CSSProperties = {
  display: 'inline-flex',
  alignItems: 'center',
  gap: theme.spacing.xs,
  padding: `${theme.spacing.xs} ${theme.spacing.sm}`,
  background: 'transparent',
  border: `1px solid ${theme.colors.border.light}`,
  borderRadius: theme.borderRadius.sm,
  color: theme.colors.text.primary,
  fontSize: theme.typography.fontSize.sm,
  cursor: 'pointer',
}
