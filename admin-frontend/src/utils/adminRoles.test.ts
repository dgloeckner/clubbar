import { describe, it, expect } from 'vitest'
// Imported as text (Vite's `?raw`) rather than through Node's fs, so the test
// needs no filesystem types and breaks loudly if a file is ever renamed.
import mainLayoutSource from '../components/layout/MainLayout.tsx?raw'
import bottomTabBarSource from '../components/layout/BottomTabBar.tsx?raw'
import {
  parseRoles,
  rolesForPath,
  permitsPath,
  landingPath,
  SECTION_ROLES,
} from './adminRoles'

describe('parseRoles', () => {
  it('keeps the roles the API knows about', () => {
    expect(parseRoles(['admin'])).toEqual(['admin'])
    expect(parseRoles(['kassenwart', 'getraenkewart'])).toEqual(['kassenwart', 'getraenkewart'])
  })

  // Fail closed: a role this build has never heard of grants nothing, rather
  // than being carried around as a string that no comparison matches but that
  // makes `roles.length` look reassuring.
  it('drops anything that is not a role', () => {
    expect(parseRoles(['admin', 'vorstand', 42, null])).toEqual(['admin'])
  })

  it('answers with an empty list for a missing or malformed field', () => {
    expect(parseRoles(undefined)).toEqual([])
    expect(parseRoles(null)).toEqual([])
    expect(parseRoles('admin')).toEqual([])
  })

  it('deduplicates', () => {
    expect(parseRoles(['admin', 'admin'])).toEqual(['admin'])
  })
})

describe('rolesForPath', () => {
  it('classifies a section by its own entry', () => {
    expect(rolesForPath('/products')).toEqual(SECTION_ROLES['/products'])
  })

  // The sub-route rule the sidebar already follows: /members/excluded is a tab
  // of Members and inherits its grant rather than falling through to the
  // default.
  it('gives a sub-route its parent section grant', () => {
    expect(rolesForPath('/members/excluded')).toEqual(SECTION_ROLES['/members'])
    expect(rolesForPath('/products/categories')).toEqual(SECTION_ROLES['/products'])
    expect(rolesForPath('/settlements/new')).toEqual(SECTION_ROLES['/settlements'])
  })

  // Default-deny, the frontend half of RouteRoleMap's first rule. A page added
  // without a classification is invisible and unreachable for the lesser
  // roles until somebody grants it deliberately.
  it('treats an unclassified path as admin-only', () => {
    expect(rolesForPath('/some-new-page')).toEqual(['admin'])
    expect(rolesForPath('/')).toEqual(['admin'])
  })

  // A prefix must end at a path segment: /productsomething is not a sub-route
  // of /products.
  it('does not match a section on a partial segment', () => {
    expect(rolesForPath('/productsomething')).toEqual(['admin'])
  })
})

describe('permitsPath', () => {
  it('lets admin reach every section', () => {
    for (const path of Object.keys(SECTION_ROLES)) {
      expect(permitsPath(['admin'], path), path).toBe(true)
    }
  })

  it('keeps the Getränkewart on the drinks list and the shared surfaces', () => {
    expect(permitsPath(['getraenkewart'], '/products')).toBe(true)
    expect(permitsPath(['getraenkewart'], '/products/categories')).toBe(true)
    expect(permitsPath(['getraenkewart'], '/reports')).toBe(true)
    expect(permitsPath(['getraenkewart'], '/profile')).toBe(true)

    expect(permitsPath(['getraenkewart'], '/members')).toBe(false)
    expect(permitsPath(['getraenkewart'], '/dashboard')).toBe(false)
    expect(permitsPath(['getraenkewart'], '/settlements')).toBe(false)
    expect(permitsPath(['getraenkewart'], '/journal')).toBe(false)
    expect(permitsPath(['getraenkewart'], '/notifications')).toBe(false)
    expect(permitsPath(['getraenkewart'], '/settings')).toBe(false)
    expect(permitsPath(['getraenkewart'], '/audit-log')).toBe(false)
  })

  it('keeps the Kassenwart out of the operator surfaces', () => {
    expect(permitsPath(['kassenwart'], '/members')).toBe(true)
    expect(permitsPath(['kassenwart'], '/settlements')).toBe(true)
    expect(permitsPath(['kassenwart'], '/journal')).toBe(true)
    expect(permitsPath(['kassenwart'], '/notifications')).toBe(true)

    expect(permitsPath(['kassenwart'], '/settings')).toBe(false)
    expect(permitsPath(['kassenwart'], '/audit-log')).toBe(false)
    expect(permitsPath(['kassenwart'], '/products')).toBe(false)
  })

  // Grants are additive (ADR-0044 rule 2) — holding both lesser roles is the
  // union of them and still not `admin`.
  it('unions the grants of several roles held at once', () => {
    const both = ['kassenwart', 'getraenkewart'] as const
    expect(permitsPath([...both], '/members')).toBe(true)
    expect(permitsPath([...both], '/products')).toBe(true)
    expect(permitsPath([...both], '/audit-log')).toBe(false)
  })

  it('grants nothing at all to an account holding no role', () => {
    for (const path of Object.keys(SECTION_ROLES)) {
      expect(permitsPath([], path), path).toBe(false)
    }
  })
})

describe('landingPath', () => {
  it('sends the offices that can see the club position to the dashboard', () => {
    expect(landingPath(['admin'])).toBe('/dashboard')
    expect(landingPath(['kassenwart'])).toBe('/dashboard')
    expect(landingPath(['kassenwart', 'getraenkewart'])).toBe('/dashboard')
  })

  // The Getränkewart's dashboard would be a 403 — it carries named members and
  // their Deckel. Products is the page they actually came for.
  it('sends the Getränkewart to the drinks list', () => {
    expect(landingPath(['getraenkewart'])).toBe('/products')
  })

  // No role reaches nothing, so there is no landing page to choose. It lands
  // where the guard will name the refusal rather than bouncing between
  // redirects.
  it('lands a role-less account somewhere the refusal is shown', () => {
    const path = landingPath([])
    expect(permitsPath([], path)).toBe(false)
    expect(Object.keys(SECTION_ROLES)).toContain(path)
  })
})

/**
 * The completeness property, borrowed from `RouteRoleMapCompletenessTest`:
 * adding a navigation entry is what makes this fail, so a new section cannot
 * ship unclassified. Reading the source is deliberate — the alternative is
 * duplicating the nav lists here, which would then be the thing that drifts.
 */
describe('every navigable section is classified', () => {
  const navSources = {
    'MainLayout.tsx': mainLayoutSource,
    'BottomTabBar.tsx': bottomTabBarSource,
  }

  for (const [name, text] of Object.entries(navSources)) {
    it(`covers every path named in ${name}`, () => {
      const paths = [...text.matchAll(/path: '(\/[^']*)'/g)].map((m) => m[1])

      expect(paths.length).toBeGreaterThan(0)
      for (const path of paths) {
        expect(Object.keys(SECTION_ROLES), `${path} is not classified`).toContain(path)
      }
    })
  }
})
