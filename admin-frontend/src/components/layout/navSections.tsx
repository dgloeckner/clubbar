/**
 * The panel's sections, once, for every navigation surface (#782 follow-up).
 *
 * There are two navigations — `DesktopNav` in the header and `BottomTabBar` at
 * the foot of a phone — and until this table existed each kept its own literal
 * list. That is a silent failure mode rather than a duplication annoyance: the
 * self-registration inbox was added to the header list alone, so `/registrations`
 * was classified in `SECTION_ROLES`, routed, permitted, and simply **not
 * reachable on a phone at all**. Nothing failed; the entry was just nowhere.
 *
 * So the list lives here and the two surfaces render it. A section added below
 * appears on both, and `navSections.test.ts` asserts the property that the old
 * arrangement could not have: every classified section is reachable on every
 * surface that navigates.
 *
 * Three fields carry the differences the surfaces genuinely have:
 *
 * - `desktop` — `/profile` is false. Between 769px and 1500px the header
 *   reaches it through the user badge instead, so a nav entry would be a second
 *   door to the same page in a row that is already measuring itself for space.
 * - `mobile` — `'primary'` is a tab in the bar, `'more'` is the popup behind it.
 *   The bar has room for a handful of icons and the rest is one tap further in;
 *   the header has no such split, because `DesktopNav` decides its own overflow
 *   by measurement.
 * - `shortLabelKey` — the bottom bar renders labels at 9px in a shared row, so
 *   the long German compounds get an abbreviation rather than an ellipsis in
 *   the middle of a word.
 *
 * Roles are applied by the surfaces through `permitsPath`, not stored here:
 * `SECTION_ROLES` is the one table that answers who may open what (ADR-0044),
 * and a second copy of that answer is exactly what this file exists to prevent.
 */

import type { ComponentType } from 'react'
import { NavCountBadge } from './NavCountBadge'
import { permitsPath } from '../../utils/adminRoles'
import type { AdminRole } from '../../api/generated/adminRole'
import type { IconProps } from '../icons/types'
import {
  AuditLogIcon,
  DatabaseIcon,
  MailIcon,
  HomeIcon,
  UsersIcon,
  UserPlusIcon,
  PackageIcon,
  BookIcon,
  ReceiptIcon,
  ChartIcon,
  SettingsIcon,
  UserIcon,
} from '../icons'

/** Where the bottom tab bar puts a section: a tab of its own, or behind More. */
export type MobilePlacement = 'primary' | 'more'

export interface NavSection {
  /** The section root. Sub-routes inherit it, here as in `SECTION_ROLES`. */
  path: string
  /** Translation key for the full label. */
  labelKey: string
  /** Translation key the bottom bar's primary row prefers, where one exists. */
  shortLabelKey?: string
  icon: ComponentType<IconProps>
  /** Rendered as `nav-<id>` in the header and `tab-<id>` in the bottom bar. */
  id: string
  /** Whether the header nav lists it — see `/profile` in the docblock. */
  desktop: boolean
  mobile: MobilePlacement
  /**
   * The count worn by the icon. Only the registration inbox has one; the field
   * is a name rather than a number because the count is fetched by a hook and
   * this table is data.
   */
  badge?: 'pendingRegistrations'
}

export const NAV_SECTIONS: NavSection[] = [
  {
    path: '/dashboard',
    labelKey: 'nav.dashboard',
    icon: HomeIcon,
    id: 'dashboard',
    desktop: true,
    mobile: 'primary',
  },
  {
    path: '/members',
    labelKey: 'nav.members',
    icon: UsersIcon,
    id: 'members',
    desktop: true,
    mobile: 'primary',
  },
  {
    // A primary tab rather than a More entry, and the badge is the reason: the
    // inbox is work waiting on somebody, and a count nobody sees until they
    // open a menu is a count that does not do its job.
    path: '/registrations',
    labelKey: 'nav.registrations',
    shortLabelKey: 'nav.registrationsShort',
    icon: UserPlusIcon,
    id: 'registrations',
    desktop: true,
    mobile: 'primary',
    badge: 'pendingRegistrations',
  },
  {
    path: '/products',
    labelKey: 'nav.products',
    icon: PackageIcon,
    id: 'products',
    desktop: true,
    mobile: 'primary',
  },
  {
    path: '/journal',
    labelKey: 'nav.journal',
    shortLabelKey: 'nav.journalShort',
    icon: BookIcon,
    id: 'journal',
    desktop: true,
    mobile: 'primary',
  },
  {
    path: '/settlements',
    labelKey: 'nav.settlements',
    icon: ReceiptIcon,
    id: 'settlements',
    desktop: true,
    mobile: 'more',
  },
  {
    path: '/reports',
    labelKey: 'nav.reports',
    icon: ChartIcon,
    id: 'reports',
    desktop: true,
    mobile: 'more',
  },
  {
    path: '/settings',
    labelKey: 'nav.settings',
    icon: SettingsIcon,
    id: 'settings',
    desktop: true,
    mobile: 'more',
  },
  {
    path: '/notifications',
    labelKey: 'nav.notifications',
    icon: MailIcon,
    id: 'notifications',
    desktop: true,
    mobile: 'more',
  },
  {
    path: '/backups',
    labelKey: 'nav.backups',
    icon: DatabaseIcon,
    id: 'backups',
    desktop: true,
    mobile: 'more',
  },
  {
    path: '/audit-log',
    labelKey: 'nav.auditLog',
    icon: AuditLogIcon,
    id: 'audit-log',
    desktop: true,
    mobile: 'more',
  },
  {
    path: '/profile',
    labelKey: 'nav.profile',
    icon: UserIcon,
    id: 'profile',
    desktop: false,
    mobile: 'more',
  },
]

/** The sections `roles` may open, in render order. */
export function permittedSections(roles: AdminRole[] | undefined): NavSection[] {
  return NAV_SECTIONS.filter((section) => permitsPath(roles ?? [], section.path))
}

/** The header nav's entries: everything permitted that the header lists. */
export function desktopSections(roles: AdminRole[] | undefined): NavSection[] {
  return permittedSections(roles).filter((section) => section.desktop)
}

/** The bottom bar's tabs. */
export function mobilePrimarySections(roles: AdminRole[] | undefined): NavSection[] {
  return permittedSections(roles).filter((section) => section.mobile === 'primary')
}

/** The bottom bar's More popup. */
export function mobileMoreSections(roles: AdminRole[] | undefined): NavSection[] {
  return permittedSections(roles).filter((section) => section.mobile === 'more')
}

/**
 * A section's icon, wearing its badge where it has one.
 *
 * Shared so both surfaces show the same count. The badge's test id deliberately
 * does not start with `nav-`: the E2E suite enumerates sections with
 * `[data-testid^="nav-"]`, and a badge nested inside an entry would be counted
 * as a section of its own.
 */
export function renderSectionIcon(
  section: NavSection,
  size: number,
  counts: { pendingRegistrations: number }
): React.ReactNode {
  const Icon = section.icon

  if (section.badge === 'pendingRegistrations') {
    return (
      <NavCountBadge count={counts.pendingRegistrations} testId="registrations-count-badge">
        <Icon size={size} />
      </NavCountBadge>
    )
  }

  return <Icon size={size} />
}
