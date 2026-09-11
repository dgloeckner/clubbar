import { describe, expect, it } from 'vitest'
import {
  buildVolumePlaceholder,
  formatCanonicalLitresForDisplay,
  getVolumeFormat,
  maskVolumeInput,
  millilitresToCanonicalLitres,
  parseLitresToMillilitres,
  toCanonicalLitres,
  VOLUME_MAX_ML,
  VOLUME_MIN_ML,
} from './volume'

/**
 * Litres in, millilitres out (ADR-0056, M4).
 *
 * The failure this guards against is the one `<input type="number">` caused for
 * prices (#863): a German admin types `0,5`, the control reports `''`, and the
 * form is refused beside a field that plainly has a size in it.
 */

const de = getVolumeFormat('de-DE')
const en = getVolumeFormat('en-GB')

describe('getVolumeFormat', () => {
  it("reads the locale's separators rather than assuming them", () => {
    expect(de.decimal).toBe(',')
    expect(en.decimal).toBe('.')
  })
})

describe('maskVolumeInput', () => {
  it('accepts either separator whichever language is on', () => {
    // A numeric keypad emits a dot in a German panel, and an admin who learned
    // the panel in German types a comma into the English one.
    expect(maskVolumeInput('0,5', de)).toBe('0,5')
    expect(maskVolumeInput('0.5', de)).toBe('0,5')
    expect(maskVolumeInput('0,5', en)).toBe('0.5')
    expect(maskVolumeInput('0.5', en)).toBe('0.5')
  })

  it('keeps three decimal digits, because 1 ml is 0,001 l', () => {
    expect(maskVolumeInput('0,001', de)).toBe('0,001')
    expect(maskVolumeInput('0,0005', de)).toBe('0,000')
  })

  it('lets a trailing separator stand, so the field can be typed through', () => {
    expect(maskVolumeInput('0,', de)).toBe('0,')
  })

  it('completes a leading separator to nought point something', () => {
    expect(maskVolumeInput(',33', de)).toBe('0,33')
  })

  it('strips anything that is not a digit or a separator', () => {
    expect(maskVolumeInput('0,5 l', de)).toBe('0,5')
    expect(maskVolumeInput('abc', de)).toBe('')
  })
})

describe('parseLitresToMillilitres', () => {
  it('reads the sizes a bar actually pours', () => {
    expect(parseLitresToMillilitres('0.5')).toBe(500)
    expect(parseLitresToMillilitres('0.33')).toBe(330)
    expect(parseLitresToMillilitres('0.3')).toBe(300)
    expect(parseLitresToMillilitres('1')).toBe(1000)
    expect(parseLitresToMillilitres('1.5')).toBe(1500)
    expect(parseLitresToMillilitres('10')).toBe(VOLUME_MAX_ML)
    expect(parseLitresToMillilitres('0.001')).toBe(VOLUME_MIN_ML)
    expect(parseLitresToMillilitres('0.02')).toBe(20)
  })

  it('reads a blank field as "this product has no size"', () => {
    // Not 0: 0 would print as a size while meaning none, which is why the API
    // refuses it.
    expect(parseLitresToMillilitres('')).toBeNull()
    expect(parseLitresToMillilitres('   ')).toBeNull()
    expect(parseLitresToMillilitres('0')).toBeNull()
    expect(parseLitresToMillilitres('0.000')).toBeNull()
  })

  it('refuses anything that is not unambiguously a size', () => {
    // `parseFloat` would answer 5 for '5abc' and 0 for '0,5'; neither is safe
    // to send to an API that stores what it is given.
    expect(parseLitresToMillilitres('abc')).toBeNull()
    expect(parseLitresToMillilitres('0,5')).toBeNull() // canonical is dot-decimal
    expect(parseLitresToMillilitres('5abc')).toBeNull()
    expect(parseLitresToMillilitres('-1')).toBeNull()
    expect(parseLitresToMillilitres('1.2.3')).toBeNull()
  })

  it('does not clamp — an out-of-range size reaches the page that refuses it', () => {
    // The mask is not the validator: silently turning 50 l into 10 l would be
    // worse than a refusal the admin can read.
    expect(parseLitresToMillilitres('50')).toBe(50000)
  })
})

describe('the round trip a stored product makes', () => {
  it('comes back reading the way it was typed', () => {
    // 500 must reload as `0,5`, not `0,500`.
    expect(millilitresToCanonicalLitres(500)).toBe('0.5')
    expect(millilitresToCanonicalLitres(330)).toBe('0.33')
    expect(millilitresToCanonicalLitres(1000)).toBe('1')
    expect(millilitresToCanonicalLitres(1)).toBe('0.001')
    expect(millilitresToCanonicalLitres(10000)).toBe('10')
  })

  it('renders an absent size as an empty field', () => {
    expect(millilitresToCanonicalLitres(null)).toBe('')
    expect(millilitresToCanonicalLitres(undefined)).toBe('')
  })

  it('survives typing, canonicalising and reloading in either language', () => {
    for (const [spec, typed] of [
      [de, '0,33'],
      [en, '0.33'],
    ] as const) {
      const millilitres = parseLitresToMillilitres(toCanonicalLitres(typed, spec))
      expect(millilitres).toBe(330)
      expect(
        formatCanonicalLitresForDisplay(millilitresToCanonicalLitres(millilitres), spec),
      ).toBe(typed)
    }
  })
})

describe('buildVolumePlaceholder', () => {
  it('offers an example in the reader s own notation', () => {
    expect(buildVolumePlaceholder(de)).toBe('0,5')
    expect(buildVolumePlaceholder(en)).toBe('0.5')
  })
})
