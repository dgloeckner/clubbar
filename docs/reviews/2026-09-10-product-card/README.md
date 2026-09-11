# Product card review — 2026-09-10

The visual record behind [#878](https://github.com/dgloeckner/clubbar/issues/878)
and [ADR-0056](../../../adr/0056-product-volume.md): why the terminal's product
tile was redrawn, and what it was redrawn into.

| File | What it shows |
|------|---------------|
| `01-before-mid-word-split.png` | Before the layout branch. Flutter splits a word that does not fit, with no hyphen: `Alkoholfreie` / `s Bier`, `Apfelschorl` / `e (0,3l)` |
| `02-solver-whole-words-price-misaligned.png` | With `ProductGridLayout`. Words stay whole, but a one-line name moved its price up relative to a two-line neighbour. Commit `2b4d50b5` fixed that by pinning the name box |
| `03-alkoholfrei-category-split.png` | The owner's data experiment on the integration server: an *Alkoholfreie Getränke* category lets "Alkoholfreies Bier" become "Bier". The names get short; the volume suffixes are still inline |
| `04-prototype.png`, `prototype.html` | The approved target. Open the HTML in a browser |
| `05-card-after.png` | **The shipped card**, rendered from the real widgets |

## About `05-card-after.png`

It is not a device screenshot. It is the actual `ProductCard`, laid out by the
actual `ProductGridLayout` at the production font scale (`productNameMin` 31,
`productNameMax` 46.5, `xxl` 27), captured through Flutter's golden-file
machinery with the SDK's Roboto loaded — `flutter_test` otherwise draws every
glyph as a filled box.

The six products are the ones the issue's screenshots use, with the size moved
out of the name and into `volume_ml`. The solver's answer for them:

```
columns=3  name=42.5  price=38.25  badge=17.0  tile=408.0x262.375
```

Three things in the image are the point:

1. **The names are on one line**, at 42.5 px — well above the 31 px floor,
   because the suffix that used to need a second line is now a badge.
2. **Sauna-Token and Kaffee have no badge, and their price pills sit at exactly
   the same height as Radler's.** The volume row is reserved whether or not a
   product has a size; that is what holds every price on a row level, and it is
   the invariant `02-…` shows being broken.
3. **The price is the tile's figure**, in a pill, at 0.9 × the name — loud
   through its fill and border rather than by outgrowing the name, so #369's
   finding that a member picks by name still holds.

The image was produced by a throwaway harness rather than a committed golden
test, deliberately: a golden pinned to one renderer and one font build fails on
an SDK bump for reasons that have nothing to do with the card. What *is*
committed is the arithmetic — `product_grid_layout_test.dart` and
`product_card_test.dart` assert the geometry, the reserved row and the price
alignment directly.

A real device screenshot needs the Sycreader setup the prototype was built on,
and is the one check that stays manual.
