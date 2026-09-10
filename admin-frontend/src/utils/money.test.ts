import { describe, expect, it } from 'vitest'
import {
  buildMoneyPlaceholder,
  formatCanonicalForDisplay,
  getMoneyFormat,
  maskMoneyInput,
  normaliseCanonicalMoney,
  parseMoneyToCents,
  toCanonicalMoney,
} from './money'

const DE = getMoneyFormat('de-DE')
const EN = getMoneyFormat('en-GB')

describe('getMoneyFormat', () => {
  it('reads the German separators', () => {
    expect(DE).toEqual({ decimal: ',', group: '.' })
  })

  it('reads the English separators', () => {
    expect(EN).toEqual({ decimal: '.', group: ',' })
  })

  it('falls back to German for a tag Intl refuses', () => {
    // A trimmed ICU, or a malformed tag, must not throw inside a render.
    expect(getMoneyFormat('!!')).toEqual({ decimal: ',', group: '.' })
  })
})

describe('maskMoneyInput', () => {
  it('writes a typed dot as the German comma', () => {
    // The keypad emits a dot; German writes a comma. Both must work.
    expect(maskMoneyInput('3.50', DE)).toBe('3,50')
  })

  it('leaves a typed German comma alone', () => {
    expect(maskMoneyInput('3,50', DE)).toBe('3,50')
  })

  it('writes a typed comma as the English dot', () => {
    expect(maskMoneyInput('3,50', EN)).toBe('3.50')
  })

  it('keeps a trailing separator so typing can continue', () => {
    expect(maskMoneyInput('3,', DE)).toBe('3,')
    expect(maskMoneyInput('3.', DE)).toBe('3,')
  })

  it('truncates beyond two decimal digits', () => {
    expect(maskMoneyInput('3,999', DE)).toBe('3,99')
  })

  it('reads a lone group separator with three digits as thousands', () => {
    // A credit limit is typed as a round thousand; 1.000 € is not 1 €.
    expect(maskMoneyInput('1.000', DE)).toBe('1000')
    expect(maskMoneyInput('1,000', EN)).toBe('1000')
  })

  it('reads a fully grouped amount from either side', () => {
    expect(maskMoneyInput('1.234,56', DE)).toBe('1234,56')
    expect(maskMoneyInput('1,234.56', DE)).toBe('1234,56')
    expect(maskMoneyInput('1.234,56', EN)).toBe('1234.56')
  })

  it('drops characters that are not part of an amount', () => {
    expect(maskMoneyInput('3,50 €', DE)).toBe('3,50')
    expect(maskMoneyInput('abc', DE)).toBe('')
  })

  it('completes a leading separator to nought point something', () => {
    expect(maskMoneyInput(',50', DE)).toBe('0,50')
  })

  it('keeps a leading minus, so the form refuses it rather than the mask', () => {
    expect(maskMoneyInput('-5', DE)).toBe('-5')
    expect(maskMoneyInput('-', DE)).toBe('-')
  })

  it('passes an empty field through', () => {
    expect(maskMoneyInput('', DE)).toBe('')
    expect(maskMoneyInput('   ', DE)).toBe('')
  })
})

describe('toCanonicalMoney', () => {
  it('converts the locale separator to a dot', () => {
    expect(toCanonicalMoney('3,50', DE)).toBe('3.50')
    expect(toCanonicalMoney('3.50', EN)).toBe('3.50')
  })

  it('accepts the other locale’s separator too', () => {
    expect(toCanonicalMoney('3.50', DE)).toBe('3.50')
    expect(toCanonicalMoney('3,50', EN)).toBe('3.50')
  })

  it('drops a half-typed trailing separator', () => {
    expect(toCanonicalMoney('3,', DE)).toBe('3')
  })

  it('is empty for an empty field', () => {
    expect(toCanonicalMoney('', DE)).toBe('')
  })

  it('keeps a negative sign for the validator to refuse', () => {
    expect(toCanonicalMoney('-5', DE)).toBe('-5')
  })
})

describe('formatCanonicalForDisplay', () => {
  it('writes the canonical value the locale’s way', () => {
    expect(formatCanonicalForDisplay('250.00', DE)).toBe('250,00')
    expect(formatCanonicalForDisplay('250.00', EN)).toBe('250.00')
  })

  it('leaves an empty value empty', () => {
    expect(formatCanonicalForDisplay('', DE)).toBe('')
  })
})

describe('normaliseCanonicalMoney', () => {
  it('writes a finished amount out to the cent', () => {
    expect(normaliseCanonicalMoney('3')).toBe('3.00')
    expect(normaliseCanonicalMoney('3.5')).toBe('3.50')
  })

  it('keeps a zero a zero', () => {
    // Never an empty field: 0 means "no ceiling", empty means "the club's".
    expect(normaliseCanonicalMoney('0')).toBe('0.00')
  })

  it('hands back anything it cannot read, for the page to refuse', () => {
    expect(normaliseCanonicalMoney('-5')).toBe('-5')
    expect(normaliseCanonicalMoney('')).toBe('')
  })
})

describe('buildMoneyPlaceholder', () => {
  it('offers an example in the locale’s notation', () => {
    expect(buildMoneyPlaceholder(DE)).toBe('10,50')
    expect(buildMoneyPlaceholder(EN)).toBe('10.50')
  })

  it('takes the example from the caller', () => {
    expect(buildMoneyPlaceholder(DE, '100.00')).toBe('100,00')
  })
})

describe('parseMoneyToCents', () => {
  it('parses a dot-decimal price', () => {
    expect(parseMoneyToCents('3.50')).toBe(350)
  })

  it('parses a German comma-decimal price', () => {
    // parseFloat('3,50') silently truncates to 3 — the original money bug.
    expect(parseMoneyToCents('3,50')).toBe(350)
  })

  it('parses whole euros without decimals', () => {
    expect(parseMoneyToCents('3')).toBe(300)
  })

  it('parses a single decimal digit as tens of cents', () => {
    expect(parseMoneyToCents('19,9')).toBe(1990)
  })

  it('parses small cent amounts', () => {
    expect(parseMoneyToCents('0,05')).toBe(5)
  })

  it('parses zero', () => {
    expect(parseMoneyToCents('0')).toBe(0)
  })

  it('tolerates surrounding whitespace', () => {
    expect(parseMoneyToCents(' 2,00 ')).toBe(200)
  })

  it('tolerates a trailing decimal separator while typing', () => {
    expect(parseMoneyToCents('3,')).toBe(300)
    expect(parseMoneyToCents('3.')).toBe(300)
  })

  it('rejects the empty string', () => {
    expect(parseMoneyToCents('')).toBeNull()
    expect(parseMoneyToCents('   ')).toBeNull()
  })

  it('rejects non-numeric input', () => {
    expect(parseMoneyToCents('abc')).toBeNull()
  })

  it('rejects trailing garbage that parseFloat would swallow', () => {
    // parseFloat('12abc') === 12 — must not be accepted as a price.
    expect(parseMoneyToCents('12abc')).toBeNull()
    expect(parseMoneyToCents('3,50 €')).toBeNull()
  })

  it('rejects negative prices', () => {
    expect(parseMoneyToCents('-1')).toBeNull()
  })

  it('rejects ambiguous thousands separators', () => {
    // The field never produces one — `maskMoneyInput` resolves it first —
    // but a value arriving from anywhere else must not be guessed at.
    expect(parseMoneyToCents('1.234,56')).toBeNull()
  })

  it('rejects sub-cent precision', () => {
    expect(parseMoneyToCents('3.999')).toBeNull()
  })
})
