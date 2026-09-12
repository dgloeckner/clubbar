/**
 * The sizes a product can have: offered as a list, stored in millilitres.
 *
 * A product's size is language-neutral data on the product rather than part of
 * its translated name ([ADR-0056](../../../adr/0056-product-volume.md)), and
 * the wire carries whole millilitres.
 *
 * It used to be *typed*, in litres, which meant carrying money's locale-aware
 * masking one unit over so a German admin could write `0,5` without the native
 * number input reporting it as the empty string
 * ([#863](https://github.com/dgloeckner/clubbar/issues/863)). The form offers
 * the sizes instead, and that is still the path almost every product takes:
 *
 * - **The list is the validator.** Every option is inside the API's 1–10 000 ml
 *   range by construction, so on the ordinary path there is no separator to
 *   parse, nothing to mask, and no typo to refuse.
 * - **Millilitres, always.** The option's value *is* what the API receives; the
 *   litres a reader sees are a rendering of it, exactly as `3,50` is a
 *   rendering of 350 cents.
 * - **`null` is "this product has no size."** A Sauna-Token, a Kaffee. Never
 *   `0`, which would print as a size.
 *
 * What the list cannot do is be complete. A club pours what it pours — a 0,4 l
 * Weizenglas, a 0,7 l Schnapsflasche, a 1,5 l PET bottle for a table — and a
 * picker that has no answer for one of them sends the size back into the name,
 * which is the habit ADR-0056 exists to end. So there is a last option that
 * opens a millilitre field (`VOLUME_CUSTOM_OPTION` below), and the presets are
 * what a club reaches for rather than the only thing it may say.
 *
 * The field is safe to type into for the reason the litres field was not: a
 * millilitre is a whole number, so there is no decimal separator in it at all.
 * `maskVolumeInput` keeps it that way, and a size typed in litres out of habit
 * (`0,5`) becomes `5` — visibly wrong in the preview beside the field, which
 * spells every size out as the member will read it.
 */

/**
 * The sizes the picker offers, largest first.
 *
 * The sizes a club bar actually pours, written in the unit a crate is labelled
 * in. Largest first because that is how a drinks list is read, and so the
 * bottle sizes group at the top and the spirits at the bottom.
 *
 * | Size | What it is |
 * |------|------------|
 * | 1000 ml | A litre bottle, a Maß |
 * | 750 ml | A wine bottle, sold whole to a table |
 * | 500 ml | The half litre: the commonest beer in the house |
 * | 400 ml | The 0,4 l glass — a Weizen, a large soft drink |
 * | 330 ml | A bottle or a can |
 * | 300 ml | The 0,3 l glass |
 * | 250 ml | A Viertel of wine, a small soft drink |
 * | 200 ml | A glass of wine, a Stange of beer |
 * | 100 ml | A small glass of wine or Sekt |
 * | 40 ml | A double spirit — 4 cl |
 * | 20 ml | A Schnaps — 2 cl |
 *
 * A glass of wine is the reason there are three sizes below a quarter litre:
 * German wine is poured at 0,2 l as the ordinary glass, 0,1 l for a small one
 * or a Sekt, and 0,25 l where the list says *Viertel*. All three are on a
 * typical club's card at once, so all three are offered.
 *
 * Anything else is typed — see `VOLUME_CUSTOM_OPTION`.
 */
export const VOLUME_PRESETS_ML: readonly number[] = [
  1000, 750, 500, 400, 330, 300, 250, 200, 100, 40, 20,
]

/** The API's bounds (ADR-0056). Ten litres is a typo guard, not a business rule. */
export const VOLUME_MIN_ML = 1
export const VOLUME_MAX_ML = 10000

/**
 * The `<select>` value that means "not on the list — let me type it".
 *
 * A string that can never be a size, so it cannot collide with an option's
 * millilitres: `parseVolumeOption` refuses anything that is not digits, and the
 * control intercepts this value before parsing anyway.
 */
export const VOLUME_CUSTOM_OPTION = 'custom'

/** Is this size one the picker lists, or one that has to be typed? */
export function isPresetVolume(value: number | null | undefined): boolean {
  return value !== null && value !== undefined && VOLUME_PRESETS_ML.includes(value)
}

/**
 * What a keystroke in the millilitre field is allowed to leave behind.
 *
 * Digits, and nothing else. A millilitre is a whole number, so unlike the
 * litres field this replaces there is no separator to accept — which is the
 * whole reason typing is safe here: the `0,5` that `<input type="number">`
 * reported as the empty string (#863) cannot be entered at all.
 *
 * Leading zeros go too, so `0500` is `500` and the text in the field always
 * matches the number underneath it.
 */
export function maskVolumeInput(raw: string): string {
  return raw.replace(/\D+/g, '').replace(/^0+(?=\d)/, '')
}

/**
 * A `<select>` option or a typed millilitre string back to whole millilitres,
 * or `null`.
 *
 * `null` for the empty option — the product has no size, which is the ordinary
 * state of a snacks list — and `null` for anything that is not a whole number
 * of millilitres, which is what a half-typed field holds. Callers must treat
 * `null` as "no value", **never as 0**: 0 would print as a size while meaning
 * none, which is exactly why the API refuses it.
 *
 * Note what this deliberately does *not* do: it does not enforce the 1–10 000
 * range. An out-of-range number is a refusal the admin has to see beside the
 * field, not a value silently swallowed on its way to the form — the same rule
 * `MoneyField` follows for a negative price.
 */
export function parseVolumeOption(raw: string): number | null {
  if (!/^\d+$/.test(raw.trim())) return null

  const millilitres = parseInt(raw.trim(), 10)

  return millilitres === 0 ? null : millilitres
}
