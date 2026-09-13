import { describe, expect, it } from 'vitest'
import {
  formatTransactionType,
  getBalanceColor,
  getTransactionAmountColor,
  getTransactionTypeColor,
} from './transactions'

describe('formatTransactionType', () => {
  it('labels known transaction types', () => {
    expect(formatTransactionType('purchase')).toBe('Purchase')
    expect(formatTransactionType('storno')).toBe('Storno')
    expect(formatTransactionType('payout')).toBe('Payout')
  })

  it('passes unknown types through unchanged', () => {
    expect(formatTransactionType('manual_adjustment')).toBe('manual_adjustment')
  })
})

describe('getTransactionTypeColor', () => {
  it('returns distinct badge colors for purchase and storno', () => {
    const purchase = getTransactionTypeColor('purchase')
    const storno = getTransactionTypeColor('storno')
    expect(purchase.text).toBe('#3b82f6')
    expect(storno.text).toBe('#f97316')
    expect(purchase.bg).not.toBe(storno.bg)
  })

  it('falls back to a neutral color for unknown types', () => {
    expect(getTransactionTypeColor('unknown').text).toBe('#64748b')
  })
})

// The colour rule these two functions encode is ADR-0042's, and it is the same
// rule the terminal implements in `design_tokens.dart`. These cases mirror
// `terminal-frontend/test/utils/money_semantics_test.dart` one for one, so a
// change on either side that breaks the cross-app agreement shows up as a
// failing test rather than as two apps quietly disagreeing (#28 → #93 → #376).
const DANGER = '#ef4444'
const SUCCESS = '#22c55e'
const WARNING = '#f97316'
const NEUTRAL = '#f1f5f9'

describe('getBalanceColor', () => {
  // The club's shipped policy: a €100.00 ceiling warned at 80%, so an
  // inherited band falls at 8000. It arrives per row as
  // `credit_limit_warn_at_cents`; nothing here computes it.
  const BAND = 8000

  it('shows credit in green, whatever the band', () => {
    expect(getBalanceColor(-1, BAND)).toBe(SUCCESS)
    expect(getBalanceColor(-5000, BAND)).toBe(SUCCESS)
    expect(getBalanceColor(-5000, null)).toBe(SUCCESS)
  })

  it('leaves a settled account and an ordinary open tab neutral', () => {
    expect(getBalanceColor(0, BAND)).toBe(NEUTRAL)
    expect(getBalanceColor(1, BAND)).toBe(NEUTRAL)
    // #926: €23.00 against an €80.00 band is an ordinary evening, and a
    // column that ambers on those teaches its reader to ignore the colour.
    expect(getBalanceColor(2300, BAND)).toBe(NEUTRAL)
    expect(getBalanceColor(BAND - 1, BAND)).toBe(NEUTRAL)
  })

  it('warns in amber from the band itself', () => {
    // `>=`, matching the terminal and PHP's CreditLimit::status().
    expect(getBalanceColor(BAND, BAND)).toBe(WARNING)
    expect(getBalanceColor(BAND + 1, BAND)).toBe(WARNING)
  })

  it('keeps a tab past the ceiling amber rather than red', () => {
    // Colour-by-state belongs to the dashboard's near-limit panel, not to
    // the amount (ADR-0042 scope).
    expect(getBalanceColor(50000, BAND)).toBe(WARNING)
  })

  it('never warns about a member with no ceiling', () => {
    // null is "no line to approach" (ADR-0047 rule 2), not a band of zero.
    expect(getBalanceColor(1, null)).toBe(NEUTRAL)
    expect(getBalanceColor(1000000, null)).toBe(NEUTRAL)
  })

  it('degrades to no cue when the field is absent', () => {
    // A bundle running against a backend that predates the field: better no
    // warning than a wrong one.
    expect(getBalanceColor(1000000, undefined)).toBe(NEUTRAL)
  })

  it('leaves a settled account neutral even against a zero band', () => {
    // warn_threshold_percent may be 1, so a small ceiling rounds its band
    // down to nothing — and a €0.00 balance in warning colour is bug #28.
    expect(getBalanceColor(0, 0)).toBe(NEUTRAL)
    expect(getBalanceColor(-500, 0)).toBe(SUCCESS)
    expect(getBalanceColor(1, 0)).toBe(WARNING)
  })
})

describe('getTransactionAmountColor', () => {
  it('leaves a charge neutral — it is not an error', () => {
    expect(getTransactionAmountColor(0)).toBe(NEUTRAL)
    expect(getTransactionAmountColor(250)).toBe(NEUTRAL)
    // Larger than any band a club would set, and still not amber: a single
    // booking is coloured by sign, never by size.
    expect(getTransactionAmountColor(1000000)).toBe(NEUTRAL)
  })

  it('shows a credit or refund in green', () => {
    expect(getTransactionAmountColor(-250)).toBe(SUCCESS)
  })
})

describe('money colours in general', () => {
  // [balance, the member's band] — the band varies per member now, so the
  // sweeps below cover the matrix rather than a single threshold.
  const samples: [number, number | null][] = [
    [-5000, 8000],
    [-250, null],
    [-1, 8000],
    [0, 8000],
    [0, 0],
    [1, 8000],
    [250, null],
    [7999, 8000],
    [8000, 8000],
    [50000, 8000],
    [50000, null],
  ]

  // The pages feed the result straight into an inline `style={{ color: … }}`,
  // so the value must be a CSS color, not a Tailwind class name (#93).
  it('return CSS color values, not Tailwind class names', () => {
    for (const [cents, band] of samples) {
      expect(getBalanceColor(cents, band)).toMatch(/^#[0-9a-f]{6}$/i)
      expect(getTransactionAmountColor(cents)).toMatch(/^#[0-9a-f]{6}$/i)
    }
  })

  // The point of ADR-0042: danger red is reserved for things that are actually
  // wrong. Owing money for a beer is not one of them.
  it('never paint an amount in the danger colour', () => {
    for (const [cents, band] of samples) {
      expect(getBalanceColor(cents, band)).not.toBe(DANGER)
      expect(getTransactionAmountColor(cents)).not.toBe(DANGER)
    }
  })
})
