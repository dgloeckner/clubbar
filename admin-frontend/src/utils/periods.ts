/**
 * Period presets for the date-range filters on Journal and Settlements.
 *
 * The range is *derived* from the selected preset, never stored: a page seeds
 * its filter from the default preset when it mounts and recomputes it when the
 * admin picks another one. Keeping that rule here is what lets `PeriodPicker`
 * be a plain control — it announces a range when a button is clicked instead of
 * announcing it from an effect on every render, which is what made pagination
 * impossible on both pages (#89).
 */

import { toClubIsoDate } from './dates'

export type PeriodKey = '1m' | '3m' | '6m' | '1y' | '2y' | 'all'

/** Preset both filter pages start on. */
export const DEFAULT_PERIOD: PeriodKey = '3m'

/** Display order of the segmented control. */
export const PERIOD_KEYS: readonly PeriodKey[] = ['1m', '3m', '6m', '1y', '2y', 'all']

/** Days back per preset; `null` means "no lower bound". */
const PERIOD_DAYS: Record<PeriodKey, number | null> = {
  '1m': 30,
  '3m': 90,
  '6m': 180,
  '1y': 365,
  '2y': 730,
  all: null,
}

export interface PeriodRange {
  /** Undefined for the "all" preset — the API then applies no lower bound. */
  dateFrom: string | undefined
  dateTo: string
}

/**
 * The `date_from` / `date_to` pair a preset stands for, as **club** calendar
 * days — the backend resolves them against a UTC column as the club's days, so
 * building them from the reader's calendar put the window a day out for anyone
 * whose midnight is not the club's (#365).
 *
 * `today` is injectable so callers (and tests) can pin the reference day; it
 * defaults to now.
 */
export function getPeriodRange(period: PeriodKey, today: Date = new Date()): PeriodRange {
  const dateTo = toClubIsoDate(today)
  const days = PERIOD_DAYS[period]

  if (days === null) {
    return { dateFrom: undefined, dateTo }
  }

  const from = new Date(today)
  from.setDate(from.getDate() - days)

  return { dateFrom: toClubIsoDate(from), dateTo }
}
