import { describe, it, expect } from 'vitest'
import { fitNavItems } from './navOverflow'

describe('fitNavItems', () => {
  it('keeps every entry inline when the row fits', () => {
    expect(fitNavItems([100, 100, 100], 60, 400, 2)).toBe(3)
  })

  it('counts the gaps between entries, not around them', () => {
    // 3 * 100 + 2 * 2 = 304 — exactly the width available.
    expect(fitNavItems([100, 100, 100], 60, 304, 2)).toBe(3)
    expect(fitNavItems([100, 100, 100], 60, 303, 2)).toBe(2)
  })

  it('reserves the More button and its gap once the row overflows', () => {
    // Two entries and the gap are 202; the button and the gap in front of it
    // are another 62, so two entries need 264 and a third would need 366.
    expect(fitNavItems([100, 100, 100], 60, 303, 2)).toBe(2)
    expect(fitNavItems([100, 100, 100], 60, 264, 2)).toBe(2)
    expect(fitNavItems([100, 100, 100], 60, 263, 2)).toBe(1)
  })

  it('moves everything into More when not even one entry fits beside it', () => {
    expect(fitNavItems([100, 100], 60, 80, 2)).toBe(0)
  })

  it('shows everything when the width has not been measured yet', () => {
    // A zero-width container is a nav that has not been laid out, not a nav
    // with no room: hiding entries on that reading would hide all of them.
    expect(fitNavItems([100, 100], 60, 0, 2)).toBe(2)
    expect(fitNavItems([100, 100], 60, Number.NaN, 2)).toBe(2)
  })

  it('handles an empty nav', () => {
    expect(fitNavItems([], 60, 400, 2)).toBe(0)
  })
})
