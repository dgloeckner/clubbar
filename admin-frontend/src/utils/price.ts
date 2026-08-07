/**
 * Price input parsing.
 *
 * Price form fields accept German comma decimals ("3,50") as well as dot
 * decimals ("3.50"). `parseFloat` handles neither safely: it truncates
 * "3,50" to 3 and swallows trailing garbage ("12abc" → 12), so all price
 * inputs must go through this parser instead.
 */

const PRICE_PATTERN = /^(\d+)(?:[.,](\d{0,2}))?$/

/**
 * Parse a user-entered price string to integer cents.
 *
 * Accepts comma or dot as decimal separator, at most two decimal digits,
 * and a trailing separator ("3,") while the user is still typing.
 * Returns null for anything that is not unambiguously a non-negative
 * price — callers must treat null as a validation error, never as 0.
 */
export function parsePriceToCents(input: string): number | null {
  const match = PRICE_PATTERN.exec(input.trim())
  if (!match) return null

  const euros = parseInt(match[1], 10)
  const cents = parseInt((match[2] ?? '').padEnd(2, '0'), 10)
  return euros * 100 + cents
}
