import { describe, it, expect } from 'vitest'
// Imported as text (Vite's `?raw`) rather than through Node's fs, so the test
// needs no filesystem types and breaks loudly if a file is ever renamed.
import mainLayoutSource from '../components/layout/MainLayout.tsx?raw'
import bottomTabBarSource from '../components/layout/BottomTabBar.tsx?raw'
import settingsPageSource from '../pages/SettingsPage.tsx?raw'
import {
  parseRoles,
  rolesForPath,
  permitsPath,
  landingPath,
  toggleRole,
  sameRoleSet,
  SECTION_ROLES,
  SETTINGS_TAB_ROLES,
  settingsTabsFor,
  maySeeSettingsTab,
  firstSettingsTab,
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

    // The treasurer's own slice of Settings exists now (ADR-0046, #562): the
    // club's credit ceiling is theirs to set, so the door is open — and
    // SETTINGS_TAB_ROLES is what keeps the rest of the page shut.
    expect(permitsPath(['kassenwart'], '/settings')).toBe(true)

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
 * Admin-exclusivity (CONTEXT.md's Role entry): a role set is either `admin`
 * alone, or a non-empty combination of the two lesser roles. `toggleRole` is
 * what the checkbox group in the create/edit admin modals calls on click, so
 * the exclusivity rule has to live here rather than being re-derived per
 * checkbox handler.
 */
describe('toggleRole', () => {
  it('checking a lesser role from empty just adds it', () => {
    expect(toggleRole([], 'kassenwart')).toEqual(['kassenwart'])
  })

  it('the two lesser roles freely combine', () => {
    expect(toggleRole(['kassenwart'], 'getraenkewart')).toEqual(['kassenwart', 'getraenkewart'])
  })

  it('unchecking a lesser role removes only that one', () => {
    expect(toggleRole(['kassenwart', 'getraenkewart'], 'kassenwart')).toEqual(['getraenkewart'])
  })

  it('checking admin replaces whatever lesser roles were selected', () => {
    expect(toggleRole(['kassenwart', 'getraenkewart'], 'admin')).toEqual(['admin'])
  })

  it('checking a lesser role while admin is selected drops admin', () => {
    expect(toggleRole(['admin'], 'kassenwart')).toEqual(['kassenwart'])
  })

  it('unchecking admin clears the set — it has no lesser-role fallback', () => {
    expect(toggleRole(['admin'], 'admin')).toEqual([])
  })

  it('result never mixes admin with a lesser role, for any starting point', () => {
    const starts: Array<Array<'admin' | 'kassenwart' | 'getraenkewart'>> = [
      [],
      ['admin'],
      ['kassenwart'],
      ['getraenkewart'],
      ['kassenwart', 'getraenkewart'],
    ]
    const roles: Array<'admin' | 'kassenwart' | 'getraenkewart'> = ['admin', 'kassenwart', 'getraenkewart']

    for (const start of starts) {
      for (const role of roles) {
        const result = toggleRole(start, role)
        const hasAdmin = result.includes('admin')
        const hasLesser = result.includes('kassenwart') || result.includes('getraenkewart')
        expect(hasAdmin && hasLesser, `toggleRole(${JSON.stringify(start)}, '${role}') = ${JSON.stringify(result)}`).toBe(false)
      }
    }
  })
})

/**
 * The Edit modal's step-up gate reads this to decide whether the account's
 * roles were actually touched — order must not count as a change, since
 * `toggleRole` appends rather than re-sorting.
 */
describe('sameRoleSet', () => {
  it('is order-insensitive', () => {
    expect(sameRoleSet(['getraenkewart', 'kassenwart'], ['kassenwart', 'getraenkewart'])).toBe(true)
  })

  it('is false when the sets differ', () => {
    expect(sameRoleSet(['kassenwart'], ['kassenwart', 'getraenkewart'])).toBe(false)
  })

  it('two empty sets are the same set', () => {
    expect(sameRoleSet([], [])).toBe(true)
  })
})

/**
 * The Settings page's own table (ADR-0046, #562).
 *
 * `/settings` is one screen carrying several `admin`-only surfaces, so opening
 * the section to the treasury moves the boundary rather than removing it: this
 * table is where it now lives, under the same two rules as `SECTION_ROLES`.
 */
describe('SETTINGS_TAB_ROLES', () => {
  it('gives the Kassenwart the Limits tab and nothing else', () => {
    const visible = settingsTabsFor(['kassenwart'])

    expect(visible).toEqual(['limits'])
  })

  it('leaves every tab open to an admin', () => {
    const visible = settingsTabsFor(['admin'])

    expect(visible).toEqual(Object.keys(SETTINGS_TAB_ROLES))
    expect(visible).toContain('admin-users')
    expect(visible).toContain('sepa')
    expect(visible).toContain('mail')
  })

  /**
   * The SEPA tab stays shut for the treasurer even though `GET /sepa-config`
   * is TREASURY: `PATCH` is not, so showing the tab means building a read-only
   * mode for it. Named as a non-goal on #555 rather than half-done here.
   */
  it('keeps the Kassenwart out of the SEPA tab', () => {
    expect(settingsTabsFor(['kassenwart'])).not.toContain('sepa')
  })

  it('shows the Getränkewart nothing, because the section is shut to them', () => {
    expect(settingsTabsFor(['getraenkewart'])).toEqual([])
  })

  it('unions the tabs of several roles held at once', () => {
    expect(settingsTabsFor(['kassenwart', 'getraenkewart'])).toEqual(['limits'])
  })

  /** Default-deny: an unclassified tab is invisible to everyone but `admin`. */
  it('refuses a tab it has never heard of', () => {
    expect(maySeeSettingsTab(['kassenwart'], 'invented-later')).toBe(false)
    expect(maySeeSettingsTab(['admin'], 'invented-later')).toBe(false)
  })

  it('opens the first tab the caller may actually see', () => {
    expect(firstSettingsTab(['admin'])).toBe('admin-users')
    expect(firstSettingsTab(['kassenwart'])).toBe('limits')
  })
})

/**
 * The same completeness property the section table has: a tab rendered by the
 * page but missing from the table would be `admin`-only by default, which is
 * safe — but a tab in the table that no longer exists is a grant that reads as
 * live, and the next person to change this reasons from it.
 */
describe('every Settings tab is classified', () => {
  it('covers every tab the page renders, and names no tab it does not', () => {
    const rendered = [
      ...settingsPageSource.matchAll(/data-testid="settings-tab-([a-z-]+)"/g),
    ].map((m) => m[1])

    expect(rendered.length).toBeGreaterThan(0)
    for (const tab of rendered) {
      expect(Object.keys(SETTINGS_TAB_ROLES), `${tab} is not classified`).toContain(tab)
    }
    for (const tab of Object.keys(SETTINGS_TAB_ROLES)) {
      expect(rendered, `${tab} is classified but never rendered`).toContain(tab)
    }
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
