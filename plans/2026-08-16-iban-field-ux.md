# Banking Fields UX: IBAN and Mandate Reference

**Issue**: follow-up to [#392](https://github.com/dgloeckner/clubbar/issues/392)
**Governing decisions**: [ADR-0036](../adr/0036-iban-encryption-sealed-box.md) (sealed IBANs), [ADR-0006](../adr/0006-sepa-mandate-reference-strategy.md) (mandate reference)
**Predecessor plan**: [IBAN Encryption (Sealed Boxes)](./2026-08-13-iban-encryption-sealed-box.md) — P0–P8 complete

## Why

ADR-0036 made the member form's IBAN field **overwrite-only**: the stored value
is sealed under the club's public key, the API returns only its last four
characters, and a blank field on save therefore means *keep*, not *clear*. The
UI was nudged to match but not redesigned, and the result stated the opposite of
the truth:

- An empty input carrying the grey placeholder `DE89370400440532013000`, which
  reads as a stored value that has been greyed out. The real stored account sat
  in small text *underneath* it — two competing answers to "what is this
  member's IBAN?".
- A label reading `(SEPA, optional)` for a member who already has bank details,
  where neither "optional" nor "empty" is true.
- No statement anywhere that leaving the field alone keeps the account — while
  the SEPA settings page has carried exactly that sentence for the creditor IBAN
  all along.
- A green "SEPA-Mandat gültig" banner that ignored unsaved changes, so it kept
  announcing validity directly above the line saying the mandate was about to be
  revoked.

The **mandate reference** has the same shape of problem. The server mints it
when the mandate is opened; typing one exists only to carry over a reference
that already exists on paper or in a previous system. The form made that
exception the default: a free-text input with a plausible-looking generated
reference as its placeholder and two sentences underneath explaining that
leaving it empty is the normal thing to do.

**The fix in one sentence**: where a value is already on file, show *it* instead
of an input, and put the actions that change it underneath — so
"overwrite-or-keep" is visible rather than explained.

## Milestones

### M1 — Field components ([#392](https://github.com/dgloeckner/clubbar/issues/392))

- [x] `components/members/StoredFieldBox.tsx` — the read-only box + `StoredFieldAction` text buttons, shared by both fields. Deliberately not an `<input readOnly>`; actions are always `type="button"`
- [x] `components/members/MemberIbanField.tsx` — three states (`stored` / `entry` / `removing`); owns `useBankName`; focus moves into the input on *Change*; the empty `<p>` that used to render for a DE-prefixed invalid IBAN is gone
- [x] `components/members/MemberMandateReferenceField.tsx` — three states (`assigned` / `auto` / `entry`); copy button on the assigned reference via the existing `useClipboardCopy`
- [x] `MembersPage.tsx`: `isReplacingIban` + `isEnteringMandateReference` state, derived modes, `resetBankingFieldModes()` wired into all four open/close paths — including the Cancel button, which previously reset nothing
- [x] Submit payload unchanged: `iban: removeStoredIban ? null : formData.iban || undefined`
- Verify: **passed** — `tsc --noEmit` and `eslint` clean

### M2 — SEPA banner as a live preview

- [x] `utils/sepaStatus.ts` — `deriveSepaFormStatus()`, a pure function over the saved state plus what the form currently holds
- [x] Banner rebuilt on the shared `Alert` component; `data-testid="members-form-sepa-status"` kept; wrapped in `aria-live="polite"`
- Verify: **passed** — `utils/sepaStatus.test.ts`, 12/12 green

### M3 — i18n

- [x] 16 keys added to `public/locales/de.json` and `en.json` (additive; no existing key changed)
- [x] The hardcoded `DE89370400440532013000` placeholder is now localized and `z. B.`-prefixed, matching `settings.sepa.placeholders.creditorIban`
- Verify: **passed** — additive-only diff confirmed against git

### M4 — E2E

- [x] Page object: `beginIbanChange`, `cancelIbanChange`, `fillIban`, `expectIbanInput{Visible,Hidden}`, `undoRemoveStoredIban`, `expectIbanRemovalPending{Visible,Hidden}`, `expectSepaStatusContains`, and the mandate-reference equivalents. `fillMemberForm`/`fillMandateReference` reveal the input themselves, so callers keep expressing intent
- [x] Two existing assertions of a blank input in edit mode replaced by `expectIbanInputHidden()` (`members.spec.ts`, `members-bank-name.spec.ts`)
- [x] Four new flow specs: change-then-cancel keeps the account through a save; change-then-save replaces it; remove-then-undo is a no-op and remove-then-save revokes; mandate reference assigned by default and shown as a value once minted
- Verify: **passed** — `admin-chromium` 308/308 against a rebuilt bundle, including the four new flows. One unrelated failure appeared in a 4-worker run (`ui-features.spec.ts` "should perform logout and redirect to login") and passed 8/8 when its file was run alone: a session-state race under parallelism, in a spec this diff does not touch. `admin-mobile` was **not run** — WebKit could not be downloaded in this sandbox (`dev-setup.sh` reports it as a warning); CI covers that project

### M5 — Docs

- [x] `use-cases/admin/UC-A12-edit-member.md` — new *Banking Fields* section with both state tables; the two statements that clearing the IBAN field revokes SEPA corrected; SEPA status table gains the two preview rows; test derivation rewritten
- [x] `use-cases/sepa/uc-sepa-03-member-iban.md` — main flow gains the *Change* step and is renumbered; AF3 rewritten from "clears IBAN field" to *Remove bank details*; T08 split into remove / leave-alone / cancel
- [x] `admin-frontend/patterns/components.md` — `StoredFieldBox` / `StoredFieldAction` entry under Form Components
- [x] `plans/INDEX.md` row

No ADR change: ADR-0036 §"API surface" already specifies this semantics — the UI
now follows it visibly instead of describing it.

### Out of scope, carried in the same PR

`newSettlement.ineligible.issue.iban` and `.mandate` are swapped in **both**
locale files: `mandateIssue()` names *what is missing*, so a member with no IBAN
is currently reported to the treasurer as "Mandat fehlt". Four values to swap,
its own commit.
