import { describe, expect, it } from 'vitest'
import {
  getCanonicalIconNames,
  getProductIcon,
  isCanonicalIconName,
} from './productIcons'
import { PackageIcon } from '../components/icons/PackageIcon'

describe('getProductIcon', () => {
  it('resolves a canonical icon name to a component', () => {
    expect(getProductIcon('beer-pils')).not.toBe(PackageIcon)
  })

  it('is case-insensitive', () => {
    expect(getProductIcon('Beer-Pils')).toBe(getProductIcon('beer-pils'))
  })

  it('falls back to the package icon for unknown names', () => {
    expect(getProductIcon('does-not-exist')).toBe(PackageIcon)
  })

  it('falls back to the package icon for null and undefined', () => {
    expect(getProductIcon(null)).toBe(PackageIcon)
    expect(getProductIcon(undefined)).toBe(PackageIcon)
    expect(getProductIcon('')).toBe(PackageIcon)
  })
})

describe('getCanonicalIconNames', () => {
  it('lists the canonical kebab-case names from the icon registry', () => {
    const names = getCanonicalIconNames()
    expect(names).toContain('beer-pils')
    expect(names).toContain('sauna-session')
    // Every listed name must resolve to a real (non-fallback) icon.
    for (const name of names) {
      expect(getProductIcon(name)).not.toBe(PackageIcon)
    }
  })
})

describe('isCanonicalIconName', () => {
  it('accepts canonical names regardless of case', () => {
    expect(isCanonicalIconName('beer-pils')).toBe(true)
    expect(isCanonicalIconName('BEER-PILS')).toBe(true)
  })

  it('rejects unknown and legacy names', () => {
    expect(isCanonicalIconName('does-not-exist')).toBe(false)
    expect(isCanonicalIconName('BeerIcon')).toBe(false)
  })
})
