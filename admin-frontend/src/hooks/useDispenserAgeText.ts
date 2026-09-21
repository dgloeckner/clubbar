/**
 * How old a dispenser report is, written for the reader's language (#954).
 *
 * Its own file rather than a second export from `TerminalDispenserCell`: the
 * cell and the detail panel both need it, and a component module that also
 * exports a hook breaks Fast Refresh.
 *
 * The unit and the number come from {@link durationParts}, so the same helper
 * writes the age of a report and the controller's uptime — and so the
 * boundaries can be tested without a locale.
 */

import { useTranslation } from 'react-i18next'

import type { DispenserAge } from '../utils/dispenserStatus'

const AGE_KEY: Record<DispenserAge['unit'], string> = {
  now: 'settings.terminalDispenserAgeNow',
  minutes: 'settings.terminalDispenserAgeMinutes',
  hours: 'settings.terminalDispenserAgeHours',
  days: 'settings.terminalDispenserAgeDays',
}

export function useDispenserAgeText(): (age: DispenserAge) => string {
  const { t } = useTranslation()

  return (age) => (age.unit === 'now' ? t(AGE_KEY.now) : t(AGE_KEY[age.unit], { value: age.value }))
}
