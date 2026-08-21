# Mandatory data: visible, consistent, and told as a story

**Issue**: [#629](https://github.com/dgloeckner/clubbar/issues/629)
**Status**: Implemented (M1–M4 done and each verified)
**Branch**: `claude/mandatory-field-visibility-oku149`

---

## Why

The panel has always *known* which data is mandatory. It has never said so in a
way anyone could act on, and the gap showed up in four places at once:

1. **`*` is not a signal.** Five member-form labels carried an asterisk in the
   same colour, size and weight as the label it hung off, in a two-column grid
   where half the fields are optional. It is a lookup key for a convention, not
   something an eye can scan.
2. **Creating a member offered no guidance.** Nothing said what was still
   missing, and nothing distinguished *optional* (account holder) from
   *optional but load-bearing* — a card UID gates terminal access, IBAN +
   mandate gate SEPA collection ([ADR-0020](../adr/0020-sepa-mandate-requirement-terminal-access.md)).
   Submitting blank returned one native browser bubble at a time.
3. **Editing could delete stored data silently.** Since [#131](https://github.com/dgloeckner/clubbar/issues/131)
   a blank optional input reaches the API as an explicit `null`, so clearing a
   card UID revokes that member's terminal access and clearing the mandate date
   deletes it — with nothing on screen saying the save was a deletion. The
   banking fields got the honest treatment in [#392](https://github.com/dgloeckner/clubbar/issues/392);
   `card_uid`, `account_holder_name` and `mandate_signed_at` did not.
4. **The marker had already drifted.** `CategorySelect`, `IconSelect` and
   `LanguageTabsInput` each rendered their own `*` when `required` was set, and
   call sites appended one to the label string as well — so the product form
   read `Kategorie * *` while its mandatory `Preis (€)` had no marker at all.
5. **The roster could not tell the story.** No way to spot a member with
   missing data from the list. Four filter pill groups could *find* them and
   said nothing about **why it matters** — disconnected toggles, not a story.

**Is a mandatory field ever silently reset?** Two different answers, and the
split is the point. A *required* field cannot end up empty by accident: the
form refuses the save and names the field. The real hazard was the three
clearable ones, where blank has meant `null` since #131 and nothing said so.

---

## Decisions

| Decision | Choice | Why |
|---|---|---|
| Required marker | **Live pill** — amber `! Pflicht` while empty, green `✓ Pflicht` once filled | The form visibly converges as it is completed, and an unfinished field is findable at a glance |
| Third tier | **`conditional`** — a blue pill naming the capability | Calling a card UID "optional" is true of the database and false of the club |
| Validation | `noValidate` + checks in `handleSubmit` | Native validation stops at the first offending field and says nothing about the rest |
| Roster | Goes **through the backend** | The birth-date gap is the one with a legal edge and the one invisible from the list |
| Filter pills | **Stay** | The panel explains and drives them rather than competing |
| Who counts | **Active members only** | An inactive member cannot book and is not collected from; a headline that can never reach zero stops being read |
| Placement | **Members page only** | One click from a gap to the filtered list to the edit form. The Dashboard already carries two warning banners |

---

## M1 — The requirement marker `[x]`

- `[x]` `admin-frontend/src/utils/memberFormRequirements.ts` — pure rules:
  `missingRequiredFields`, `countSatisfiedRequired`, `clearedFields`,
  `isPlausibleEmail`. Documents why the IBAN and the mandate reference are
  deliberately **not** clearable (blank means *keep*, #392 / ADR-0006).
- `[x]` `admin-frontend/src/components/forms/FieldLabel.tsx` — the three tiers.
  Marker text inside the `<label>` so it is part of the accessible name; the
  glyph is `aria-hidden`; the control keeps `required` / `aria-required`.
- `[x]` `theme.badges.warning/info/neutral` gained `border` tokens, derived with
  `withAlpha` per the #289 rule.

**Verified**: `memberFormRequirements.test.ts`, 25 tests.

## M2 — The member form `[x]`

- `[x]` `MemberFormRequirements` — progress line + bar, missing fields as
  buttons that focus the field they name, and a count of stored values the save
  would delete. `role="status"` until a submit is refused, then `role="alert"`.
- `[x]` `ClearedValueNotice` — inline "Gespeichert: X — wird beim Speichern
  gelöscht" with a **Wiederherstellen** link, on `card_uid`,
  `account_holder_name` and `mandate_signed_at`.
- `[x]` `MembersPage.tsx` — every label converted; `noValidate`; every gap
  reported at once; jump-to-field; inline errors on all five required fields;
  one `formInputStyle()` helper replacing ten copies.

**Verified**: `members-required-fields.spec.ts`, 6 specs — a blank create names
all four missing fields and focuses the first; markers flip `open → satisfied`;
clearing a card UID warns, restores, and survives the round trip; clearing and
saving genuinely removes it; a required field emptied on an edit blocks the save.

## M3 — One marker everywhere `[x]`

- `[x]` `CategorySelect`, `IconSelect`, `LanguageTabsInput` take
  `requirement` / `satisfied` and render `FieldLabel`; no component renders its
  own `*` any more.
- `[x]` `ProductsPage` — `Kategorie * *` gone, **Preis (€)** marked required for
  the first time, `Symbol` / `Mindestalter` on the `optional` tier.
- `[x]` `CategoriesPage` — same treatment.
- `[ ]` `LoginForm`, `ProfilePage`, the Settings tabs — they mark *nothing*
  today, so they are consistent with each other rather than half-migrated.
  Convert each when it is next touched; the rule is in
  `admin-frontend/patterns/components.md`.

## M4 — The roster tells the story `[x]`

- `[x]` `MembersService::listMembers()` — `unset($member['date_of_birth'])`
  becomes `has_date_of_birth => …!== null`. The **flag**, not the date, so
  [ADR-0045](../adr/0045-age-restricted-products.md)'s intent (no birth dates on
  a roster an admin scrolls) survives while the gap becomes findable.
- `[x]` `MembersRepository::listPaginated()` — `has_date_of_birth` filter, and
  `data_status=complete|incomplete` as one `OR` predicate. Expressed
  server-side because a union assembled in the browser is wrong the moment the
  result spans a page.
- `[x]` `MembersRepository::countDataGaps()` + `GET /api/admin/members/completeness`
  — one query, so every figure comes from the same snapshot.
- `[x]` `api/admin.yaml` + orval regeneration (pinned to the version that
  produced the committed client, so the diff is 7 files rather than 285).
- `[x]` `MemberDataQualityPanel` — the four gaps, each with its count, its
  consequence and a button that applies the filter *plus* `status=active`, so
  the list holds exactly the members the number counted. Collapses to one green
  line when nothing is missing.
- `[x]` `MemberGapChips` — the roster's SEPA-only column becomes a **Daten**
  column reporting all four gaps, with the consequence in each chip's `title`.
  Same `memberGaps()` the panel counts from, so the column and the headline
  cannot disagree.

**Verified**: `member-completeness.spec.ts` (6 API specs),
`AdminControllerCompletenessTest` (2 unit tests),
`memberCompleteness.test.ts` (11 unit tests), plus the roster half of
`members-required-fields.spec.ts`.

---

## Test results

| Suite | Result |
|---|---|
| `admin-frontend` vitest | **394/394** |
| `tsc --noEmit`, `eslint` | clean; 0 errors |
| Backend PHPUnit | **2776/2777** — the one failure, `ServiceFactoryTest::test_getRateLimitMiddleware_is_active_by_default`, reproduces with this branch stashed; [#638](https://github.com/dgloeckner/clubbar/pull/638) independently records it as red on `main` too (the dev container sets `DISABLE_TERMINAL_RATE_LIMITING`) |
| `api-tests` | **697/697** |
| `admin-chromium` | **340/340** |
| `admin-mobile` | **54/54** |
| `chain` lane (`E2E_LANE=chain`) | **31/31** |

One spec needed updating rather than fixing: `form-lifecycle.spec.ts` asserted
the SEPA column read "Fehlt". That column is now the Daten column, so it
asserts the SEPA *chip* instead.

---

## Rebased onto `main`

Linear history, no merge commit. Two conflicts, both in files `main` and this
branch each extended:

- `MembersPage.tsx` — [#631](https://github.com/dgloeckner/clubbar/pull/631)
  landed `DateField` on the two date fields this branch was relabelling. Both
  kept: `DateField` owns the control, `FieldLabel` owns the marker. It exposes
  neither an `id` nor a ref, so the label drops `htmlFor` there and
  `registerField` holds the wrapper — `jumpToField` therefore descends to the
  first focusable descendant, so "Geburtsdatum" in the missing-fields list
  still puts the caret in the date input.
- `plans/INDEX.md` — both sides prepended rows to the same table; all kept, and
  `main`'s newer revision of a shared row wins over this branch's stale copy.

**Two commits were dropped rather than replayed.** This branch had fixed two
age-leak assertions in the Jugendschutz specs that also matched timestamps —
red for the whole 15:00 hour, on the 15th of a month, and at minute or second
15. [#638](https://github.com/dgloeckner/clubbar/pull/638) fixed both
independently and better: the API check asserts the payload's whole *key
shape*, which also catches a field a value-based check would let through, and
the mail check strips the values that legitimately carry digits before looking
for the age. `main`'s versions are taken verbatim; the two specs are now
byte-identical to `main` on this branch.

## Follow-ups

- `LoginForm`, `ProfilePage` and the Settings tabs still mark no fields at all
  (M3, above).
- The panel is Members-page only by decision. If a treasurer who only opens the
  Dashboard turns out to need it, the existing warning-banner pattern there is
  the place for a one-line link.
