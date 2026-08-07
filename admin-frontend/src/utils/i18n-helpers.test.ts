import { describe, expect, it } from 'vitest'
import {
  getIntlLocale,
  getLocalizedName,
  hasAnyName,
} from './i18n-helpers'

describe('getLocalizedName', () => {
  const names = { de: 'Bier', en: 'Beer' }

  it('returns the requested locale when available', () => {
    expect(getLocalizedName(names, 'en')).toBe('Beer')
    expect(getLocalizedName(names, 'de')).toBe('Bier')
  })

  it('falls back to German when the requested locale is missing', () => {
    expect(getLocalizedName(names, 'fr')).toBe('Bier')
  })

  it('falls back to English when German is missing too', () => {
    expect(getLocalizedName({ en: 'Beer' }, 'fr')).toBe('Beer')
  })

  it('falls back to the first available translation as a last resort', () => {
    expect(getLocalizedName({ fr: 'Bière' }, 'de')).toBe('Bière')
  })

  it('returns an empty string when there are no names', () => {
    expect(getLocalizedName({}, 'de')).toBe('')
    expect(getLocalizedName(null, 'de')).toBe('')
    expect(getLocalizedName(undefined, 'de')).toBe('')
  })

  it('skips empty-string entries in the fallback chain', () => {
    // de exists but is empty — falls through to en
    expect(getLocalizedName({ de: '', en: 'Beer' }, 'de')).toBe('Beer')
  })
})

describe('hasAnyName', () => {
  it('is true when at least one language has a non-empty value', () => {
    expect(hasAnyName({ de: 'Bier', en: '' })).toBe(true)
  })

  it('is false for empty and whitespace-only values', () => {
    expect(hasAnyName({ de: '', en: '   ' })).toBe(false)
  })

  it('is false for empty, null, or missing objects', () => {
    expect(hasAnyName({})).toBe(false)
    expect(hasAnyName(null)).toBe(false)
    expect(hasAnyName(undefined)).toBe(false)
  })
})

describe('getIntlLocale', () => {
  it('maps language codes to Intl locales', () => {
    expect(getIntlLocale('de')).toBe('de-DE')
    expect(getIntlLocale('en')).toBe('en-GB')
  })

  it('defaults unknown languages to de-DE', () => {
    expect(getIntlLocale('fr')).toBe('de-DE')
    expect(getIntlLocale('')).toBe('de-DE')
  })
})
