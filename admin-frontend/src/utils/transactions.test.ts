import { describe, expect, it } from 'vitest'
import {
  MONEY_WARN_ABOVE_CENTS,
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
  it('shows credit in green', () => {
    expect(getBalanceColor(-1)).toBe(SUCCESS)
    expect(getBalanceColor(-5000)).toBe(SUCCESS)
  })

  it('leaves a settled account and a small open tab neutral', () => {
    expect(getBalanceColor(0)).toBe(NEUTRAL)
    expect(getBalanceColor(1)).toBe(NEUTRAL)
    expect(getBalanceColor(MONEY_WARN_ABOVE_CENTS)).toBe(NEUTRAL)
  })

  it('warns in amber above the threshold', () => {
    expect(getBalanceColor(MONEY_WARN_ABOVE_CENTS + 1)).toBe(WARNING)
  })
})

describe('getTransactionAmountColor', () => {
  it('leaves a charge neutral — it is not an error', () => {
    expect(getTransactionAmountColor(0)).toBe(NEUTRAL)
    expect(getTransactionAmountColor(250)).toBe(NEUTRAL)
    expect(getTransactionAmountColor(MONEY_WARN_ABOVE_CENTS + 1)).toBe(NEUTRAL)
  })

  it('shows a credit or refund in green', () => {
    expect(getTransactionAmountColor(-250)).toBe(SUCCESS)
  })
})

describe('money colours in general', () => {
  const samples = [-5000, -250, -1, 0, 1, 250, MONEY_WARN_ABOVE_CENTS, MONEY_WARN_ABOVE_CENTS + 1]

  // The pages feed the result straight into an inline `style={{ color: … }}`,
  // so the value must be a CSS color, not a Tailwind class name (#93).
  it('return CSS color values, not Tailwind class names', () => {
    for (const cents of samples) {
      expect(getBalanceColor(cents)).toMatch(/^#[0-9a-f]{6}$/i)
      expect(getTransactionAmountColor(cents)).toMatch(/^#[0-9a-f]{6}$/i)
    }
  })

  // The point of ADR-0042: danger red is reserved for things that are actually
  // wrong. Owing money for a beer is not one of them.
  it('never paint an amount in the danger colour', () => {
    for (const cents of samples) {
      expect(getBalanceColor(cents)).not.toBe(DANGER)
      expect(getTransactionAmountColor(cents)).not.toBe(DANGER)
    }
  })
})
