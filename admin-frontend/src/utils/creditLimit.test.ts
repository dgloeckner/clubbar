import { describe, it, expect } from 'vitest'
import {
  creditLimitToInput,
  creditLimitFromInput,
  isValidCreditLimitInput,
  MAX_CREDIT_LIMIT_CENTS,
} from './creditLimit'

/**
 * The member override's serialisation (ADR-0046, #563).
 *
 * This is the whole risk of the member form. An emptied input must become
 * `null` — "follow the club default" — and never `0`, which means "no ceiling
 * for this member". Confusing them grants somebody unlimited credit silently,
 * which is the worst failure this feature can have, so the conversion lives
 * here with its own tests rather than inline in a 2000-line page.
 */
describe('creditLimitFromInput', () => {
  it('reads an emptied field as "follow the club default"', () => {
    expect(creditLimitFromInput('')).toBeNull()
    expect(creditLimitFromInput('   ')).toBeNull()
  })

  it('reads a typed zero as "no ceiling for this member"', () => {
    expect(creditLimitFromInput('0')).toBe(0)
    expect(creditLimitFromInput('0.00')).toBe(0)
  })

  it('never turns an empty field into a zero', () => {
    // The two lines above in one assertion, because this is the pair that
    // must not collapse: null inherits, 0 uncaps.
    expect(creditLimitFromInput('')).not.toBe(0)
    expect(creditLimitFromInput('0')).not.toBeNull()
  })

  it('sends euros as integer cents (ADR-0001)', () => {
    expect(creditLimitFromInput('200')).toBe(20000)
    expect(creditLimitFromInput('12.34')).toBe(1234)
    expect(creditLimitFromInput('0.05')).toBe(5)
  })

  it('accepts the decimal comma a German keyboard produces', () => {
    expect(creditLimitFromInput('12,34')).toBe(1234)
  })

  it('rounds to the nearest cent rather than truncating a float', () => {
    // 19.99 * 100 is 1998.9999999999998 in IEEE 754.
    expect(creditLimitFromInput('19.99')).toBe(1999)
  })

  it('reports a value it cannot read as such', () => {
    expect(creditLimitFromInput('abc')).toBeNaN()
    expect(creditLimitFromInput('-1')).toBeNaN()
  })
})

describe('creditLimitToInput', () => {
  it('shows an inherited limit as an empty field', () => {
    expect(creditLimitToInput(null)).toBe('')
    expect(creditLimitToInput(undefined)).toBe('')
  })

  it('shows a member with no ceiling as a typed zero', () => {
    // Not empty: the difference has to survive a round trip through the edit
    // form, or opening and saving a member would re-cap them.
    expect(creditLimitToInput(0)).toBe('0.00')
  })

  it('shows a ceiling in euros', () => {
    expect(creditLimitToInput(20000)).toBe('200.00')
    expect(creditLimitToInput(1234)).toBe('12.34')
  })

  it('round-trips every case the form can hold', () => {
    for (const cents of [null, 0, 5, 1234, 20000, MAX_CREDIT_LIMIT_CENTS]) {
      expect(creditLimitFromInput(creditLimitToInput(cents))).toBe(cents)
    }
  })
})

describe('isValidCreditLimitInput', () => {
  it('accepts an empty field, a zero and a plain amount', () => {
    expect(isValidCreditLimitInput('')).toBe(true)
    expect(isValidCreditLimitInput('0')).toBe(true)
    expect(isValidCreditLimitInput('200')).toBe(true)
  })

  it('refuses what the API would refuse', () => {
    // Bounds are the server's rule; the form states them rather than
    // discovering them from a 422 after the volunteer has already saved.
    expect(isValidCreditLimitInput('-1')).toBe(false)
    expect(isValidCreditLimitInput('abc')).toBe(false)
    expect(isValidCreditLimitInput(String(MAX_CREDIT_LIMIT_CENTS / 100 + 1))).toBe(false)
  })

  it('allows exactly the upper bound', () => {
    expect(isValidCreditLimitInput(String(MAX_CREDIT_LIMIT_CENTS / 100))).toBe(true)
  })
})
