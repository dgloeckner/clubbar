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
 * `/settings` is `admin`-only even though a Kassenwart may *read* the SEPA
 * configuration: the page is one screen carrying mail configuration, terminal
 * tokens, encryption keys and admin accounts, all of which are `admin`-only on
 * the server. Splitting the treasurer's slice of it out is a page change, not
 * a grant change, and until that happens the honest answer is to hide it.
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
  '/settings': ADMIN_ONLY,
  '/audit-log': ADMIN_ONLY,
  '/profile': EVERY_ROLE,
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
