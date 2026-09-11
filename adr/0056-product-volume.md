# ADR-0056: A Product's Size Is Data, Not Part of Its Name

**Status**: Accepted

**Date**: 2026-09-10

**Extends**: [ADR-0002](./0002-product-internationalization.md) — *Product
Internationalization*. Names stay translated JSON; the size moves out of them
into a language-neutral column.

---

## Context

A product's size is written into its name today. Test data alone spells the same
half litre five ways — `Weizenbier (0,5l)`, `Pils 0,5L`, `Bier 0,5 l`,
`Apfelschorle (0,3l)`, `Cola 0,33l` — because nothing has ever told an admin
which spelling to use. There is no field to put it in, so it goes in the only
field there is.

Three costs follow.

**The terminal cannot lay the name out.** The suffix is what pushes a name onto
a second line. At the production font scale the grid solver had
`Apfelschorle (0,3l)` break mid-word as `Apfelschorl` / `e (0,3l)`. The card has
to reserve two name lines for every tile because some tile needs them, and that
height is exactly the height the price wants. The screenshots in
[`docs/reviews/2026-09-10-product-card/`](../docs/reviews/2026-09-10-product-card/)
are the record of this.

**The size cannot be read by anything.** It cannot be sorted, validated,
compared or converted. A club cannot ask "what do we sell in half-litres"
because the answer lives in prose, in several languages, in five spellings.

**Every reader gets the writer's punctuation.** ADR-0002 makes a name
translatable precisely so an English member does not read German. The size
inside that name defeats it: an English translation that copies the German name
carries `0,5l` with a comma, and one that does not carries a size the German
translation spells differently.

---

## Decision

**A product carries an optional volume, in millilitres, in its own column.
Everything that prints a product name prints the volume after it.**

### 1. `products.volume_ml INT UNSIGNED NULL`, 1–10 000

| Property | Value |
|---|---|
| Column | `products.volume_ml` |
| Type | `INT UNSIGNED NULL` |
| Range | 1–10 000 (ten litres) |
| `NULL` | The product has no volume — Sauna-Token, Kaffee, a Portion Nüsse |
| Unit | Millilitres, always, on the wire and in the database |

Millilitres because they are the smallest unit any drink is sold in, and an
integer count of them needs no rounding decisions in storage. `NULL` and not `0`
because "no size" is not "zero size", the same reading `min_age` already has
(ADR-0045).

The upper bound is a sanity check, not a business rule: ten litres is past any
glass and past most kegs a member buys by the unit, so a value above it is a
typo — someone entered litres where millilitres were asked for, or added a
digit.

### 2. No automatic backfill

The migration adds the column and nothing else. Admins set a volume and shorten
the name in the same save.

A regex could find `(0,5l)` in a German name. It could not decide what the
English translation should become, whether `0,5` in a name is a size or a price,
or whether `Radler 0,5` was ever a volume at all. It would mangle a minority of
products silently, and a silently mangled name on a bar terminal is read by
members, not by the admin who could spot it.

Because the name edit and the volume edit are one form and one save, no product
is ever left half-renamed.

### 3. The volume is looked up live, exactly as the name is

A transaction stores `product_id` and `amount_cents` and nothing else about the
product. Every surface that prints a product name — the admin transaction list,
the terminal's history, the Deckelauszug, settlement mail, reports, exports —
joins the product row and reads `names` as it stands now. The volume is read
from that same join.

This means editing a volume changes how past bookings read, in exactly the way
that renaming a product already does. That is a known property of the current
model, not something this decision introduces, and the alternative — a snapshot
on the booking — is a change to ADR-0004's transaction shape that deserves its
own decision. It is deferred, not dismissed.

### 4. One formatting rule, three implementations, one set of vectors

The stored value is language-neutral. Each surface formats it for its reader:

| Rule | |
|---|---|
| At **100 ml and above** | Litres. At 100 ml the litre value gains a non-zero first decimal, so this is where litres start reading as a size rather than as a zero |
| Below 100 ml | Whole millilitres. `0,02 l` tells a reader less than `20 ml` does, and below 5 ml two decimals of a litre round to zero outright |
| Rounding | Half away from zero, at hundredths of a litre, computed on integers so no float ever decides a boundary — `1005 → 1,01 l` |
| Decimals | At most two, trailing zeros dropped — `1000 → 1 l`, not `1,00 l` |
| Separator | The reader's — `0,5 l` in German, `0.5 l` in English |
| Unit | A no-break space, then `l` or `ml`, so a size never wraps across a line |

PHP, TypeScript and Dart each implement it, and each language's test suite reads
the same vectors from
[`api/fixtures/volume-format.json`](../api/fixtures/volume-format.json). Three
implementations of one rule diverge; three implementations checked against one
file diverge visibly, in CI, on the commit that did it.

### 5. Additive on the wire

`volume_ml` is a new optional field on the product payloads. A terminal built
before this change ignores it. A terminal built after it, given a product with
no volume, draws no badge. So the backend can be deployed first, and
[ADR-0054](./0054-terminal-runs-its-backends-version.md) makes terminals follow
the backend in any case.

---

## What follows for the terminal card

Once a name no longer carries a suffix, the grid's names are single words or
short phrases, and the card can be laid out the way the approved prototype draws
it: **one** name line, a volume badge in a row of its own, and the price in a
pill as the most prominent number on the tile.

The volume row keeps its height whether or not the product has a volume. That is
not cosmetic: it is what holds every price on a row at the same height, which is
the invariant the fixed name box held before. A row that collapsed when a
product had no volume would put that tile's price above its neighbours', which is
the regression the screenshots in the review folder show.

---

## Alternatives rejected

**A free-text `volume_label` per language.** The first shape considered, and the
one the prototype's intro text still names. It is the current situation with a
second field: five spellings become five spellings in a column, nothing can sort
or compare them, and each translation is a separate chance to write the size
differently. It buys only the layout benefit, and it buys that by asking admins
to be consistent — which is what has not worked so far.

**An amount plus a unit (`0.5` + `l`, `330` + `ml`).** Faithful to what the
admin typed, and that is the problem: two products of the same size compare
unequal because one was entered in litres and one in millilitres. Every reader
then has to normalise before it can do anything, which is the normalisation this
decision does once, at the boundary.

**A general quantity column covering grams and pieces.** Volume is what drinks
have and what the layout problem is about. A column that means "0.5 of
something" needs the unit back, and lands on the alternative above. Decision 1
leaves room: another unit, when a club needs one, is another column with its own
range and its own formatter — not a rewrite of this one.

**Snapshotting name and volume onto the transaction.** The right long-term
answer for an immutable ledger, and out of scope here. Taking it would mean
changing the transaction shape, migrating existing rows against names that have
already drifted, and deciding what a reversal of a renamed product shows —
against ADR-0004 and ADR-0033. Doing it inside a layout change would bury it.

---

## Consequences

**Good**

- Names fit one line on the terminal, and the height that frees goes to the
  price — the number a member actually checks.
- One spelling of a size, everywhere, in the reader's own punctuation, derived
  rather than typed.
- The size is queryable: a club can ask what it sells in half-litres, and a
  report can group by it.
- Additive and nullable, so nothing changes on screen until an admin sets a
  volume. There is no flag day.

**Bad**

- **Renaming is manual work**, once, for every product a club has. Decision 2
  accepts this rather than risk mangling translations. A club with a hundred
  products has a hundred edits — an afternoon, and the plan ships without a
  helper on purpose (see *Deferred*).
- **A volume edit rewrites history**, as a rename already does. Decision 3
  chooses consistency with the existing behaviour over fixing half of it here.
- **Three formatters**, in three languages, that must agree. The shared fixture
  is the mitigation, and it is a real one only as long as every new vector is
  added to the file rather than to one suite.
- **Two units in one rule.** A reader below 100 ml sees millilitres. The
  threshold is a judgement call, and it is in the fixture so it cannot be
  quietly moved in one language.
- Products whose size is not a volume — a Portion, a Stück — still carry it in
  the name. They are the minority this decision does not serve.
- **The Deckelauszug's plain-text label column is 34 characters**, and a label
  is now the name *plus* the size. A realistic name still fits — `Alkoholfreies
  Bier 0,5 l` is 24 — and a name long enough to be truncated was already being
  truncated before the size was appended. The column is not widened: that would
  rewrap every statement for every club to serve the longest name any of them
  has. It is pinned by a test instead, so a future change that lengthens labels
  fails there rather than in somebody's inbox.

---

## References

- [ADR-0002](./0002-product-internationalization.md) — product names are
  translated JSON; this ADR takes the size out of them
- [ADR-0004](./0004-immutable-transaction-storage.md) — why a booking holds no
  product snapshot to put a volume in
- [ADR-0045](./0045-age-restricted-products.md) — `min_age`, the nullable
  product column this one is shaped after
- [ADR-0054](./0054-terminal-runs-its-backends-version.md) — why deploying the
  backend first is safe
- [`plans/2026-09-10-product-volume.md`](../plans/2026-09-10-product-volume.md)
  — the implementation plan
- [`docs/reviews/2026-09-10-product-card/`](../docs/reviews/2026-09-10-product-card/)
  — the prototype and the three screenshots of the problem
