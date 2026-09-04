# ADR-0053: The Anmeldelink Carries No Credential

**Status**: Accepted

**Date**: 2026-09-04

**Amends**: [ADR-0052](./0052-member-self-registration-via-qr-code.md) (extends its reach from the clubhouse wall to a mailbox; every invariant unchanged)

---

## Context

[ADR-0052](./0052-member-self-registration-via-qr-code.md) gave the club a way
for somebody to register themselves: a QR poster on the clubhouse wall, encoding
a URL whose fragment carries a shared secret, opening a form
([UC-P01](../use-cases/public/UC-P01-member-self-registration.md)).

The poster reaches exactly the people standing in the building. A club that
meets a prospective member at a regatta, or takes an enquiry by mail, has no
path at all: today a Kassenwart pastes the URL into their private WhatsApp —
unbranded, unrecorded, and from a person rather than from the club.

Sending that URL by mail sounds like a small feature, and it is. What makes it
worth an ADR is that the obvious design for it is wrong, and wrong in a way that
looks like diligence.

### The design that looks right

Everything else in this system that mails somebody a way in mails them a
**credential**. `admin_user_invitations` (migration 058) mints a single-use
token, seals it, expires it in seven days, revokes it when a replacement is
issued, and attributes it to the account it onboards. That is the shape a
reviewer expects, and modelling a member invitation on it would produce:
a `member_invitations` table, a TTL, a purge job, revoke-on-reissue, per-invitee
attribution and a campaign view.

Every one of those is machinery for a property this link does not have.

## Decision

**The Anmeldelink is the poster's own URL, sent verbatim. It carries no
credential of its own, and nothing is stored about the person it is sent to.**

The reasoning is one sentence: **the secret is already printed on a wall the
public walks past.** A copy of it in an inbox reaches nobody the wall did not.

Wrapping a public value in a per-recipient token does not make it less public.
What it *would* do is change what `self_registration_config` holds — today *the*
secret, then a *set* of live secrets — and that is the load-bearing loss. The
rotation story ADR-0052 rests on is "replace one value and every poster in the
building stops working, at once, with no overlap window" (UC-A69). A set has no
such moment. Rotation would become a sweep over rows, each with its own
lifetime, and the one operation that must be instant and total would become the
one that is neither.

So there is nothing to mint, nothing to expire, nothing to revoke, and no
schema of its own.

### What is stored: the outbox row, and nothing else

`mail_outbox.recipient` is the entire record that a link was sent. There is no
invitee table, and that is a decision rather than an omission — ADR-0052
decision 10's words: *a queue nobody empties is exactly how personal data about
somebody who never joined would accumulate.*

The queue row is the right home for that record because it is already
governed. It ages out under the outbox's own retention (ninety days, the
default window every non-money kind uses), it is visible under Notifications
where an admin can see who was written to, and it is the club's way of
re-sending after a rotation without maintaining a list of people who did not
answer.

It is also the first queued message addressed to somebody this database holds
**no row for**: `member_id` and `admin_user_id` are both NULL. `ADMIN_INVITATION`
looks like a precedent and is not — the account it onboards already exists in
`admin_users`, and `subject_id` points at it. Here the subject is the
registration *surface*, because what the message is about is the club's open
door rather than the person walking through it.

### Sending is a promise

The send is refused when the club could not answer the link — self-registration
switched off, no poster secret, no configured document — reusing the
availability switch's own typed reasons.

A poster has an excuse for going stale: it is paper, printed months ago, and the
club cannot recall it. A message composed one second ago has none. Mailing a
link that opens *„Anmeldung ist derzeit nicht möglich"* makes the club look
broken to exactly the person it is courting.

The gate is checked twice — once when the admin sends, once when the drain
renders — because the club can change its mind in between, and the second check
is what stops the queue from delivering a promise the first one made.

### Two sends to one address are two messages

`dedup_key` carries a per-send nonce, which effectively switches the outbox's
`UNIQUE (kind, subject_id, dedup_key)` off for this kind.

That index exists so a repeating **scan** is idempotent: a digest must not send
twice for one window, an expiry warning must not re-fire on every tick. There is
no scan here. A human types an address and clicks send, and clicking again is
the intent — a re-send is what answers *"I never got it"*. A key of the bare
address would refuse that silently, from the database, behind a success
response. The double click is guarded in the UI, which is where that mistake
actually happens.

### Who may send it

`[ADMIN, KASSENWART]`, derived the way every notification audience is derived:
mirror the grant on the surface the mail points at. The mail points at the
registration surface — the review inbox
([UC-A17](../use-cases/admin/UC-A17-review-pending-registrations.md)), which is
`TREASURY` — and not at the credential surface (UC-A69, `ADMIN` only, because
reading the poster *is* reading the plaintext secret).

Those two sets differing on adjacent routes is not an inconsistency. Sending the
link hands over nothing the club has not already published on a wall; reading
the secret back hands over the bearer credential itself.

The control lives on `/registrations` rather than in Settings beside the poster,
which is where it conceptually belongs. Settings is `ADMIN_ONLY`; holding one
button there would lock out the Kassenwart whose queue this is, and splitting
that tab's role set for a single control is precisely the drift
[ADR-0044](./0044-tiered-admin-roles.md)'s default-deny exists to
prevent.

### Language

German, frozen into the row at enqueue like every other kind. There is no
club-level default language to read — `instance_config` holds the club's name
and nothing else — and inventing one as a side effect of this change was
rejected. It belongs to [#820](https://github.com/dgloeckner/clubbar/issues/820).

The English wording exists in the string table anyway, so the day that default
lands this is a one-argument change rather than a translation pass.

### The body says paper is coming

The biggest surprise in this flow is that filling the form is not joining: the
applicant prints, signs by hand, and hands the sheet in. Somebody standing at
the poster is in the clubhouse and learns that in a minute. Somebody opening a
link at home learns it only if the message says so, and a message that omits it
converts worse than one that is honest about the step.

## Consequences

### Positive

- **No schema of its own.** No table, no column, no index, no TTL, no purge job,
  no revoke path — and therefore none of them to get wrong.
- **Rotation keeps meaning exactly what it meant.** One value, one moment, every
  copy dead. ADR-0052's story is untouched.
- **Nothing accumulates about people who never join.** The club keeps no list of
  prospects, by construction rather than by policy.
- **The link is provably the poster's**, because it is rebuilt from the same
  config row the poster reads at print time. There is no second copy to drift.
- **A re-send works**, which is the operation a support conversation actually
  needs.

### Negative

- **A sent link dies on rotation**, silently and permanently, exactly as every
  printed poster does. Nobody is notified; the reader simply finds a page that
  refuses them. Mitigated by saying so on the screen that rotates (UC-A69) and
  by pointing at Notifications, where the addresses that were written to are
  listed — so a club that rotates can re-send rather than having to remember.
- **The club has no invitee list.** "Who have we invited and who has not
  answered?" is not a question this feature can answer beyond the outbox's
  ninety-day window. Accepted: it is the same trade ADR-0052 decision 10 already
  made, and the alternative is the queue nobody empties.
- **No attribution from a registration back to the invitation that produced
  it.** A submission arriving from a mailed link is indistinguishable from one
  arriving from the wall. Accepted: attribution would need a per-recipient
  token, which is the whole thing this ADR declines to build.
- **A misdirected link cannot be recalled**, and nothing verifies the address
  before sending. Mitigated by what the link is: a public URL, opening a form
  that collects nothing until somebody fills it in. A misdirected one produces
  at worst a pending registration that fails review, and the trust boundary is
  the signed paper, which already exists.
- **No send cap.** An authenticated admin can send arbitrarily many. Accepted:
  the surface is behind mandatory TOTP and the admin is trusted; a cap would be
  a control against a threat this installation does not have.
- **Two enum columns still had to be widened.** `mail_outbox.kind` and
  `audit_log.action` are ENUMs in this schema, so "no schema change" is true of
  tables and columns and not of a migration file. See below.

### A migration exists, and the design intent still holds

The design's claim was "no schema change at all". In this schema that could not
be literally true: a `MailKind` with no value in `mail_outbox.kind` is refused
by MariaDB at write time, which would make the send return success and queue
nothing. Migration `064` widens both enums and does nothing else — no table, no
column, no index, no data.

## Alternatives considered

| Alternative | Why not |
|---|---|
| **A per-member invitation credential** — single-use, expiring, revocable, attributable, modelled on `admin_user_invitations` | Unnecessary once the secret's publicness is seen. It buys attribution and revocation for a value already printed on a public wall, and costs the rotation story: `self_registration_config` would hold a *set* of live secrets |
| **A `member_invitations` table with TTL and purge** | Nothing to store. The outbox row is already the record, already retained, already visible, already pruned |
| **`dedup_key` = the recipient's address** | Refuses the re-send that answers "I never got it", silently, from the database. The index is for idempotent scans; there is no scan here |
| **Double opt-in on the recipient address** | Verifies nothing that matters. The trust boundary is the signed paper, and the poster flow already has applicants typing their *own* address — an admin-typed one is the less reliable of the two, and its failure is self-announcing and self-healing |
| **Borrowing `MailSubject::INSTANCE_CONFIG`** | Would have fit with no new code, and would make `subject_id` claim an Anmeldelink is a message about the club's configuration. The enums are exhaustive so a new kind must *state* its nature |
| **`instance_config.default_language`** | A club-level default is a real gap and a real decision. Inventing one as a side effect of adding a button is how a setting arrives with nobody having thought about it. Deferred to #820 |
| **A per-send daily cap** | See "No send cap" above |
| **The control in Settings, beside the poster** | Conceptually the right home and role-wise the wrong one: that tab is `ADMIN_ONLY`, and the Kassenwart is the office that empties this queue |

## Related

- [ADR-0052](./0052-member-self-registration-via-qr-code.md) — member self-registration via QR code
- [ADR-0038](./0038-transactional-mail-outbox-on-shared-hosting.md) — the outbox and the drain
- [ADR-0044](./0044-tiered-admin-roles.md) — default-deny roles, and mirroring the grant on the surface
- [UC-A70](../use-cases/admin/UC-A70-send-anmeldelink.md) — sending the link
- [UC-A69](../use-cases/admin/UC-A69-configure-self-registration.md) — the poster, the switch and the rotation
- [UC-A17](../use-cases/admin/UC-A17-review-pending-registrations.md) — the inbox the control sits on
- [UC-P01](../use-cases/public/UC-P01-member-self-registration.md) — the page the link opens
