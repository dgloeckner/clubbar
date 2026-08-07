import { describe, expect, it } from 'vitest'
import { parsePriceToCents } from './price'

describe('parsePriceToCents', () => {
  it('parses a dot-decimal price', () => {
    expect(parsePriceToCents('3.50')).toBe(350)
  })

  it('parses a German comma-decimal price', () => {
    // parseFloat('3,50') silently truncates to 3 — the original money bug.
    expect(parsePriceToCents('3,50')).toBe(350)
  })

  it('parses whole euros without decimals', () => {
    expect(parsePriceToCents('3')).toBe(300)
  })

  it('parses a single decimal digit as tens of cents', () => {
    expect(parsePriceToCents('19,9')).toBe(1990)
  })

  it('parses small cent amounts', () => {
    expect(parsePriceToCents('0,05')).toBe(5)
  })

  it('parses zero', () => {
    expect(parsePriceToCents('0')).toBe(0)
  })

  it('tolerates surrounding whitespace', () => {
    expect(parsePriceToCents(' 2,00 ')).toBe(200)
  })

  it('tolerates a trailing decimal separator while typing', () => {
    expect(parsePriceToCents('3,')).toBe(300)
    expect(parsePriceToCents('3.')).toBe(300)
  })

  it('rejects the empty string', () => {
    expect(parsePriceToCents('')).toBeNull()
    expect(parsePriceToCents('   ')).toBeNull()
  })

  it('rejects non-numeric input', () => {
    expect(parsePriceToCents('abc')).toBeNull()
  })

  it('rejects trailing garbage that parseFloat would swallow', () => {
    // parseFloat('12abc') === 12 — must not be accepted as a price.
    expect(parsePriceToCents('12abc')).toBeNull()
    expect(parsePriceToCents('3,50 €')).toBeNull()
  })

  it('rejects negative prices', () => {
    expect(parsePriceToCents('-1')).toBeNull()
  })

  it('rejects ambiguous thousands separators', () => {
    expect(parsePriceToCents('1.234,56')).toBeNull()
  })

  it('rejects sub-cent precision', () => {
    expect(parsePriceToCents('3.999')).toBeNull()
  })
})
