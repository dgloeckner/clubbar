/**
 * Locale coverage guards.
 *
 * #129 was not a case of missing translations — de.json and en.json were in
 * perfect parity — but of strings that never reached them at all, and of keys
 * that reached only one file. Parity alone therefore proves nothing; these
 * tests check the two directions that actually broke:
 *
 *   1. every `t('literal.key')` in the source resolves in both languages, and
 *   2. the two files declare exactly the same keys, plural forms included.
 *
 * Keys assembled at runtime (`t(`periods.${key}`)`, `t(fallbackKey)`) cannot be
 * read statically and are skipped — the list below records the ones that exist
 * so a reader knows the sweep is incomplete on purpose.
 */

import { describe, expect, it } from 'vitest'

import de from '../../public/locales/de.json'
import en from '../../public/locales/en.json'

type Tree = { [key: string]: string | Tree }

/** Dotted key → value, for every leaf in a locale file. */
function flatten(tree: Tree, prefix = ''): Map<string, string> {
  const out = new Map<string, string>()
  for (const [key, value] of Object.entries(tree)) {
    if (typeof value === 'string') {
      out.set(prefix + key, value)
    } else {
      for (const [k, v] of flatten(value, `${prefix}${key}.`)) out.set(k, v)
    }
  }
  return out
}

const LOCALES = { de: flatten(de as Tree), en: flatten(en as Tree) }

/** i18next resolves `foo` from `foo_one`/`foo_other` when a count is passed. */
function resolves(keys: Map<string, string>, key: string): boolean {
  return keys.has(key) || (keys.has(`${key}_one`) && keys.has(`${key}_other`))
}

/**
 * Every source file as text. `import.meta.glob` rather than `node:fs` — this
 * file is type-checked by the app's own `tsc`, which has no node types.
 */
const SOURCES: Record<string, string> = import.meta.glob('../**/*.{ts,tsx}', {
  query: '?raw',
  import: 'default',
  eager: true,
})

/** Every `t('some.key')` written as a plain literal, with the file it came from. */
function usedKeys(): Array<{ key: string; file: string }> {
  const pattern = /\bt\(\s*'([a-zA-Z][\w.]*)'/g
  return Object.entries(SOURCES)
    .filter(([file]) => !file.includes('/generated/') && !/\.test\.tsx?$/.test(file))
    .flatMap(([file, text]) => [...text.matchAll(pattern)].map((m) => ({ key: m[1], file })))
}

describe('locale files', () => {
  it('declare exactly the same keys in both languages', () => {
    const inDe = [...LOCALES.de.keys()].sort()
    const inEn = [...LOCALES.en.keys()].sort()
    expect(inDe.filter((k) => !LOCALES.en.has(k))).toEqual([])
    expect(inEn.filter((k) => !LOCALES.de.has(k))).toEqual([])
  })

  it('give every pluralized key both an _one and an _other form', () => {
    for (const [lang, keys] of Object.entries(LOCALES)) {
      for (const key of keys.keys()) {
        if (!key.endsWith('_one')) continue
        const other = `${key.slice(0, -'_one'.length)}_other`
        expect(keys.has(other), `${lang}: ${key} has no ${other}`).toBe(true)
      }
    }
  })

  it('never leave a count-bearing string to fake its own plural', () => {
    // "{{count}} member(s)" was the workaround #129 called out. A string that
    // interpolates a count belongs in _one/_other forms instead.
    for (const [lang, keys] of Object.entries(LOCALES)) {
      for (const [key, value] of keys) {
        if (!value.includes('{{count}}')) continue
        if (key.endsWith('_one') || key.endsWith('_other')) continue
        expect(/\(e?[rs]\)|\(en\)/.test(value), `${lang}: ${key} = ${value}`).toBe(false)
      }
    }
  })
})

describe('translation keys used in the source', () => {
  const used = usedKeys()

  it('finds the calls to check', () => {
    expect(used.length).toBeGreaterThan(200)
  })

  for (const lang of ['de', 'en'] as const) {
    it(`all resolve in ${lang}`, () => {
      const missing = used
        .filter(({ key }) => !resolves(LOCALES[lang], key))
        .map(({ key, file }) => `${key} (${file})`)
      expect([...new Set(missing)]).toEqual([])
    })
  }
})
