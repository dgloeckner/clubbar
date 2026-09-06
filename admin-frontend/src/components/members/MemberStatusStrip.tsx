/**
 * MemberStatusStrip — the member dialog's one status line (#830).
 *
 * It replaces four things that used to disagree about tone while agreeing
 * about facts: the requirements panel with its progress bar (#629), the SEPA
 * alert under it (#392), a green "✓ Pflicht" pill on every satisfied label,
 * and blue "→ erforderlich für …" pills on the conditional ones. Four green
 * things is not four times the reassurance; it is a dialog where nothing is
 * emphasised because everything is.
 *
 * Two rows, and each answers one question:
 *
 *   caption row — "can this member use the Clubbar?" on the left as a section
 *                 label, and on the right the only thing that stops the save:
 *                 the required fields, or the stored values this submit would
 *                 delete (#131). It is the one place the dialog raises its
 *                 voice, and it only does so after a save was actually refused
 *                 — `role="alert"` then, `role="status"` before, because
 *                 announcing "3 of 5" on every keystroke trains an admin to
 *                 ignore the region that will later matter.
 *   tiles row   — Terminal, SEPA-Einzug, Erreichbar. Grouped by outcome, not
 *                 by field, so the answer is where the question is; a tile
 *                 with a gap names the consequence first and the field second,
 *                 as a button that puts the caret in it.
 *
 * Colour carries one meaning throughout: green the capability is on, orange
 * something is missing, blue this save turns it on, red this save turns it
 * off. The same orange is on the input borders of exactly the fields the
 * strip names, which is what lets an admin scan down instead of reading.
 */

import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { useFormatters } from '../../hooks/useFormatters'
import type { MemberStatusTile, MemberStatusTileId, MemberStatusTone } from '../../utils/memberFormStatus'

export interface MemberStatusStripRequirement {
  /** Form field key, handed back to `onJumpTo`. */
  field: string
  /** Translated field name, as the label above the input reads. */
  label: string
}

export interface MemberStatusStripProps {
  tiles: MemberStatusTile[]
  /** Required fields still empty, in form order. */
  missingRequired: MemberStatusStripRequirement[]
  /** How many stored values this submit would delete (#131). */
  clearingCount: number
  /** True once a submit was refused for missing fields — raises the tone. */
  blocked: boolean
  compact: boolean
  onJumpTo: (field: string) => void
}

/** Tone → the badge palette it borrows. One mapping, used by tile and dot alike. */
const TONE_BADGE: Record<MemberStatusTone, 'success' | 'warning' | 'info' | 'danger'> = {
  ok: 'success',
  partial: 'warning',
  gap: 'warning',
  pending: 'info',
  losing: 'danger',
}

const TILE_TITLE_KEY: Record<MemberStatusTileId, string> = {
  terminal: 'members.status.terminal.title',
  sepa: 'members.status.sepa.title',
  reachable: 'members.status.reachable.title',
}

export function MemberStatusStrip({
  tiles,
  missingRequired,
  clearingCount,
  blocked,
  compact,
  onJumpTo,
}: MemberStatusStripProps) {
  const { t } = useTranslation()

  return (
    <section
      data-testid="members-form-status"
      data-state={blocked ? 'blocked' : missingRequired.length > 0 ? 'incomplete' : 'complete'}
      aria-label={t('members.status.caption')}
      style={{
        display: 'flex',
        flexDirection: 'column',
        gap: theme.spacing.sm,
        marginBottom: theme.spacing.lg,
      }}
    >
      <div
        style={{
          display: 'flex',
          flexWrap: 'wrap',
          alignItems: 'center',
          justifyContent: 'space-between',
          gap: theme.spacing.sm,
          minHeight: '20px',
        }}
      >
        <span
          style={{
            fontSize: theme.typography.fontSize.xs,
            fontWeight: theme.typography.fontWeight.semibold,
            letterSpacing: '0.08em',
            textTransform: 'uppercase',
            color: theme.colors.text.label,
          }}
        >
          {t('members.status.caption')}
        </span>

        <RequiredSummary
          missing={missingRequired}
          clearingCount={clearingCount}
          blocked={blocked}
          compact={compact}
          onJumpTo={onJumpTo}
        />
      </div>

      <div
        style={{
          display: 'grid',
          gridTemplateColumns: compact ? '1fr' : 'repeat(3, 1fr)',
          gap: compact ? theme.spacing.sm : theme.spacing.md,
        }}
      >
        {tiles.map((tile) => (
          <StatusTile
            key={tile.id}
            tile={tile}
            title={t(TILE_TITLE_KEY[tile.id])}
            compact={compact}
            onJumpTo={onJumpTo}
          />
        ))}
      </div>
    </section>
  )
}

/**
 * One capability, as a box the eye can classify before it reads.
 *
 * The icon sits in a tinted square rather than loose beside the text: at three
 * tiles across, a bare glyph and a coloured word read as two separate signals,
 * and the square is what makes the tile a single object.
 */
function StatusTile({
  tile,
  title,
  compact,
  onJumpTo,
}: {
  tile: MemberStatusTile
  title: string
  compact: boolean
  onJumpTo: (field: string) => void
}) {
  const { t } = useTranslation()
  const formatters = useFormatters()
  const badge = theme.badges[TONE_BADGE[tile.tone]]

  const message = t(tile.messageKey, {
    date: tile.since ? formatters.formatDate(tile.since) : '',
  })

  return (
    <div
      data-testid={`members-form-status-tile-${tile.id}`}
      data-tone={tile.tone}
      style={{
        display: 'flex',
        alignItems: compact ? 'center' : 'flex-start',
        gap: theme.spacing.md,
        padding: compact ? `${theme.spacing.sm} ${theme.spacing.md}` : theme.spacing.md,
        background: badge.bg,
        border: `1px solid ${badge.border}`,
        borderRadius: theme.borderRadius.md,
        minHeight: compact ? '40px' : '56px',
      }}
    >
      <span
        aria-hidden="true"
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          justifyContent: 'center',
          flex: '0 0 auto',
          width: '28px',
          height: '28px',
          borderRadius: theme.borderRadius.sm,
          background: badge.bg,
          border: `1px solid ${badge.border}`,
          color: badge.text,
        }}
      >
        <TileIcon id={tile.id} />
      </span>

      {/* Compact puts the outcome to the right of the title on one row and
          only wraps when a gap adds links; the full tile stacks them, which is
          what keeps three tiles the same height at desktop width. */}
      <div
        style={{
          display: 'flex',
          flexDirection: compact && tile.gaps.length === 0 ? 'row' : 'column',
          alignItems: compact && tile.gaps.length === 0 ? 'center' : 'flex-start',
          justifyContent: 'space-between',
          flexWrap: 'wrap',
          gap: compact ? theme.spacing.xs : '2px',
          flex: 1,
          minWidth: 0,
        }}
      >
        <span
          style={{
            fontSize: theme.typography.fontSize.sm,
            fontWeight: theme.typography.fontWeight.semibold,
            color: theme.colors.text.primary,
          }}
        >
          {title}
        </span>

        <span
          data-testid={`members-form-status-tile-${tile.id}-message`}
          style={{
            display: 'inline-flex',
            flexWrap: 'wrap',
            alignItems: 'center',
            gap: theme.spacing.xs,
            fontSize: theme.typography.fontSize.xs,
            color: badge.text,
            lineHeight: theme.typography.lineHeight.normal,
          }}
        >
          {message}
          {tile.gaps.length > 0 && <span aria-hidden="true">·</span>}
          {tile.gaps.map((gap) => (
            <button
              key={gap.field}
              type="button"
              data-testid={`members-form-status-gap-${gap.field}`}
              onClick={() => onJumpTo(gap.field)}
              title={t('members.requirements.jumpTo', { field: t(gap.labelKey) })}
              style={{
                padding: 0,
                background: 'none',
                border: 'none',
                color: badge.text,
                fontSize: theme.typography.fontSize.xs,
                fontWeight: theme.typography.fontWeight.semibold,
                textDecoration: 'underline',
                cursor: 'pointer',
              }}
            >
              {t(gap.labelKey)}
            </button>
          ))}
        </span>
      </div>
    </div>
  )
}

/**
 * The right-hand end of the caption row: the one thing that can stop the save.
 *
 * Four states in priority order, because only one can be the headline —
 * refused, incomplete, deleting, done. A refusal outranks a pending deletion
 * because the deletion cannot happen until the refusal is cleared.
 */
function RequiredSummary({
  missing,
  clearingCount,
  blocked,
  compact,
  onJumpTo,
}: {
  missing: MemberStatusStripRequirement[]
  clearingCount: number
  blocked: boolean
  compact: boolean
  onJumpTo: (field: string) => void
}) {
  const { t } = useTranslation()

  const tone: 'success' | 'warning' | 'danger' =
    blocked ? 'danger' : missing.length > 0 || clearingCount > 0 ? 'warning' : 'success'
  const badge = theme.badges[tone]

  const text = blocked
    ? t('members.status.required.blocked', { count: missing.length })
    : missing.length > 0
      ? t('members.status.required.missing', { count: missing.length })
      : clearingCount > 0
        ? t('members.status.required.clearing', { count: clearingCount })
        : compact
          ? t('members.status.required.completeShort')
          : t('members.status.required.complete')

  return (
    <div
      data-testid="members-form-status-required"
      data-tone={tone}
      // Until a save has actually been refused this is a running count, and a
      // count that interrupts on every keystroke is a count nobody hears.
      role={blocked ? 'alert' : 'status'}
      aria-live="polite"
      style={{
        display: 'flex',
        flexWrap: 'wrap',
        alignItems: 'center',
        gap: theme.spacing.sm,
        fontSize: theme.typography.fontSize.xs,
        fontWeight: theme.typography.fontWeight.semibold,
        color: badge.text,
      }}
    >
      <span aria-hidden="true">{tone === 'success' ? '✓' : '!'}</span>
      <span data-testid="members-form-status-required-text">{text}</span>

      {/* Not on a phone: the caption row is one line there, and the chips
          wrap it to two. The jump link the phone needs is in the pinned
          header's summary line instead, where it stays reachable after the
          strip has scrolled away. */}
      {!compact && missing.map((item) => (
        <button
          key={item.field}
          type="button"
          data-testid={`members-form-requirements-missing-${item.field}`}
          onClick={() => onJumpTo(item.field)}
          title={t('members.requirements.jumpTo', { field: item.label })}
          style={{
            padding: `1px ${theme.spacing.sm}`,
            borderRadius: theme.borderRadius.full,
            background: badge.bg,
            border: `1px solid ${badge.border}`,
            color: badge.text,
            fontSize: theme.typography.fontSize.xs,
            fontWeight: theme.typography.fontWeight.semibold,
            cursor: 'pointer',
          }}
        >
          {item.label}
        </button>
      ))}
    </div>
  )
}

/**
 * The compact line the mobile header shows once the strip has scrolled away
 * (#830): three dots in the tiles' own colours, plus whatever still blocks the
 * save as a jump link. It is the strip's conclusion, not a second opinion —
 * both read the same tiles.
 */
export function MemberStatusSummaryLine({
  tiles,
  missingRequired,
  blocked,
  onJumpTo,
}: {
  tiles: MemberStatusTile[]
  missingRequired: MemberStatusStripRequirement[]
  blocked: boolean
  onJumpTo: (field: string) => void
}) {
  const { t } = useTranslation()
  const tone = blocked ? 'danger' : 'warning'
  const badge = theme.badges[tone]

  return (
    <div
      data-testid="members-form-status-summary"
      style={{
        display: 'flex',
        flexWrap: 'wrap',
        alignItems: 'center',
        justifyContent: 'space-between',
        gap: theme.spacing.sm,
        fontSize: theme.typography.fontSize.xs,
      }}
    >
      <div style={{ display: 'flex', alignItems: 'center', gap: theme.spacing.md }}>
        {tiles.map((tile) => (
          <span
            key={tile.id}
            data-testid={`members-form-status-dot-${tile.id}`}
            data-tone={tile.tone}
            style={{ display: 'inline-flex', alignItems: 'center', gap: theme.spacing.xs }}
          >
            <span
              aria-hidden="true"
              style={{
                width: '8px',
                height: '8px',
                borderRadius: theme.borderRadius.full,
                background: theme.badges[TONE_BADGE[tile.tone]].dot,
              }}
            />
            <span style={{ color: theme.colors.text.secondary }}>
              {t(`${TILE_TITLE_KEY[tile.id]}Short`)}
            </span>
          </span>
        ))}
      </div>

      {missingRequired.length > 0 && (
        <div style={{ display: 'flex', alignItems: 'center', gap: theme.spacing.xs, color: badge.text }}>
          <span aria-hidden="true">!</span>
          <button
            type="button"
            data-testid="members-form-status-summary-jump"
            onClick={() => onJumpTo(missingRequired[0].field)}
            style={{
              padding: 0,
              background: 'none',
              border: 'none',
              color: badge.text,
              fontSize: theme.typography.fontSize.xs,
              fontWeight: theme.typography.fontWeight.semibold,
              textDecoration: 'underline',
              cursor: 'pointer',
            }}
          >
            {missingRequired[0].label}
          </button>
        </div>
      )}
    </div>
  )
}

/**
 * One glyph per capability — a card reader, a bank, an envelope.
 *
 * Inline rather than from an icon set because all three are one path each, and
 * because the tile needs them to share a stroke weight with nothing else on
 * the row.
 */
function TileIcon({ id }: { id: MemberStatusTileId }) {
  const common = {
    width: 15,
    height: 15,
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 2,
    strokeLinecap: 'round' as const,
    strokeLinejoin: 'round' as const,
  }

  if (id === 'terminal') {
    return (
      <svg {...common}>
        <rect x="2" y="5" width="20" height="14" rx="2" />
        <line x1="2" y1="10" x2="22" y2="10" />
      </svg>
    )
  }

  if (id === 'sepa') {
    return (
      <svg {...common}>
        <path d="M3 21h18" />
        <path d="M5 21V10l7-5 7 5v11" />
        <path d="M9 21v-6h6v6" />
      </svg>
    )
  }

  return (
    <svg {...common}>
      <rect x="2" y="4" width="20" height="16" rx="2" />
      <path d="m2 7 10 6 10-6" />
    </svg>
  )
}
