import { describe, expect, it } from 'vitest'

import {
  encryptionKeyMessage,
  sepaConfigMessage,
  type AlertMessage,
} from './dashboardAlerts'

import de from '../../public/locales/de.json'
import en from '../../public/locales/en.json'

/**
 * The regression these tests exist for (#741) is not a wrong sentence: it is a
 * *right* sentence in the wrong language. The dashboard printed the backend's
 * English `message` verbatim, so the load-bearing assertions here are that
 * every state yields a locale key, and that every one of those keys is
 * actually present in both locale files — a key that resolves in `en` and not
 * in `de` reproduces the bug with an extra step.
 */

type Tree = { [key: string]: string | Tree }

/** The leaf at a dotted key, or undefined — i18next's own lookup, minus i18next. */
function lookup(tree: Tree, key: string): string | undefined {
  const value = key.split('.').reduce<string | Tree | undefined>(
    (node, part) => (typeof node === 'object' && node !== null ? node[part] : undefined),
    tree,
  )
  return typeof value === 'string' ? value : undefined
}

/** i18next resolves `foo` from `foo_one`/`foo_other` when a count is passed. */
function resolves(tree: Tree, message: AlertMessage): boolean {
  if (lookup(tree, message.key) !== undefined) return true
  return (
    lookup(tree, `${message.key}_one`) !== undefined &&
    lookup(tree, `${message.key}_other`) !== undefined
  )
}

function expectTranslated(message: AlertMessage) {
  expect(resolves(en as Tree, message), `${message.key} missing from en.json`).toBe(true)
  expect(resolves(de as Tree, message), `${message.key} missing from de.json`).toBe(true)
}

describe('encryptionKeyMessage', () => {
  it('names a missing key without pretending it has an identifier', () => {
    const message = encryptionKeyMessage({ state: 'missing', key_identifier: null })

    expect(message.key).toBe('dashboard.encryptionKeyMissing')
    expect(message.values).toBeUndefined()
    expectTranslated(message)
  })

  it('reads an expired key as its own sentence', () => {
    const message = encryptionKeyMessage({
      state: 'expired',
      key_identifier: 'club-key-2026',
      days_until_expiry: -3,
    })

    expect(message.key).toBe('dashboard.encryptionKeyExpired')
    expect(message.values).toEqual({ key: 'club-key-2026' })
    expectTranslated(message)
  })

  it('counts down through every advance-warning tier', () => {
    // info/warning/critical differ by how loud the banner is, not by what it
    // says, so one string covers all three.
    for (const [state, days] of [['info', 80], ['warning', 20], ['critical', 5]] as const) {
      const message = encryptionKeyMessage({
        state,
        key_identifier: 'club-key-2026',
        days_until_expiry: days,
      })

      expect(message.key).toBe('dashboard.encryptionKeyExpiring')
      expect(message.values).toEqual({ key: 'club-key-2026', count: days })
      expectTranslated(message)
    }
  })

  it('still says something true when the payload carries no state at all', () => {
    const message = encryptionKeyMessage({})

    expect(message.values).toEqual({ key: '', count: 0 })
    expectTranslated(message)
  })
})

describe('sepaConfigMessage', () => {
  it('distinguishes the two halves of the SEPA setup', () => {
    const creditor = sepaConfigMessage({ state: 'creditor_missing' })
    const mandate = sepaConfigMessage({ state: 'mandate_template_missing' })

    expect(creditor.key).toBe('dashboard.sepaConfigCreditorMissing')
    expect(mandate.key).toBe('dashboard.sepaConfigMandateMissing')
    expect(creditor.key).not.toBe(mandate.key)
    expectTranslated(creditor)
    expectTranslated(mandate)
  })

  it('falls back to the creditor wording for a state it does not recognise', () => {
    // Not to the backend's English `message` — that is the bug, arriving
    // quietly. A club with nothing configured is the honest default.
    expect(sepaConfigMessage({}).key).toBe('dashboard.sepaConfigCreditorMissing')
    expect(sepaConfigMessage({ state: 'something_new' }).key).toBe(
      'dashboard.sepaConfigCreditorMissing',
    )
  })
})

describe('the strings themselves', () => {
  it('interpolate exactly the placeholders the mapping supplies', () => {
    const cases: AlertMessage[] = [
      encryptionKeyMessage({ state: 'missing' }),
      encryptionKeyMessage({ state: 'expired', key_identifier: 'k' }),
      encryptionKeyMessage({ state: 'warning', key_identifier: 'k', days_until_expiry: 20 }),
      sepaConfigMessage({ state: 'creditor_missing' }),
      sepaConfigMessage({ state: 'mandate_template_missing' }),
    ]

    for (const message of cases) {
      const supplied = new Set(Object.keys(message.values ?? {}))
      for (const [lang, tree] of [['en', en], ['de', de]] as const) {
        for (const suffix of ['', '_one', '_other']) {
          const text = lookup(tree as Tree, message.key + suffix)
          if (text === undefined) continue
          for (const [, name] of text.matchAll(/\{\{(\w+)\}\}/g)) {
            expect(supplied.has(name), `${lang}: ${message.key} wants {{${name}}}`).toBe(true)
          }
        }
      }
    }
  })
})
