import { describe, expect, it } from 'vitest'
import {
  parseVolumeOption,
  volumeOptionsFor,
  VOLUME_MAX_ML,
  VOLUME_MIN_ML,
  VOLUME_PRESETS_ML,
} from './volume'

/**
 * The sizes the product form offers, and what a chosen one becomes (ADR-0056).
 *
 * The size used to be typed in litres, which put a locale-aware mask between
 * the admin and the column. Picking from a list removes that problem and
 * introduces exactly one of its own: a product saved before the list existed
 * can hold a size the list does not contain, and the picker must not quietly
 * drop it. That is what most of these tests are about.
 */

describe('VOLUME_PRESETS_ML', () => {
  it('offers the sizes a club pours, largest first', () => {
    expect([...VOLUME_PRESETS_ML]).toEqual([1000, 500, 330, 250, 200])
  })

  it('offers only sizes the API will take', () => {
    for (const millilitres of VOLUME_PRESETS_ML) {
      expect(Number.isInteger(millilitres)).toBe(true)
      expect(millilitres).toBeGreaterThanOrEqual(VOLUME_MIN_ML)
      expect(millilitres).toBeLessThanOrEqual(VOLUME_MAX_ML)
    }
  })
})

describe('volumeOptionsFor', () => {
  it('offers the presets for a product with no size, and for one that has a listed size', () => {
    expect(volumeOptionsFor(null)).toEqual([1000, 500, 330, 250, 200])
    expect(volumeOptionsFor(undefined)).toEqual([1000, 500, 330, 250, 200])
    expect(volumeOptionsFor(500)).toEqual([1000, 500, 330, 250, 200])
  })

  it('keeps a size the list does not contain, in its place among them', () => {
    // The failure this prevents: a product saved when sizes were typed holds
    // 750 ml, the picker shows the presets only, the select falls back to the
    // empty option — and the next save of an unrelated field clears a column
    // nobody touched.
    expect(volumeOptionsFor(750)).toEqual([1000, 750, 500, 330, 250, 200])
    expect(volumeOptionsFor(300)).toEqual([1000, 500, 330, 300, 250, 200])
  })

  it('puts a size larger than any preset first and a smaller one last', () => {
    expect(volumeOptionsFor(5000)).toEqual([5000, 1000, 500, 330, 250, 200])
    expect(volumeOptionsFor(20)).toEqual([1000, 500, 330, 250, 200, 20])
  })

  it('never repeats a size', () => {
    for (const preset of VOLUME_PRESETS_ML) {
      const options = volumeOptionsFor(preset)
      expect(new Set(options).size).toBe(options.length)
    }
  })

  it('leaves the preset list itself alone', () => {
    volumeOptionsFor(750)
    expect([...VOLUME_PRESETS_ML]).toEqual([1000, 500, 330, 250, 200])
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
    // Unreachable through the select, and cheap insurance against a value that
    // did not come from one — `parseInt` would answer 500 for '500.5' and 5 for
    // '5abc', and an API that stores what it is given deserves neither.
    expect(parseVolumeOption('500.5')).toBeNull()
    expect(parseVolumeOption('0,5')).toBeNull()
    expect(parseVolumeOption('5abc')).toBeNull()
    expect(parseVolumeOption('-500')).toBeNull()
  })
})
