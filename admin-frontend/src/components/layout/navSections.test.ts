import { describe, it, expect } from 'vitest'
// Imported as text (Vite's `?raw`) rather than through Node's fs, so the test
// needs no filesystem types and breaks loudly if a file is ever renamed.
import mainLayoutSource from './MainLayout.tsx?raw'
import bottomTabBarSource from './BottomTabBar.tsx?raw'
import de from '../../../public/locales/de.json'
import en from '../../../public/locales/en.json'
import {
  NAV_SECTIONS,
  desktopSections,
  mobilePrimarySections,
  mobileMoreSections,
  permittedSections,
} from './navSections'
import { SECTION_ROLES } from '../../utils/adminRoles'
import type { AdminRole } from '../../api/generated/model/adminRole'

const ALL_ROLES: AdminRole[][] = [['admin'], ['kassenwart'], ['getraenkewart'], ['kassenwart', 'getraenkewart']]

describe('NAV_SECTIONS', () => {
  it('has a unique path and a unique id per section', () => {
    expect(new Set(NAV_SECTIONS.map((s) => s.path)).size).toBe(NAV_SECTIONS.length)
    expect(new Set(NAV_SECTIONS.map((s) => s.id)).size).toBe(NAV_SECTIONS.length)
  })

  /**
   * The property #782 shipped without: a section can be classified, routed and
   * permitted, and still be listed by no navigation. That is invisible on a
   * desktop, where the header nav happened to have it, and total on a phone,
   * where the bar is the only way in.
   */
  it('reaches every classified section from the bottom tab bar', () => {
    const mobilePaths = NAV_SECTIONS.map((section) => section.path)

    for (const path of Object.keys(SECTION_ROLES)) {
      expect(mobilePaths, `${path} is unreachable on mobile`).toContain(path)
    }
  })

  // /profile is the one deliberate exception, and the header reaches it through
  // the user badge instead — asserting the exception by name is what keeps a
  // second one from being added silently.
  it('lists every section in the header nav except the profile', () => {
    const missing = NAV_SECTIONS.filter((section) => !section.desktop).map((s) => s.path)

    expect(missing).toEqual(['/profile'])
  })

  it('has a translation for every label it names, in both languages', () => {
    const lookup = (bundle: Record<string, unknown>, key: string) =>
      key.split('.').reduce<unknown>((node, part) => (node as Record<string, unknown>)?.[part], bundle)

    for (const section of NAV_SECTIONS) {
      for (const key of [section.labelKey, section.shortLabelKey].filter(Boolean) as string[]) {
        expect(lookup(de, key), `${key} missing from de.json`).toBeTruthy()
        expect(lookup(en, key), `${key} missing from en.json`).toBeTruthy()
      }
    }
  })
})

/**
 * The surfaces render this table and nothing else. A literal `path: '/…'` back
 * in either component would be a second list, which is exactly the arrangement
 * that lost `/registrations` on mobile.
 */
describe('the navigation components hold no list of their own', () => {
  for (const [name, source] of Object.entries({
    'MainLayout.tsx': mainLayoutSource,
    'BottomTabBar.tsx': bottomTabBarSource,
  })) {
    it(`${name} names no section path`, () => {
      expect([...source.matchAll(/path: '(\/[^']*)'/g)].map((m) => m[1])).toEqual([])
    })
  }
})

describe('role filtering', () => {
  it('shows a section only to the roles that may open it', () => {
    for (const roles of ALL_ROLES) {
      for (const section of permittedSections(roles)) {
        expect(
          SECTION_ROLES[section.path].some((role) => roles.includes(role)),
          `${section.path} shown to ${roles.join('+')}`
        ).toBe(true)
      }
    }
  })

  it('keeps the registration inbox for the treasury offices only', () => {
    const paths = (roles: AdminRole[]) => mobilePrimarySections(roles).map((s) => s.path)

    expect(paths(['admin'])).toContain('/registrations')
    expect(paths(['kassenwart'])).toContain('/registrations')
    expect(paths(['getraenkewart'])).not.toContain('/registrations')
  })

  // Every permitted section is in the bar or in its More popup, never in
  // neither: the split is a layout decision, not a second filter.
  it('splits the permitted sections between the bar and its More popup', () => {
    for (const roles of ALL_ROLES) {
      const split = [...mobilePrimarySections(roles), ...mobileMoreSections(roles)].map((s) => s.path)

      expect(split.sort()).toEqual(permittedSections(roles).map((s) => s.path).sort())
    }
  })

  it('gives every role a header nav and a tab bar with something in them', () => {
    for (const roles of ALL_ROLES) {
      expect(desktopSections(roles).length, roles.join('+')).toBeGreaterThan(0)
      expect(mobilePrimarySections(roles).length, roles.join('+')).toBeGreaterThan(0)
    }
  })

  it('shows nothing at all to an account holding no role', () => {
    expect(permittedSections([])).toEqual([])
    expect(permittedSections(undefined)).toEqual([])
  })
})
