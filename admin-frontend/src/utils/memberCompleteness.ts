/**
 * What a member is missing, and what that costs the club (#629).
 *
 * The roster could always *filter* for a gap — Karte, SEPA and E-Mail have had
 * their own pill groups for a long time. What it could never do is say how
 * many members had one, or what having one means. Four capable filters
 * answered a question nobody knew to ask, and the fourth gap (no birth date)
 * had no pill at all because the list did not carry the fact.
 *
 * So the gaps are declared once, here, each with:
 *
 *   - how to detect it on a roster row,
 *   - the list filter that returns exactly the members with it, and
 *   - the i18n keys for its name and its consequence.
 *
 * One declaration, three consumers: the summary panel's rows, the per-row
 * chips, and the filter each "Anzeigen" button applies. They cannot drift into
 * disagreeing about what "incomplete" means, which is the failure that would
 * make the panel worse than nothing — a count whose list holds different
 * members teaches an admin to distrust the number.
 */

import type { MemberListItem } from '../api/generated'

export type MemberGap = 'card_uid' | 'sepa' | 'email' | 'date_of_birth'

/** The list filters a gap's "show me these" button applies. */
export interface MemberGapFilters {
  cardUid?: 'without'
  sepaStatus?: 'invalid'
  email?: 'without'
  dateOfBirth?: 'without'
}

export interface MemberGapDefinition {
  gap: MemberGap
  /** Key into `members.completeness.*` for the short chip label. */
  labelKey: string
  /** Key into `members.completeness.*` for the "what it costs" sentence. */
  consequenceKey: string
  /** Key into the completeness response for this gap's count. */
  countKey: 'without_card_uid' | 'without_mandate' | 'without_email' | 'without_date_of_birth'
  /** Which list filters isolate the members with this gap. */
  filters: MemberGapFilters
}

/**
 * Ordered by how much the gap costs, worst first: a member who cannot book is
 * more urgent than one who cannot be emailed. The panel renders them in this
 * order and so do the chips, so the same gap is always in the same place.
 */
export const MEMBER_GAPS: readonly MemberGapDefinition[] = [
  {
    gap: 'card_uid',
    labelKey: 'members.completeness.missingCardUid',
    consequenceKey: 'members.completeness.cardUidHint',
    countKey: 'without_card_uid',
    filters: { cardUid: 'without' },
  },
  {
    gap: 'sepa',
    labelKey: 'members.completeness.missingSepa',
    consequenceKey: 'members.completeness.sepaHint',
    countKey: 'without_mandate',
    filters: { sepaStatus: 'invalid' },
  },
  {
    gap: 'date_of_birth',
    labelKey: 'members.completeness.missingDateOfBirth',
    consequenceKey: 'members.completeness.dateOfBirthHint',
    countKey: 'without_date_of_birth',
    filters: { dateOfBirth: 'without' },
  },
  {
    gap: 'email',
    labelKey: 'members.completeness.missingEmail',
    consequenceKey: 'members.completeness.emailHint',
    countKey: 'without_email',
    filters: { email: 'without' },
  },
] as const

/**
 * The gaps one roster row has, in `MEMBER_GAPS` order.
 *
 * `has_date_of_birth` is a flag the list gained in #629; a row from an older
 * response leaves it `undefined`, which is read as "not known" and therefore
 * not reported as a gap — inventing one from a missing field would put a
 * warning chip on every member the moment the API and the panel are a deploy
 * apart.
 */
export function memberGaps(member: MemberListItem): MemberGap[] {
  const gaps: MemberGap[] = []

  for (const definition of MEMBER_GAPS) {
    switch (definition.gap) {
      case 'card_uid':
        if (!member.card_uid) gaps.push('card_uid')
        break
      case 'sepa':
        if (!member.is_sepa_valid) gaps.push('sepa')
        break
      case 'date_of_birth':
        if (member.has_date_of_birth === false) gaps.push('date_of_birth')
        break
      case 'email':
        if (!member.email) gaps.push('email')
        break
    }
  }

  return gaps
}

/** Whether this row has everything the club needs from it. */
export function isMemberDataComplete(member: MemberListItem): boolean {
  return memberGaps(member).length === 0
}
