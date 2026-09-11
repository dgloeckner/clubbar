/**
 * The sizes a product can have: picked from a list, stored in millilitres.
 *
 * A product's size is language-neutral data on the product rather than part of
 * its translated name ([ADR-0056](../../../adr/0056-product-volume.md)), and
 * the wire carries whole millilitres.
 *
 * It used to be *typed*, in litres, which meant carrying money's locale-aware
 * masking one unit over so a German admin could write `0,5` without the native
 * number input reporting it as the empty string
 * ([#863](https://github.com/dgloeckner/clubbar/issues/863)). A club pours a
 * handful of sizes, though, and each one typed freehand is another chance to
 * enter `50` for half a litre or `0,33` where the crate says `330`. So the form
 * offers the sizes instead of a number field:
 *
 * - **The list is the validator.** Every option is inside the API's 1–10 000 ml
 *   range by construction, so there is no separator to parse, nothing to mask,
 *   and no typo to refuse. The range constants stay because a product saved
 *   before this change can still carry a value from outside the list.
 * - **Millilitres, always.** The option's value *is* what the API receives; the
 *   litres a reader sees are a rendering of it, exactly as `3,50` is a
 *   rendering of 350 cents.
 * - **`null` is "this product has no size."** A Sauna-Token, a Kaffee. Never
 *   `0`, which would print as a size.
 */

/**
 * The sizes the picker offers, largest first.
 *
 * Bottle and glass sizes a club pours, written in the unit a crate is labelled
 * in. Largest first because that is how a drinks list is read, and because the
 * half litre — the commonest choice — then sits at the top rather than buried.
 *
 * A club that pours something else is served by the rule below: a product
 * already carrying another size keeps it, and this list is one edit away from
 * carrying it too.
 */
export const VOLUME_PRESETS_ML: readonly number[] = [1000, 500, 330, 250, 200]

/** The API's bounds (ADR-0056). Ten litres is a typo guard, not a business rule. */
export const VOLUME_MIN_ML = 1
export const VOLUME_MAX_ML = 10000

/**
 * The sizes to offer for a product that currently has `value`.
 *
 * The presets, plus `value` itself when it is not one of them. That second half
 * is the whole reason this is a function: a product saved when the size was
 * typed freehand can hold 750 ml, and a picker that simply listed the presets
 * would show such a product as having *no* size — and then silently clear the
 * column on the next unrelated save. An unexpected size is kept, offered, and
 * re-selectable; it just cannot be created any more.
 */
export function volumeOptionsFor(value: number | null | undefined): number[] {
  const options = [...VOLUME_PRESETS_ML]
  if (value === null || value === undefined || options.includes(value)) return options

  // Descending, so a legacy size lands where a reader would look for it rather
  // than at the end of the list.
  const at = options.findIndex((preset) => preset < value)
  if (at === -1) options.push(value)
  else options.splice(at, 0, value)

  return options
}

/**
 * A `<select>`'s value back to whole millilitres, or `null`.
 *
 * `null` for the empty option — the product has no size, which is the ordinary
 * state of a snacks list — and `null` for anything that is not a whole number
 * of millilitres, which only a tampered-with DOM can produce. Callers must
 * treat `null` as "no value", **never as 0**: 0 would print as a size while
 * meaning none, which is exactly why the API refuses it.
 */
export function parseVolumeOption(raw: string): number | null {
  if (!/^\d+$/.test(raw.trim())) return null

  const millilitres = parseInt(raw.trim(), 10)

  return millilitres === 0 ? null : millilitres
}
