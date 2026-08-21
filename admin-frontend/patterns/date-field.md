# Date Field Pattern

**Use `DateField` for every date input in the admin panel. Do not use
`<input type="date">`.**

```tsx
import { DateField } from '../components/forms/DateField'

<DateField
  testId="members-form-dob-input"
  mode="birthdate"
  required
  value={formData.date_of_birth}          // ISO 'YYYY-MM-DD', or ''
  onChange={(iso) => setFormData({ ...formData, date_of_birth: iso })}
  min={EARLIEST_BIRTH_DATE}
  max={toIsoDate(new Date())}
  invalid={Boolean(formErrors.date_of_birth)}
  describedBy={formErrors.date_of_birth ? 'members-form-dob-error' : undefined}
/>
```

---

## Why not the native control

`<input type="date">` is three different controls. Chrome renders a text field
with segments plus a calendar; Firefox and WebKit disagree about what the
picker even is; on a phone it is a spinner wheel. All of them open on **today**,
which is the wrong place for the one date the panel now requires:
[ADR-0045](../../adr/0045-age-restricted-products.md) made `date_of_birth`
mandatory on every member, and reaching 1979 from today's month is either a
hundred clicks on a chevron or the undocumented trick of typing over the year
segment. The screenshot that opened the issue showed a picker sitting on
*April 2026* while the field held today's date.

Nothing about that is fixable from the outside — the native picker takes no
`openTo`, no min-year hint and no styling — so the panel owns the control.

## What the pattern is

| Decision | Why |
|----------|-----|
| **Typing is the primary path** | A birth date is *remembered*, not chosen. `23111979` becomes `23.11.1979` as you type, `1.1.1990` and a pasted `1979-11-23` are both understood, and a two-digit year resolves into the past (`79` → 1979) |
| **The calendar is one tap away, not in the way** | For the dates that *are* chosen — a report range, a mandate date — and as the fallback for everyone else |
| **Month and year are each their own grid** | The header's month name and year are buttons. Year block → year → month → day is three taps to any date in living memory. This is the "quick selection of year and month" requirement, and the reason the calendar has three views rather than one with chevrons |
| **Touch gets a bottom sheet** | A 320px popover anchored to a field halfway up a scrolling modal is off-screen or under the keyboard. Below 768px (`useBreakpoint`) the calendar is a full-width sheet on a backdrop with ≥44px targets |
| **ISO on the wire, locale on screen** | The value is always `YYYY-MM-DD`; the display order and separator come from `Intl` (`de` → `23.11.1979`, `en-US` → `11/23/1979`) |
| **Bounds are enforced in the field** | `min`/`max` grey out days, months, years *and* the navigation that would reach them, and a typed date outside them is refused with a message rather than sent to the API |

### Birth-date mode

`mode="birthdate"` adds the two things a birth date specifically wants:

- The calendar opens on the **year view** when the field is empty, paged
  around 30 years ago rather than around today.
- The **age** the date implies is shown under the field. A slip in the year is
  invisible in `12.03.1997` and obvious as "29 Jahre" — and the age is exactly
  what the terminal computes when it refuses a restricted drink.

Set `min` to a floor (the members form uses today − 120 years) and `max` to
today. Both are ordinary props; the mode does not assume them.

## Anatomy

```
src/utils/dateField.ts        pure logic — format spec, mask, parse, calendar math
src/components/forms/DateField.tsx    the input, the hint line, popover vs. sheet
src/components/forms/DateCalendar.tsx the three views and the keyboard model
```

Every date calculation lives in `utils/dateField.ts` and is unit-tested there
(`src/utils/**` is the coverage scope — see `vite.config.ts`). The components
are covered by Playwright: `tests/admin/date-field.spec.ts` and
`tests/admin-mobile/date-field-mobile.spec.ts`.

## Test IDs

`testId` is the base; the field publishes a fixed set beneath it, so a page
object never needs to know the internals:

| Test ID | What it is |
|---------|------------|
| `{testId}` | the text input — `fill()` accepts an ISO string |
| `{testId}-value` | hidden input holding the **ISO** value; assert on this |
| `{testId}-hint` | the line under the field: format hint, age, or error |
| `{testId}-open-calendar` | the calendar button |
| `{testId}-sheet` | the mobile backdrop (mobile only) |
| `{testId}-calendar` | the panel |
| `{testId}-calendar-prev` / `-next` | header navigation, whatever the view |
| `{testId}-calendar-month-view-button` / `-year-view-button` | the header's month/year jumps |
| `{testId}-calendar-year-range` | e.g. `1960 – 1979` |
| `{testId}-calendar-day-YYYY-MM-DD` | a day cell |
| `{testId}-calendar-month-N` | a month cell, 1-based |
| `{testId}-calendar-year-YYYY` | a year cell |
| `{testId}-calendar-today` / `-clear` / `-close` | footer actions |

**Assert on `{testId}-value`, not on the visible input**, so a spec does not
break when the panel is switched to English.

## Accessibility

- The input keeps `required`, and an unreadable entry is pushed into
  `setCustomValidity`, so the browser blocks the submit with the same message
  the field shows — a field whose text was edited into nonsense never submits
  the last good value it happened to hold.
- The panel is a `role="dialog"`; the day grid is a `role="grid"` with roving
  `tabindex`, `aria-selected` on the chosen day and `aria-current="date"` on
  today. Arrow keys move by day, `PageUp`/`PageDown` by month (`Shift` for a
  year), `Home`/`End` within the week, `Escape` closes and returns focus.
- `aria-live` announces the month, the year, or the year range being shown.
- The sheet traps `Tab`; the popover deliberately does not — it sits beside its
  field and `Tab` should leave it.

## Pitfalls

- **Do not read the visible input in a test.** It renders in the admin's
  locale. `{testId}-value` is the contract.
- **Do not pass a `Date`.** Props and `onChange` are ISO strings; build them
  with `toIsoDate` (never `toISOString()`, which shifts the day — #95).
- **A toolbar needs a width.** `variant="filter"` is the compact padding, but
  the field fills its container; give it one (`DATE_FILTER_WIDTH` on the
  Reports and Audit-Log toolbars).
- **`clearable` is for optional dates**, and adds the Clear action to the
  calendar footer. A required field should not offer it.
