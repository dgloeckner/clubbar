import { describe, expect, it } from 'vitest'
import { ageRestrictionOf } from './ageRestriction'

describe('ageRestrictionOf', () => {
  it('returns the age a restricted product carries', () => {
    expect(ageRestrictionOf(16)).toBe(16)
    expect(ageRestrictionOf(18)).toBe(18)
  })

  it('treats the bounds of the stored range as restrictions', () => {
    // The API validates `gte:1` / `lte:99`; both ends are legitimate rows.
    expect(ageRestrictionOf(1)).toBe(1)
    expect(ageRestrictionOf(99)).toBe(99)
  })

  it('reads a missing minimum age as unrestricted', () => {
    expect(ageRestrictionOf(null)).toBeNull()
    expect(ageRestrictionOf(undefined)).toBeNull()
  })

  it('reads 0 as unrestricted rather than as a restriction', () => {
    // The point of the helper: `min_age && …` would call 0 unrestricted by
    // accident, and any other falsy-number bug the same way.
    expect(ageRestrictionOf(0)).toBeNull()
  })

  it('ignores values the column cannot hold', () => {
    expect(ageRestrictionOf(-1)).toBeNull()
    expect(ageRestrictionOf(16.5)).toBeNull()
    expect(ageRestrictionOf(Number.NaN)).toBeNull()
  })
})
