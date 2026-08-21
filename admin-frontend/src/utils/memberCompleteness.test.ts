import { describe, expect, it } from 'vitest'
import type { MemberListItem } from '../api/generated'
import { MEMBER_GAPS, isMemberDataComplete, memberGaps } from './memberCompleteness'

/** A roster row with nothing missing. */
function complete(overrides: Partial<MemberListItem> = {}): MemberListItem {
  return {
    id: 'm-1',
    first_name: 'Dietrich',
    last_name: 'Dollenfett',
    email: 'dietrich@example.de',
    card_uid: '0002011450',
    is_sepa_valid: true,
    has_date_of_birth: true,
    is_active: true,
    ...overrides,
  }
}

describe('memberGaps', () => {
  it('reports nothing for a complete member', () => {
    expect(memberGaps(complete())).toEqual([])
    expect(isMemberDataComplete(complete())).toBe(true)
  })

  it.each([
    ['card_uid', { card_uid: null }],
    ['sepa', { is_sepa_valid: false }],
    ['date_of_birth', { has_date_of_birth: false }],
    ['email', { email: null }],
  ] as const)('reports %s', (gap, overrides) => {
    expect(memberGaps(complete(overrides))).toEqual([gap])
    expect(isMemberDataComplete(complete(overrides))).toBe(false)
  })

  it('reports several gaps in the declared order, worst first', () => {
    expect(
      memberGaps(complete({ email: null, card_uid: null, has_date_of_birth: false })),
    ).toEqual(['card_uid', 'date_of_birth', 'email'])
  })

  it('treats an empty string as missing, not as a value', () => {
    expect(memberGaps(complete({ card_uid: '' }))).toEqual(['card_uid'])
    expect(memberGaps(complete({ email: '' }))).toEqual(['email'])
  })

  /**
   * The flag arrived with #629. A row from an API that predates it must not
   * light up every member with a birth-date warning during the deploy window.
   */
  it('says nothing about a birth date the response does not mention', () => {
    const older = complete()
    delete older.has_date_of_birth
    expect(memberGaps(older)).toEqual([])
  })
})

describe('MEMBER_GAPS', () => {
  it('gives every gap a filter that isolates it', () => {
    for (const definition of MEMBER_GAPS) {
      expect(Object.keys(definition.filters).length).toBeGreaterThan(0)
    }
  })

  it('declares each gap exactly once', () => {
    const gaps = MEMBER_GAPS.map((definition) => definition.gap)
    expect(new Set(gaps).size).toBe(gaps.length)
  })

  /** Every gap the panel counts must be a gap a row can actually report. */
  it('covers exactly the gaps memberGaps can produce', () => {
    const allMissing = memberGaps({
      card_uid: null,
      email: null,
      is_sepa_valid: false,
      has_date_of_birth: false,
    })
    expect(new Set(allMissing)).toEqual(new Set(MEMBER_GAPS.map((d) => d.gap)))
  })
})
