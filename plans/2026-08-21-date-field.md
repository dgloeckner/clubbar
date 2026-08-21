# Date Field: one date control for desktop and mobile

**Status**: Implemented (M1–M5 done and each verified)

Follow-up to [ADR-0045](../adr/0045-age-restricted-products.md) / the Jugendschutz
plan, which made `date_of_birth` **mandatory on every member** and so put a date
control on the critical path of creating one.

The control it landed on was `<input type="date">`, which the whole panel used.
That is three different widgets depending on the browser, and none of them is
usable for a birth date: the reported screenshot showed the picker sitting on
**April 2026** while the field held today's date, and reaching 1979 from there
is either a hundred clicks on a chevron or the undocumented trick of typing over
the year segment. On a phone it is a spinner wheel that also starts at the
current year. None of it is fixable from the outside — the native picker takes
no "open to" hint, no min-year, and no styling — so the panel owns the control.

**What this is not**: a change to what a date *means*. Every value on the wire
stays ISO `YYYY-MM-DD`, no API changed, and no date is stored differently.

## Design

| Decision | Reasoning |
|----------|-----------|
| Typing is the primary path | A birth date is *remembered*, not chosen. `23111979` becomes `23.11.1979` as you type; `1.1.1990`, `1 1 1990` and a pasted `1979-11-23` all parse; a two-digit year resolves into the past |
| The calendar is second, one tap away | For the dates that are genuinely *chosen*: report ranges, a mandate date |
| Month and year each have their own grid | The requirement ("quick selection of year and month as a minimum"). Year block → year → month → day is three taps to any date in living memory |
| Bottom sheet below 768px | A 320px popover anchored to a field halfway up a scrolling modal is off-screen or under the keyboard. 44px targets throughout |
| Locale on screen, ISO on the wire | Order and separator from `Intl` (`de` 23.11.1979, `en-US` 11/23/1979); the value is always ISO, and also exposed in a hidden input for tests and plain form posts |
| `min`/`max` enforced in the field | Disabled days, months, years *and* the navigation that would reach them; a typed date outside them is refused with a message instead of being sent to the API |
| Birth-date mode | Opens on the **year** view when empty, paged around 30 years ago, and shows the **age** the date implies — a slip in the year is invisible in `12.03.1997` and obvious as "29 Jahre alt". That age is what the terminal computes when it refuses a restricted drink |

## Milestones

- [x] **M1 — Date logic (`src/utils/dateField.ts`)**
  Locale format spec from `Intl.formatToParts`, input masking with overflow
  carry, lenient parsing (single digits, any separator, bare 6/8-digit runs,
  ISO), strict calendar validation (31 February is refused, not rolled into
  March), six-row month grids, month/year/day shifting, aligned year pages,
  week start and locale labels, age.
  *Verified*: `npx vitest run src/utils/dateField.test.ts` → **54/54**; whole
  suite **412/412**. Coverage lands in the measured scope (`src/utils/**`).

- [x] **M2 — The control (`DateField` + `DateCalendar`)**
  Text input with the format placeholder and a calendar button; popover
  anchored to the field (repositioned on scroll, because the field usually sits
  in a modal that scrolls) or a bottom sheet on touch; three calendar views;
  roving-tabindex day grid with arrows/PageUp/PageDown/Home/End; Escape bound on
  `window` in the capture phase so it closes the picker and not the form behind
  it; `setCustomValidity` so a field edited into nonsense cannot submit the last
  good value it happened to hold.
  *Verified*: `npx tsc --noEmit` clean, `eslint` clean, and the E2E flows in M4.

- [x] **M3 — Every date input in the panel switched over**
  Members form (date of birth, mandate date), Audit Log filters (from/to),
  Reports filters (from/to, on both filter rows). No `<input type="date">`
  remains in `admin-frontend/src`.
  *Verified*: `grep -rn 'type="date"' admin-frontend/src` → no matches.

- [x] **M4 — E2E, desktop and mobile**
  `tests/admin/date-field.spec.ts` — typing, the year → month → day pick, a
  future birth date refused, Escape, and a full create → API → reopen round
  trip. `tests/admin-mobile/date-field-mobile.spec.ts` — the sheet is
  full-width and bottom-anchored, day cells are ≥44px, typing still works with
  `inputmode="numeric"`, and the backdrop closes without choosing.
  The suite earned its keep immediately: it caught a mask that dropped the
  keystroke after a full segment (`23.11` + `1` → `23.11`), and an
  outside-click test that treated a tap on the sheet's own backdrop as a tap on
  the field.
  *Verified*: `--project=admin-chromium --grep "Date field"` → **5/5**, and the whole `admin-chromium` project **333/333**;
  `--project=admin-mobile` → **54/54**.

- [x] **M5 — Documentation**
  `admin-frontend/patterns/date-field.md` (the pattern, the test IDs, the
  pitfalls), indexed in `patterns/README.md`, `patterns/components.md` and
  `CLAUDE.md`.

## Notes for whoever comes next

- **The terminal is untouched.** It has its own date entry problem and its own
  stack (Flutter); nothing here applies to it.
- **Two-digit years lean into the past** (`79` → 1979, `27` → 1927 as of 2026).
  Every date field in the panel records something that has already happened. A
  field that must accept future dates should say so before reusing that rule.
- **`en` is the only other locale**, and its `en-US` month-first order is what
  the format spec produces. A locale whose numeric format Intl writes with a
  padded separator (`31. 12. 2001`) is handled; one that writes dates
  right-to-left is not, and would need thinking about before being added.
