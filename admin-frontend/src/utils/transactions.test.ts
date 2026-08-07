import { describe, expect, it } from 'vitest'
import {
  formatTransactionType,
  getAmountColor,
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

describe('getAmountColor', () => {
  // JournalPage feeds the result straight into an inline `style={{ color: … }}`,
  // so the value must be a CSS color, not a Tailwind class name.
  it('returns a CSS color value, not a Tailwind class', () => {
    for (const cents of [350, -350, 0]) {
      expect(getAmountColor(cents)).toMatch(/^#[0-9a-f]{6}$/i)
    }
  })

  it('marks charges (positive cents) with the semantic danger color', () => {
    expect(getAmountColor(350)).toBe('#ef4444')
  })

  it('marks credits (negative cents) with the semantic success color', () => {
    expect(getAmountColor(-350)).toBe('#22c55e')
  })

  it('renders a zero amount in the neutral secondary text color', () => {
    expect(getAmountColor(0)).toBe('#94a3b8')
  })
})
