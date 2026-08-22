import { describe, expect, it } from 'vitest'

import {
  UNNAMED_DIMENSION_KEYS,
  reportDimensionLabel,
  type ReportDimensionSource,
} from './reportDimensions'

import de from '../../public/locales/de.json'
import en from '../../public/locales/en.json'

/**
 * The regression here is a report row that reads `Unknown` in a German panel —
 * an English literal the backend used to bake into its SQL — and, worse, several
 * unrelated products sharing that one row. The load-bearing assertions are
 * therefore that a nameless row is translated, and that two nameless rows with
 * different ids do not render as the same label.
 */

/** i18next's `t` reduced to what this module uses: a key and `{{id}}`. */
function translator(locale: Record<string, unknown>) {
  return (key: string, params?: Record<string, string>): string => {
    const value = key
      .split('.')
      .reduce<unknown>((node, part) => (node as Record<string, unknown>)?.[part], locale)
    if (typeof value !== 'string') return key
    return Object.entries(params ?? {}).reduce(
      (text, [name, replacement]) => text.split(`{{${name}}}`).join(replacement),
      value,
    )
  }
}

const t = translator(de)
const tEn = translator(en)

const unnamedProduct = (id: string | null): ReportDimensionSource => ({
  dimension: null,
  dimension_id: id,
})

describe('reportDimensionLabel', () => {
  it('uses the name the server gave, whatever the grouping', () => {
    expect(reportDimensionLabel({ dimension: 'Helles', dimension_id: 'p1' }, 'product', t)).toBe(
      'Helles',
    )
    expect(reportDimensionLabel({ dimension: '2019-03', dimension_id: null }, 'month', t)).toBe(
      '2019-03',
    )
  })

  it('translates a nameless product rather than showing an English literal', () => {
    const label = reportDimensionLabel(unnamedProduct(null), 'product', t)

    expect(label).toBe('Unbekanntes Produkt')
    expect(label).not.toContain('Unknown')
    expect(reportDimensionLabel(unnamedProduct(null), 'product', tEn)).toBe('Unknown product')
  })

  it('tells two nameless products apart by their id', () => {
    const first = reportDimensionLabel(unnamedProduct('7f3a1c2b-1111-2222-3333-444444444444'), 'product', t)
    const second = reportDimensionLabel(unnamedProduct('91b4d0e5-1111-2222-3333-444444444444'), 'product', t)

    expect(first).toBe('Unbekanntes Produkt (7f3a1c2b)')
    expect(second).toBe('Unbekanntes Produkt (91b4d0e5)')
    expect(first).not.toBe(second)
  })

  it('names a nameless category as a category', () => {
    expect(reportDimensionLabel({ dimension: null, dimension_id: null }, 'category', t)).toBe(
      'Unbekannte Kategorie',
    )
  })

  it('falls back to a neutral label for any other grouping', () => {
    expect(reportDimensionLabel({ dimension: null, dimension_id: null }, 'member', t)).toBe(
      'Unbekannt',
    )
    expect(reportDimensionLabel({ dimension: null, dimension_id: null }, 'day', t)).toBe('Unbekannt')
  })

  it('treats an empty name as no name', () => {
    expect(reportDimensionLabel({ dimension: '', dimension_id: null }, 'product', t)).toBe(
      'Unbekanntes Produkt',
    )
  })

  it('survives a row that carries neither field', () => {
    expect(reportDimensionLabel({}, 'product', t)).toBe('Unbekanntes Produkt')
  })
})

describe('locale coverage', () => {
  it.each(UNNAMED_DIMENSION_KEYS)('%s is translated in every locale', (key) => {
    expect(translator(de)(key)).not.toBe(key)
    expect(translator(en)(key)).not.toBe(key)
  })
})
