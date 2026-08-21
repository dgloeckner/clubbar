/**
 * MemberGapChips — one roster row's missing data, as chips (#629).
 *
 * The roster used to carry a SEPA-only column: the one gap of four that had a
 * column, beside an email column that showed its own "Fehlt" badge, beside two
 * gaps — no card UID, no birth date — that were invisible from the list
 * entirely. An admin could not answer "who is missing something?" by looking.
 *
 * The chips name the gaps rather than scoring the member, because "70 %
 * complete" is not something anyone can act on and "Karte, Geburtsdatum" is.
 * Each chip's `title` carries the consequence, the same sentence the
 * Datenqualität panel prints in full — the panel teaches it once, the chip
 * reminds whoever hovers.
 *
 * Gaps come from `memberGaps`, which the panel's counts also derive from, so
 * the column and the headline cannot disagree about who is incomplete.
 */

import { useTranslation } from 'react-i18next'
import { theme } from '../../styles/design-system'
import { MEMBER_GAPS, memberGaps } from '../../utils/memberCompleteness'
import type { MemberListItem } from '../../api/generated'

export function MemberGapChips({ member }: { member: MemberListItem }) {
  const { t } = useTranslation()
  const gaps = memberGaps(member)

  if (gaps.length === 0) {
    return (
      <span
        data-testid={`members-table-cell-data-complete-${member.id}`}
        style={{
          display: 'inline-flex',
          alignItems: 'center',
          gap: theme.spacing.xs,
          color: theme.colors.semantic.success,
          fontSize: theme.typography.fontSize.xs,
          fontWeight: theme.typography.fontWeight.semibold,
        }}
      >
        <span aria-hidden="true">✓</span>
        {t('members.completeness.complete')}
      </span>
    )
  }

  return (
    <span style={{ display: 'flex', flexWrap: 'wrap', gap: theme.spacing.xs }}>
      {gaps.map((gap) => {
        const definition = MEMBER_GAPS.find((candidate) => candidate.gap === gap)
        if (!definition) return null

        return (
          <span
            key={gap}
            data-testid={`members-table-cell-data-gap-${gap}-${member.id}`}
            title={t(definition.consequenceKey)}
            style={{
              padding: `2px ${theme.spacing.sm}`,
              borderRadius: theme.borderRadius.full,
              background: theme.badges.warning.bg,
              border: `1px solid ${theme.badges.warning.border}`,
              color: theme.badges.warning.text,
              fontSize: theme.typography.fontSize.xs,
              fontWeight: theme.typography.fontWeight.semibold,
              whiteSpace: 'nowrap',
            }}
          >
            {t(definition.labelKey)}
          </span>
        )
      })}
    </span>
  )
}
