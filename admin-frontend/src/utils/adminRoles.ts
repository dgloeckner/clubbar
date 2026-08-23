/**
 * Which sections of the panel each admin role may open (ADR-0044, #516).
 *
 * The client half of the backend's `RouteRoleMap`, and deliberately a
 * *separate* table rather than a copy of it: the server maps API routes, this
 * maps SPA sections, and one page fans out to several endpoints. Hiding
 * navigation is not enforcement — the server refuses independently on every
 * request — so what this table has to get right is that a role is never shown
 * a door that answers 403 behind it.
 *
 * Two rules, both inherited from the server side:
 *
 * 1. **Default-deny.** A path with no entry is `admin`-only, so a section
 *    added without a classification is invisible to the lesser roles until
 *    somebody grants it in a diff a reviewer sees.
 * 2. **Grants are additive.** Holding several roles is the union of them;
 *    there are no deny rules.
 *
 * `/settings` was `admin`-only for exactly this reason: the page is one screen
 * carrying mail configuration, terminal tokens, encryption keys and admin
 * accounts, all `admin`-only on the server, so opening the section would have
 * shown a Kassenwart six doors that answer 403. Splitting the treasurer's slice
 * out was called a page change rather than a grant change, and ADR-0047 made
 * it due: the club's credit ceiling is the Kassenwart's own setting.
 *
 * That split is {@link SETTINGS_TAB_ROLES} below. The section is TREASURY now,
 * and the boundary moved one level down rather than disappearing — same two
 * rules, in a table one screen further in.
 */

import { AdminRole } from '../api/generated/adminRole'

const ADMIN_ONLY: AdminRole[] = ['admin']
const TREASURY: AdminRole[] = ['admin', 'kassenwart']
const BAR: AdminRole[] = ['admin', 'getraenkewart']
const EVERY_ROLE: AdminRole[] = ['admin', 'kassenwart', 'getraenkewart']

/** Every role this build knows, in the canonical order the API reports them. */
const KNOWN_ROLES: AdminRole[] = EVERY_ROLE

/**
 * Section path → the roles that may open it. Sub-routes inherit their parent
 * (`/members/excluded` is a tab of Members), so only section roots appear.
 */
export const SECTION_ROLES: Record<string, AdminRole[]> = {
  '/dashboard': TREASURY,
  '/members': TREASURY,
  '/products': BAR,
  '/journal': TREASURY,
  '/settlements': TREASURY,
  '/reports': EVERY_ROLE,
  '/notifications': TREASURY,
  '/settings': TREASURY,
  '/audit-log': ADMIN_ONLY,
  '/profile': EVERY_ROLE,
}

/**
 * Settings tab → the roles that may see it (ADR-0047, #562).
 *
 * The second half of opening `/settings` to the treasury. `SECTION_ROLES` says
 * who reaches the page; this says what they find on it, under the same rules:
 * **default-deny**, so a tab added without an entry is `admin`-only until
 * somebody grants it in a diff a reviewer sees, and **additive**, so holding
 * several roles is the union.
 *
 * The Kassenwart gets Limits alone. SEPA is the near miss worth naming:
 * `GET /sepa-config` is TREASURY, but `PATCH` is `admin`-only, so showing that
 * tab means building a read-only mode for it — a non-goal of the epic rather
 * than something half-done here.
 *
 * Keys are the `data-testid` suffixes the page renders, which is what lets a
 * unit test check that the two lists still describe the same set of tabs.
 */
export const SETTINGS_TAB_ROLES: Record<string, AdminRole[]> = {
  'admin-users': ADMIN_ONLY,
  sepa: ADMIN_ONLY,
  terminals: ADMIN_ONLY,
  security: ADMIN_ONLY,
  credentials: ADMIN_ONLY,
  instance: ADMIN_ONLY,
  mail: ADMIN_ONLY,
  limits: TREASURY,
}

/** Whether `held` may see one Settings tab — false for a tab with no entry. */
export function maySeeSettingsTab(held: AdminRole[], tab: string): boolean {
  const allowed = SETTINGS_TAB_ROLES[tab]

  return allowed ? held.some((role) => allowed.includes(role)) : false
}

/** The Settings tabs `held` may see, in the order the page renders them. */
export function settingsTabsFor(held: AdminRole[]): string[] {
  return Object.keys(SETTINGS_TAB_ROLES).filter((tab) => maySeeSettingsTab(held, tab))
}

/**
 * The tab a caller lands on: the first one they may actually open, rather than
 * a hardcoded default that answers 403 for everybody but `admin`.
 */
export function firstSettingsTab(held: AdminRole[]): string | undefined {
  return settingsTabsFor(held)[0]
}

/**
 * Where each role lands when it arrives at `/` or at a page it may not open.
 * Ordered by preference; the first entry the caller may actually open wins.
 */
const LANDING_CANDIDATES = ['/dashboard', '/products', '/reports', '/profile']

/**
 * The roles in an API payload, keeping only what this build understands.
 *
 * A role name a newer server invented grants nothing here rather than being
 * carried around as a string no comparison matches.
 */
export function parseRoles(value: unknown): AdminRole[] {
  if (!Array.isArray(value)) return []

  return KNOWN_ROLES.filter((role) => value.includes(role))
}

/** The roles allowed on a path — `admin` alone when it has no entry. */
export function rolesForPath(pathname: string): AdminRole[] {
  const section = Object.keys(SECTION_ROLES).find(
    (path) => pathname === path || pathname.startsWith(`${path}/`)
  )

  return section ? SECTION_ROLES[section] : ADMIN_ONLY
}

/**
 * Apply one checkbox click to a role set, keeping it a valid combination.
 *
 * Admin-exclusivity (CONTEXT.md's Role entry): a role set is either `admin`
 * alone, or a non-empty combination of the two lesser roles. Checking `admin`
 * therefore replaces whatever lesser roles were selected, and checking a
 * lesser role drops `admin` if it was set — there is no state a sequence of
 * clicks can reach that mixes the two.
 */
export function toggleRole(current: AdminRole[], role: AdminRole): AdminRole[] {
  if (role === 'admin') {
    return current.includes('admin') ? [] : ['admin']
  }

  const withoutAdmin = current.filter((r) => r !== 'admin')

  return withoutAdmin.includes(role)
    ? withoutAdmin.filter((r) => r !== role)
    : [...withoutAdmin, role]
}

/** Whether two role sets are the same, regardless of order. */
export function sameRoleSet(a: AdminRole[], b: AdminRole[]): boolean {
  if (a.length !== b.length) return false

  const sorted = (roles: AdminRole[]) => [...roles].sort()

  return sorted(a).every((role, i) => role === sorted(b)[i])
}

/** Whether an account holding `held` may open `pathname`. */
export function permitsPath(held: AdminRole[], pathname: string): boolean {
  const allowed = rolesForPath(pathname)

  return held.some((role) => allowed.includes(role))
}

/**
 * The page to open for a caller holding `held`.
 *
 * An account holding no role reaches nothing, so there is no page to send it
 * to: it gets the first candidate, whose guard names the refusal. Bouncing it
 * through further redirects would only hide why nothing works.
 */
export function landingPath(held: AdminRole[]): string {
  return LANDING_CANDIDATES.find((path) => permitsPath(held, path)) ?? LANDING_CANDIDATES[0]
}
