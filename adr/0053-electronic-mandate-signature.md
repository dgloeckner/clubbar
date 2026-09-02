# ADR-0053: An Adult Signs Their Own Mandate in the Browser; the Record Is the Beleg

**Status**: Proposed

**Date**: 2026-09-02

**Relates to**: [ADR-0052](./0052-member-self-registration-via-qr-code.md) (the paper flow this extends), [ADR-0036](./0036-iban-encryption-sealed-box.md) (no exception), [ADR-0037](./0037-mandate-documents-not-retained.md), [ADR-0038](./0038-transactional-mail-outbox-on-shared-hosting.md), [ADR-0029](./0029-two-tier-retention-and-erasure.md), [ADR-0006](./0006-sepa-mandate-reference-strategy.md), [ADR-0020](./0020-sepa-mandate-requirement-terminal-access.md), [ADR-0028](./0028-legal-constraints-on-money-handling.md) §3, [ADR-0044](./0044-tiered-admin-roles.md), [ADR-0048](./0048-shared-symmetric-crypto-abstraction.md)

**Amends, if accepted**: ADR-0037 (an *electronic* mandate document is retained, sealed — there is no paper to be the Beleg), ADR-0052 (approval attests the signature record where there is no paper; "a submission queues no mail" narrows to the paper path), ADR-0029 (one new retention-tier artefact), ADR-0038 (one new mail subject type; nothing about sending changes)

**Research**: [`research/electronic-signature-onboarding.md`](../research/electronic-signature-onboarding.md) — read it first; this ADR states what was decided, the research states why it holds.

---

## Context

ADR-0052 removed the transcription from onboarding and left one piece of paper:
the applicant prints the filled Anmeldung, signs it by hand, and brings it to the
bar, and the Kassenwart's approval is the attestation that the signed sheet is
in hand. That sheet is still on the critical path — ADR-0020 keeps the new
member off the terminal until the treasurer has seen it — and for a club with
one treasurer evening a month, "seen it" is measured in weeks.

The research establishes that the sheet is not what the law requires. None of
the three declarations on it — Aufnahmeantrag, SEPA-Basislastschriftmandat,
Kenntnisnahme — carries a statutory form requirement; a Satzung "schriftlich"
is satisfied by telecommunicative transmission (§ 127 Abs. 2 BGB); the SEPA
scheme "does not prescribe nor limit the methods of signing electronic mandates"
(EPC132-17); the Bundesbank says a mandate may be given over the Internet and
that the payee's own bank decides whether it accepts that. A simple electronic
signature is *valid*. What it lacks against paper is **proof**: no § 416 ZPO
Urkunde, no § 371a Anscheinsbeweis, and — the part every court in the
neighbouring case law actually turned on — nothing that ties a click to a
person. The courts that rejected electronic declarations wanted a protocol of
the process, the exact text and data, a confirmation from the mailbox on file,
an authentication step, a protected link, and a record that prints. The ones
that accepted them had those.

So the design problem is not "how do we draw a signature on a phone" — a drawn
scribble is legally a typed name — but "what record do we keep, and how does the
treasurer hand it to a bank inside seven business days when a member says *kein
Mandat*". Four constraints of this codebase shape the answer:

1. **The plaintext IBAN exists for the length of one request** (ADR-0036). Whatever is rendered with the IBAN in it must be rendered *in that request*, and whatever is stored must be sealed to the club's public key, openable only by the treasurer's private key at export time.
2. **Mail leaves every 15 minutes at best, hourly on many hosts** (ADR-0038, `cron_interval`). A code the applicant must type while signing would leave them staring at a phone for up to an hour. Confirmation therefore happens *after* signing, by a link, on the applicant's own time — which is the double-opt-in shape BGH I ZR 164/09 accepts.
3. **The club's document is what the applicant signs** (ADR-0052 decision 5) and it is a PDF a phone will not reliably render inline. The declarations must therefore be on the screen as text, and the PDF is the frozen artefact that carries them.
4. **A pending registration is not a member** (ADR-0052). An electronic signature does not change that: approval still creates membership, and a human still checks duplicates and plausibility. What changes is what the human attests.

## Decision

**An applicant who is an adult signing for their own account may sign the
Anmeldung electronically in the browser. The signature is a sealed, hashed
record of the exact document and declarations shown, the typed name, the act's
time and origin, and a later confirmation from the submitted e-mail address —
and that record, not paper, is the mandate's Beleg. Nothing is enabled until
the club has recorded its bank's acceptance of electronic mandates.**

### 1. Scope: adults, own account, opt-in; paper stays for everyone

The electronic path is offered on the review screen, beside the paper path, when
all of the following hold: the club has enabled it (decision 2), the submitted
date of birth makes the applicant an adult, and `account_holder_name` is empty
or equals the applicant's name. A minor's registration and a third-party
account holder keep the paper path with its legal-representative and
Kontoinhaber signature lines — the browser cannot tell who is typing, and the
paper already can. The paper path stays available to every applicant, and a
club that never enables electronic signing sees no change at all.

### 2. Three enable conditions, each refused by name

Electronic signing cannot be switched on unless, in addition to ADR-0052's
document URL:

| Condition | Refusal reason | Why |
|---|---|---|
| The club has recorded its bank's acceptance of electronically given mandates: a date and a free-text note naming the Inkassovereinbarung edition or the bank's written confirmation | `bank_confirmation_missing` | The Bundesbank hands the decision to the "erste Inkassostelle". Without it every electronic collection is one the club cannot defend |
| A membership declaration text is configured, German required, English optional | `membership_declaration_missing` | The Beitritt wording is the club's — it names its Satzung and Ordnungen. Club Bar ships no club prose (ADR-0052 decision 6) |
| `sepa_config.creditor_id` and `creditor_name` are set | `creditor_config_incomplete` | Both are mandatory mandate content (rulebook AT-02, AT-03) and appear in the on-screen mandate text |

The switch, the bank confirmation and the declaration text are `[ADMIN]`
surfaces beside the poster secret; a restore that writes the row directly meets
the same gate in the service, as ADR-0052's did.

### 3. The signature happens inside the submission request

`POST /api/public/registrations` accepts an optional `signature` object:

| Field | Rule |
|---|---|
| `typed_name` | Required. Must equal `first_name last_name` after whitespace and case normalisation — a mismatch is refused (`typed_name_mismatch`), not stored |
| `declarations.membership` | Must be `true`: the first-person membership declaration was accepted |
| `declarations.mandate` | Must be `true`: the first-person mandate authorisation was accepted |

There is deliberately **no** `declarations.privacy_notice`. Art. 13 is
discharged by the link and `privacy_notice_shown_at` (ADR-0052 decision 6); a
mandatory "zur Kenntnis genommen" box is the shape BGH III ZR 368/13 struck
down under § 309 Nr. 12 b BGB, and the statutory exception is handwritten or
qualified signatures only. Every box on the signing screen is a declaration in
the first person, never a confirmation of a fact.

In that one request, with the plaintext still in memory, the server:

1. validates the submission exactly as today and refuses the electronic path when the club's document cannot be fetched and filled (`document_unavailable`) — the applicant may retry or choose paper; a submission is never silently downgraded to paper;
2. renders the **member variant** of the club's document (full IBAN, UMR, no signature drawn — ADR-0052 decision 5 stands) and takes its SHA-256;
3. seals the PDF bytes to the active public key, in the recipient-list container ADR-0049 introduced for archives, so the stored copy is openable by the treasurer's private key and by nothing on the server;
4. builds the **declaration record** (decision 5), takes its SHA-256, and stores both with the sealed document;
5. mints a confirmation token (decision 4) and queues **one** mail (decision 6);
6. returns the receipt as today, with the PDF, and additionally the declaration hash so the applicant can compare it with the mail.

### 4. Confirmation from the mailbox on file completes the signature

The mail carries a link to `/register/confirm#<token>` — the credential in the
fragment, never the path, as the poster secret and the invitation link already
do. The token is 32 random bytes; its SHA-256 is stored for lookup and its
ciphertext under the symmetric secret box so the drain can render the link
(ADR-0038 rule 5). It is single-use and expires after **7 days**, well inside
the 30-day purge.

`POST /api/public/registrations/confirm-signature` with `{token}` records
`confirmed_at`, the confirming IP and user agent, and answers uniformly for an
unknown, expired or already-used token, as the context endpoint does for a bad
secret. The confirmation page shows the mandate summary again and says plainly
that the applicant is not yet a member.

Until confirmed, the registration reads *signed, awaiting confirmation* in the
inbox and cannot be approved through the electronic attestation (decision 7).
The paper attestation remains possible for it — an applicant whose mail never
arrives can still print and sign.

### 5. The record

`mandate_signatures` — one row per electronic signature, created at submission,
carried to the mandate at approval:

| Column | Type | Description |
|---|---|---|
| `id` | CHAR(36) | |
| `registration_id` | CHAR(36) NULL | The pending row, until approval |
| `mandate_id` | CHAR(36) NULL | The mandate, from approval on |
| `declaration_json` | JSON | The canonical declaration record (below) |
| `declaration_sha256` | CHAR(64) | Hash of the canonical bytes; sent to the applicant |
| `document_ciphertext` | MEDIUMBLOB | The rendered member document, sealed to the active key |
| `document_sha256` | CHAR(64) | Hash of the plaintext PDF bytes |
| `encryption_key_id` | CHAR(36) | FK `encryption_keys` — the key the document is sealed to |
| `confirm_token_hash` | CHAR(64) | SHA-256 of the confirmation token |
| `confirm_token_cipher` | TEXT | The token, symmetric-sealed for the drain |
| `confirm_expires_at` | DATETIME | |
| `confirmed_at` | DATETIME NULL | Set once by the confirmation endpoint |
| `confirmed_ip` | VARCHAR(45) NULL | |
| `confirmed_user_agent` | VARCHAR(255) NULL | |
| `tsa_url` | VARCHAR(255) NULL | The time-stamp authority asked, if any (decision 9) |
| `tsa_token` | BLOB NULL | RFC 3161 `TimeStampToken`, DER |
| `tsa_stamped_at` | DATETIME NULL | |
| `created_at` | DATETIME | |

The declaration record is canonical JSON (sorted keys, no whitespace, UTF-8),
so its hash is reproducible from the stored columns:

```json
{
  "version": 1,
  "registration_id": "…", "mandate_reference": "…",
  "creditor": {"id": "DE98ZZZ09999999999", "name": "…"},
  "applicant": {"first_name": "…", "last_name": "…", "date_of_birth": "…", "email": "…"},
  "iban": {"last4": "3000", "fingerprint": "…", "bank_name": "…"},
  "document": {"url": "https://…/anmeldung.pdf", "template_sha256": "…", "rendered_sha256": "…"},
  "declarations": [
    {"kind": "membership_application", "language": "de", "text_sha256": "…", "text": "…", "accepted": true},
    {"kind": "sepa_mandate", "language": "de", "text_sha256": "…", "text": "…", "accepted": true},
    {"kind": "privacy_notice", "url": "https://…", "shown_at": "2026-09-02T18:41:03Z"}
  ],
  "signature": {"method": "typed_name", "typed_name": "…", "signed_at": "2026-09-02T18:42:10Z",
                "ip": "…", "user_agent": "…", "accept_language": "de-DE,de;q=0.9"}
}
```

Nothing in the record is a plaintext IBAN; the IBAN lives only in the sealed
document. The full declaration texts are stored, not only their hashes, because
VG Düsseldorf 29 K 9714/24 wanted the *content* of what was confirmed, and a
hash alone would leave the club reconstructing wording from a template it no
longer has. `method` leaves room for `webauthn` and `qes` later; nothing in v1
depends on them.

### 6. One mail, addressed to a registration

`MailKind::REGISTRATION_SIGNATURE_CONFIRM` is queued once per electronic
signature, to the submitted address, in the submitted language. It carries the
confirmation link, the club's name and Gläubiger-ID, the Mandatsreferenz, the
masked IBAN (`****3000`, the industry floor — Stripe and Adyen mail last-4),
the date of signing, the note that the Vorabankündigung reaches this address by
e-mail, and the **declaration hash** — the applicant's own copy of the record's
fingerprint, which the club cannot alter after the fact.

This is a new `MailSubject::REGISTRATION`: `subject_id` is the registration id,
the recipient is the snapshot of the submitted address, and the `MailRequestDto`
invariant gains that case beside member, admin and club. Erasure follows the
row it is about: the outbox row is scrubbed when the pending row is rejected or
purged, and **re-keyed to the member at approval** so that member erasure
finds it. `recipientRoles()` is `[]`, `addressesMember()` false,
`addressesClub()` false — the four exhaustive matches each gain a branch, as
the notification rule in `CLAUDE.md` requires.

ADR-0052's epic-wide assertion "a submission queues no mail" becomes "a **paper**
submission queues no mail; an electronic one queues exactly this message". The
accepted-submission meter (60 per hour per address) bounds the mail a public
surface can cause, and `countByEmail` refuses a second electronic signature for
an address that already has one pending.

### 7. Approval attests the record, not paper

`POST /api/admin/registrations/{id}/approve` gains a second attestation shape.
The existing `signed_mandate_confirmed` + `mandate_signed_at` stays for paper.
For a confirmed electronic signature the body carries
`signature_record_reviewed: true` and **no** date: `signed_at` is derived from
the record — the calendar day of `signature.signed_at` in the club's time zone
(`ClubTimeZone`, never the UTC day). An electronic attestation on an
unconfirmed signature, or a paper attestation on a registration whose PDF was
never printed, is each refused by name.

Approval, inside the existing transaction, creates the mandate with
`signing_method = 'electronic'` (a new column on `mandates`, default `'paper'`,
so every existing row reads truthfully), re-points the `mandate_signatures` row
from the registration to the mandate **before** the pending row is deleted, and
adds `signing_method` and `declaration_sha256` to the audit payload.

The Kassenwart's review drawer shows the record: typed name, signed-at, the
confirmation status and time, the origin fields, the declaration hash, and a
"view what they signed" that renders the declaration texts — no key needed. The
plausibility check moves from "does the handwritten IBAN end in 3000" to "is
this a real person joining under their own name and account".

### 8. The Mandatskopie the bank will read

`POST /api/admin/members/{id}/mandate/copy` (also reachable for a pending
registration) produces a single PDF:

- **Without a key** (`[ADMIN, KASSENWART]`): an evidence sheet composed from the record — mandate summary with the masked IBAN, every declaration text, the signature and confirmation facts, both hashes, the TSA token's serial if one exists. Enough for the first answer to a bank query and for the member's own request.
- **With `private_key`** and step-up authentication, exactly as the SEPA export does: the sealed document is opened in the same request and the evidence sheet is appended to it, so the bank receives the club's own mandate form with the full IBAN followed by how it was signed. The private key lives in a local for the length of the call and is wiped in `finally`.

Both variants are audited (`MANDATE_COPY_EXPORTED`, masked IBAN only), the way
the admin print is. The point of this endpoint is decision-time speed: the DK
terms give seven Geschäftstage, and EPC173-14 makes silence an automatic loss.

### 9. Optional qualified time stamp

When `TSA_URL` is configured, the submission requests an RFC 3161 time stamp
over `declaration_sha256` and stores the token; a qualified TSA earns the
Art. 41(2) eIDAS presumption on time and integrity. The request is
best-effort and bounded: a TSA that does not answer never fails a signature,
the row records `tsa_stamped_at = NULL`, and a cron job retries pending stamps
on the next tick, ahead of the drain. The default is **no TSA**: the hash in
the applicant's mailbox is the anchor the design relies on; the stamp is a
second, independent one for a club that wants it. Club Bar ships no TSA URL
because the free-use terms of the qualified endpoints are unpublished.

### 10. Retention and erasure

`mandate_signatures` is **Beleg-bearing**: it is the electronic counterpart of
the signed sheet in the treasurer's folder. It survives anonymisation with the
mandate row, is kept at least 14 months after the last collection (rulebook) and
for the § 147 AO period with the mandate, and is deleted with the mandate at
`retention_expires_at`. In the pending state it is deleted with the pending row
on reject, purge or — after re-pointing — approval.

The record is kept **intact**: its IP, user agent and e-mail are part of the
hashed bytes the applicant holds a fingerprint of, and redacting them would
destroy the only integrity anchor the record has. Restriction is by access, as
ADR-0029 already does for the mandate row. The Art. 13 notice gains one row for
this proof data. Whether field-level redaction should apply once the 14-month
window has closed is listed as an open question.

### 11. What is shown on the screen

| Declaration | Wording | Source |
|---|---|---|
| Membership application | First person: "Ich beantrage die Aufnahme in den … und erkenne Satzung und Ordnungen an" or whatever the club configures | `self_registration_config.membership_declaration` per language — club prose, club-configured |
| SEPA mandate | The Deutsche Kreditwirtschaft model authorisation text with the club as Zahlungsempfänger, Gläubiger-ID, Mandatsreferenz, "wiederkehrende Zahlungen", the applicant's name and IBAN, the shortened Vorabankündigung note | Shipped as a constant in both languages and pinned by a unit test — it is scheme text every creditor must carry verbatim, not club prose |
| Datenschutzhinweise | A link to the club's document, above the form, as today | `privacy_notice_url`, `privacy_notice_shown_at` — no checkbox |

The typed-name field is the signature control. There is no canvas.

### 12. Roles, derived from the surface

| Route | Roles |
|---|---|
| `POST /api/public/registrations` (with `signature`), `POST …/confirm-signature` | Public, unclassified, meters and honeypot as today |
| Enable switch, bank confirmation, declaration text | `[ADMIN]` — beside the poster secret |
| Review, approve with either attestation, key-less copy | `[ADMIN, KASSENWART]` — member management |
| Copy with the private key | `[ADMIN, KASSENWART]` behind step-up, the SEPA export's own gate |

### 13. What is deliberately not built in v1

- **No drawn signature.** Same legal weight as a typed name, more personal data, and it recreates the "IBAN plus signature" file class ADR-0037 removed.
- **No WebAuthn second factor.** The strongest non-qualified step available; deferred until the adult path has run a season, and recorded as `method: webauthn` when it comes.
- **No QES.** Free for members through the EUDI wallet once German QES lands in 2027; nothing here is in its way.
- **No parent path for minors.** Legally coherent (§ 182 Abs. 2 BGB, a parent's own mailbox and account), practically unverifiable in a browser; paper handles it today.
- **No automatic approval.** A confirmed signature is still a pending registration; ADR-0052's invariant that approval creates membership, after a human looks, stands.

```mermaid
sequenceDiagram
    participant A as Applicant (phone)
    participant P as /register page
    participant S as Backend
    participant O as Mail outbox
    participant K as Kassenwart

    A->>P: fill form, review
    P->>A: "Online unterschreiben" (adult, own account, club enabled)
    A->>P: reads declarations, types full name, ticks membership + mandate
    P->>S: POST /api/public/registrations {…, signature}
    S->>S: validate; fill club document (full IBAN); sha256; seal to public key
    S->>S: build declaration record; sha256; mint confirm token
    S->>O: enqueue REGISTRATION_SIGNATURE_CONFIRM (link, summary, hash)
    S-->>P: 201 receipt + PDF + declaration hash
    Note over O: next cron tick (≤ 15 min … 1 h)
    O-->>A: mail: confirm link, ****3000, UMR, Gläubiger-ID, hash
    A->>S: POST /confirm-signature {token}
    S->>S: confirmed_at, ip, ua
    K->>S: review record; approve {signature_record_reviewed: true}
    S->>S: member + mandate (signing_method=electronic, signed_at from record); re-point signature row; delete pending
    Note over K,S: bank asks "kein Mandat?" → POST /mandate/copy with private key → PDF (document + evidence sheet) within 7 Geschäftstage
```

## Consequences

### Positive

- An adult joining under their own name is a member the terminal can serve as soon as the treasurer has looked, without anyone printing anything.
- Every objection the neighbouring case law raised — protocol, content, confirmation from the mailbox, protected link, printable record — has a column.
- The IBAN's exposure does not grow: plaintext for one request, sealed at rest, opened only by the treasurer's key at export time. The record itself carries last-4 and a keyed fingerprint.
- The treasurer can answer a bank inside the seven-day window with one click and the key; silence — the way MD01 refunds are actually lost — is designed out.
- The paper flow, the minor flow and the third-party-holder flow are untouched, and a club without its bank's confirmation cannot enable any of this by accident.

### Negative

- ADR-0037's "no mandate document in the system" gains a sealed exception, and with it a MEDIUMBLOB per electronic mandate (a four-page PDF, a few hundred kilobytes) that backups and the retention tier must carry.
- Proof still rests on § 286 ZPO free evaluation. A determined "someone else used my mailbox" defence is answered by context, not by the record — the same position every online mandate in German e-commerce is in.
- The public surface now causes a mail per electronic submission. The meters bound it; a club that finds this abused switches the path off with a member-facing reason, as it can the whole feature.
- The confirmation arrives on the drain's cadence. On an hourly host the applicant confirms an hour later, from wherever they are — acceptable, and stated on the confirmation screen, but not instant.
- Two attestation shapes on one endpoint, and a review drawer with two states, are more UI than one.

### Neutral

- pain.008 is unchanged: `signed_at` is a calendar day either way, and the mandate block does not say how the mandate was signed.
- The Satzung, the bank confirmation and the Beitragsordnung's pre-notification clause are the club's work, outside this repository, and gate *enabling*, not shipping.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **A code the applicant types while signing** (mailbox confirmation *before* the declaration) | Needs the mail to arrive while the applicant waits; the outbox drains every 15 minutes at best. Sending inline from a public request would amend ADR-0038 and hand an anonymous surface a transport to exhaust. The link-afterwards shape is what BGH I ZR 164/09 blesses anyway |
| **A drawn signature on a canvas** | Legally identical to a typed name (Bundesarchiv), more data, a handwriting comparison the club would lose, and the file class ADR-0037 removed |
| **A commercial e-signature or e-mandate provider** | Cost per signature or per month for a club of a few dozen; member name, IBAN and behaviour sent to a third party — the exposure ADR-0040 removed on the extraction side; no legal gain over a well-kept record for these declarations |
| **QES now via sign-me / eID** | A contract with D-Trust, an AusweisApp round-trip on a phone at a bar wall, and cost that lands on the club. Earns § 371a, which nothing here needs. Kept as the 2027 wallet path |
| **Store the structured record only and re-render the document at export** | The club's document is a URL, not a pinned file; the template can change under the record, and the rulebook says the data must be extracted "without altering the content of the electronic document". Keep the bytes, sealed |
| **Auto-approve on confirmation** | Removes the human that catches a duplicate, a colliding e-mail, a typo in a name — and ADR-0052's invariant that approval is a person's act |
| **Redact IP and user agent after 14 months** | Would break the hash the applicant holds. Listed as an open question instead of decided |

## Open questions — the owner decides before M1

1. **The ADR and the schema.** One new table, two new columns (`mandates.signing_method`, `self_registration_config.membership_declaration`), three new config fields for the bank confirmation and the switch, one new mail kind and subject, one new audit action. Explicit confirmation required.
2. **Bank.** Which edition of the Inkassovereinbarung the club holds, and whether it or a side letter accepts electronic mandates. The feature refuses to enable without a recorded answer.
3. **Satzung.** What the Beitritt clause says; whether to adopt Textform expressly at the next amendment.
4. **The DK mandate text in the repository.** It is scheme text, not club prose, and every creditor ships it verbatim — but it is the first legal wording committed here. Acceptable, or should it too be club-configured?
5. **Last-4 in the mail.** Stripe and Adyen mail it; the club may prefer none.
6. **Field-level redaction after the 14-month window** versus an intact record (decision 10).
7. **A default TSA.** None shipped; a club that wants one configures it.
8. **The 7-day confirmation window** and whether an unconfirmed signature should be reminded once (a second mail) or simply lapse into the paper path.

## Related Decisions

- [ADR-0052](./0052-member-self-registration-via-qr-code.md) — the flow this extends; its invariant that a pending registration is not a member is untouched
- [ADR-0037](./0037-mandate-documents-not-retained.md) / [ADR-0040](./0040-remove-mandate-scan-extraction.md) — the sealed electronic document is the one exception, for the one case where there is no paper
- [ADR-0036](./0036-iban-encryption-sealed-box.md) / [ADR-0049](./0049-encrypted-offsite-backups-on-shared-hosting.md) — the sealing primitives, unchanged
- [ADR-0038](./0038-transactional-mail-outbox-on-shared-hosting.md) / [ADR-0051](./0051-member-lifecycle-mail.md) — the outbox the confirmation rides
- [ADR-0028](./0028-legal-constraints-on-money-handling.md) §3 — the 8-week and 13-month windows the record exists for
- [ADR-0029](./0029-two-tier-retention-and-erasure.md) — the tier the record joins
