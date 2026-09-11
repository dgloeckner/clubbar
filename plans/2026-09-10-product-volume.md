# Product Volume: A Size Beside the Name

**Issue**: [#878](https://github.com/dgloeckner/clubbar/issues/878)
**Status**: Implemented — M1–M8 complete, each verified (see `plans/INDEX.md` for the suite counts).
M9 follows up: the size is picked from a predefined list rather than typed
**Design**: ADR-0056 (to be written in M1)
**Branch**: stacked on `claude/terminal-product-card-layout-tr5rue`. M6 needs that branch's
`ProductGridLayout` solver and its price-alignment change to `ProductCard`. One PR per
milestone, each based on the milestone before it.
**Prototype**: [`docs/reviews/2026-09-10-product-card/`](../docs/reviews/2026-09-10-product-card/)
— `prototype.html` (open it in a browser) and its render `04-prototype.png`, beside the three
screenshots of the problem it answers

---

## What this buys

Product names carry their size inline — `Weizenbier (0,5l)`, `Pils 0,5l`, `Bier 0,5 l`. Test data
alone has five spellings. On the terminal the suffix is what pushes a name onto a second line.
At the production scale (`xxxl` 31), `Apfelschorle (0,3l)` split as `Apfelschorl` / `e (0,3l)`.
Moving the size out of the name gives three things:

1. **Names fit on one line.** Without the suffix, the grid's names are single words or short
   phrases. The card drops to one name line, which frees the height for a larger price.
2. **One way to write a size.** The size becomes data, and every surface formats it for its
   reader: `0,5 l` in German, `0.5 l` in English (ADR-0002: the API stays language-neutral).
3. **The price stands out.** The prototype puts the price in a pill and makes it the most
   prominent number on the tile. The member picks by name, but the price is what they check.

**After this plan**: a product carries an optional `volume_ml`. Admins set it in the product
form. The terminal shows it as a badge under a one-line name. Every surface that prints a product
name — statements, settlement mail, history, reports and exports — prints the name followed by
the volume.

**Not contained here**:

- recording the name or volume on the booking (see decision 3);
- rewriting existing names automatically;
- units other than volume (grams, pieces, "Portion").

---

## Decisions taken

| # | Decision | Consequence |
|---|---|---|
| 1 | **`products.volume_ml INT UNSIGNED NULL`**, 1–10 000. NULL means "no size", as for Sauna-Token or Kaffee | Sortable, validatable and language-neutral. Anything that is not a volume stays in the name |
| 2 | **No automatic backfill.** The migration only adds the column; admins set the volume and shorten the name by hand | Names are multilingual JSON with no consistent suffix format, so a regex would mangle some translations. A shortened name and its new volume are one save, so nothing is ever left half-renamed |
| 3 | **Volume is looked up the same way the name is.** No snapshot on `transactions` | A booking stores only `product_id` + `amount_cents` (`001_initial_schema.sql:179-191`); every surface joins the current product. Printing the current volume beside the current name keeps old lines readable after a rename. A later volume edit changes history the same way a rename already does — a known property, not a new one. Recording name and volume on the booking is a separate issue |
| 4 | **One formatting rule, stated in three languages, checked against one set of test vectors** | Litres, up to two decimals, trailing zeros dropped, the locale's decimal separator, no-break space before `l`: `300 → 0,3 l`, `330 → 0,33 l`, `1000 → 1 l`, `1500 → 1,5 l`. PHP, TypeScript and Dart each implement it. All three test suites read the same `api/fixtures/volume-format.json`, so the three implementations cannot diverge unnoticed |
| 5 | **Additive on the wire** | Older terminals ignore the unknown field, and a newer terminal given no value shows no badge. Deploying the backend first is safe, and ADR-0054 makes terminals follow the backend anyway |

---

## Milestones

Ordered by dependency. `[ ]` not started · `[~]` in progress · `[x]` passed (test verified) ·
`[!]` failed.

### M1 — Design of record

No production code.

- [x] `adr/0056-product-volume.md` — decisions 1–5, the alternatives rejected (a free-text
      label, amount + unit, a snapshot on the booking), and the rule that everything printing a
      name also prints the volume
- [x] `adr/README.md` — the 0056 row
- [x] `CONTEXT.md` — the **Volume** term, and the ADR-0002 sentence it extends (the product's
      size is language-neutral data, not part of its translated name)
- [x] `api/fixtures/volume-format.json` — the formatting vectors (`de`, `en`, the edge cases
      1 / 999 / 1000 / 1005 / 10000)

**Verified by**: review. There is nothing to run.

### M2 — Backend: the column and the contract

- [x] `backend/db/migrations/067_product_volume.sql` +
      `backend/db/rollback/067_product_volume.down.sql`. Pattern: `049_age_restrictions.sql:48-51`
      (`ADD COLUMN … NULL COMMENT … AFTER`)
- [x] `ProductDto` — `fromRow` / `toArray` carry `volume_ml`, nullable like `min_age`
- [x] `ProductsRepository` — the INSERT column list **and** the update allowlist `$allowed`
      (`:82`); a column missing from the allowlist is dropped without any error
- [x] `AdminController` validation (Pattern 001) for create and update: `nullable|integer|min:1|max:10000`.
      Update checks with `array_key_exists`, so an explicit `null` clears the value. First
      confirm which of the two rule sets (`:45-66` or `:167-207`) is actually used
- [x] `ProductsService` — volume in the create/update audit values
- [x] `api/admin.yaml` — `Product`, `ProductCreateRequest`, `ProductUpdateRequest`.
      `api/terminal.yaml` — `Product`. Replace the `"Pils 0,5L"` example names with a short name
      plus `volume_ml`
- [x] `docs/erm-master.md` (mermaid block and products table) and `docs/erm-frontend.md`
      (`products_cache`)

**Verified by**:

- PHPUnit Unit: DTO round-trip, including null
- Feature: the repository persists the value and clears it
- `api-tests` `products.spec.ts`:
  - create with and without a volume
  - update to `null` clears it
  - `0`, `-1`, `10001`, `"0,5"` and `0.5` each return 422
  - `/api/sync/products` carries `volume_ml` on a product whose volume changed

### M3 — The formatter, three times

- [x] PHP `Shared\Format\VolumeFormatter::format(int $ml, string $lang): string`
- [x] TypeScript: `formatVolume` on `useFormatters()`, next to `formatPrice`, using `Intl`
- [x] Dart: `formatVolume(int ml, String locale)` in `terminal-frontend/lib/utils/formatters.dart`,
      next to `formatPrice`

**Verified by**: each language's suite reading `api/fixtures/volume-format.json`. The fixture is
the single source of the formatting rule; the three implementations are checked against it.

### M4 — Admin: setting it

- [x] `VolumeField` — entry in **litres** with either decimal separator, as the Money Field pattern
      does (#863: `<input type="number">` reports `0,5` as empty). Canonical millilitres on the
      wire, and `{testId}-value` for E2E tests to assert on
- [x] `ProductsPage` — the field in the create/edit form, a volume column in the list, and the
      create/update payloads (`:276-278`, `:333-340`)
- [x] `ProductPreview` — the badge, drawn the same way the terminal draws it
- [x] `public/locales/de.json` / `en.json` — the label and a hint saying the size goes here,
      not in the name
- [x] `admin-frontend/patterns/` — the volume-field entry in the component index

**Verified by**:

- vitest for `VolumeField` parsing: `0,5`, `0.5`, `1`, `0,33`, blank → null, `abc` refused
- `admin-chromium` `products.spec.ts`, an end-to-end flow:
  1. create a product with `0,5`, see the list show `0,5 l`, then GET the product and find
     `volume_ml: 500`
  2. edit it, clear the volume, and find it null
- `admin-mobile` for the field at 390 px

### M5 — Terminal: carrying it

- [x] `products_cache.dart` — the nullable `volumeMl` column
- [x] `database.dart` — `schemaVersion` 12 → 13, plus an `if (from < 13)` step using
      `_addColumnIfNotExists`. Regenerate `database.g.dart` and commit it (it is tracked)
- [x] `products_repository.dart:91-104` — the DTO → Companion mapping
- [x] Regenerate the swagger client (`build_runner`)

**Verified by**:

- a migration test that opens a schema-12 database and upgrades it with its rows intact
- a repository test showing `volume_ml` synced, then cleared to null

### M6 — Terminal: the card from the prototype

- [x] `ProductTileMetrics` — `nameLines` 2 → 1, and a fixed **volume row** whose height is
      reserved whether or not the product has a volume. The row is what keeps prices level
      across tiles, as the name box did before. Update `tileHeight` and its inverse
      `nameFontSizeFor` together, since they are one equation
- [x] `ProductGridLayout` — the name "fits" when it fits on **one** line. The ellipsis remains
      only as a fallback for a name wider than the whole tile at the lower bound (`xxl`)
- [x] `ProductCard`:
  - a one-line name;
  - a volume badge in `textSecondary` on a faint fill;
  - the price in a pill, `semanticInfo` on a fill of about 28 %, with a 1 px border.

  Its contrast is checked in `contrast_test.dart`
- [x] **Price size — decided: `max(xxl, 0.9 × name)`**, the recommendation above.
      `ProductTileMetrics.priceScale` states it once; `tileHeight` and its inverse both
      account for it, which is why the inverse now has two branches. The price stays loud
      through its *pill* rather than by outgrowing the name, so #369's finding (a member
      picks by name) still holds and its test passes unchanged.
- [x] Cart, checkout confirmation and the failed-sales banner — name + volume
      (`cart_provider.dart:54,73` carries the display string)

**Verified by**:

- `product_grid_layout_test.dart`:
  - one line, and no word ever split
  - the reserved volume row
- `product_card_test.dart` — the price's position is identical for a product with and without
  a volume, and for a short and a long name (the alignment regression from this branch)
- `product_selection_screen_test.dart` — the kiosk tests at 1280 × 800 still show two whole
  rows with the credit-limit banner up
- The full Flutter suite
- A manual pass against the integration server on the Sycreader setup used to build the
  prototype

### M7 — Everywhere a name is printed

Every surface currently joins `p.names` live, so each gets `volume_ml` from the same join and
formats it with M3's formatter:

- [x] `TransactionsRepository.php:236` — the admin transaction list and terminal history
      (`product_name`; add `product_volume_ml` so clients format it themselves)
- [x] `SettlementsRepository.php:64` → `SettlementItemDto`
- [x] `DeckelStatementRepository.php:55,94` → `DeckelStatementService.php:191,201`
- [x] `SettlementMailBuilder.php:167`
- [x] `JugendschutzViolationMailBuilder.php:113`
- [x] `ReportsRepository`, `DashboardRepository`, `UnsettledTransactions`, and the CSV exports
- [x] Terminal `transaction_history_service.dart:165`

**Verified by**:

- PHPUnit for each builder and repository
- `mail-statement`: a delivered Deckelauszug read from Mailpit (Pattern 010) shows
  `Weizenbier 0,5 l`, and a line for a product with no volume shows the name alone
- `api-tests` — the transaction list carries `product_volume_ml`

### M8 — Documentation and close-out

- [x] `UC-A41` / `UC-A42` — acceptance criteria for the volume field; `UC-T01` — the badge
- [x] `docs/` — any operator-facing text that shows a name with a suffix
- [x] A short "renaming your products" note for admins: set the volume, shorten the name, one
      save
- [x] `plans/INDEX.md` — the status

**Verified by**: every suite named above green on the stacked branch, and CI green on each PR.

### M9 — Admin: the size becomes a choice

Follow-up to M4, after the first club used it. A size is now **picked** from the sizes a club
pours rather than typed, which removes the last way to get one wrong: `50` where `0,5` was meant
passed the mask and reached a refusal, and `0,33` where the crate says `330` passed everything.
The predefined list is **1000, 500, 330, 300, 250, 200 ml**, labelled in the unit a crate is
labelled in; every reader still sees litres, which is what the preview beside the picker shows —
and the preview now draws the terminal's own tile rather than an approximation of it.

- [x] `utils/volume.ts` — `VOLUME_PRESETS_ML`, `volumeOptionsFor` (a product's own size is added
      to the options when the list does not contain it) and `parseVolumeOption`. The litres
      masking goes: with nothing typed there is no separator to read
- [x] `VolumeSelect` replaces `VolumeField` — a native `<select>`, so a phone draws it as a wheel
      and the size is set with a thumb. `{testId}-value` stays, carrying the millilitres
- [x] `design-system.ts` — `formatMillilitres()` for an option's label, beside `formatVolume()`
      for what a reader sees. One module owns the no-break space between a size and its unit
- [x] `ProductsPage` — the picker in the create/edit form; the range check stays, because a
      product saved before the list can still carry a size from outside it
- [x] `public/locales/de.json` / `en.json` — `volumeNone` for the empty option, and a hint that
      says the terminal prints litres
- [x] `ProductPreview` — the terminal's own tile rather than an approximation of it: one name
      line bottom-aligned in a fixed box, the reserved volume row, and the price in its pill,
      drawn from `ProductTileMetrics` at the name floor (exported as `TERMINAL_TILE`). The
      preview column widens to 200 px to hold it
- [x] `admin-frontend/patterns/volume-select.md` (was `volume-field.md`), the component index,
      `CLAUDE.md`, `UC-A41`, `UC-A42` and `docs/procedures.md`

**Verified by**:

- vitest: the preset list, `volumeOptionsFor` keeping a 750 ml product's size in its place among
  the presets, `parseVolumeOption`, and the pairing that every preset labelled in millilitres
  reads back as litres in both languages; `ProductPreview.test.tsx` for the tile's layout — one
  name line, the reserved badge row, and a price derived from the name size
- `admin-chromium` `product-volume.spec.ts`: the picker offers exactly the five sizes; each one
  previews as its litres; 500 ml round-trips to the API, the list and back into the form; the
  size clears; and a product created with 750 ml keeps it through a price-only edit
- `admin-mobile`: the picker fits the 390 px modal, is 44 px tall, and offers the same five sizes

**Deliberately not done**: no data migration. The presets are what can be *created*; every
existing size stays exactly as it is.

---

## As implemented — where the result differs from this plan

Five things were decided during the work rather than before it. Each is a
deliberate choice with its reason, not a slip.

1. **The formatting rule has two units, not one.** Litres from 100 ml up;
   whole millilitres below it. The plan's rule — litres, two decimals — renders
   `1 ml` as `0 l` and `20 ml` as `0,02 l`, and 1 is one of the edge cases the
   plan itself asked the fixture to carry. 100 ml is where the litre value gains
   a non-zero first decimal. Both units, and the threshold, are in
   `api/fixtures/volume-format.json`, so the choice cannot be quietly moved in
   one language.

2. **An unknown language falls back to the decimal comma**, in all three
   implementations. Dart's `formatPrice` falls back the other way; the volume
   formatter deliberately does not follow it, because the fallback is part of a
   rule three surfaces share and the other two are German-first.

3. **The admin list prints the size after the name rather than in a column of
   its own.** The plan asked for a column. ADR-0056's own rule is that
   everything printing a product name prints the volume after it — a column
   would say something different from every other surface, and would be empty on
   most of a snacks list. The cell still carries
   `products-table-cell-volume-{id}`, so it is addressable exactly as a column
   would have been.

4. **The price pill's fill is 22 %, not the prototype's 28 %.** At 28 % it
   measures 4.3:1 against the price text over `bgCard` — passing AA only under
   the large-text allowance, and under the flat 4.5:1 the rest of
   `contrast_test.dart` holds text to. A new token, `infoOnTint` (`#38bdf8`),
   exists for the same reason `dangerOnTint` does. A test fails if the 28 %
   version is ever restored.

5. **The failed-sales banner needed no change.** The plan lists it beside the
   cart and the receipt; it names the *member*, not the product, so there was
   nothing to append.

One constraint the work surfaced: the plain-text Deckelauszug lays labels out in
a fixed **34-character column**. A label is now name + size, so that column is a
real constraint rather than a generous one — `Alkoholfreies Bier 0,5 l` is 24 and
fits. It is not widened (that would rewrap every statement for every club); two
tests pin it and ADR-0056 records it.

## Rollout

1. Merge and deploy M2 through M7. Nothing changes on screen until a product has a volume.
2. Admins edit the products: set the volume, shorten the name (`Weizenbier (0,5l)` →
   `Weizenbier` + 0,5 l). The alcohol-free split already tried on the integration server
   (an *Alkoholfreie Getränke* category) is a data decision made in the same pass.
3. Every edited product bumps its `updated_at`, and terminals pick up the new data on their next
   delta sync.

## Deferred, deliberately

- **Recording name and volume on the booking.** A rename already rewrites history today; this
  plan keeps that behaviour and does not fix it. It deserves its own issue and its own ADR
  against ADR-0004 and ADR-0033.
- **A migration helper** that proposes a volume from each existing name for an admin to accept.
  It is worth building only if hand-editing turns out to be tedious for a real club.
- **Other units** (g, pieces). Decision 1 makes this a new column when it comes, not a
  rewrite of this one.
