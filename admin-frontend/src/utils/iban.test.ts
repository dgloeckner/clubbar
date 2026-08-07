import { describe, expect, it } from 'vitest'
import { normalizeIban, validateIban } from './iban'

describe('normalizeIban', () => {
  it('strips spaces and uppercases', () => {
    expect(normalizeIban('de89 3704 0044 0532 0130 00')).toBe(
      'DE89370400440532013000'
    )
  })

  it('leaves an already-normalized IBAN unchanged', () => {
    expect(normalizeIban('DE89370400440532013000')).toBe(
      'DE89370400440532013000'
    )
  })
})

describe('validateIban', () => {
  // Known-good IBANs taken from official country examples (ECBS registry).
  it.each([
    'DE89370400440532013000', // Germany
    'GB82WEST12345698765432', // United Kingdom (letters in BBAN)
    'FR1420041010050500013M02606', // France (mixed alphanumeric)
    'AT611904300234573201', // Austria
    'NL91ABNA0417164300', // Netherlands
  ])('accepts the valid IBAN %s', (iban) => {
    expect(validateIban(iban)).toBe(true)
  })

  it('accepts lowercase input with spaces', () => {
    expect(validateIban('de89 3704 0044 0532 0130 00')).toBe(true)
  })

  it('rejects an IBAN whose mod-97 checksum is broken', () => {
    // Last digit flipped: 0 -> 1
    expect(validateIban('DE89370400440532013001')).toBe(false)
  })

  it('rejects an IBAN with wrong check digits', () => {
    // Check digits 89 -> 88
    expect(validateIban('DE88370400440532013000')).toBe(false)
  })

  it('rejects a transposition error in the BBAN', () => {
    // 37 04 -> 73 04 — the classic typo mod-97 exists to catch
    expect(validateIban('DE89730400440532013000')).toBe(false)
  })

  it('rejects IBANs that are too short', () => {
    expect(validateIban('DE8937040044')).toBe(false)
  })

  it('rejects IBANs missing the country prefix', () => {
    expect(validateIban('89370400440532013000DE')).toBe(false)
  })

  it('rejects the empty string', () => {
    expect(validateIban('')).toBe(false)
  })

  it('rejects input with invalid characters', () => {
    expect(validateIban('DE89-3704-0044-0532-0130-00')).toBe(false)
  })
})
