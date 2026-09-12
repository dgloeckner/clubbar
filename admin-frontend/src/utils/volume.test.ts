import { describe, expect, it } from 'vitest'
import {
  isPresetVolume,
  maskVolumeInput,
  parseVolumeOption,
  VOLUME_CUSTOM_OPTION,
  VOLUME_MAX_ML,
  VOLUME_MIN_ML,
  VOLUME_PRESETS_ML,
} from './volume'

/**
 * The sizes the product form offers, and what a chosen or typed one becomes
 * (ADR-0056).
 *
 * The size used to be typed in litres, which put a locale-aware mask between
 * the admin and the column. Picking from a list removed that problem; a list
 * that cannot be complete introduced a smaller one, which is why there is a
 * typed millilitre field behind the last option — and why most of these tests
 * are about what that field is allowed to leave behind.
 */

describe('VOLUME_PRESETS_ML', () => {
  it('offers the sizes a club bar pours, largest first', () => {
    expect([...VOLUME_PRESETS_ML]).toEqual([1000, 750, 500, 400, 330, 300, 250, 200, 100, 40, 20])
  })

  it('covers a glass of wine at each of the three sizes a card lists it in', () => {
    // 0,2 l is the ordinary German pour, 0,1 l the small glass or Sekt, 0,25 l
    // the Viertel. A club's card carries all three at once, so a picker that
    // had only one of them would send the other two back into the product name.
    expect(VOLUME_PRESETS_ML).toContain(200)
    expect(VOLUME_PRESETS_ML).toContain(100)
    expect(VOLUME_PRESETS_ML).toContain(250)
  })

  it('covers the other things a bar pours: bottles, the 0,4 l glass, and spirits', () => {
    expect(VOLUME_PRESETS_ML).toContain(750) // a wine bottle
    expect(VOLUME_PRESETS_ML).toContain(400) // the 0,4 l glass
    expect(VOLUME_PRESETS_ML).toContain(40) // a double spirit, 4 cl
    expect(VOLUME_PRESETS_ML).toContain(20) // a Schnaps, 2 cl
  })

  it('offers only sizes the API will take', () => {
    for (const millilitres of VOLUME_PRESETS_ML) {
      expect(Number.isInteger(millilitres)).toBe(true)
      expect(millilitres).toBeGreaterThanOrEqual(VOLUME_MIN_ML)
      expect(millilitres).toBeLessThanOrEqual(VOLUME_MAX_ML)
    }
  })

  it('never repeats a size, and never reorders one', () => {
    expect(new Set(VOLUME_PRESETS_ML).size).toBe(VOLUME_PRESETS_ML.length)
    expect([...VOLUME_PRESETS_ML]).toEqual([...VOLUME_PRESETS_ML].sort((a, b) => b - a))
  })
})

describe('VOLUME_CUSTOM_OPTION', () => {
  it('cannot be mistaken for a size', () => {
    // The control intercepts it before parsing; this is the second line of
    // defence, and the reason the sentinel is a word rather than a number.
    expect(parseVolumeOption(VOLUME_CUSTOM_OPTION)).toBeNull()
    expect(VOLUME_PRESETS_ML.map(String)).not.toContain(VOLUME_CUSTOM_OPTION)
  })
})

describe('isPresetVolume', () => {
  it('knows a listed size from one that has to be typed', () => {
    expect(isPresetVolume(500)).toBe(true)
    expect(isPresetVolume(20)).toBe(true)
    expect(isPresetVolume(700)).toBe(false)
    expect(isPresetVolume(1500)).toBe(false)
  })

  it('treats "no size" as not a preset — it is the empty option, not a pick', () => {
    expect(isPresetVolume(null)).toBe(false)
    expect(isPresetVolume(undefined)).toBe(false)
  })
})

describe('maskVolumeInput', () => {
  it('keeps the digits of a millilitre value', () => {
    expect(maskVolumeInput('500')).toBe('500')
    expect(maskVolumeInput('1500')).toBe('1500')
  })

  it('refuses a decimal separator, in either notation', () => {
    // The failure this prevents is #863 turned around: the litres field needed
    // a locale-aware mask because a comma reached script as the empty string.
    // A millilitre is a whole number, so the separator is simply not a
    // character this field has — `0,5` becomes `5`, which the preview beside
    // the field then spells out as `5 ml` rather than quietly storing 0,5 l.
    expect(maskVolumeInput('0,5')).toBe('5')
    expect(maskVolumeInput('0.5')).toBe('5')
    expect(maskVolumeInput('1,5')).toBe('15')
  })

  it('drops everything that is not a digit', () => {
    expect(maskVolumeInput('500 ml')).toBe('500')
    expect(maskVolumeInput('abc')).toBe('')
    expect(maskVolumeInput('-500')).toBe('500')
    expect(maskVolumeInput('')).toBe('')
  })

  it('drops leading zeros, so the text always matches the number underneath', () => {
    expect(maskVolumeInput('0500')).toBe('500')
    expect(maskVolumeInput('007')).toBe('7')
    // …but a lone zero is left alone by the mask, because there is no digit
    // after it to keep. `parseVolumeOption` is what turns it into "no size" —
    // the field is driven by the parsed number, so typing `0` shows nothing.
    expect(maskVolumeInput('0')).toBe('0')
    expect(parseVolumeOption('0')).toBeNull()
  })
})

describe('parseVolumeOption', () => {
  it('reads a chosen option as whole millilitres', () => {
    expect(parseVolumeOption('500')).toBe(500)
    expect(parseVolumeOption('330')).toBe(330)
    expect(parseVolumeOption('1000')).toBe(1000)
  })

  it('reads the empty option as "this product has no size"', () => {
    // Not 0: 0 would print as a size while meaning none, which is why the API
    // refuses it.
    expect(parseVolumeOption('')).toBeNull()
    expect(parseVolumeOption('   ')).toBeNull()
    expect(parseVolumeOption('0')).toBeNull()
  })

  it('refuses anything that is not a whole number of millilitres', () => {
    // `parseInt` would answer 500 for '500.5' and 5 for '5abc', and an API that
    // stores what it is given deserves neither.
    expect(parseVolumeOption('500.5')).toBeNull()
    expect(parseVolumeOption('0,5')).toBeNull()
    expect(parseVolumeOption('5abc')).toBeNull()
    expect(parseVolumeOption('-500')).toBeNull()
  })

  it('hands an out-of-range size on rather than swallowing it', () => {
    // The range is the page's refusal to show beside the field, not this
    // function's to hide — same rule `MoneyField` follows for a negative price.
    expect(parseVolumeOption('99999')).toBe(99999)
  })
})
