/**
 * Every security-finding category the API can return has somewhere to go.
 *
 * The security report exists so that a protection which stopped working says
 * so. Its worst failure mode is therefore not a wrong row — it is a **missing**
 * one, which reads exactly like "nothing to report".
 *
 * That happened. `SecurityCheckTab` rendered `CATEGORY_ORDER.filter(present)`,
 * so a category the backend reported and this list had not heard of was dropped
 * from the page. `delivery` (#406) did precisely that: the mail-delivery rows
 * were measured, returned by the API, and never shown to anybody. The component
 * now appends unknown categories instead, and these tests keep the four places
 * that name categories from drifting apart in the first place:
 *
 * - `api/admin.yaml`, via the generated `SecurityFindingCategory`
 * - `SecuritySelfCheck`'s `CATEGORY_*` constants (asserted in the PHP suite)
 * - this component's `CATEGORY_ORDER`
 * - the locale files, which give each one a heading
 *
 * Part of #710, epic #686.
 */

import { describe, expect, it } from 'vitest'
import { SecurityFindingCategory } from '../../api/generated'
import de from '../../../public/locales/de.json'
import en from '../../../public/locales/en.json'
import { CATEGORY_ORDER, orderedCategories } from './securityCategories'

const ALL = Object.values(SecurityFindingCategory)

describe('security finding categories', () => {
  it('are all placed in the render order', () => {
    expect([...CATEGORY_ORDER].sort()).toEqual([...ALL].sort())
  })

  it.each([
    ['de', de],
    ['en', en],
  ])('all have a heading in %s', (_locale, messages) => {
    const headings = messages.settings.security.categories as Record<string, string>

    for (const category of ALL) {
      expect(headings[category], `no heading for "${category}"`).toBeTruthy()
    }
  })
})

describe('orderedCategories', () => {
  const finding = (category: string) => ({ category }) as never

  it('returns only the categories the report actually contains', () => {
    expect(orderedCategories([finding('runtime'), finding('backup')])).toEqual(['backup', 'runtime'])
  })

  it('follows CATEGORY_ORDER rather than the order findings arrive in', () => {
    const ordered = orderedCategories([finding('runtime'), finding('exposure'), finding('data')])

    expect(ordered).toEqual(['exposure', 'data', 'runtime'])
  })

  it('deduplicates a category several findings share', () => {
    expect(orderedCategories([finding('data'), finding('data'), finding('data')])).toEqual(['data'])
  })

  /**
   * **The regression this file exists for.** A backend that starts reporting a
   * category the frontend has not been taught about must still show the rows —
   * unstyled and at the bottom is survivable, invisible is not.
   */
  it('appends a category it has never heard of rather than dropping it', () => {
    const ordered = orderedCategories([finding('runtime'), finding('quantum')])

    expect(ordered).toContain('quantum')
    expect(ordered).toEqual(['runtime', 'quantum'])
  })
})
