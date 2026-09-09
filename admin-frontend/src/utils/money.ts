/**
 * Money entry, in the reader's own number conventions.
 *
 * Amounts are *displayed* through `Intl` everywhere (`formatPrice`), but they
 * used to be *typed* into `<input type="number">`, which is not a locale-aware
 * control: its value sanitisation algorithm accepts one separator — the dot —
 * whatever the page's language is. A German admin typing the price of a beer
 * the way German writes it, `3,50`, therefore handed the page an **empty
 * string**, silently: the field looks filled, `e.target.value` is `''`, and
 * the form refuses to save a price that is plainly on screen. The panel
 * defaults to German, so this was the default experience.
 *
 * This module is the pure half of `MoneyField` — the separator the locale
 * writes, the masking as you type, and the conversion to and from the
 * canonical form. It mirrors `dateField.ts`, which solved the same problem for
 * dates, and it keeps the same contract:
 *
 * - **Canonical on the outside.** Pages hold a dot-decimal string (`"3.50"`,
 *   `""` for empty) — the form of `toFixed(2)` and the form the cents parsers
 *   below take. Nothing outside the field has to know what the user sees.
 * - **Locale on screen.** The decimal separator comes from `Intl`, not from a
 *   table of our own, so a locale added later is right without an edit here.
 *
 * The separator is read rather than assumed for the reason `getDateFormat`
 * gives about date order: `de` writes `3,50` and `en-GB` writes `3.50`, and a
 * hardcoded map is wrong the first time somebody adds a language.
 */

export interface MoneyFormatSpec {
  /** The decimal separator, e.g. `,` for de and `.` for en. */
  decimal: string
  /** The thousands separator, e.g. `.` for de and `,` for en. */
  group: string
}

/** German, the panel's default — used when `Intl` cannot be read. */
const FALLBACK_SPEC: MoneyFormatSpec = { decimal: ',', group: '.' }

/** A number whose group and decimal separators are both present. */
const PROBE = 1234.5

/**
 * The separators a locale writes an amount with, read from `Intl`.
 *
 * An environment with a trimmed ICU can return parts we cannot interpret, so
 * an incomplete read falls back to the German pair rather than throwing inside
 * a render.
 */
export function getMoneyFormat(locale: string): MoneyFormatSpec {
  let parts: Intl.NumberFormatPart[]
  try {
    parts = new Intl.NumberFormat(locale, {
      minimumFractionDigits: 1,
      useGrouping: true,
    }).formatToParts(PROBE)
  } catch {
    return FALLBACK_SPEC
  }

  const decimal = parts.find((part) => part.type === 'decimal')?.value
  const group = parts.find((part) => part.type === 'group')?.value
  if (!decimal) return FALLBACK_SPEC

  // A locale can write a group separator we must not confuse with the decimal
  // one (a narrow no-break space, in fr). Only a *different* character is a
  // usable group separator; otherwise there is nothing to strip.
  return { decimal, group: group && group !== decimal ? group : '' }
}

/**
 * What the field should show for what the user just typed.
 *
 * Forgiving on input and strict on output: both separators are understood
 * whichever language the panel is in, because a numeric keypad emits a dot in
 * German and a German admin types a comma into an English panel. What comes
 * back is always written the locale's way.
 *
 * The rules, in the order they matter:
 *
 * - **The last separator is the decimal one.** That reads a pasted `1.234,56`
 *   and a pasted `1,234.56` correctly without knowing which side wrote it.
 * - **…unless a single separator is the locale's group separator followed by
 *   exactly three digits.** `1.000` is a thousand euros to a German, not one
 *   euro; credit limits are typed as round thousands, so this case is the
 *   difference between a €1,000 ceiling and a €1 one.
 * - **At most two decimal digits**, and a trailing separator survives so that
 *   `3,` is a state you can keep typing from.
 * - **A leading `-` survives.** The mask is not the validator: a negative
 *   amount has to reach the form's own refusal, beside the field, rather than
 *   being silently turned into a positive one.
 */
export function maskMoneyInput(raw: string, spec: MoneyFormatSpec): string {
  const trimmed = raw.trim()
  const sign = trimmed.startsWith('-') ? '-' : ''
  const body = trimmed.replace(/[^\d.,]/g, '')

  if (body === '') return sign

  const lastSeparator = Math.max(body.lastIndexOf('.'), body.lastIndexOf(','))
  if (lastSeparator === -1) return sign + body

  const separator = body[lastSeparator]
  const head = body.slice(0, lastSeparator).replace(/[.,]/g, '')
  const tail = body.slice(lastSeparator + 1).replace(/[.,]/g, '')

  const separatorCount = (body.match(/[.,]/g) ?? []).length
  const isGrouping = separatorCount === 1 && separator === spec.group && tail.length === 3 && head !== ''

  if (isGrouping) return sign + head + tail

  // A separator typed before any digit means "nought point something", which
  // is how a price under a euro is usually typed in a hurry.
  return sign + (head === '' ? '0' : head) + spec.decimal + tail.slice(0, 2)
}

/**
 * The canonical form of what the field holds: dot-decimal, or `''`.
 *
 * A trailing separator is dropped — `3,` is a half-typed `3`, and the page's
 * validator should see a number rather than a syntax error on every keystroke.
 */
export function toCanonicalMoney(text: string, spec: MoneyFormatSpec): string {
  const masked = maskMoneyInput(text, spec)
  if (masked === '' || masked === '-') return masked

  const canonical = masked.split(spec.decimal).join('.')
  return canonical.endsWith('.') ? canonical.slice(0, -1) : canonical
}

/** The canonical value as the locale writes it, for the visible input. */
export function formatCanonicalForDisplay(canonical: string, spec: MoneyFormatSpec): string {
  if (canonical === '') return ''

  return canonical.split('.').join(spec.decimal)
}

/**
 * A finished amount, written out to the cent.
 *
 * Applied when the field is left, not while it is being typed in: `3` is what
 * an admin means to type on the way to `3,50`, and `3,00` is what they meant
 * once they have moved on. Anything the field cannot read is handed back
 * untouched, so the page's own refusal is what explains it.
 */
export function normaliseCanonicalMoney(canonical: string): string {
  const cents = parseMoneyToCents(canonical)
  if (cents === null) return canonical

  return (cents / 100).toFixed(2)
}

/** An example amount in the locale's notation, for an empty field. */
export function buildMoneyPlaceholder(spec: MoneyFormatSpec, canonicalExample = '10.50'): string {
  return formatCanonicalForDisplay(canonicalExample, spec)
}

const MONEY_PATTERN = /^(\d+)(?:[.,](\d{0,2}))?$/

/**
 * Parse a user-entered amount to integer cents (ADR-0001: money is cents).
 *
 * Accepts comma or dot as the decimal separator, at most two decimal digits,
 * and a trailing separator (`3,`) while the user is still typing. Returns null
 * for anything that is not unambiguously a non-negative amount — callers must
 * treat null as a validation error, **never as 0**.
 *
 * `parseFloat` handles none of this safely: it truncates `3,50` to 3 and
 * swallows trailing garbage (`12abc` → 12), so every amount typed into the
 * panel goes through this instead.
 */
export function parseMoneyToCents(input: string): number | null {
  const match = MONEY_PATTERN.exec(input.trim())
  if (!match) return null

  const euros = parseInt(match[1], 10)
  const cents = parseInt((match[2] ?? '').padEnd(2, '0'), 10)
  return euros * 100 + cents
}
