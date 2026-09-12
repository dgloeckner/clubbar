# Volume Select Pattern

**Use `VolumeSelect` for a product's size. It is picked from a predefined list
of whole millilitres — or typed in millilitres when the list has no answer —
and every reader is shown it in litres.**

```tsx
import { VolumeSelect } from '../components/forms/VolumeSelect'

<VolumeSelect
  testId="products-form-volume-select"
  value={formData.volumeMl}                                  // 500, or null
  onChange={(volumeMl) => setFormData({ ...formData, volumeMl })}
  emptyLabel={t('products.volumeNone')}
  customLabel={t('products.volumeCustom')}                   // "Other size…"
  customFieldLabel={t('products.volumeCustomLabel')}         // the field's a11y name
  customPlaceholder={t('products.volumeCustomPlaceholder')}
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

## Why a list first, and a typed number behind it

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

But a list of sizes cannot be complete, and a picker with no answer for the 0,7 l
Schnapsflasche or the 1,5 l PET bottle on the table sends the size straight back
into the product name — the habit ADR-0056 exists to end. So the last option
opens a millilitre field.

**Typing is safe there for the reason it was not safe in litres: a millilitre is
a whole number.** There is no decimal separator in one, so `maskVolumeInput`
drops every character that is not a digit and the `0,5` that `<input
type="number">` reported as the empty string cannot be entered at all. What it
becomes instead is `5` — and the preview beside the field, which is already
there, spells that out as `5 ml` where the admin is already looking.

## What the pattern is

| Decision | Why |
|----------|-----|
| **Picked from `VOLUME_PRESETS_ML`** — 1000, 750, 500, 400, 330, 300, 250, 200, 100, 40, 20 ml, largest first | The sizes a club bar pours, in the order a drinks list is read. On this path the list *is* the validation: every option is inside the API's 1–10 000 ml range by construction |
| **A last option types millilitres** (`VOLUME_CUSTOM_OPTION`) | A list cannot be complete, and the alternative to an escape hatch is the size back in the name. Digits only — a millilitre has no separator — and the preview beside it reads the result back as litres |
| **Labelled in millilitres, read in litres** | A Getränkewart picks a size off a crate, which says `330 ml`; a member reads one on a terminal, which says `0,33 l`. Both come from the same stored number, and the preview beside the picker shows the second half so the pairing is visible while choosing |
| **A size from outside the list opens the field, filled in** | The control derives that from the value itself (`isPresetVolume`), not from state it has to keep in sync — a `<select>` asked to show `700` with no such option renders blank, and the next unrelated save would clear a column nobody touched. Deriving it also means the legacy size is *editable*, which listing it as an extra option never made it |
| **`null` means the product has no size** | A Sauna-Token, a Kaffee — and the default, first in the list. Not `0`: zero would print as a size while meaning none, which is why the API refuses it |
| **Sent on every update, never omitted** | Clearing a size is an explicit `null`; a dropped key would leave the old volume on the row with the form claiming it was cleared (the backend reads it with `array_key_exists`) |
| **A native `<select>`** | On a phone the platform draws it as a wheel or a sheet, so a size is set with a thumb. It also needs no dropdown of our own to keep accessible |

### What the eleven sizes are

| Size | What it is |
|------|------------|
| 1000 ml | A litre bottle, a Maß |
| 750 ml | A wine bottle, sold whole to a table |
| 500 ml | The half litre: the commonest beer in the house |
| 400 ml | The 0,4 l glass — a Weizen, a large soft drink |
| 330 ml | A bottle or a can |
| 300 ml | The 0,3 l glass |
| 250 ml | A Viertel of wine, a small soft drink |
| 200 ml | A glass of wine, a Stange of beer |
| 100 ml | A small glass of wine or Sekt |
| 40 ml | A double spirit — 4 cl |
| 20 ml | A Schnaps — 2 cl |

**Wine is why there are three sizes below a quarter litre.** German wine is
poured at 0,2 l as the ordinary glass, 0,1 l for a small one or a Sekt, and
0,25 l where the card says *Viertel*. A typical club's card carries all three at
once, so a picker with only one of them would push the other two back into the
product name.

To add or remove a size, edit `VOLUME_PRESETS_ML` in
[`src/utils/volume.ts`](../src/utils/volume.ts). Products already carrying the
size you remove keep it — the control opens the typed field for it — so a
removal is a decision about what is *convenient to create*, never a migration
and never a size a club can no longer express.

## The two halves

- [`src/utils/volume.ts`](../src/utils/volume.ts) — the pure half: the preset
  list, `isPresetVolume`, `maskVolumeInput` and `parseVolumeOption`. Unit tested.
- [`src/components/forms/VolumeSelect.tsx`](../src/components/forms/VolumeSelect.tsx)
  — the control, with its own unit tests beside it. Covered end to end by
  Playwright in `tests/admin/product-volume.spec.ts` and
  `tests/admin-mobile/product-volume-mobile.spec.ts`.

Neither half enforces the 1–10 000 ml range. An out-of-range size is handed to
the page so it reaches the admin as a refusal beside the field, exactly as a
negative amount does in `MoneyField` — the mask is not the validator.

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

// A typed size: the field's own text, and `null` when the picker never opened it.
await products.setCustomVolume(700)
expect(await products.getCustomVolumeText()).toBe('700')
expect(await products.getFormVolumeValue()).toBe('700')
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
| Products → create/edit | `products-form-volume-select` (picker), `products-form-volume-select-custom` (typed millilitres) |
