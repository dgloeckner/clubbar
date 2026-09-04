# UC-A70: Send the Anmeldelink to a Prospective Member

**Implementation Status**: Implemented ([#821](https://github.com/dgloeckner/clubbar/issues/821)) —
`POST /api/admin/registrations/link`, the send control in the `/registrations`
page header and its empty state, and the `registration_link` mail kind. Verified
by `api-tests` `self-registration.spec.ts` (9 specs for this use case),
`admin-chromium` `registrations-inbox.spec.ts` (3), the `mail-anmeldelink`
chain project (3) and 6 component tests for the double-click guard. The link
also fills in the address it was sent to
([#823](https://github.com/dgloeckner/clubbar/issues/823)), covered by the same
chain project and by `register` `onboarding.spec.ts`.

## Actors

- **Admin** and **Kassenwart** — either may send. The mail points at the
  registration surface, whose review routes are `TREASURY`, and the rule is to
  mirror the grant on the surface the mail points at (ADR-0044, ADR-0053).
- **Prospective member** — receives the message. Has no account, no member
  record and no row of any kind in this database, and this use case creates
  none.
- **Getränkewart** — reaches neither the control nor the endpoint. Member
  onboarding is outside their remit on every surface (ADR-0045 invariant 5).

## Motivation

The QR poster (UC-A69) reaches exactly the people standing in the clubhouse. A
club that meets somebody at a regatta, or takes an enquiry by mail, has no path
at all — so a Kassenwart pastes the URL into their private WhatsApp, unbranded,
unrecorded, and from a person rather than from the club.

This is the club sending it instead, from the club's own address, in the club's
own words, with a record that it happened.

## Preconditions

- Caller is signed in and holds `admin` or `kassenwart`.
- Self-registration can actually answer the link. All three of the availability
  switch's preconditions must hold: registration switched **on**, a poster
  secret exists, and the club document URL is configured (UC-A69). Each is
  refused by name — see E1–E3.

## Main Flow

1. The admin opens **Anmeldungen** (`/registrations`) and chooses **Anmeldelink
   senden**, from the page header or from the empty state's prompt.
2. They type the prospective member's email address. Nothing else is collected:
   there is no name field, because nothing about this person is stored.
3. The system checks the three preconditions and refuses by name if any fails
   (E1–E3). **Sending is a promise** — a link to a refusal page makes the club
   look broken to the person it is courting.
4. The system queues **one** message: `member_id` and `admin_user_id` both NULL,
   `recipient` the typed address, `dedup_key` a fresh nonce, language German.
5. The system writes an audit entry naming the admin, the act and the address.
6. The screen confirms, naming the address it queued and saying the message goes
   out with the next send run — not that it has arrived.
7. On the scheduler's next drain the message is rendered and sent. The link is
   built **at send time** from the club's current poster secret, and carries the
   address the message is going to:
   `{appUrl}/register#<secret>&email=<urlencoded address>` — the poster's own
   URL, plus the one thing this reader cannot get wrong. The address is in the
   **fragment**, for the reason the secret is: a query string is part of the
   request line and would put a prospective member's address into every access
   log in front of the installation.
8. The recipient opens the link and sees the registration form (UC-P01) with the
   E-Mail field already filled in — editable, like every other field. They
   submit. Their row appears in the inbox the admin sent from, indistinguishable
   from one produced by the wall.

## Alternative Flow: sending again to the same address

1. The admin sends a second time to an address they have already written to —
   normally because the person says they never got it.
2. A second message is queued and a second message is delivered. This is the
   intent, not an accident: the unique index exists so a repeating *scan* is
   idempotent, and there is no scan here (ADR-0053).
3. The **UI** guards the impatient double click: the send button disables itself
   for the duration of the request and stays disabled while its confirmation
   stands. Editing the address re-arms it, because a corrected address is the
   deliberate second send.

## Alternative Flow: the club rotates its poster secret afterwards

1. An admin rotates the secret on Security & Credentials (UC-A69).
2. Every link already delivered stops working, at once, exactly as every printed
   poster does. Nobody is notified; a reader who follows an old link simply
   reaches `registration_unavailable`, indistinguishable from a wrong guess.
3. The rotation screen says so before it is confirmed, and points at
   **Benachrichtigungen** — where the addresses that were written to are listed,
   so the club can send again rather than having to remember who.

## Exception Flows

### E1: Self-registration is switched off
Refused with `registration_disabled`. Nothing is queued and nothing is audited.
The admin fixes it on Security & Credentials, where the switch lives.

### E2: No poster secret has ever been generated
Refused with `registration_no_secret`. There is no link to send.

### E3: No club document URL is configured
Refused with `document_url_missing`. Under the one-document ruling (ADR-0052
decision 6) the club's published Anmeldung is both the Art. 13 notice and the
print template, so without it the form has nothing to show an applicant before
collecting their name, birth date and IBAN — the public endpoint would refuse
the submission anyway.

### E4: The address is missing, blank or not an address
Refused (422) before anything is queued. Validated as an address and nothing
more: there is no membership check, no duplicate check and no verification step.

### E5: A Getränkewart attempts the send
403, from the route's own grant. The control is not rendered for them either,
but the endpoint is the gate.

### E6: The club changes its mind between the send and the drain
The message fails rather than being delivered — the builder checks the same
preconditions again at send time. The failure is recorded against the message,
where an admin sees it under Benachrichtigungen and can fix the club's state and
send again. A link to a refusal page is worse than a message that visibly did
not go.

### E7: The address was mistyped
Nothing detects this. There is no bounce path back into the panel, and nothing
is gated on delivery. The failure is self-announcing — the person says they
never got it — and self-healing: the admin corrects the address and sends again.

## Rules

| Rule | Why |
|------|-----|
| The link is the poster's own URL, verbatim | The secret is printed on a wall the public walks past; a copy in an inbox reaches nobody the wall did not (ADR-0053) |
| No token is minted, and nothing expires or can be revoked | There is no per-recipient credential to mint. A per-send secret would make `self_registration_config` hold a *set*, and rotation would stop being one instant, total act |
| Nothing is stored about the recipient | ADR-0052 decision 10 — a queue nobody empties is how personal data about somebody who never joined accumulates. `mail_outbox.recipient` is the whole invitation history |
| The queued row names no member and no admin | This person has no row anywhere, which is the design rather than a gap in it. Both id columns are NULL |
| The subject is the registration surface, not the person | What the message is *about* is the club's open door; there is no record of the person to point at |
| Sending is refused unless the club can answer the link | A poster has an excuse for going stale, being paper. A mail composed one second ago does not |
| The preconditions are checked twice — at send and at drain | The club can change its mind in between, and the second check is what stops the queue delivering a promise the first one made |
| `dedup_key` is a per-send nonce | The unique index makes a repeating *scan* idempotent. A key of the bare address would refuse the re-send that answers "I never got it", silently, behind a success response |
| The double click is guarded in the UI | That is where the mistake happens. Guarding it in the database would refuse the deliberate second send too |
| `[ADMIN, KASSENWART]`, while the poster's own controls are `[ADMIN]` | Mirror the grant on the surface the mail points at: the review inbox is TREASURY, and reading the secret back is not — reading the poster *is* reading the plaintext credential (UC-A69) |
| The control sits on `/registrations`, not in Settings | Settings is `ADMIN_ONLY`; one button there would lock out the Kassenwart whose queue this is, and splitting that tab's role set is the drift ADR-0044's default-deny prevents |
| German, frozen at enqueue | There is no club-level default language to read, and inventing one as a side effect of this feature was rejected (deferred to [#820](https://github.com/dgloeckner/clubbar/issues/820)) |
| The body states that a signed paper form is part of joining | The biggest surprise in the flow. A poster-scanner is in the building and learns it in a minute; somebody reading a link at home learns it only if the message says so |
| The body names no expiry | Because there is none. Naming a lifetime the system does not enforce is a promise nobody keeps |
| The link prefills the E-Mail field with the address it was sent to, in the fragment ([#823](https://github.com/dgloeckner/clubbar/issues/823)) | The reader is the one person who cannot get that field wrong, and it is the first thing the form asks them for. In the fragment because it is personal data and a query string is written into every access log; nothing new is stored — it is the outbox row's own `recipient` |
| The prefilled value stays editable, and an implausible one is dropped | The club may have typed it wrong, and a reader may want their statements elsewhere. A prefill the form would then reject hands a visitor an error they did not make |
| The poster's URL is unchanged | A wall reaches nobody in particular, so there is nobody to fill in — and every printed poster must keep working, unreissued, for years |
| An audit entry carries the address | An admin causing the installation to write to a named third party is the shape of everything else in the log. It ages out with the log rather than being exempted |
| The response is 202, never 200 | Nothing has been delivered when it returns. An admin who reads "sent" tells the person waiting something untrue |

## Postconditions

**After a successful send**
- Exactly one `mail_outbox` row exists, `pending`, addressed to the typed
  address, naming no member and no admin.
- One `registration_link_sent` audit entry names the admin, the act and the
  address.
- Nothing else in the database has changed. No member, no pending registration,
  no invitee record — none of them exist yet, and two of them may never.

**After the drain**
- The recipient holds a message carrying
  `{appUrl}/register#<secret>&email=<urlencoded address>` and saying that a
  signed paper form is part of joining.
- The outbox row is `sent`, visible under Benachrichtigungen with the address it
  went to.

**After a refusal**
- Nothing is queued and nothing is audited. The state is exactly what it was.

## Acceptance Criteria

- A Kassenwart sends from the inbox; Mailpit holds one German message carrying
  the club's current `{appUrl}/register#<secret>&email=<urlencoded address>` and
  stating that the form is printed, signed and handed in
- Opening that delivered link in a browser holding no session reaches the
  registration page and completes a submission that appears in the inbox —
  i.e. the delivered link is genuinely the poster's
- That page opens with the E-Mail field already holding the address the message
  was sent to, and a submission that never touches the field is found in the
  inbox by that same address
- Sending twice to one address delivers twice
- With self-registration switched off, the send is refused with
  `registration_disabled` and nothing is queued
- With no poster secret, the send is refused with `registration_no_secret`
- With no club document URL, the send is refused with `document_url_missing`
- A missing, blank or malformed address is refused (422) with nothing queued
- A Getränkewart is refused (403) and nothing is queued
- The queued row carries NULL in both `member_id` and `admin_user_id`, and a
  `dedup_key` that differs between two sends to one address
- An audit entry names the admin, the act and the address
- The panel renders each refusal in the admin's own language, from the reason
  code, never from the backend's English sentence
- Clicking send twice in the dialog issues one request

## Related

- [ADR-0053](../../adr/0053-anmeldelink-carries-no-credential.md) — the decision
  this specifies, and why the link carries no credential
- [ADR-0052](../../adr/0052-member-self-registration-via-qr-code.md) — member
  self-registration; this extends its reach and changes none of its invariants
- [UC-A69](./UC-A69-configure-self-registration.md) — the poster, the switch,
  the document URL, and the rotation that kills every sent link
- [UC-A17](./UC-A17-review-pending-registrations.md) — the inbox this control
  sits on, and where a submission produced by the link arrives
- [UC-P01](../public/UC-P01-member-self-registration.md) — the page the link
  opens
- [UC-A68](./UC-A68-invite-admin.md) — the message this one is deliberately
  *not* modelled on: an admin invitation carries a working credential
