# UC-P01: Register as a Member by Scanning the Club's QR Code

**Implementation Status**: Not implemented — specified

Implements [ADR-0052](../../adr/0052-member-self-registration-via-qr-code.md).
Feeds [UC-A17](../admin/UC-A17-review-pending-registrations.md) (an admin,
holding the signed paper, turns what this use case produces into a member) and
is configured by [UC-A69](../admin/UC-A69-configure-self-registration.md) (the
on/off switch, the reason text, and the secret this use case's URL carries).

## Actors

- **Prospective Member** — an unauthenticated visitor on their own phone, who
  has just scanned a QR code off a poster. Holds no account, no session and no
  token of any kind; the only thing distinguishing them from any other visitor
  to the same URL is the secret in the fragment they arrived with.
- **The Club** — passive throughout this use case. It generated the secret and
  printed the poster (UC-A69) before this use case begins, and it reads what
  gets submitted only afterwards, in UC-A17. It performs no action here.

## Motivation

Joining today is a transcription exercise: a prospective member fills in a
paper form, hands it to a Kassenwart, and the Kassenwart retypes it — including
a 22-character IBAN whose only defence against a misread digit is a checksum
that catches a transposition and shrugs at a substitution. That retyping also
sits on the critical path to a working membership, because a member cannot use
the terminal without a mandate (ADR-0020), and "found the time to type the
form" is measured in a club in weeks, not minutes.

This use case lets the prospective member type their own data once, on their
own phone, from the card in their own hand. It does not shorten the path to
membership by skipping a check — the signed paper is still what makes the
mandate lawful, and this use case explicitly ends before anyone has seen it.

## Preconditions

- The club has generated a self-registration secret and printed or displayed
  the resulting QR code (UC-A69). Without this step the endpoint answers
  `registration_unavailable` to everyone, by design (see Rules).
- Self-registration is switched on. It is off by default on a fresh
  installation and on any installation where nobody has touched the setting,
  and it cannot be switched on at all until the club has configured **both** a
  poster secret and the URL of its own Datenschutzhinweis (UC-A69).
- The applicant has a phone with a camera or the printed URL, and a browser.
  Nothing else — no app, no account, no prior contact with the club.

## Main Flow

1. The applicant scans the QR code (or types the printed address), opening
   `https://<club>/register#<secret>`. The secret travels in the URL
   **fragment**, so the browser never sends it to the server as part of the
   request line and it never appears in an access log.
2. The page reads the secret out of the fragment in the browser and posts it,
   in the request body, to the read-only context check. Two of the three
   possible answers end the flow here — see **Error Cases** E1 and E2. On the
   third, the page proceeds.
3. Before any field is offered, the page shows a **prominent link to the club's
   own Datenschutzhinweis** — the club's document, at the club's URL, in the
   applicant's chosen language where the club published one — beside an
   **unticked** acknowledgement checkbox. No Datenschutz text is rendered by
   Club Bar itself, in any language. The rest of the form stays inactive until
   the applicant ticks the box.
4. The applicant fills in the form (see **Form Fields**). A hidden field
   present in the markup — the honeypot — is never shown to a person and is
   left empty by one.
5. The applicant submits. The browser validates the same rules the server
   will apply — format and IBAN checksum in particular — before sending, so a
   mistyped IBAN is caught without a round trip.
6. The server re-validates everything, resolves the bank name from the IBAN's
   BLZ, allocates a mandate reference from the row's own UUID, seals the IBAN
   under the club's public key, and writes one `pending_registrations` row. It
   returns the mandate reference and a one-time **download token**, valid for
   30 minutes, and nothing else about what is now stored.
7. The confirmation screen states plainly that the applicant is **not a member
   yet**, and what has to happen next: sign the mandate sheet and bring it, or
   have it brought, to the club — a Kassenwart still has to see it (UC-A17).
8. The applicant downloads the sheet. The browser still holds the IBAN it just
   collected, so it posts that IBAN together with the download token to render
   a **pre-filled** PDF — full IBAN, name, and, when supplied, the account
   holder's name on the signature line instead of the applicant's. The
   response is `Cache-Control: no-store`.
9. The applicant signs the sheet and brings it to the club. This use case ends
   here. Nothing the applicant does after step 8 is observable to the system
   until an admin acts on the pending row.

## Alternative Flow: Registering on Behalf of Someone Else

1. The account that will be debited is not the applicant's own — a parent
   registering a child is the ordinary case. The applicant fills in the
   **applicant's** name and date of birth as the member, and the payer's name
   in the optional **account holder name** field.
2. If the account holder name is set, the downloaded (or admin-printed) sheet
   names the account holder on the signature line, not the applicant — the
   mandate has to be signed by whoever owns the account.
3. If the applicant's date of birth makes them a minor, the sheet additionally
   carries a legal-representative signature line, independent of whether an
   account holder name was given.
4. Nothing about a second person is stored beyond that one name string. No
   separate address, no separate Art. 13 notice, no separate erasure right —
   an account holder is a name on a mandate, not a second data subject.

## Alternative Flow: The Applicant Never Downloads a Sheet

1. The applicant closes the tab at step 7, loses the phone, or the 30-minute
   download token simply lapses before they use it (see Error Cases E5).
2. Nothing is lost that step 6 did not already capture: the pending row, the
   sealed IBAN and the mandate reference all still exist, keyed by the
   applicant's own submission, not by whether a PDF was ever rendered.
3. At review, the admin prints a sheet that needs no plaintext IBAN at all — a
   blank line and a `****<last4>` hint read straight off the sealed row — and
   the applicant writes their IBAN there by hand for the admin to cross-check.
   This path is the one that always works, with or without step 8 above.

## Worked example: a parent registering their child

| Step | What the applicant (a parent registering a 15-year-old) sees |
|------|----------------------------------------------------------------|
| Scans the poster | The club's onboarding page opens directly — no typing, no app store |
| Gate check | Secret valid, registration switched on: the form loads with no mention of the check that just happened |
| Datenschutzhinweis | Full notice text and a version stamp; an unticked box the rest of the form waits on |
| The form | Child's first name, last name and date of birth as the member; the parent's own name in **account holder name** — "if this account isn't yours, whose is it?"; the parent's email and phone as the contact the club can reach; the parent's IBAN |
| Submit | Accepted. Nothing on the response says whether the family was already known to the club |
| Confirmation screen | "You are not a member yet." A download button, and instructions to sign and bring the sheet in |
| The downloaded PDF | IBAN fully pre-filled; the signature line reads the parent's name, with a second line, "gesetzlicher Vertreter", because the member named on the sheet is a minor |
| At the bar | The parent signs and hands the sheet to the Kassenwart — UC-A17 begins there, not here |

## Form Fields

| Field | Required | Validation | Note |
|-------|----------|------------|------|
| First name | Yes | Non-empty, max 100 chars | The member being registered, never the account holder |
| Last name | Yes | Non-empty, max 100 chars | |
| Date of birth | Yes | Date, not in the future | Jugendschutz (ADR-0045); also decides whether the sheet gets a legal-representative line |
| Email | Yes | Valid email format | Never checked against existing members before accepting — a duplicate is accepted like any first-time submission (ADR-0052 §9) |
| Phone | No | Max 20 chars | |
| Preferred language | Yes | ISO 639-1 code from the enabled list | Selects the language this page and the confirmation are shown in |
| IBAN | Yes | Valid IBAN format + checksum | Unlike UC-A11, there is no path through this form that leaves it empty — the point of registering is a signed mandate |
| Account holder name | No | Max 70 chars | When set, the printed signature block names the holder, not the applicant (ADR-0052 §7) |
| Datenschutzhinweis acknowledgement | Yes | Checkbox must be ticked | Starts unticked; the **exact URL** the applicant was pointed at and the moment they ticked are both recorded — not just that a box was ticked. The document is the club's, hosted by the club (ADR-0052 §6) |

**No card UID field.** A card is assigned by an admin, later, and assigning it
is what actually welcomes the member (UC-A67) — self-registration never
touches that step.

**No mandate date field.** The applicant has not signed anything yet at
submission time; the signature date is what the admin records at review, from
the paper in front of them (UC-A17).

## Rules

| Rule | Why |
|------|-----|
| The secret lives in the URL fragment, never the path, and all three public endpoints take it in the request body — including the one that only reads | A fragment is the one part of a URL a browser never sends; a path is written verbatim into every access log in front of the installation, twice per request in the shipped package |
| Three gate answers exist, and only two carry detail | No secret, or a wrong one → uniform `registration_unavailable`, no detail — an anonymous prober must not learn a valid secret exists. Right secret, switched off → `registration_disabled` plus the club's own reason text — the person is standing in the clubhouse holding a poster the club printed. Right secret, switched on → the form |
| Registration is disabled by default until a secret has been generated | A fresh install and a half-configured one both refuse quietly, rather than accepting submissions nobody is watching for |
| The refusal is enforced on the submission endpoint itself, server-side | Not rendering the form is a UI convenience, never the gate |
| The submission endpoint is write-only | It validates, resolves the bank name, allocates the reference, seals the IBAN, writes one row, and returns — it answers no question about what is stored, and its response is identical whether or not the club already knows this person |
| The IBAN is stored in exactly the `mandates` column shape | `iban_ciphertext` + `iban_last4` + `iban_fingerprint` + `encryption_key_id` — no plaintext column and no weaker cipher for the pending state; ADR-0036 gets no exception here |
| The mandate reference is minted at submission, from the row's own UUID | It has to be printed on the paper before a mandate exists, and the paper and the eventual `mandates` row must name the same UMR |
| The member-download PDF needs the download token **and** a matching plaintext IBAN | Neither alone is enough: the token cannot print somebody's IBAN by itself, and an IBAN by itself cannot be tested against a registration whose id the caller does not have |
| The download token is valid for 30 minutes and may be spent more than once inside that window; the response is `Cache-Control: no-store` | A phone that fails to save the sheet on the first tap must not be locked out of its own registration — but a bearer credential for a document holding a full IBAN should not outlive the sitting, nor survive in shared-device history or a proxy cache |
| The admin-print fallback needs no plaintext IBAN at all | It prints `****<last4>` off the sealed row; this is the variant that always works, download route used or not |
| The Datenschutzhinweis is **linked, not authored here**, and its link comes before any data entry | Club Bar is generic software installed by clubs it knows nothing about; legal text shipped in a product is text somebody else's lawyer wrote about a processing situation they never saw. The club configures a URL and the page points at it |
| The notice is acknowledged, never signed, and the printed sheet never carries it | Art. 13 is an information duty discharged at collection, which happens here, on screen; merging it into the signed mandate would manufacture an apparent Einwilligung for processing that already rests on Art. 6(1)(b) |
| The row records the URL shown, not a version number | This system does not host the document and must not fetch it — an admin-supplied URL retrieved server-side is an SSRF primitive — so the URL displayed is the most it can honestly record. A club that wants versioning puts it in the URL |
| A language with no configured document falls back to the club's default, and the page says which language it is in | Requiring every club to publish a translation before it can onboard anybody would block the feature on work Club Bar cannot do for them; silently showing a German document to somebody who chose English is the Art. 12(1) failure |
| No mail is sent by submitting | The address is unverified; a public endpoint that mails it is an email-bombing amplifier aimed at strangers, and the welcome mail is earned by a card, not a form (UC-A67 rule 1) |
| No enumeration | A submission naming an email or an IBAN the club already knows is accepted exactly like any other; the duplicate surfaces only at review, to an authenticated admin, flagged by matching email or fingerprint |
| A honeypot field is silently accepted and never stored | The traffic this URL will actually attract is commodity form-filling bots, not a targeted attacker |
| Two rate meters, not one | The login surface counts failed attempts; here a caller holding the real secret can flood the queue with perfectly *valid* submissions, so accepted submissions get their own per-IP counter alongside the refused-gate counter, which uses the shared budget invitations use |
| A pending row purges after 14 days (configurable); a rejection deletes it immediately | Nothing here is a Beleg — no money has moved and no contract has been performed — so none of the ten-year accounting retention attaches, and data about somebody who never joined must not accumulate |
| A pending registration creates no `members` row and no `mandates` row | `GET /api/sync/members` cannot return it, so no terminal can recognise the applicant — not by policy, but because there is nothing there to recognise |
| No personal data appears in the logs of the public endpoints | A refusal logs its reason code and nothing about the person who triggered it |
| The page is served by the backend itself, as its own small bundle | One deployment, one origin, no CORS, matching the constraint ADR-0031 already imposes — and it is deliberately **not** the admin SPA: an anonymous visitor on a phone must not be served the panel's routes, strings or weight |
| Mobile-first: single column, large tap targets, no assumption of a keyboard | The only device this page will ever be opened on is the phone that just scanned the poster |

## Postconditions

- A `pending_registrations` row exists, holding the sealed IBAN in the
  `mandates` column shape, the minted mandate reference, the URL of the notice
  the applicant acknowledged and when they did, and a 14-day expiry clock.
- No `members` row and no `mandates` row exist. The applicant is not a member;
  the terminal has no way to recognise them.
- The download token lapses on its own 30 minutes after it was issued, used or
  not, and its expiry affects nothing else about the pending row.
- No mail has been sent to the applicant, and nothing about the submission has
  been disclosed to anyone but the applicant themselves.
- No audit log entry is written. This use case runs with no session; the
  audit trail begins at review (UC-A17), the first point an authenticated
  actor performs an act on the row.

## Error Cases

### E1: No secret, or a wrong one
`registration_unavailable`, with no further detail. Identical whether the
secret is missing, malformed, expired-by-rotation, or simply never valid — an
anonymous caller must not be able to tell a live club running this feature
from one that is not, or a near-miss secret from a random guess.

### E2: The right secret, but registration is switched off
`registration_disabled`, together with the club's own configured reason text
(e.g. *"Beta-Phase schon voll"*). The person holding this secret is standing
in the clubhouse in front of the poster the club printed; a blank refusal here
reads as a bug, not as a policy.

### E3: Validation failed
Field-specific errors, shown next to the offending field; the form is not
submitted. Mirrors UC-A11's rules for name, date of birth, email and IBAN —
this form collects no field UC-A11 does not already validate the same way.

### E4: The applicant is a duplicate (known email, or a matching IBAN)
Accepted exactly like a first-time submission, with an identical response. The
club does not learn about the match until an admin opens the pending list in
UC-A17, where it is flagged by a matching `email` or `iban_fingerprint` — the
fingerprint being precisely the comparison ADR-0036 built for, answerable
without ever opening the ciphertext.

### E5: The download token has expired, or the IBAN does not match
The sheet is not rendered. The token was returned once, in the submission
response, and cannot be reissued — the applicant is pointed at the fallback
that never needed the token: bring the pending registration's mandate
reference in person and have the admin print the blank-IBAN sheet at review.

### E6: A rate limit is hit
Refused with a generic throttling message, on whichever of the two meters
tripped — repeated refused-gate attempts, or repeated accepted submissions,
both counted per source IP.

### E7: The honeypot field is filled in
Accepted with an ordinary-looking 200 response. Nothing is stored, and nothing
in the response tells the filler that anything different happened.

## Test Derivation

- No secret and a wrong secret both answer `registration_unavailable`,
  identically and with no distinguishing detail
- A right secret with registration switched off answers `registration_disabled`
  and returns the club's configured reason text
- A right secret with registration switched on returns the form context, and
  nothing about the club's internal configuration beyond it
- Neither public endpoint's secret appears anywhere in an access log line
- A fresh installation, with no secret generated, answers `unavailable`
- Submitting valid data creates one `pending_registrations` row with a sealed
  IBAN, correct `iban_last4` and `iban_fingerprint`, and no plaintext column
- No `members` row and no `mandates` row exist after submission
- The returned mandate reference matches what the row stores, in ADR-0006's
  format
- Missing or invalid required fields are rejected field by field, both in the
  browser and again by the server
- Submitting without ticking the Datenschutzhinweis box is refused
- A successful submission records the **exact notice URL** shown, not just a
  timestamp
- The page renders a link to the club's configured document and embeds no
  Datenschutz text of its own — asserted by the absence of any such string in
  the served bundle
- An applicant choosing a language the club published no document for is shown
  the default document, and told which language it is in
- A second submission reusing a known email or IBAN is accepted identically to
  a first-time one — no different status code, no different body
- The duplicate is visible at review, flagged by matching email or fingerprint
- The download endpoint renders a PDF only when the token and a fingerprint-
  matching IBAN are both presented; either alone is refused
- The download response carries `Cache-Control: no-store`
- A download attempted inside the 30-minute window succeeds a second time; one
  attempted after the window is refused
- A download attempted with the right token but a different IBAN is refused
- The admin-print variant renders `****<last4>` with no plaintext IBAN
  appearing anywhere in its request or response
- An account holder name different from the applicant's own name is what
  appears on the sheet's signature line, not the applicant's name
- A minor applicant's date of birth adds a legal-representative signature line
  to the sheet, independent of whether an account holder name was given
- The honeypot field, when filled, returns 200 and stores nothing
- Repeated refused-gate attempts from one IP are throttled on the shared
  budget invitations use
- Repeated accepted submissions from one IP are throttled on their own,
  independent counter
- A row older than 14 days is purged by the scheduled tick, logging a count
  and no identities
- Rejecting a pending row deletes it immediately, with the audit entry's IBAN
  masked
- The confirmation screen states the applicant is not yet a member and names
  what to do next
- The registration page renders usably at a phone viewport width, and is
  served from a bundle distinct from the admin SPA's

## Related

- [ADR-0052](../../adr/0052-member-self-registration-via-qr-code.md) — the
  decision this use case implements
- [UC-A11](../admin/UC-A11-create-member.md) — the member record shape this
  form fills in, and the flow approval must end up equivalent to
- [UC-A17](../admin/UC-A17-review-pending-registrations.md) — what happens to
  the row this use case produces
- [UC-A68](../admin/UC-A68-invite-admin.md) — the public-token precedent this
  use case's fragment-secret and constant-URL rules are drawn from
- [UC-A69](../admin/UC-A69-configure-self-registration.md) — the switch, the
  reason text, and the secret this use case depends on already existing
