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

**The secret lives in the URL fragment, never in the path**, and all three
public endpoints — read the context, submit, render the member's sheet — are
`POST` at constant URLs taking the secret in the body, including the one that
only reads. This is UC-A68's reasoning, applied to a credential with
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
(this decision) *and* a configured Datenschutz URL (decision 6). A fresh
installation and a half-finished configuration both answer "unavailable" rather
than accepting registrations nobody is watching for, or collecting data from
somebody who was never told what happens to it.

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

### 5. The IBAN is on the paper, but the server need not be able to print it

The debtor IBAN is mandatory mandate content under the EPC SDD Core Rulebook, so
it cannot be left off the signed document. It need not be *machine-printed*, and
that distinction is what keeps decision 3 exception-free. Two variants, both
generated in-request and neither persisted (ADR-0037: the signed paper is the
Beleg, and this system does not keep copies of it):

| Variant | Who prints | IBAN on the sheet | Where the plaintext comes from |
|---|---|---|---|
| **Member download** | The member, during registration | Fully pre-filled | The browser still holds what the member just typed, and posts it back with the download token. The server renders and forgets |
| **Admin print** | The Kassenwart, at review | A **blank line** with a printed `****3000` hint | Nowhere. The server needs no plaintext: it prints the hint from `iban_last4` |

In the admin-print variant the member writes their IBAN by hand at signature and
the admin cross-checks the last four against the screen. This is the variant that
always works — including for a member who registered from a phone and lost the
tab, and for a club that never enables the member download.

The member-download route is bound by a **download token** returned once in the
submission response (32 random bytes, stored hashed on the row, valid 30
minutes, spendable more than once inside that window — a phone that fails to
save the sheet on the first tap must not be locked out of its own
registration). Rendering the sheet requires the token *and* the plaintext IBAN,
whose fingerprint must match the stored row. Neither alone is enough: the token by
itself cannot print somebody's IBAN, and an IBAN by itself cannot be tested
against a registration whose id the caller does not have. The response carries
`Cache-Control: no-store`.

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

| Question | Answer | Why |
|---|---|---|
| Where is the URL stored? | `instance_config` | It is instance identity, not accounting — ADR-0034's category. Decisive: `GET /api/instance-config` is already the *public* config read, and an anonymous phone at a poster needs this URL with no session to fetch it behind |
| One URL, or one per language? | A **language-keyed map**, ADR-0002's shape, with one entry required | A member picks their language on this very form; pointing a member who chose `en` at a German-only document is the failure Art. 12(1)'s *"in klarer und einfacher Sprache"* is about. Requiring a club to publish two documents is not, so a missing language falls back to the configured default and the page says which language the document is in |
| What does the row record? | The **exact URL shown**, and when the box was ticked | This system does not host the document and must not fetch it — an admin-supplied URL fetched server-side is an SSRF primitive, and a club that wants versioning can put it in the URL (`/datenschutz-2026-08`). Recording a version we cannot observe would be recording a guess |

The acknowledgement stays: an **unticked** box the member actively ticks, saying
they have been pointed at the notice. What the club must be able to prove is
*that it informed*, and a link nobody has to acknowledge proves nothing. Nothing
is signed — a signature here would manufacture an apparent Einwilligung for
processing that rests on Art. 6(1)(b), the LfDI BW *Täuschung* trap that
`research/175-onboarding-form-datenschutz.md` documents:

> **Es empfiehlt sich nicht, Einwilligungen für Datenverarbeitungsmaßnahmen
> einzuholen, die bereits aufgrund einer gesetzlichen Erlaubnis möglich sind.**

**The URL is the second fail-closed condition.** Self-registration cannot be
switched on without it, and the refusal is typed and named
(`datenschutz_url_missing`) rather than a disabled button with no explanation —
an admin who cannot turn a feature on must be told which of the two
preconditions they are missing. Together with decision 2's secret, that is two
conditions and one shipped state: off.

### 6a. The printed sheet is the mandate, and nothing else

The Datenschutzhinweis is therefore **not** on the printed sheet either, and
neither is anything else: the admin prints exactly one page, the SEPA mandate,
for signature.

On the digital route that sheet **supersedes the Anmeldung and mandate sections
of the club's existing combined paper form** — the applicant has already typed
the Anmeldung half, so reprinting it for them to sign again is asking for the
same data twice. The generated sheet takes the club's existing mandate wording
as its baseline rather than inventing new wording, and reviewing the template
means checking it against that form. The combined form stays exactly as it is
for the offline route: somebody who will not use a phone still fills in one
sheet of paper.

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
| `PATCH /api/admin/self-registration/availability` | `[ADMIN, KASSENWART]` | The switch belongs to whoever is running the onboarding table |
| `POST /api/admin/self-registration/secret` (rotate), and reading the poster URL | `[ADMIN]` | Minting a bearer credential for a public write surface is ADR-0044 rule 2 territory — the same reason terminal token rotation is admin-only |
| Setting the Datenschutz URL (`instance_config`) | `[ADMIN]` | It is already an `admin`-only surface, and it is the club's published legal pointer — the one field here whose wrongness is invisible to everybody except the person who reads it too late |

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
| 14 days pass (configurable) | The row is purged by the `bin/cron.php` tick, which logs a **count** and no identities |
| Admin rejects | The row is deleted immediately; the audit entry records the act, the reason, and the IBAN **masked** (ADR-0005) |
| Admin approves | `members` + `mandates` rows are created, the ciphertext and the reference move across, the pending row is deleted |

This store is not the retention tier. Nothing here is a Beleg: no money has moved
and no contract has been performed, so ADR-0029's ten-year accounting retention
attaches to none of it. Data about somebody who never joined must not accumulate,
and a queue nobody empties is exactly how it would.

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

## Open questions for the owner

1. **Optional consents (Art. 6(1)(a)).** The #175 instrument split keeps
   photo/newsletter consents separate from both the mandate and the Art. 13
   notice, and #776 asks for them to be stored with the registration. There is
   nowhere on `members` for them to go at approval, and a consent record that
   vanishes when the member is created is worse than none — the club would hold
   a tick nobody can honour or withdraw. This draft therefore records **only**
   the Art. 13 acknowledgement (the URL shown and the timestamp) in v1 and
   proposes a `member_consents` store as its own issue. Confirm, or accept the
   consent store in this epic.
2. **TTL length.** 14 days is a guess calibrated on "the treasurer looks weekly".
3. **Schema.** `pending_registrations` (below, and in `docs/erm-master.md`) is a
   new table and needs explicit confirmation before migration `059` is written.
   Decision 6 additionally adds a language-keyed `privacy_policy_urls` to
   `instance_config`.
4. **The club's document has to exist.** Decision 6 links a document this
   software does not host, so the feature cannot be switched on until the club
   publishes one at a stable URL — for FRGS,
   [frgs-website#32](https://github.com/dgloeckner/frgs-website/issues/32),
   which splits the Datenschutzhinweise out of the combined paper form. That is
   an **external dependency**, outside `feat/user-onboarding` and outside this
   repository; the enable gate is what keeps its absence from being silent.

## Related

- Epic [#776](https://github.com/dgloeckner/clubbar/issues/776), spec issue
  [#777](https://github.com/dgloeckner/clubbar/issues/777)
- [#175](https://github.com/dgloeckner/clubbar/issues/175),
  [#360](https://github.com/dgloeckner/clubbar/issues/360) — the legal split and
  the combined paper form this splits the roles of: its Anmeldung and mandate
  sections become the generated sheet on the digital route, its
  Datenschutzhinweise become the club's own linked page, and the form itself
  stays whole as the offline fallback; `research/175-onboarding-form-datenschutz.md`
- [frgs-website#32](https://github.com/dgloeckner/frgs-website/issues/32) —
  **external dependency**: the club publishes its Datenschutzhinweise at a
  stable URL, which is what decision 6 configures
- [UC-P01](../use-cases/public/UC-P01-member-self-registration.md),
  [UC-A17](../use-cases/admin/UC-A17-review-pending-registrations.md),
  [UC-A69](../use-cases/admin/UC-A69-configure-self-registration.md)
- [UC-A11](../use-cases/admin/UC-A11-create-member.md) — the flow approval must
  end up equivalent to, and [UC-A68](../use-cases/admin/UC-A68-invite-admin.md) —
  the public-token precedent this borrows its URL shape from
