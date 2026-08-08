/**
 * Shared option lists for `PillFilter`.
 *
 * Only lists used by more than one page live here — a filter that exists on a
 * single page keeps its options next to that page, where the i18n keys it uses
 * are obvious.
 */

import type { PillFilterOption } from './PillFilter'

/** Translator shape these builders need — `t` from `useTranslation` satisfies it. */
type Translate = (key: string) => string

export type ActiveStatus = 'all' | 'active' | 'inactive'

/** All / Active / Inactive — the tri-state used by Categories and Products. */
export function activeStatusOptions(t: Translate): ReadonlyArray<PillFilterOption<ActiveStatus>> {
  return [
    { value: 'all', label: t('common.all') },
    { value: 'active', label: t('common.active') },
    { value: 'inactive', label: t('common.inactive') },
  ]
}
