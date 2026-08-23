/**
 * The member's own credit ceiling, between the form and the API
 * ([ADR-0047](../../../adr/0047-configurable-credit-limits.md)).
 *
 * One field, three meanings, and keeping them apart is the whole job:
 *
 * | Field | Sent | Meaning |
 * |---|---|---|
 * | empty | `null` | Follow the club default, and move with it |
 * | `0` | `0` | No ceiling for this member |
 * | `200` | `20000` | This member's ceiling, in cents |
 *
 * **An emptied field must never become `0`.** `null` hands the member back to
 * the club default; `0` grants them unlimited credit. A form that serialises
 * one as the other does it silently, to a member nobody was thinking about at
 * the time — which is why this conversion lives here, with its own tests,
 * rather than inline in the members page.
 *
 * Money is integer cents (ADR-0001): the volunteer types euros, the API is
 * sent cents.
 */

/** The largest ceiling the API accepts, in cents — `CreditLimitPolicy::MAX_LIMIT_CENTS`. */
export const MAX_CREDIT_LIMIT_CENTS = 10_000_000

/**
 * A stored override as the string the input holds.
 *
 * `0` comes back as `"0.00"` rather than as an empty field, because the
 * difference has to survive a round trip: an edit form that showed an uncapped
 * member a blank box would re-cap them on the next save of an unrelated field.
 */
export function creditLimitToInput(cents: number | null | undefined): string {
  if (cents === null || cents === undefined) return ''

  return (cents / 100).toFixed(2)
}

/**
 * What the field should send: `null` for empty, cents otherwise, `NaN` for a
 * value that is not a non-negative amount.
 */
export function creditLimitFromInput(value: string): number | null {
  const normalised = value.trim().replace(',', '.')
  if (normalised === '') return null
  if (!/^\d+(\.\d{1,2})?$/.test(normalised)) return NaN

  // Rounded, not truncated: 19.99 * 100 is 1998.9999999999998 in IEEE 754, and
  // a cent lost here is a cent the ceiling is wrong by forever.
  return Math.round(Number(normalised) * 100)
}

/** Whether the field holds something the API will accept. */
export function isValidCreditLimitInput(value: string): boolean {
  const cents = creditLimitFromInput(value)
  if (cents === null) return true

  return Number.isFinite(cents) && cents >= 0 && cents <= MAX_CREDIT_LIMIT_CENTS
}
