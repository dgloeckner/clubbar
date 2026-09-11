# Volume Select Pattern

**Use `VolumeSelect` for a product's size. It is picked from a predefined list
of whole millilitres, and every reader is shown it in litres.**

```tsx
import { VolumeSelect } from '../components/forms/VolumeSelect'

<VolumeSelect
  testId="products-form-volume-select"
  value={formData.volumeMl}                                  // 500, or null
  onChange={(volumeMl) => setFormData({ ...formData, volumeMl })}
  emptyLabel={t('products.volumeNone')}
  invalid={!isVolumeInRange(formData.volumeMl)}
/>
```

---

## Why the control exists at all

A product's size used to live inside its translated name — `Weizenbier (0,5l)`,
`Pils 0,5L`, `Bier 0,5 l`, five spellings in the test data alone. It could not
be sorted or compared, it reached an English member with German punctuation, and
on the terminal it was what pushed a name onto a second line.

[ADR-0056](../../adr/0056-product-volume.md) moves it into `products.volume_ml`:
language-neutral whole millilitres, formatted for each reader at the edge. This
control is where an admin sets it.

## Why a list and not a typed number

The size was typed once, in litres, and that field had to carry money's
locale-aware masking one unit over: `<input type="number">` accepts one decimal
separator whatever language the page is in and reports anything else to script
as the **empty string**, so a Getränkewart typing `0,5` the way German writes it
handed the form nothing ([#863](https://github.com/dgloeckner/clubbar/issues/863)
is the same failure, for prices).

A club pours a handful of sizes. Offering them removes that problem and the one
underneath it — every freehand number is a chance to enter `50` for half a
litre, or `0,33` where the crate says `330`. There is no separator to read, no
mask, and nothing left for the form to refuse.

## What the pattern is

| Decision | Why |
|----------|-----|
| **Picked from `VOLUME_PRESETS_ML`** — 1000, 500, 330, 300, 250, 200 ml, largest first | The sizes a club pours, in the order a drinks list is read. The list *is* the validation: every option is inside the API's 1–10 000 ml range by construction |
| **Labelled in millilitres, read in litres** | A Getränkewart picks a size off a crate, which says `330 ml`; a member reads one on a terminal, which says `0,33 l`. Both come from the same stored number, and the preview beside the picker shows the second half so the pairing is visible while choosing |
| **A size from outside the list is kept** | `volumeOptionsFor(value)` adds the product's own size to the options when it is not a preset, in descending order. Without it, a product saved with 750 ml would open showing *no* size and the next unrelated save would clear the column |
| **`null` means the product has no size** | A Sauna-Token, a Kaffee — and the default, first in the list. Not `0`: zero would print as a size while meaning none, which is why the API refuses it |
| **Sent on every update, never omitted** | Clearing a size is an explicit `null`; a dropped key would leave the old volume on the row with the form claiming it was cleared (the backend reads it with `array_key_exists`) |
| **A native `<select>`** | On a phone the platform draws it as a wheel or a sheet, so a size is set with a thumb. It also needs no dropdown of our own to keep accessible |

To add or remove a size, edit `VOLUME_PRESETS_ML` in
[`src/utils/volume.ts`](../src/utils/volume.ts). Products already carrying the
size you remove keep it — that is what `volumeOptionsFor` is for — so a removal
is a decision about what can be *created*, not a migration.

## The two halves

- [`src/utils/volume.ts`](../src/utils/volume.ts) — the pure half: the preset
  list, `volumeOptionsFor` and `parseVolumeOption`. Unit tested.
- [`src/components/forms/VolumeSelect.tsx`](../src/components/forms/VolumeSelect.tsx)
  — the control. Covered by Playwright in `tests/admin/product-volume.spec.ts`
  and `tests/admin-mobile/product-volume-mobile.spec.ts`.

Styling comes from the caller via `style`, like `MoneyField`, and so does the
wording: `emptyLabel` is passed in, so the control carries no translation keys
of its own.

## Assert on the canonical value in E2E

The control renders a hidden `{testId}-value` holding the millilitres, for the
same reason `MoneyField` and `DateField` do: an assertion on what is on screen
is an assertion about presentation — and here there are two presentations of the
same number, one in the option and one in the preview.

```ts
expect(await products.getFormVolumeValue()).toBe('500')       // what the API receives
expect(plain(await products.getFormVolumeText())).toBe('500 ml')  // the option's wording
expect(plain(await products.getPreviewVolume())).toBe('0,5 l')    // what the member will read
```

## Never format a volume by hand

`useFormatters().formatVolume(millilitres)` is what a **reader** sees — litres
from 100 ml up; `null` formats to `''`, so a caller can concatenate without a
branch. `formatMillilitres(millilitres)` is the other direction, and exists only
to label an option in the unit a crate is labelled in.

The reader's rule, and its vectors, live in
[`api/fixtures/volume-format.json`](../../api/fixtures/volume-format.json), and
PHP, TypeScript and Dart each implement it against **that file**. A hand-rolled
`${ml / 1000} l` somewhere in a page is how the panel starts disagreeing with
the Deckelauszug and the terminal badge. A new vector belongs in the fixture,
never in one suite.

## Where sizes are set

| Page | Control |
|------|---------|
| Products → create/edit | `products-form-volume-select` |
