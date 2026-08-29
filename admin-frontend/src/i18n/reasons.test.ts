/**
 * Every business-rule refusal has words in every language.
 *
 * The backend answers a refused rule with an English sentence *and* a
 * `BusinessRuleReason` code; the panel shows `errors.reasons.<code>` from its
 * own locale file. A code with no entry falls back to the caller's generic
 * message ("Cannot anonymize member"), which is translated but says nothing —
 * the admin loses the one thing the refusal was for: *why*.
 *
 * So the enum is the source of truth and this test reads it directly. Adding a
 * case without wording, in either language, fails the unit suite rather than
 * review — the same guarantee `adminRoles` gives an unclassified nav entry.
 */

import { describe, expect, it } from 'vitest'

import de from '../../public/locales/de.json'
import en from '../../public/locales/en.json'

const ENUM_SOURCE: Record<string, string> = import.meta.glob(
  '../../../backend/src/Shared/Exceptions/BusinessRuleReason.php',
  { query: '?raw', import: 'default', eager: true },
)

/** The `case NAME = 'value';` lines of the PHP enum. */
function reasonCodes(): string[] {
  const source = Object.values(ENUM_SOURCE)[0]
  expect(source, 'BusinessRuleReason.php not found').toBeTruthy()
  return [...source.matchAll(/case\s+\w+\s*=\s*'([a-z0-9_]+)'\s*;/g)].map((m) => m[1])
}

const REASONS = { de: de.errors.reasons as Record<string, string>, en: en.errors.reasons as Record<string, string> }

/** `{{name}}` placeholders a sentence interpolates, in the order they appear. */
function placeholders(text: string): string[] {
  return [...text.matchAll(/\{\{\s*(\w+)\s*\}\}/g)].map((m) => m[1]).sort()
}

describe('business rule reasons', () => {
  const codes = reasonCodes()

  it('finds the enum cases to check', () => {
    expect(codes.length).toBeGreaterThan(20)
    expect(codes).toContain('member_balance_outstanding')
  })

  for (const lang of ['de', 'en'] as const) {
    it(`has wording for every reason in ${lang}`, () => {
      const missing = codes.filter((code) => !REASONS[lang][code]?.trim())
      expect(missing).toEqual([])
    })
  }

  it('has no wording for a reason the backend cannot send', () => {
    // An orphan is a code that was renamed or retired without its strings —
    // harmless on screen, and the reason the next reader trusts neither list.
    const known = new Set(codes)
    expect(Object.keys(REASONS.en).filter((key) => !known.has(key))).toEqual([])
  })

  it('interpolates the same values in both languages', () => {
    // A placeholder present in one language and not the other means one of the
    // two sentences silently drops the number the admin needed.
    for (const code of codes) {
      expect(placeholders(REASONS.de[code]), code).toEqual(placeholders(REASONS.en[code]))
    }
  })

  it('never interpolates a raw cents value', () => {
    // `{{balance_cents}}` would print 750. The hook derives `{{balance}}` from
    // it, formatted in the reader's locale — that is the one to interpolate.
    for (const lang of ['de', 'en'] as const) {
      for (const [code, text] of Object.entries(REASONS[lang])) {
        expect(placeholders(text).filter((p) => p.endsWith('_cents')), `${lang}: ${code}`).toEqual([])
      }
    }
  })
})
