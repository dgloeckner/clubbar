# Volume Field Pattern

**Use `VolumeField` for a product's size. It is typed in litres, stored in whole
millilitres, and it is not `<input type="number">`.**

```tsx
import { VolumeField } from '../components/forms/VolumeField'

<VolumeField
  testId="products-form-volume-input"
  value={formData.volumeMl}                                  // 500, or null
  onChange={(volumeMl) => setFormData({ ...formData, volumeMl })}
  invalid={!isVolumeInRange(formData.volumeMl)}
/>
```

---

## Why the field exists at all

A product's size used to live inside its translated name — `Weizenbier (0,5l)`,
`Pils 0,5L`, `Bier 0,5 l`, five spellings in the test data alone. It could not
be sorted or compared, it reached an English member with German punctuation, and
on the terminal it was what pushed a name onto a second line.

[ADR-0056](../../adr/0056-product-volume.md) moves it into `products.volume_ml`:
language-neutral whole millilitres, formatted for each reader at the edge. This
field is where an admin puts it.

## Why not the native control

The same reason as [`MoneyField`](./money-field.md), one unit over.
`<input type="number">` is not locale-aware: whatever language the page is in,
its value sanitisation accepts one decimal separator — the dot — and reports
anything else to script as the **empty string**. A Getränkewart typing `0,5` the
way German writes it would hand the form nothing, beside a list that *displays*
every size as `0,5 l` ([#863](https://github.com/dgloeckner/clubbar/issues/863)
is the same failure, for prices).

## What the pattern is

| Decision | Why |
|----------|-----|
| **Typed in litres, stored in millilitres** | Nobody thinks of a beer as `500`. The litres are a rendering, exactly as `3,50` is a rendering of 350 cents |
| **The locale decides the separator, both are accepted** | Read from `Intl`, shared with money — the separators are a property of the locale, not of what is being counted. A numeric keypad emits a dot in German, and an admin who learned the panel in German types a comma into the English one |
| **Three decimal digits**, not two | The bottom of the validated range is 1 ml, which is `0,001` l. Money's masking is reused with `maxDecimals`, rather than copied — a second implementation of "which separator did they mean" is how the two drift |
| **`null` means the product has no size** | A Sauna-Token, a Kaffee. Not `0`: zero would print as a size while meaning none, which is why the API refuses it. An empty field and a typed `0` both come back as `null` |
| **The mask is not the validator** | `50` litres passes through the field and reaches the page's own refusal beside it. Silently clamping it to ten would be worse than a sentence the admin can read |
| **Sent on every update, never omitted** | Clearing a size is an explicit `null`; a dropped key would leave the old volume on the row with the form claiming it was cleared (the backend reads it with `array_key_exists`) |

## The two halves

- [`src/utils/volume.ts`](../src/utils/volume.ts) — the pure half: the masking
  (delegated to `money.ts` at three decimals), `parseLitresToMillilitres` and
  its inverse. Unit tested.
- [`src/components/forms/VolumeField.tsx`](../src/components/forms/VolumeField.tsx)
  — the control. Covered by Playwright in `tests/admin/product-volume.spec.ts`
  and `tests/admin-mobile/product-volume-mobile.spec.ts`.

Styling comes from the caller via `style`, like `MoneyField`: the component is
about the *value*, not the chrome.

## Assert on the canonical value in E2E

The field renders a hidden `{testId}-value` holding the millilitres, for the
same reason `MoneyField` and `DateField` do: an assertion on the visible input
is an assertion about the locale.

```ts
expect(await products.getFormVolumeValue()).toBe('500')   // what the API receives
expect(await products.getFormVolumeText()).toBe('0,5')    // …and only when the localisation is what is under test
```

## Never format a volume by hand

`useFormatters().formatVolume(millilitres)` goes through the shared rule; `null`
formats to `''`, so a caller can concatenate without a branch.

The rule and its vectors live in
[`api/fixtures/volume-format.json`](../../api/fixtures/volume-format.json), and
PHP, TypeScript and Dart each implement it against **that file**. A hand-rolled
`${ml / 1000} l` somewhere in a page is how the panel starts disagreeing with
the Deckelauszug and the terminal badge. A new vector belongs in the fixture,
never in one suite.

## Where sizes are typed

| Page | Field |
|------|-------|
| Products → create/edit | `products-form-volume-input` |
