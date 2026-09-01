# ADR-0052: A Member Registers Themselves; an Admin Attests the Paper

**Status**: Proposed

**Date**: 2026-08-31

**Relates to**: [ADR-0006](./0006-sepa-mandate-reference-strategy.md) (the reference is minted before the paper is printed), [ADR-0016](./0016-transport-security.md), [ADR-0020](./0020-sepa-mandate-requirement-terminal-access.md), [ADR-0029](./0029-two-tier-retention-and-erasure.md), [ADR-0031](./0031-production-hardening-on-shared-hosting.md), [ADR-0036](./0036-iban-encryption-sealed-box.md) (no exception), [ADR-0037](./0037-mandate-documents-not-retained.md), [ADR-0044](./0044-tiered-admin-roles.md)

---

## Context

Joining a club today is a transcription exercise. The prospective member fills
in a blank PDF ([#360](https://github.com/dgloeckner/clubbar/issues/360),
[#175](https://github.com/dgloeckner/clubbar/issues/175)) or a paper form at the
bar, hands it over, and a Kassenwart types all of it a second time into
`POST /api/admin/members` (UC-A11). The most error-prone field in the system is
the one being copied by hand from somebody else's handwriting: a 22-character
IBAN, whose only defence is a mod-97 checksum that catches a transposition but
happily accepts a digit read as another digit in a valid account number.

That transcription also sits on the critical path to a working membership.
ADR-0020 hard-gates terminal access on an active mandate, so until the treasurer
has found the time to type the form, the new member cannot buy anything. In a
club, "found the time" is measured in weeks.

The fix is obvious in shape — let the member type their own data on their own
phone, from a QR code on the wall — and it is exactly the shape that makes three
things easy to get wrong:

1. **A write endpoint with no session.** Everything else in this system either
   carries an admin session cookie or a terminal bearer token. A public endpoint
   that creates rows is a new class of surface, and the naive version of it is an
   open funnel from the internet into the treasurer's inbox.
2. **An IBAN arriving where the server can read it.** ADR-0036's guarantee is
   that a database dump yields no readable IBAN, because the server holds only
   the public half of the keypair. A pending-registration store is a second place
   IBANs live; if it is any weaker than `mandates`, the guarantee is gone and
   nothing announces that it went.
3. **A member who exists before anybody checked the paper.** The signed SEPA
   mandate is what makes a collection lawful. A flow that turns a form submission
   into a member turns an unverified stranger into somebody the terminal will
   serve.

The last of these is the one that decides the shape of everything else, so it is
stated first and as an invariant rather than as a rule: **a pending registration
is not a member.** It creates no `members` row and no mandate, so
`GET /api/sync/members` cannot return it, so no terminal can recognise the
person — not by policy, but because there is nothing to recognise.

## Decision

**A member fills in their own details behind a URL secret; the submission is
sealed and parked in a store nothing else reads; an admin, holding the signed
paper, approves — and approval is the only thing in the system that turns a
registration into a member and a mandate.**

### 1. The gate is a URL secret, carried in the fragment

The QR code encodes `https://<club>/register#<secret>` — 32 random bytes,
base64url, minted by the club and reprintable. The secret is the one and only
gate on the public surface: without it the endpoint answers a uniform refusal, so
a scanner that finds `/api/public/registrations` learns nothing and cannot put a
row in front of the treasurer.

**The secret lives in the URL fragment, never in the path**, and both public
endpoints — read the context, submit — are `POST` at constant URLs taking the
secret in the body, including the one that only reads. There is no third: the
member's sheet comes back in the submission response (decision 5). This is UC-A68's reasoning, applied to a credential with
a much longer life than an invitation link: a request line is written verbatim
into every access log in front of the installation (twice per request in the
shipped package), a fragment is the one part of a URL a browser never sends, and
a request body is written to no log at all. A poster on a clubhouse wall is a
credential that will still be on that wall in two years; putting it in a path
would print it into every log rotation for the same two years.

Stored like an invitation token and for the same two reasons: `secret_hash`
(SHA-256, the lookup key, so a dump yields no working poster) plus a
`SymmetricSecretBox` copy, so an admin can *reprint the poster* without rotating
the secret and invalidating the one already on the wall.

### 2. Availability is an explicit, member-facing state — and it fails closed

The club can switch self-registration off and give a reason addressed to the
people scanning the code (*„Beta-Phase schon voll"*). Three answers, and the
difference between them is the whole point:

| Request | Answer | Why |
|---|---|---|
| No secret, or a wrong one | `registration_unavailable`, no detail | An anonymous prober must not learn that a valid secret exists, nor that this club runs the feature |
| Right secret, switched off | `registration_disabled` **plus the club's reason text** | The person is standing in the clubhouse holding a poster the club printed; a blank refusal is a bug report |
| Right secret, switched on | The form context | — |

**The refusal is enforced on the submission endpoint, server-side.** Not
rendering the form is a UI convenience, not a gate.

**Disabled is the default until both preconditions are met** — a poster secret
(this decision) *and* the club's document URL (decisions 5a and 6, one setting
serving both). A fresh installation and a half-finished configuration both answer
"unavailable" rather than accepting registrations nobody is watching for, or
collecting data from somebody who was never told what happens to it.

### 3. The public endpoint is write-only, and what it writes is sealed

`POST /api/public/registrations` validates the payload, resolves the bank name
from the BLZ, allocates the mandate reference, seals the IBAN, writes one row,
and returns. It reads nothing back, it answers no question about stored data, and
its response is identical whether or not the club already knows this person.

The IBAN is stored in **exactly the `mandates` column shape** — `iban_ciphertext`
(sealed box under the active public key), `iban_last4`, `iban_fingerprint`,
`encryption_key_id`. No plaintext column, no second cipher, no "temporary"
weakening for the pending state. ADR-0036 gets no exception here, and the
approval step below is what keeps it that way: approval **moves the ciphertext
verbatim** into `mandates`. The server never opens it, at submission or at
approval, because it cannot.

The plaintext IBAN exists in exactly the window ADR-0036 already permits — inside
the request, for validation, bank-name resolution and fingerprinting — and is
gone when the response is written.

### 4. The mandate reference is allocated at submission, not at approval

ADR-0006 mints a reference when a mandate is opened. Here the reference must
exist *before* the mandate does, because it is printed on the paper the member
signs, and the paper and the stored mandate have to name the same UMR — that is
what makes a returned collection matchable months later.

So `pending_registrations.mandate_reference` is minted at submission from the
row's own UUID, in ADR-0006's format, and **approval carries it into the
`mandates` row unchanged**. A rejected or purged registration takes its
reference with it; references are 32 hex characters from a UUID and are not a
scarce resource.

### 5. The club's whole Anmeldung is what gets filled, and every page survives

The signed artifact is not a sheet this software composes. It is **the club's own
combined Anmeldung** — for FRGS four pages: the form page carrying Anmeldung, SEPA
mandate and the Kenntnisnahme, then the Datenschutzhinweise, then the
Nutzungsordnung. Clubbar fills page 1 and hands back the document whole. Nothing
is superseded, extracted or recomposed; the member signs the same paper the club
has always used, with the tedious parts already typed.

The debtor IBAN is mandatory mandate content under the EPC SDD Core Rulebook, so
it cannot be left off. It need not be *machine-printed*, and that distinction is
what keeps decision 3 exception-free. Two variants, both rendered in-request and
neither persisted (ADR-0037: the signed paper is the Beleg, and this system does
not keep copies of it):

| Variant | Who prints | `iban` | `iban_last4` | Delivery |
|---|---|---|---|---|
| **Member** | The member, during registration | Filled in full, from the plaintext still in memory in that request | Filled | Returned **in the `POST /api/public/registrations` response itself**, `Cache-Control: no-store` |
| **Admin print** | The Kassenwart, at review | **Empty** — hand-written into the IBAN-Kamm at signature | Filled, printed as the `endet auf ****3000` hint | `GET` from the pending row, audited |

The member's document arrives with the submission response and nowhere else.
There is no second endpoint, no download token, and reloading the confirmation
screen cannot re-fetch it — the plaintext IBAN existed only for the length of that
one request, so there is nothing left to render from afterwards. The admin-print
variant is the path that always works: it needs no plaintext at all, printing the
hint from `iban_last4`, so a member who lost the tab is not stuck.

**When the club's document cannot be fetched, the member gets no document — never
a different one.** A webhost outage must not cost a registration that has already
been written, so the submission stands and `document` comes back null. Substituting
a neutral template would be worse than the gap it fills: the applicant would be
handed a mandate they never read, missing the very pages they were pointed at,
since for a real club document pages 2+ *are* the Datenschutzhinweise. The
admin-print variant recovers it later, from data already stored. (This is why
clubbar ships no default template: an instance with no club document URL cannot
switch self-registration on at all — decision 6 — so there is no state in which a
fallback template would be reached.)

**Ort/Datum, the signatures and the Kenntnisnahme checkboxes are never
machine-filled.** They are what the member does *at* signature, by hand, and a
valid template carries no fields for them.

#### How the fill works (settled by the spike, [#786](https://github.com/dgloeckner/clubbar/pull/786))

The club authors the document as **HTML/CSS in its own website repository** —
the same master its ordinary Chromium print already uses — and builds it with
**WeasyPrint `--pdf-forms --uncompressed-pdf`**. Both flags are
load-bearing: the first turns `<input>` elements into native AcroForm text
fields, the second writes a classic cross-reference table, which the free FPDI
parser and the field enumerator both require. Headless Chromium was cross-checked
on the same HTML and renders a visually identical page with **zero** form fields,
so it cannot substitute.

The AcroForm fields are an **addressing contract, not a form to fill**: clubbar
enumerates their names and rectangles by scanning the raw PDF, imports **page 1**
with FPDI — which does not import annotations, so the output is **flattened by
construction** — draws the values at those rectangles, and then **appends the
remaining template pages unchanged**. The order is not stylistic: FPDF cannot
revisit a page it has moved past, so everything page 1 needs must be drawn before
the Datenschutzhinweise and the Nutzungsordnung are added behind it. Dependencies are
`setasign/fpdf` + `setasign/fpdi` only. FPDM was excluded as unmaintained since
2017; the commercial SetaPDF-FormFiller is the documented fallback if this proves
insufficient.

Two details cost real debugging in the spike and belong in the record: WeasyPrint
writes `/Rect` corners **top-down**, so corner order must be normalized before a
rectangle is usable; and field *borders* are annotation properties, so they vanish
on flatten — a template's writing lines must be page content (CSS borders), or the
admin variant's blank IBAN line prints as nothing at all. Core-font output needs
Latin-1 transliteration.

A third belongs beside them, found against the club's own published document:
**a value is fitted to its field, never drawn at a fixed size.** The reference
club's `mandatsreferenz` field is 108pt wide and holds 32 hex characters, which at
10pt is 166pt of text — 58pt of a member's mandate reference running into whatever
sits beside it, on a document that looks fine everywhere except on paper. The size
steps down until the value fits, with a floor below which it is drawn cramped
rather than clipped: a document that is visibly tight gets looked at, and one that
is silently overlapping does not.

**Field vocabulary — member-specific only:**

| Field | |
|---|---|
| `mandatsreferenz`, `vorname`, `nachname`, `iban`, `iban_last4` | Required. A template missing any of them is refused |
| `geburtsdatum`, `email`, `kontoinhaber`, and creditor fields in the shipped default | Optional: filled when present, ignored when absent |
| `iban_1` … `iban_n` | Optional, and the way a German form actually prints an IBAN: an **IBAN-Kamm**, one box per character, sized for a handwritten letter. A value drawn as one continuous run across a comb lands *between* the boxes rather than in them, so a template that wants its comb filled declares one field per box — which is also how it would be authored in HTML. Each box gets one character of the compact IBAN, centred; whitespace never gets a box. A template with a single wide `iban` field is unaffected |
| Ort/Datum, signatures, the Kenntnisnahme checkboxes | **Not fields.** Done by hand at signature, always |

The creditor block is printed **statically** by a club's template — its identity
belongs in its own document. The neutral DK-Muster default that clubbar ships for
unconfigured instances may instead carry creditor fields, filled from
`sepa_config`.

**Verified three ways.** In the spike's sandbox on PHP 8.4 matching IONOS, against
both the neutral template and the real four-page FRGS master; by the owner **on the
production shared hosting** on 2026-08-31 — *„100% ok"*, which is what #777 made a
precondition of ratifying this ADR; and against the **published** document itself,
which satisfies every clause of the contract above:

| Clause | `Anmeldung_Ruderbar.pdf`, as published |
|---|---|
| Classic cross-reference table, no object streams | `%PDF-1.7`, classic `xref`, no `/ObjStm` and no `/Type/XRef` — FPDI can read it |
| Fields on page 1, other pages plain | `/Count 4`; all eight `/Widget` annotations hang off the first page object, pages 2–4 carry none |
| Required vocabulary present | `mandatsreferenz`, `vorname`, `nachname`, `iban`, `iban_last4` |
| Optional vocabulary present | `geburtsdatum`, `email`, `kontoinhaber` |
| Nothing machine-fillable that must not be | no Ort/Datum, signature or checkbox fields exist |

So the upload-time validation this ADR specifies would accept the live document
unchanged. At roughly 1 MB it is also the reason the render-time fetch is
memoized per request rather than repeated per field.

### 5a. Clubbar pins no template — the club's URL is the template

The obvious design is an admin upload that pins the built PDF inside clubbar.
This ADR rejects it, because the project already answered this exact question and
because the storage has nowhere good to go.

**It was already answered.** [#360](https://github.com/dgloeckner/clubbar/issues/360)
moved the blank mandate out of the application; migration `028` added
`sepa_config.mandate_template_url` for precisely this artifact — *"the club now
maintains a statically hosted registration form elsewhere"*, a link, not a secret
— and ADR-0037 records the same direction in its own Related Decisions: *"#360 —
the blank mandate template is likewise moving out of the app."* **That URL and the
fillable template are one artifact, not two.** The club's WeasyPrint build is what
it points at — for FRGS, the published
[`Anmeldung_Ruderbar.pdf`](https://www.rudern-in-frankfurt.de/media/pages/verein/ruderbar/567d8ff403-1788203644/Anmeldung_Ruderbar.pdf).

**And it is one artifact in a second sense now.** Since the club's document stayed
combined (decision 5), the same published PDF is *also* what the onboarding page
links to discharge Art. 13 — its pages 2+ are the Datenschutzhinweise. A pinned
copy would therefore be a duplicate of a file the page already sends every visitor
to, kept in the one place that cannot be checked against it.

**And storing a copy has no good home.** The backup dumper walks
`information_schema.TABLES` and nothing else (ADR-0049), while
`package/upgrade.php` deliberately preserves `backend/storage/`. So a pinned file
survives an upgrade and is *invisible to backup and restore*: restore a database
and the row claiming a template is pinned comes back while the bytes do not. The
alternative — the first file bytes in the schema — re-solves a closed problem, and
there is no multipart surface in this backend to upload through anyway (the one
place that considered it refused: *"PHP writes multipart parts to temporary files
on disk, which is exactly the persistence this design exists to avoid"*).

**The consequence, accepted deliberately:** filling needs the bytes, so clubbar
fetches them from the configured URL rather than holding them.

| Rule | |
|---|---|
| **Validated when the URL is saved** | Fetched once, `https://` required, body size capped, content type checked, fields enumerated. A URL that does not resolve to a usable template **is not saved** — refused with the typed reason naming the missing field, or telling the club to rebuild with `--uncompressed-pdf` when the cross-reference stream cannot be read |
| **Fetched at render** | Short timeout, memoized for the request, never written to disk or database |
| **Unreachable or unset** | The shipped DK-Muster default renders instead, and the response names which template was used. A club webhost outage must not fail a registration |
| **Not a public-input SSRF** | The URL is set by an `admin` and validated at that moment; the public endpoint dereferences a value only an admin could have written, never one a visitor supplies |

`mandate_template_url` is `VARCHAR(255) NULL` and explicitly unvalidated today
(migration `028`, matching the `mail_config` precedent). Saving it now validates
it — a behaviour change for any instance that has already set it to something that
is not a fillable template, which the admin surface must say plainly rather than
failing later at render.

The wording in #776 decision 3, #780 §5 and #783 §3 still describes an upload;
those issues are being amended to match. The rule that wording protects — no fetch
of an unvalidated URL on a hot path — is kept by validating at save time and
falling back to the shipped default.

### 6. The club's Datenschutzhinweis is linked, never authored here

Art. 13 is an information duty discharged *at collection*, and under this ADR
collection happens in the digital flow. So the notice has to be in front of the
person before they type anything — and it must not be **in** this software.

Club Bar is generic software installed by clubs it knows nothing about. Legal
text shipped in a product is legal text somebody else's lawyer wrote about a
processing situation they never saw; it would be wrong on the day it shipped and
staler every year after. The club already has this document, or has to write it
either way.

**So the club configures a URL, and the onboarding page links it prominently
before any data entry.** No Datenschutz prose lives in this repository, in any
language, and none is embedded in the page.

**And it is the same URL as decision 5a's.** Since the club's document stayed
combined, its Datenschutzhinweise are pages 2+ of the very PDF clubbar fills.
One published file, one setting — `sepa_config.mandate_template_url` — doing both
jobs: what the page links before data entry, and what the fill reads. There is no
second URL to configure and none to keep consistent with the first.

| Question | Answer | Why |
|---|---|---|
| Where is the URL stored? | `sepa_config.mandate_template_url` — **no new column** | It is the column migration `028` already added for the club's hosted registration form, and that form is this document. Adding a second field for the same file would create two settings an admin has to keep pointing at one PDF |
| One URL, or one per language? | **One.** | The club publishes one document, in its own language, and clubbar neither translates it nor knows what language it is in — it stores a link. A member's `preferred_language` therefore selects **no document at all**: the *page* is translated, because that is ordinary app i18n this software owns; the *document* is the club's, and it is what it is |
| What does the row record? | The **exact URL shown** at submission | Not a version: this system does not host the document, so a version is something it cannot observe and would be recording as a guess. A club that wants versioning puts it in the URL. The link is displayed and the member navigates to it themselves; the *fill* fetches the same URL server-side, which is decision 5a's business, not this one's |

**Nothing is ticked on screen.** The link is reachable before any data entry and
carries no checkbox — Art. 13 is a duty to *inform*, discharged by putting the
notice in front of the person, and a box asking them to declare that they read it
starts to look like a consent for processing that rests on Art. 6(1)(b). That is
the LfDI BW *Täuschung* trap that
`research/175-onboarding-form-datenschutz.md` documents:

> **Es empfiehlt sich nicht, Einwilligungen für Datenverarbeitungsmaßnahmen
> einzuholen, die bereits aufgrund einer gesetzlichen Erlaubnis möglich sind.**

**The paper still carries a Kenntnisnahme box, and that is a different thing.**
On page 1, beside the signature, the member ticks by hand that they have taken
note of the Datenschutzhinweise they are holding. It is an acknowledgement of
having been informed, made at the moment of signing, on the same sheet as the
document itself — not a consent, and not something this software fills or records
(decision 5: checkboxes are never machine-filled).

**The URL is the second fail-closed condition.** Self-registration cannot be
switched on without it, and the refusal is typed and named
(`document_url_missing`) rather than a disabled button with no explanation — an
admin who cannot turn a feature on must be told which of the two preconditions
they are missing. Together with decision 2's secret, that is two conditions and
one shipped state: off.

**There are no optional consents in this flow.** Art. 6(1)(a) tick-boxes —
photos, a newsletter — are a separate instrument under the #175 split, and this
club's onboarding uses none: the document carries an Anmeldung, a mandate, an
information notice and the Nutzungsordnung, and nothing to opt into. The digital flow collects exactly
what the mandate needs plus the link to the notice. A club that later wants such
consents needs a store that **survives approval**, because a tick copied onto a
member record with nowhere to keep it is one nobody can honour or withdraw — that
is its own decision, not a gap in this one.

### 6a. The admin prints the club's document, whole

There is no clubbar-composed sheet to print. The admin prints the club's own
Anmeldung — all of it — with page 1 pre-filled and the IBAN line blank beside its
`****last4` hint. Pages 2 onward come through untouched, which is what puts the
Datenschutzhinweise and the Nutzungsordnung in the member's hands at the moment
they sign rather than in a second document they might never open.

Nothing is superseded and no wording is invented: reviewing the template means
checking the club's own document, and the offline route keeps using the identical
file with nothing filled in.

### 7. The account holder may not be the member

The mandate must be signed by whoever owns the account, and in a sports club that
is routinely a parent paying for a child. v1 models this the way the schema
already does — `members.account_holder_name` exists — and no further:
`pending_registrations.account_holder_name` is optional, and when it is set, the
**signature block on the printed mandate names the account holder**, not the
member. When the submitted birth date makes the applicant a minor, the sheet
additionally carries a legal-representative signature line.

A Kontoinhaber as a separate data subject — with their own address, their own
Art. 13 notice and their own erasure rights — is deliberately **not** modelled.
A name on a mandate is the minimum the payment needs; a second person entity is a
schema change with its own GDPR surface and belongs in its own decision.

### 8. Roles are derived from the surface, not from intuition

| Route | Roles | Derivation |
|---|---|---|
| `GET/PATCH /api/admin/registrations…` (list, edit, approve, reject, print) | `[ADMIN, KASSENWART]` | This is member management. ADR-0044's grant table already reads *members — read, create, edit* as `admin` + `kassenwart`, and approval is a member create |
| `PATCH /api/admin/self-registration/availability` | `[ADMIN]` | It sits on the Security & Credentials page beside the secret it depends on, and the two are one decision in practice: switching the feature on is what exposes the public write surface |
| `POST /api/admin/self-registration/secret` (rotate), and reading the poster URL | `[ADMIN]` | Minting a bearer credential for a public write surface is ADR-0044 rule 2 territory — the same reason terminal token rotation is admin-only |
| Setting the club document URL (`sepa_config.mandate_template_url`) | `[ADMIN]` | Already an `admin`-only surface, and a pointer whose wrongness is invisible to everybody except the person who meets it too late — the member reading the notice, or the treasurer holding a document that will not fill |

The Getränkewart appears nowhere: member data is outside their remit on every
surface (ADR-0045 invariant 5).

### 9. Nothing is sent to the address, and nothing is disclosed about it

**No mail at submission.** The address is unverified, so a submission endpoint
that mails it is an email-bombing amplifier pointed at strangers, and UC-A67
rule 1 keeps the welcome the first message a member ever receives — after their
card, not before their membership. A verification link is a deliberate carve-out
for a later decision.

**No enumeration.** A submission naming an email or a person the club already
knows is accepted exactly like any other, and the duplicate surfaces at review,
where an authenticated admin is entitled to see it. The review list flags a
matching `email` or `iban_fingerprint` — the fingerprint being precisely the
comparison ADR-0036 designed for, answerable without a key.

**No personal data in the logs of the public endpoint.** A refusal logs its
reason code and nothing about the person.

### 10. A pending registration expires; a rejected one is gone at once

| Event | Effect |
|---|---|
| 30 days pass (configurable) | The row is purged by the `bin/cron.php` tick, which logs a **count** and no identities |
| Admin rejects | The row is deleted immediately; the audit entry records the act, the reason, and the IBAN **masked** (ADR-0005) |
| Admin approves | `members` + `mandates` rows are created, the ciphertext and the reference move across, the pending row is deleted |

This store is not the retention tier. Nothing here is a Beleg: no money has moved
and no contract has been performed, so ADR-0029's ten-year accounting retention
attaches to none of it. Data about somebody who never joined must not accumulate,
and a queue nobody empties is exactly how it would.

#### What is audited, and what deliberately is not

| Event | Audited | Why |
|---|---|---|
| **Approve** | Yes | The moment a member and a mandate come into existence, carrying the admin's attestation that they held the signed paper and matched `****last4`. It records the **pending registration's id**, so a member's origin stays traceable after the pending row is gone, and a masked IBAN only (ADR-0005) |
| **Reject** | Yes | An admin decided to delete somebody's data. The act, the actor and the reason are exactly what a log is for |
| **Edit** | Yes | Not obviously an authority change, and audited anyway — this is the one place in the system where one person edits another's freshly submitted personal data, including which bank account a mandate will be opened against. The entry is what makes "the IBAN on file is not the one I sent" answerable, and it carries the masked value on both sides, never the number |
| **Print** | Yes | An IBAN hint and a member's details leave the building on paper |
| **Submission** | **No** | It grants nothing. Unlike an accepted invitation — the one sessionless act this log does record, because it makes an account *usable* — a pending registration is not a member and no terminal can see it, so the entry that matters is the approval. And the audit log is retained while this store purges at 30 days: an entry naming the applicant would be a copy of their personal data outliving the deletion that exists to remove them. The trail that does exist is `registration_attempts`, metering submissions per address, and an identity-free application-log line |
| **TTL purge** | **No** | Every automated sweep in this system logs a count and writes no audit rows — the login-attempt prune, mail retention, the rest. Per-row entries here would either name the people just deleted, which is the same leak, or say nothing at all. One aggregate line is what demonstrates the retention runs |

The shape of that table is one rule: **the log follows the actor, not the
activity.** Every act an *admin* takes on somebody else's submission is audited —
approving, rejecting, correcting, printing — because each is a person exercising
authority over another's personal data. Everything the store does by itself is
not: a stranger submitting grants nothing, and the TTL purge is a sweep. Auditing
either would leave an entry naming the applicant behind after the very deletion
that exists to remove them.

### 11. The public page is served by the backend

One deployment, one origin, no CORS, no bundle to host somewhere else — the
constraint ADR-0031 already imposes on everything in the shipped package. It is
**not** the admin SPA: an anonymous visitor on a phone at the bar must not be
served the panel's routes, its strings, or its weight. A small self-contained
bundle under the backend's document root, reachable at `/register`, is what the
QR points at.

## Rate limiting, and the shape of abuse here

The login surface counts *failed* attempts, which is the wrong meter for this
endpoint: a caller holding the poster secret can flood the queue with perfectly
valid submissions. Both meters are needed.

| Dimension | Budget | What it stops |
|---|---|---|
| Refused gate attempts, per IP | The shared `login_attempts` budget, as invitations use | Guessing the secret costs the same as guessing a password |
| Accepted submissions, per IP | Its own counter and window | A member of the club emptying the poster into the treasurer's inbox |
| Body size | Hard cap, before parsing | Payload exhaustion on shared hosting |
| Honeypot field | Silently accepted, never stored | The commodity form-filling bot, which is the traffic this URL will actually attract |

## Consequences

**Positive**

- The IBAN is typed once, by the person who owns it, from the card in their hand.
- Onboarding stops being blocked on a treasurer's evening. The paper still gates
  membership, but the typing is no longer serialised behind it.
- The pending store is *weaker in privilege* than `members` and no weaker in
  cryptography: same sealed box, same key generation, same fingerprint.
- Approval becomes an explicit, audited attestation — "I have the signed mandate
  and the hand-written IBAN ends in 3000" — where today it is a side effect of
  data entry.
- The club gets an off-switch it can explain to the people it affects.

**Negative, and what is done about it**

| Cost | Mitigation |
|---|---|
| A new unauthenticated write surface exists | One secret gate, two rate meters, a body cap, a honeypot, write-only semantics, uniform refusals, and no mail |
| A poster secret is long-lived and physically copyable | Rotatable without redeploying, reprintable without rotating, and it grants nothing but the ability to *submit* — never to read |
| Personal data now sits in a second table before anybody has agreed to anything | TTL purge, immediate delete on reject, no Beleg status, no terminal visibility |
| Two mandate variants means two PDF paths to keep correct | They share one renderer and differ by a single flag; the admin-print variant is the fallback that always works |
| A member could register twice, or register and never appear | Duplicates are visible at review by email and fingerprint; abandoned rows purge themselves |

## Settled by the owner, and what is still theirs to do

| | |
|---|---|
| **The document** | Stays **one** — the club's combined Anmeldung, four pages, fields on page 1. Clubbar fills page 1 and preserves the rest |
| **The template** | Not pinned, not copied: `sepa_config.mandate_template_url` is the pointer (decision 5a), and it is the same URL the page links for Art. 13 |
| **Retention** | An unapproved registration is purged after **30 days** |
| **Translation** | None. One document, in whatever language the club published it in; no club-language setting exists to translate against |
| **Optional consents** | None. This flow is the Anmeldung and its mandate, nothing to opt into |

**The schema delta: two new tables, and no new column at all.**
`pending_registrations` and `self_registration_config` are new;
`sepa_config.mandate_template_url` already exists and does both URL jobs. Per
project convention the two tables need the owner's explicit confirmation before
migration `059` is written — that confirmation is the one thing still outstanding
inside this repository.

**Both external dependencies are now met.** The fill mechanism was verified on the
production hosting on 2026-08-31, and the club has published the built document at
its stable URL. Nothing outside this repository is blocking any more.

## Related

- Epic [#776](https://github.com/dgloeckner/clubbar/issues/776), spec issue
  [#777](https://github.com/dgloeckner/clubbar/issues/777)
- [#175](https://github.com/dgloeckner/clubbar/issues/175),
  [#360](https://github.com/dgloeckner/clubbar/issues/360) — the legal split and
  the combined paper form this splits the roles of: its Anmeldung and mandate
  sections become the generated sheet on the digital route, its
  Datenschutzhinweise become the club's own linked page, and the form itself
  stays whole as the offline fallback; `research/175-onboarding-form-datenschutz.md`
- [frgs-website#33](https://github.com/dgloeckner/frgs-website/pull/33) —
  the club's combined Anmeldung, given AcroForm fields by the same WeasyPrint
  build its Chromium print already used. Delivered, and **published** at its
  stable URL — the one setting decisions 5a and 6 both read
- [#786](https://github.com/dgloeckner/clubbar/pull/786) — the spike that settled
  decision 5's toolchain, vocabulary and fill mechanism
- [UC-P01](../use-cases/public/UC-P01-member-self-registration.md),
  [UC-A17](../use-cases/admin/UC-A17-review-pending-registrations.md),
  [UC-A69](../use-cases/admin/UC-A69-configure-self-registration.md)
- [UC-A11](../use-cases/admin/UC-A11-create-member.md) — the flow approval must
  end up equivalent to, and [UC-A68](../use-cases/admin/UC-A68-invite-admin.md) —
  the public-token precedent this borrows its URL shape from
