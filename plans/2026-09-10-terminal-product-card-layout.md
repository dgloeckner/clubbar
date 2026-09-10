# Terminal Product Card Layout: Measured Type, Whole Words

**Status**: In progress
**Branch**: `claude/terminal-product-card-layout-tr5rue`
**Scope**: `terminal-frontend/` only

## Context

The product grid sets every name at one static size, `AppFontSizes.xxxl`, in a
tile whose width is capped at 240 px. That cap exists for exactly one word:
"Alkoholfreies" needs ~190 px at the shipped 26 px, so a sixth column on the
1280 px kiosk would split it. Nothing measures the names, so the guess is only
ever right for the scale it was made at — at the production scale of 31 the
same word is ~226 px inside a 208 px line, and Flutter splits it mid-word with
no hyphen ("Alkoholfreie" / "s Bier (0,5l)").

Three targets, from the request that opened this plan:

1. **Only real word breaks.** "Apfel-schorle", never "A-pfelschorle"; in
   practice a word is never split at all.
2. **One text size per category.** Every tile of the category the member is
   looking at sets its name at the same size.
3. **Large, adaptive names with an upper bound.** A sparse category fills the
   screen with fewer, larger tiles instead of two empty columns; a full one
   stays at the configured size and scrolls, as issue #29 settled.

### The conflict with `fontSizes`

`fontSizes.xxxl` in `config.json` is a deployment setting a club has already
tuned (the production terminal runs 31). An adaptive size that overruled it
would silently undo that. Resolution chosen (option 2 of three discussed):

- **`xxxl` is the floor.** It keeps its meaning as the size the club wants at
  minimum; the layout only ever *grows* from it when the category has room.
- **The ceiling is `fontSizes.productNameMax`**, new and optional, defaulting
  to 1.5 × `xxxl` so an untouched config gets a ceiling that follows the scale
  it already set.
- **Below the floor only to keep a word whole**, after fewer columns have been
  tried first, and never below `xxl` — the name stays at least as large as
  the price (`product_card_test`).

### Design

A pure Dart solver, `ProductGridLayout` (`lib/utils/product_grid_layout.dart`),
picks the column count, tile size, name size and icon size for one category
from the names, the grid's real viewport and the type scale:

- **Measured, not guessed.** Each distinct word is measured once with a
  `TextPainter` at a reference size in the card's own style; widths scale
  linearly with the font size, so everything after that is arithmetic. A name
  "fits" at a size when it wraps at spaces into at most two lines with no word
  wider than the line — the minimal line width for two lines is the best split
  point, so no search over sizes is needed.
- **Width before type.** For every column count from one up to what the screen
  allows, the solver computes the size the tile *width* allows (longest word
  and two-line fit) and the size the tile *height* allows (all rows visible).
  It keeps the count that yields the largest name; ties go to fewer columns
  when nothing scrolls (wider tiles, no empty columns) and to more columns
  when it must scroll (fewer rows to scroll past). A category that cannot
  reach the floor without scrolling scrolls at the floor.
- **Tile height follows the size**, with the icon at 2 × the name size (52 at
  the shipped 26, unchanged) so the tile scales as one thing:
  `tileHeight = 52 + 1.2·price + 4.4·name`.
- **Tile width is capped at 420** so two products on a 1920 px screen do not
  become billboards; the grid is centred when the cap bites.

## Milestones

- [ ] **1. `ProductGridLayout` solver + unit tests.** `lib/utils/product_grid_layout.dart`
  with an injected word-width function; `test/utils/product_grid_layout_test.dart`
  covers: one size per category; floor and ceiling honoured; fewer columns
  chosen before shrinking below the floor; scrolling fallback at the floor for
  a full category; no word wider than the line for "Alkoholfreies Bier (0,5l)"
  and "Weizenbier (0,5l)"; tile-width cap; degenerate inputs (empty names, a
  word wider than the whole screen).
- [ ] **2. Wire into `ProductSelectionScreen`, size the card from it.** A
  `LayoutBuilder` around the grid feeds the viewport to the solver; word widths
  are cached in the screen state. `ProductCard` takes `nameFontSize` and
  `iconSize`. Grid switches to a fixed column count with the solved tile
  height. The two screen tests that pinned the old constant tile (`a sparse
  category gets the same tile size as a full one`, `a kiosk tile is wide enough
  for the larger name`) are replaced by tests of the new behaviour; the four
  scale tests and the two-rows-whole kiosk tests stay green. A widget test
  proves, through the rendered paragraph's line metrics, that every line
  break in a name falls on a space.
- [ ] **3. `fontSizes.productNameMax`.** `AppFontSizes.productNameMax` (nullable,
  `nameCeiling` getter defaults to 1.5 × `xxxl`), read by `applyConfig`;
  documented in `INSTALL.md`, whose `fontSizes` table also gets its stale
  rows fixed (`lg` is no longer the product name, `xxxl` is).
- [ ] **4. Full terminal suite green, plan and index updated.**

### Deferred, deliberately

- **Qualifier line.** Rendering a trailing "(0,5l)" as its own smaller line
  gives the name the full width, but it costs a third text line on every tile
  and is a visual policy of its own. Not started here.
- **Soft hyphens in names.** The one correct break inside a compound is one a
  person marked; Flutter honours U+00AD. It changes ADR-0002's name format and
  every surface that prints a name (cart, statements, mail must strip it), so
  it is a separate issue, not this branch.

## Verification

```bash
export PATH=/home/user/sdk/flutter/bin:$PATH
cd terminal-frontend
flutter test test/utils/product_grid_layout_test.dart
flutter test test/widgets/product_card_test.dart test/screens/product_selection_screen_test.dart
flutter test
```
