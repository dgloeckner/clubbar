# Product Volume: A Size Beside the Name

**Status**: Not started — plan awaiting review
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

- [ ] `adr/0056-product-volume.md` — decisions 1–5, the alternatives rejected (a free-text
      label, amount + unit, a snapshot on the booking), and the rule that everything printing a
      name also prints the volume
- [ ] `adr/README.md` — the 0056 row
- [ ] `CONTEXT.md` — the **Volume** term, and the ADR-0002 sentence it extends (the product's
      size is language-neutral data, not part of its translated name)
- [ ] `api/fixtures/volume-format.json` — the formatting vectors (`de`, `en`, the edge cases
      1 / 999 / 1000 / 1005 / 10000)

**Verified by**: review. There is nothing to run.

### M2 — Backend: the column and the contract

- [ ] `backend/db/migrations/067_product_volume.sql` +
      `backend/db/rollback/067_product_volume.down.sql`. Pattern: `049_age_restrictions.sql:48-51`
      (`ADD COLUMN … NULL COMMENT … AFTER`)
- [ ] `ProductDto` — `fromRow` / `toArray` carry `volume_ml`, nullable like `min_age`
- [ ] `ProductsRepository` — the INSERT column list **and** the update allowlist `$allowed`
      (`:82`); a column missing from the allowlist is dropped without any error
- [ ] `AdminController` validation (Pattern 001) for create and update: `nullable|integer|min:1|max:10000`.
      Update checks with `array_key_exists`, so an explicit `null` clears the value. First
      confirm which of the two rule sets (`:45-66` or `:167-207`) is actually used
- [ ] `ProductsService` — volume in the create/update audit values
- [ ] `api/admin.yaml` — `Product`, `ProductCreateRequest`, `ProductUpdateRequest`.
      `api/terminal.yaml` — `Product`. Replace the `"Pils 0,5L"` example names with a short name
      plus `volume_ml`
- [ ] `docs/erm-master.md` (mermaid block and products table) and `docs/erm-frontend.md`
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

- [ ] PHP `Shared\Format\VolumeFormatter::format(int $ml, string $lang): string`
- [ ] TypeScript: `formatVolume` on `useFormatters()`, next to `formatPrice`, using `Intl`
- [ ] Dart: `formatVolume(int ml, String locale)` in `terminal-frontend/lib/utils/formatters.dart`,
      next to `formatPrice`

**Verified by**: each language's suite reading `api/fixtures/volume-format.json`. The fixture is
the single source of the formatting rule; the three implementations are checked against it.

### M4 — Admin: setting it

- [ ] `VolumeField` — entry in **litres** with either decimal separator, as the Money Field pattern
      does (#863: `<input type="number">` reports `0,5` as empty). Canonical millilitres on the
      wire, and `{testId}-value` for E2E tests to assert on
- [ ] `ProductsPage` — the field in the create/edit form, a volume column in the list, and the
      create/update payloads (`:276-278`, `:333-340`)
- [ ] `ProductPreview` — the badge, drawn the same way the terminal draws it
- [ ] `public/locales/de.json` / `en.json` — the label and a hint saying the size goes here,
      not in the name
- [ ] `admin-frontend/patterns/` — the volume-field entry in the component index

**Verified by**:

- vitest for `VolumeField` parsing: `0,5`, `0.5`, `1`, `0,33`, blank → null, `abc` refused
- `admin-chromium` `products.spec.ts`, an end-to-end flow:
  1. create a product with `0,5`, see the list show `0,5 l`, then GET the product and find
     `volume_ml: 500`
  2. edit it, clear the volume, and find it null
- `admin-mobile` for the field at 390 px

### M5 — Terminal: carrying it

- [ ] `products_cache.dart` — the nullable `volumeMl` column
- [ ] `database.dart` — `schemaVersion` 12 → 13, plus an `if (from < 13)` step using
      `_addColumnIfNotExists`. Regenerate `database.g.dart` and commit it (it is tracked)
- [ ] `products_repository.dart:91-104` — the DTO → Companion mapping
- [ ] Regenerate the swagger client (`build_runner`)

**Verified by**:

- a migration test that opens a schema-12 database and upgrades it with its rows intact
- a repository test showing `volume_ml` synced, then cleared to null

### M6 — Terminal: the card from the prototype

- [ ] `ProductTileMetrics` — `nameLines` 2 → 1, and a fixed **volume row** whose height is
      reserved whether or not the product has a volume. The row is what keeps prices level
      across tiles, as the name box did before. Update `tileHeight` and its inverse
      `nameFontSizeFor` together, since they are one equation
- [ ] `ProductGridLayout` — the name "fits" when it fits on **one** line. The ellipsis remains
      only as a fallback for a name wider than the whole tile at the lower bound (`xxl`)
- [ ] `ProductCard`:
  - a one-line name;
  - a volume badge in `textSecondary` on a faint fill;
  - the price in a pill, `semanticInfo` on a fill of about 28 %, with a 1 px border.

  Its contrast is checked in `contrast_test.dart`
- [ ] **Price size — open, decide at review.** The prototype makes the price larger than the
      name. The solver can set a name anywhere up to `productNameMax` (46.5 in the production
      config), while the price sits at a fixed `xxl` (27). The recommendation is to take the
      height the dropped second name line frees and give it to the price, set at the larger of
      `xxl` and `0.9 × name`. That avoids adding a new config key.
- [ ] Cart, checkout confirmation and the failed-sales banner — name + volume
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

- [ ] `TransactionsRepository.php:236` — the admin transaction list and terminal history
      (`product_name`; add `product_volume_ml` so clients format it themselves)
- [ ] `SettlementsRepository.php:64` → `SettlementItemDto`
- [ ] `DeckelStatementRepository.php:55,94` → `DeckelStatementService.php:191,201`
- [ ] `SettlementMailBuilder.php:167`
- [ ] `JugendschutzViolationMailBuilder.php:113`
- [ ] `ReportsRepository`, `DashboardRepository`, `UnsettledTransactions`, and the CSV exports
- [ ] Terminal `transaction_history_service.dart:165`

**Verified by**:

- PHPUnit for each builder and repository
- `mail-statement`: a delivered Deckelauszug read from Mailpit (Pattern 010) shows
  `Weizenbier 0,5 l`, and a line for a product with no volume shows the name alone
- `api-tests` — the transaction list carries `product_volume_ml`

### M8 — Documentation and close-out

- [ ] `UC-A41` / `UC-A42` — acceptance criteria for the volume field; `UC-T01` — the badge
- [ ] `docs/` — any operator-facing text that shows a name with a suffix
- [ ] A short "renaming your products" note for admins: set the volume, shorten the name, one
      save
- [ ] `plans/INDEX.md` — the status

**Verified by**: every suite named above green on the stacked branch, and CI green on each PR.

---

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
