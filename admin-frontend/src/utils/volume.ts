/**
 * Product volume entry, typed in litres and stored in millilitres.
 *
 * A product's size is language-neutral data on the product rather than part of
 * its translated name ([ADR-0056](../../../adr/0056-product-volume.md)), and
 * the wire carries whole millilitres. Nobody types a beer as `500`, though — a
 * Getränkewart thinks in `0,5`, and in a German panel writes it with a comma.
 *
 * So this is the same problem `MoneyField` answers, one unit over: the native
 * `<input type="number">` accepts one decimal separator whatever the page's
 * language is, and reports anything else to script as the empty string
 * ([#863](https://github.com/dgloeckner/clubbar/issues/863)). The masking rules
 * are money's, reused rather than copied — a second implementation of "which
 * separator did they mean" is how the two drift apart.
 *
 * What is different is the precision and the unit:
 *
 * - **Three decimal digits**, not two. The bottom of the validated range is
 *   1 ml, which is `0,001` l; two digits could not express it.
 * - **The canonical value is millilitres**, an integer, because that is what
 *   the API takes. The litres are a rendering, exactly as `3,50` is a rendering
 *   of 350 cents.
 */

import { getMoneyFormat, maskMoneyInput, toCanonicalMoney, type MoneyFormatSpec } from './money'

/** The separators a locale writes a number with. Shared with money: they are a
 *  property of the locale, not of what is being counted. */
export type VolumeFormatSpec = MoneyFormatSpec
export const getVolumeFormat = getMoneyFormat

/** Whole millilitres, so `0,001` l is the smallest value that can be typed. */
export const VOLUME_LITRE_DECIMALS = 3

/** The API's bounds (ADR-0056). Ten litres is a typo guard, not a business rule. */
export const VOLUME_MIN_ML = 1
export const VOLUME_MAX_ML = 10000

/** What the field should show for what the user just typed, in the locale's notation. */
export function maskVolumeInput(raw: string, spec: VolumeFormatSpec): string {
  return maskMoneyInput(raw, spec, VOLUME_LITRE_DECIMALS)
}

/** The canonical litres of what the field holds: dot-decimal, or `''`. */
export function toCanonicalLitres(text: string, spec: VolumeFormatSpec): string {
  return toCanonicalMoney(text, spec, VOLUME_LITRE_DECIMALS)
}

const LITRE_PATTERN = /^(\d+)(?:\.(\d{0,3}))?$/

/**
 * Canonical litres → whole millilitres, or `null`.
 *
 * `null` for an empty field — the product has no size, which is the ordinary
 * state of a snacks list — and `null` for anything that is not unambiguously a
 * non-negative number. Callers must treat `null` as "no value", **never as 0**:
 * 0 would print as a size while meaning none, which is exactly why the API
 * refuses it.
 *
 * `parseFloat` is not used, for the reason `parseMoneyToCents` gives: it
 * truncates `0,5` to 0 and swallows trailing garbage (`5abc` → 5).
 */
export function parseLitresToMillilitres(canonicalLitres: string): number | null {
  const match = LITRE_PATTERN.exec(canonicalLitres.trim())
  if (!match) return null

  const litres = parseInt(match[1], 10)
  const millis = parseInt((match[2] ?? '').padEnd(3, '0'), 10)
  const total = litres * 1000 + millis

  // An empty field and a typed zero both mean "no size", and the difference
  // between them is not worth a second null-ish value travelling the form.
  return total === 0 ? null : total
}

/** Whole millilitres → canonical litres, for putting a stored value back in the field. */
export function millilitresToCanonicalLitres(millilitres: number | null | undefined): string {
  if (millilitres === null || millilitres === undefined) return ''

  // Trailing zeros go, so a stored 500 comes back as `0.5` and not `0.500`:
  // the field should read the way the admin typed it.
  return String(millilitres / 1000)
}

/** The canonical litres as the locale writes them, for the visible input. */
export function formatCanonicalLitresForDisplay(canonical: string, spec: VolumeFormatSpec): string {
  if (canonical === '') return ''

  return canonical.split('.').join(spec.decimal)
}

/** An example size in the locale's notation, for an empty field. */
export function buildVolumePlaceholder(spec: VolumeFormatSpec, canonicalExample = '0.5'): string {
  return formatCanonicalLitresForDisplay(canonicalExample, spec)
}
