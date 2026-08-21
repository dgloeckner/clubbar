/**
 * MemberDataQualityPanel — the roster's answer to "which members are missing
 * something, and does it matter?" (#629).
 *
 * The Members page already had four filter pill groups that could *find* these
 * members. What it never had was the story: a pill labelled "Ohne Karte" does
 * not say how many there are, and it certainly does not say that those members
 * cannot buy a drink. The filters are the tool; this is the reason to pick it
 * up. Each row therefore states three things — the gap, how many, and what it
 * costs — and its button applies the filter that lists exactly those members.
 *
 * Counts cover **active** members only, which is why every button also pins
 * the status filter to `active`: a number and a list that disagree would teach
 * an admin to distrust both.
 *
 * When nothing is missing it collapses to one green line. A panel that is only
 * ever a warning gets ignored; one that can say "all clear" is worth reading.
 */

import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { MEMBER_GAPS, type MemberGapFilters } from '../../utils/memberCompleteness'
import type { MemberDataCompleteness } from '../../api/generated'

export interface MemberDataQualityPanelProps {
  /** Null while loading, or after a failed load — the panel renders nothing. */
  completeness: MemberDataCompleteness | null
  onShowGap: (filters: MemberGapFilters) => void
  /** Applies `data_status=incomplete`: every member with any gap at all. */
  onShowAllIncomplete: () => void
  isMobile: boolean
}

export function MemberDataQualityPanel({
  completeness,
  onShowGap,
  onShowAllIncomplete,
  isMobile,
}: MemberDataQualityPanelProps) {
  const { t } = useTranslation()

  // Nothing to say yet. Deliberately not a skeleton: the panel is a summary,
  // and a placeholder that reserves space for a warning that may never come
  // is its own kind of noise.
  if (!completeness) return null

  const incomplete = completeness.incomplete ?? 0
  const total = completeness.total ?? 0

  if (incomplete === 0) {
    return (
      <div
        data-testid="members-data-quality"
        data-state="complete"
        style={{
          ...panelStyle,
          background: theme.badges.success.bg,
          border: `1px solid ${theme.badges.success.border}`,
          color: theme.badges.success.text,
        }}
      >
        <span aria-hidden="true">✓</span>
        <span data-testid="members-data-quality-headline">
          {t('members.completeness.allComplete', { count: total })}
        </span>
      </div>
    )
  }

  const rows = MEMBER_GAPS.map((definition) => ({
    ...definition,
    count: completeness[definition.countKey] ?? 0,
  })).filter((row) => row.count > 0)

  return (
    <div
      data-testid="members-data-quality"
      data-state="incomplete"
      style={{
        ...panelStyle,
        flexDirection: 'column',
        alignItems: 'stretch',
        gap: theme.spacing.md,
        background: theme.badges.warning.bg,
        border: `1px solid ${theme.badges.warning.border}`,
      }}
    >
      <div style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: theme.spacing.md }}>
        <span aria-hidden="true" style={{ color: theme.badges.warning.text }}>⚠</span>
        <strong data-testid="members-data-quality-headline" style={{ color: theme.badges.warning.text }}>
          {t('members.completeness.headline', { count: incomplete, total })}
        </strong>
        <button
          type="button"
          data-testid="members-data-quality-show-all"
          onClick={onShowAllIncomplete}
          style={linkButtonStyle}
        >
          {t('members.completeness.showAll')}
        </button>
      </div>

      <ul style={{ listStyle: 'none', margin: 0, padding: 0, display: 'grid', gap: theme.spacing.sm }}>
        {rows.map((row) => (
          <li
            key={row.gap}
            data-testid={`members-data-quality-row-${row.gap}`}
            style={{
              display: 'grid',
              // On a phone the consequence wraps under the name rather than
              // being squeezed into a column two words wide.
              gridTemplateColumns: isMobile ? 'auto 1fr' : '2.5rem 12rem 1fr auto',
              alignItems: 'baseline',
              gap: `${theme.spacing.xs} ${theme.spacing.md}`,
              fontSize: theme.typography.fontSize.sm,
            }}
          >
            <span
              data-testid={`members-data-quality-count-${row.gap}`}
              style={{
                color: theme.colors.semantic.warningLight,
                fontWeight: theme.typography.fontWeight.bold,
                fontVariantNumeric: 'tabular-nums',
              }}
            >
              {row.count}
            </span>
            <span style={{ color: theme.colors.text.primary }}>{t(row.labelKey)}</span>
            <span style={{ color: theme.colors.text.secondary, gridColumn: isMobile ? '2' : 'auto' }}>
              {t(row.consequenceKey)}
            </span>
            <button
              type="button"
              data-testid={`members-data-quality-show-${row.gap}`}
              onClick={() => onShowGap(row.filters)}
              style={{ ...linkButtonStyle, gridColumn: isMobile ? '2' : 'auto', justifySelf: 'start' }}
            >
              {t('members.completeness.show')}
            </button>
          </li>
        ))}
      </ul>
    </div>
  )
}

const panelStyle = {
  display: 'flex',
  alignItems: 'center',
  gap: theme.spacing.md,
  padding: `${theme.spacing.md} ${theme.spacing.lg}`,
  marginBottom: theme.spacing.lg,
  borderRadius: theme.borderRadius.md,
  fontSize: theme.typography.fontSize.sm,
} as const

const linkButtonStyle = {
  background: 'none',
  border: 'none',
  padding: 0,
  color: theme.colors.semantic.primary,
  cursor: 'pointer',
  font: 'inherit',
  textDecoration: 'underline',
  whiteSpace: 'nowrap',
} as const
