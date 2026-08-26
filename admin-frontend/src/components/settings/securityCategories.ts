/**
 * How security findings are grouped on the page, and in what order.
 *
 * Its own module rather than component internals because four artifacts have
 * to agree on this list — `api/admin.yaml`'s enum, `SecuritySelfCheck`'s
 * `CATEGORY_*` constants, this order and the locale headings — and a shared
 * list is easier to test than a component's private one.
 */

import type { SecurityFinding } from '../../api/generated'

/**
 * The order categories are shown in, outward-facing concerns first.
 *
 * Not a filter. A category the backend reports that is missing here is
 * appended rather than dropped — see {@link orderedCategories}.
 */
export const CATEGORY_ORDER = [
  'exposure',
  'data',
  'backup',
  'transport',
  'delivery',
  'session',
  'runtime',
] as const

/**
 * Every category present in the report: known ones in `CATEGORY_ORDER`'s order,
 * anything else after them.
 *
 * The previous version filtered `CATEGORY_ORDER` by what the report contained,
 * which reads the same and is not: a category the backend added and this list
 * had not heard of vanished from the page entirely. `delivery` (#406) did
 * exactly that — the mail-delivery rows were measured, returned by the API and
 * never rendered.
 *
 * The whole point of this report is that a protection which stopped working
 * says so, so a page that silently shows *less* than was measured is worse than
 * no page. Unstyled and at the bottom is survivable; invisible is not.
 */
export function orderedCategories(findings: SecurityFinding[]): string[] {
  const present = [...new Set(findings.map((finding) => finding.category))]
  const known = CATEGORY_ORDER.filter((category) => present.includes(category))
  const unknown = present.filter((category) => !CATEGORY_ORDER.includes(category as never))

  return [...known, ...unknown]
}
