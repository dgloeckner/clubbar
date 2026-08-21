/**
 * Jugendschutz: reading a product's minimum age (ADR-0045).
 *
 * `min_age` is a free nullable integer, 1–99, and NULL means "no age
 * restriction" — the terminal refuses the sale only when a number is on file.
 * The list has to say which of the two a row is in, so the check lives here
 * rather than as an inline truthiness test: `min_age` is a *number*, and
 * `product.min_age && …` reads a stored 0 as unrestricted. The column cannot
 * hold 0 today (the API bounds it at 1), which is precisely why an inline test
 * would look correct forever and be wrong the day the bound moves.
 */

/**
 * The age a product is restricted to, or `null` when it carries no restriction.
 *
 * Anything that is not a whole number ≥ 1 is treated as unrestricted: the
 * generated type says `number | null`, but the page renders whatever the API
 * sent, and a badge reading "ab 16.5" would be worse than no badge at all.
 */
export function ageRestrictionOf(minAge: number | null | undefined): number | null {
  if (typeof minAge !== 'number' || !Number.isInteger(minAge) || minAge < 1) {
    return null
  }
  return minAge
}
