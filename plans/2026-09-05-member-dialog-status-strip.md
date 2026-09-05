# The member dialog answers one question, and *Speichern* stays on screen

**Issue**: [#830](https://github.com/dgloeckner/clubbar/issues/830)
**Design**: [`docs/reviews/2026-09-05-member-edit-dialog/`](../docs/reviews/2026-09-05-member-edit-dialog/) (canvas: <https://claude.ai/code/artifact/c27ac99d-acf5-4f4a-8a75-6cd35ccb7157>)
**Status**: Implemented (M1–M6 done and each verified)
**Branch**: `claude/pr-831-830-fix-07hlq5`

---

## Why

*Mitglied bearbeiten* had grown four independent status indicators, and every
one of them could be green for a different reason:

1. a **requirements panel** with a progress bar ("Alle Pflichtangaben
   ausgefüllt") — [#629](https://github.com/dgloeckner/clubbar/issues/629);
2. a **SEPA alert** directly under it ("SEPA-Mandat gültig") —
   [#392](https://github.com/dgloeckner/clubbar/issues/392);
3. a green **`✓ Pflicht`** pill on every satisfied required label, and a blue
   **`→ erforderlich für …`** pill on every conditional one;
4. permanent **helper paragraphs** under Kontoinhaber, Mandatsreferenz and
   Eigenes Limit.

Four green things is not four times the reassurance. Nothing was emphasised
because everything was, and between them they still did not answer the three
questions an admin opens the dialog with: **can this member book at the
terminal, can their Deckel be collected, can the club reach them at all.**

And the dialog was about **1750 px** tall inside one `overflow-y: auto` box, so
on a 900 px screen *Speichern* was roughly 800 px below the fold. An admin
edited a field and had to scroll to find out whether they could save. That is
the panel's primary edit flow.

---

## Decisions

| Decision | Choice | Why |
|---|---|---|
| What the strip groups by | **Outcome**, not field — Terminal, SEPA-Einzug, Erreichbar | An admin came to find out what the member can do, not to audit ten inputs |
| What a tile reports | **The save, never the load** — five tones: `ok`, `partial`, `gap`, `pending`, `losing` | `SepaFormStatus` has done this since #392; a strip where one tile means "after saving" and the two beside it mean "as loaded" is worse than either rule applied consistently |
| Which gaps exist | **`MEMBER_GAPS`**, the same four the roster counts | A dialog that invented a fifth would let an admin fix everything it asks for and still see the roster call the member incomplete |
| What a gap link says | **The field's own label** ("Karten-UID"), not the roster's chip ("Karte") | It is about to put the caret in a field with that exact label |
| Field markers | **Only where something is wrong** — an orange `Pflicht` pill and an orange border; nothing on a satisfied field | One colour, one meaning: "this field is why a tile is not green" |
| Long explanations | **`FieldInfo`** — short form in the placeholder, long form behind an **i** | Each is useful the first time and then three paragraphs of body text forever |
| Dialog structure | **Three bands** — pinned header, scrolling body, pinned footer | Only the body scrolls, so the primary action cannot go below the fold at any screen height |
| Terminal without a birth date | **`partial`**, not a gap | The member *can* book, just nothing age-restricted (ADR-0045) — one red/green light would overstate or hide it |
| The zero credit limit | **Keeps its helper line** | Empty is answered by the placeholder; `0` looks like an ordinary number and means the opposite of one (ADR-0047), and the two must never read the same |
| Mobile summary | An `IntersectionObserver` on the strip; the header carries its **conclusion** | Both read the same tiles, so they cannot offer a second opinion. Nothing runs per frame while a finger drags |

---

## M1 — What the member can do, as a rule rather than a rendering `[x]`

- `[x]` `admin-frontend/src/utils/memberFormStatus.ts` — pure: three tiles from
  the form state plus the saved member, each carrying its message *key*, its
  tone, and the fields that would close its gap. Plus `statusGapFields()` (which
  inputs take the orange border) and `countChangedFields()` (the footer's
  "Keine Änderungen").
- `[x]` `memberFormStatus.test.ts` — 18 tests: all three tiles always present
  and ordered; the terminal tile separating "cannot book" from "cannot book
  everything"; every tile previewing the save in both directions; the SEPA tile
  never naming the reference (the server mints it, ADR-0006); and the assertion
  that the strip only ever names the four gaps the roster counts.
- **Verified**: `npx vitest run` → 616/616.

## M2 — One strip instead of four indicators `[x]`

- `[x]` `admin-frontend/src/components/members/MemberStatusStrip.tsx` — caption
  row (section label + the one thing that stops the save) and three tiles.
  `role="status"` until a submit is actually refused, `role="alert"` after.
  `MemberStatusSummaryLine` is its conclusion for the pinned mobile header.
- `[x]` Deleted `MemberFormRequirements.tsx` and the SEPA `Alert` in the modal.
- `[x]` The required-fields summary keeps `members-form-requirements-missing-*`,
  so #629's jump chips and their specs survive the move.
- **Verified**: the four states rendered against the design's artboard 03
  (ready / gaps / this-edit-changes-something / save-refused).

## M3 — A marker means something is missing `[x]`

- `[x]` `FieldLabel` — no pill on a satisfied required field; the conditional
  tier is a muted note rather than a blue pill, and only while the field is
  empty; new `info` prop.
- `[x]` `formInputStyle(invalid, gap)` and `DateField`'s new `warn` prop — the
  same orange on exactly the inputs the strip names, with `invalid` outranking
  it (a complaint about a value beats a note that there isn't one).
- `[x]` `admin-frontend/src/components/forms/FieldInfo.tsx` — hover **and** tap,
  wrapping text, Escape and outside-click to close. Hover and pin are separate
  states: one flag made the mouse path close it on the click that followed
  `mouseenter`.
- **Verified**: `admin-chromium` marker assertions updated and green.

## M4 — It fits, and the footer does not move `[x]`

- `[x]` `MembersPage.tsx` — pinned header / scrolling body / pinned footer, the
  `<form>` spanning body and footer so the submit stays a plain submit.
- `[x]` Height recovered: the strip instead of two boxes; the age as a suffix
  *inside* the birth-date field; IBAN and mandate-reference actions *inside*
  their box; Sprache beside Karten-UID; no helper paragraphs; a thin
  *SEPA-Lastschrift* divider instead of spacing.
- `[x]` Mobile: 44 px full-width *Speichern*, a header ✕, the export moved to
  the end of the form, and the compact summary line once the strip scrolls out.
- **Verified**: at 1440×900 the dialog opens with *Speichern* in the viewport
  and no scrolling; it is still in the viewport after the body is scrolled to
  its last field.

## M5 — Coverage `[x]`

- `[x]` `e2etests/tests/admin/members-status-strip.spec.ts` — a Terminal gap
  naming its field, jumping to it, turning green, and the **roster agreeing**
  after a round trip; the SEPA tile going red on a pending removal and offering
  the way back; the fold and the change count; the info popover.
- `[x]` `e2etests/tests/admin-mobile/members-form-mobile.spec.ts` — *Speichern*
  in the viewport and ≥44 px before and after scrolling; the header taking over
  the strip's conclusion.
- `[x]` Updated for the redesign: `members-required-fields.spec.ts` (a satisfied
  field has no marker), `members.spec.ts` (the SEPA tile, not the alert),
  `member-credit-limit-form.spec.ts` (placeholder vs the zero line),
  `date-field.spec.ts` (the age moved into the field).
- **Verified**: `admin-chromium` member + credit-limit specs 79/79; the two
  mobile specs 2/2.
  **`admin-mobile` could not be run in this session** — the sandbox's egress
  policy returns 403 for `playwright.download.prss.microsoft.com`, so WebKit
  cannot be installed. They were run against Chromium at `devices['iPhone 14']`
  instead; CI installs the full browser set and runs the real lane.

## M6 — Documentation `[x]`

- `[x]` `admin-frontend/patterns/components.md` — `FieldLabel`'s rewritten tier
  table, `FieldInfo`, `MemberStatusStrip`, and the three-band modal.
- `[x]` `admin-frontend/patterns/test-ids.md` — Pattern 5b (the three bands, and
  `toBeInViewport()` over `toBeVisible()`) and Pattern 5c (a status region keyed
  by `data-tone` rather than by translated text).

---

## Acceptance criteria (#830)

| Criterion | State |
|---|---|
| One status strip; the requirements panel and SEPA alert are gone | `[x]` |
| Every gap names its consequence and jumps to its field | `[x]` |
| Satisfied required fields carry no marker; missing ones an orange pill and border | `[x]` |
| Helper paragraphs replaced by placeholder + info tooltip | `[x]` (the zero-limit line stays — see Decisions) |
| At 1440×900 the dialog opens with *Speichern* visible, no scrolling | `[x]` |
| Footer stays visible while the body scrolls, desktop and mobile | `[x]` |
| Mobile pinned header shows the compact summary once the strip is out of view | `[x]` |
| #392 (save preview) and #131 (cleared-value count) behaviour preserved | `[x]` |
| Unit, E2E desktop and E2E mobile coverage; all green | `[x]` (mobile on Chromium here — see M5) |
