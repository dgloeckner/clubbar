import { describe, expect, it } from 'vitest'

import {
  SETTLEMENT_STATUSES,
  awaitsBankConfirmation,
  canExportSepa,
  settlementStatus,
  settlementStatusColor,
  settlementStatusLabelKey,
  type SettlementStatusTarget,
  type SettlementStatusValue,
} from './settlementStatus'

import de from '../../public/locales/de.json'
import en from '../../public/locales/en.json'

/**
 * The page reads the server's answer and nothing else (#377).
 *
 * The regression these tests exist for is not a wrong colour: it is the page
 * quietly re-deriving a status from `is_cancelled` and `exported_at`, which is
 * how a run that went to the bank came to look exactly like one whose file was
 * merely generated. So the load-bearing assertions are the negative ones —
 * that a row carrying every field of a submitted settlement, but no `status`,
 * yields nothing rather than a guess.
 */
describe('settlementStatus', () => {
  it('reports the status the server derived', () => {
    for (const status of SETTLEMENT_STATUSES) {
      expect(settlementStatus({ status })).toBe(status)
    }
  })

  it('refuses to guess a status the payload does not carry', () => {
    // Every ingredient of "submitted", and no `status`. The old page would
    // have called this one "exported"; deriving it here would be the same bug
    // in a new file.
    const rawColumnsOnly = {
      is_cancelled: false,
      exported_at: '2026-08-07T09:00:00Z',
      submitted_at: '2026-08-07T11:00:00Z',
    } as SettlementStatusTarget

    expect(settlementStatus(rawColumnsOnly)).toBeNull()
  })

  it('treats a status this build has never heard of as unknown', () => {
    expect(settlementStatus({ status: 'clawed_back_by_lawyers' })).toBeNull()
  })
})

describe('settlementStatusColor', () => {
  it('gives every rung of the ladder its own colour', () => {
    const colors = SETTLEMENT_STATUSES.map(settlementStatusColor)

    // Two statuses sharing a colour would make them indistinguishable at a
    // glance, which is the whole job of the badge.
    expect(new Set(colors).size).toBe(SETTLEMENT_STATUSES.length)
  })
})

describe('settlementStatusLabelKey', () => {
  it.each(['de', 'en'] as const)('has a %s label for every status, and no spare ones', (lang) => {
    const labels = (lang === 'de' ? de : en).settlements.statusLabels as Record<string, string>

    for (const status of SETTLEMENT_STATUSES) {
      const key = settlementStatusLabelKey(status)
      expect(key).toBe(`settlements.statusLabels.${status}`)
      // The key is assembled at runtime, so the literal-`t()` sweep in
      // `i18n/locales.test.ts` cannot see it. This is where it is checked.
      expect(labels[status], `${lang}: ${key} is missing`).toBeTruthy()
    }

    // A label with no status behind it is a rung that was renamed or removed
    // server-side and left a stale translation nobody will ever see.
    expect(Object.keys(labels).sort()).toEqual([...SETTLEMENT_STATUSES].sort())
  })
})

describe('awaitsBankConfirmation', () => {
  it('is true exactly for an exported direct debit', () => {
    expect(awaitsBankConfirmation({ status: 'exported', method: 'direct_debit' })).toBe(true)
  })

  it.each(SETTLEMENT_STATUSES.filter((s) => s !== 'exported'))(
    'is false for a %s run — every other rung outranks "a file exists"',
    (status: SettlementStatusValue) => {
      expect(awaitsBankConfirmation({ status, method: 'direct_debit' })).toBe(false)
    }
  )

  it.each(['bank_transfer', 'write_off'])('is false for a %s settlement — no bank is involved', (method) => {
    expect(awaitsBankConfirmation({ status: 'exported', method })).toBe(false)
  })

  it('is false when the payload carries no status at all', () => {
    expect(awaitsBankConfirmation({ method: 'direct_debit' })).toBe(false)
  })
})

describe('canExportSepa', () => {
  it.each(['draft', 'exported'] as const)('allows a %s run to be exported', (status) => {
    expect(canExportSepa({ status })).toBe(true)
  })

  it.each(['submitted', 'partly_reversed', 'fully_reversed', 'cancelled'] as const)(
    'refuses a %s run — regenerating the file is how it reaches the bank twice',
    (status) => {
      expect(canExportSepa({ status })).toBe(false)
    }
  )

  it('offers nothing for a row with no status', () => {
    expect(canExportSepa({})).toBe(false)
  })
})
