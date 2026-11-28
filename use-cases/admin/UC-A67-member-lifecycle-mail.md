# UC-A67: A Member Is Written To About Their Own Record

**Implementation Status**: Implemented ([ADR-0051](../../adr/0051-member-lifecycle-mail.md),
[ADR-0038](../../adr/0038-transactional-mail-outbox-on-shared-hosting.md),
migration `057_mail_outbox_member_lifecycle.sql`). **Amended 2026-11-28**: the
welcome is sent when a member has a card **and** a signed mandate, not when the
card alone arrives (ADR-0051, *Amended 2026-11-28*).

## Actor
The **member**, as a recipient. The Kassenwart or Admin triggers this without
choosing to: it is a consequence of editing a member, not a button.

Like [UC-A66](./UC-A66-credit-limit-digest.md), nobody performs the main flow.
It is written down anyway, because what lands in a member's inbox has acceptance
criteria — and here the criteria that matter most are about *when nothing is
sent*.

## Preconditions
- Mail is configured (a sender address, and a transport in `config.php`)
- The scheduler runs `backend/bin/cron.php` (ADR-0038 rule 3)
- The member has an email address on file (mandatory since
  [#362](https://github.com/dgloeckner/clubbar/issues/362); a legacy row may
  still have none)

## Trigger
`POST /api/admin/members` or `PATCH /api/admin/members/{memberId}` changes the
member's `card_uid`, `email`, or — since 2026-11-28 — their mandate (its
signature date, or the account it is on).

## Usable

Everything below turns on one state. A member is **usable** when they have a
real card and a complete SEPA mandate — reference, IBAN and signature date, the
same definition the terminal refuses a card on (ADR-0020). Until 2026-11-28 the
state that mattered was "has a card"; a member accepted ahead of their signature
([UC-A17](./UC-A17-review-pending-registrations.md)) would then have been told
their card was active while the terminal refused it.

## Why this exists

#362 made an address mandatory *because* the Vorabankündigung is a contractual
promise (Nutzungsordnung § 7 Abs. 3), and then only checked it was well-formed.
Nothing was ever sent to it. A Kassenwart who mistypes an address in March finds
out in November, seven days before a collection, from a failed row nobody had a
reason to watch.

And an address can be changed by any Kassenwart, from a list of every member,
with no notice to anybody. A member has no session to steal, so the likely cause
is the wrong row in a long table rather than an attack — which makes it more
probable, not less, and equally silent.

## Main flow — onboarding

1. The Kassenwart creates the member record — or accepts a registration
   (UC-A17). **No mail is sent.** A member who is not usable cannot start a
   Session or run up a Deckel; there is nothing yet to welcome them to.
2. The two halves arrive, in either order: the Kassenwart reads the UID off the
   card and enters it in the edit form
   ([ADR-0021](../../adr/0021-rfid-card-assignment-workflow.md)), and records
   the signature date once the paper is signed.
3. On the save that **completes the pair**, `member_welcome` is queued inside
   the same request, addressed to the member.
4. The next scheduler tick renders and sends it.
5. The member receives, in their own language: the signed mandate has arrived
   and the card works; **that the card may not have reached them yet, and works
   from the moment it does**; how the Deckel accrues; that a Vorabankündigung
   arrives at least seven days before every collection; what the club stores and
   that a reply reaches the Kassenwart.

## Alternate flows

| # | Situation | Outcome |
|---|---|---|
| A1 | The record is created with a card **and a signed mandate** already on it | Welcomed at creation — onboarded in one step |
| A1a | A card is assigned to a member whose mandate is unsigned or missing | Nothing is sent. They cannot buy yet |
| A1b | The signature date is recorded on a member who already has a card | `member_welcome` — the save that completes the pair |
| A2 | A different card is assigned to a member who was **already usable** | `member_card_replaced`: the old card has stopped working *immediately*, the new one may not have arrived yet, and the Deckel is unchanged. **Not** a second welcome |
| A2a | A different card is assigned to a member who was **never usable** | Nothing is sent. They have not been greeted, and "your card was replaced" is not a first message |
| A3 | The card is cleared | Nothing is sent |
| A4 | A card is assigned after being cleared | `member_card_replaced` — the member has been greeted before |
| A4a | The mandate is signed, the card is untouched, and the member was greeted before | Nothing is sent. The fallback to `member_card_replaced` belongs to A4 alone: it is only true when a card was (re)assigned |
| A4b | **Bank change**: the old mandate is ended, the new one is unsigned and is signed later | Nothing is sent — not a second welcome, even after the first was pruned at ninety days. The member already has an earlier signed mandate |
| A5 | The address changes on a member who **was usable** | Two messages: `member_email_changed` to the address being left, `member_email_activated` to the one being taken up |
| A6 | The address changes on a member who **was not usable** — no card, or a card without a signed mandate | Nothing is sent (see Rules) |
| A7 | The save that completes the pair also sets a new address | The welcome only, to the new address |
| A8 | The address changes in case alone | Nothing is sent |
| A9 | The address is cleared on an inactive member | Nothing is sent — only a move *to* a new address notifies |
| A10 | The member has no address | Nothing is queued; the card assignment still succeeds |
| A11 | The member is anonymised | Nothing is queued, and pending rows are superseded and their snapshots scrubbed (ADR-0029) |

## Rules

1. **The welcome is the first message a member ever receives.** No
   member-addressed lifecycle notice goes out before it. This is what makes A6
   a deliberate silence rather than a gap: a member the club has never written
   to would otherwise get an out-of-context notice from a sender they do not
   recognise.
2. **Welcome or replacement is read from the transition**, not from the queue —
   a card replacing another on a usable member is a replacement whatever the
   outbox holds. Only A4 falls back to attempting the welcome and treating a
   refused insert as proof the member was greeted before; when the pair was
   completed by the *signature* (A4a) a refused insert means nothing is sent.
3. **Neither address notice names the other address.** Bodies render at send
   time (ADR-0038 rule 5), so a printed address could have moved again before
   delivery. Each copy proves its own address by arriving there.
4. **`member_email_activated` is not a verification.** No token, nothing gated
   on it, and the message says so — a member waiting for a confirmation link
   that never comes is worse off than one told there is nothing to do. Its value
   is the bounce.
5. **The welcome carries no Mandatsreferenz and no Gläubiger-ID.** The
   registration form promises those *„mit der Vorabankündigung zum ersten
   Einzug"*. Since 2026-11-28 a signed mandate is on file whenever the welcome
   is sent, and the welcome says so — it still names neither.
6. **No card UID appears in any message.** The member is holding the card, or
   about to be.
7. **Both card notices allow for the card not having arrived.** The UID is
   typically entered while the Kassenwart *prepares* the onboarding, so the mail
   routinely lands days before the plastic. The replacement additionally names
   the gap it creates: assigning a new UID stops the old card at once, so a
   member who has not been handed the new one cannot pay until they are.
8. **A queue failure never fails the write.** The card assignment, the
   signature date and the address edit are already committed; the notice is
   best effort.
9. **A bank change is not an onboarding.** A member completing a mandate who
   already has an earlier signed one is not welcomed again (A4b). The mandate
   history answers this, because the queue forgets after ninety days.

## Acceptance criteria

- [x] Creating a member without a card queues nothing
- [x] Assigning the first card to a member with a signed mandate queues exactly
      one `member_welcome`
- [x] Creating a member with a card and a signed mandate queues the welcome
      immediately
- [x] A card on a member whose mandate is unsigned, or who has none, queues
      nothing — at creation and on assignment
- [x] Recording the signature on a carded member queues the welcome; a card
      after the signature does; both in one save queue exactly one
- [x] Replacing the card of a member who was never usable queues nothing
- [x] A signature that completes the pair never falls back to
      `member_card_replaced`
- [x] A bank change — new mandate signed later — queues no second welcome, even
      after the first was removed from the queue
- [x] Signing a *first* mandate still welcomes, however many unsigned mandates
      preceded it
- [x] A second card on a usable member queues `member_card_replaced` and no
      second welcome
- [x] Clearing a card queues nothing; reassigning after a clear is a replacement
- [x] Re-saving the same card queues nothing new
- [x] An address change on a usable member queues both kinds, each snapshotted
      against its own end of the move
- [x] An address change on an uncarded member queues nothing; so does one on a
      carded member whose mandate is unsigned
- [x] A first card plus a new address queues the welcome only, to the new
      address; so does a signature plus a new address
- [x] A case-only change queues nothing
- [x] Clearing the address of an inactive member queues nothing
- [x] A move back to a previously used address is announced again
- [x] A member with no address can still be given a card
- [x] Anonymising a member queues nothing
- [x] Both card notices allow for the card not having arrived, and the
      replacement names the window in which the member cannot pay
- [x] A real `bin/cron.php` run delivers the welcome to Mailpit, in the member's
      language, saying the signed mandate arrived and carrying no banking details
- [x] A real drain delivers nothing for a card handed out before the signature,
      nor for a second card before it, and exactly one welcome once the
      signature date is recorded
- [x] A second real drain delivers no duplicate
- [x] Neither delivered address notice contains the other address

## Tests

| Level | File |
|---|---|
| Unit — what each message says, and does not | `backend/tests/Unit/Modules/Notifications/Mail/MemberLifecycleMailTest.php` |
| Unit — the recipient comes off the row, never the member | `backend/tests/Unit/Modules/Notifications/Services/MemberLifecycleMailBuilderTest.php` |
| Unit — every kind states its own audience | `backend/tests/Unit/Modules/Notifications/Enums/MailKindTest.php` |
| Feature — which notice a record change produces | `backend/tests/Feature/Modules/Members/Services/MemberLifecycleMailTest.php` |
| E2E — card/address → `bin/cron.php` → Mailpit | `e2etests/tests/mail-member/member-lifecycle.spec.ts` (project `mail-member`) |

## Related

- [ADR-0051](../../adr/0051-member-lifecycle-mail.md) — the decision
- [ADR-0021](../../adr/0021-rfid-card-assignment-workflow.md) — why the card is a moment distinct from registration
- [UC-A11](./UC-A11-create-member.md), [UC-A12](./UC-A12-edit-member.md) — the two flows that trigger this
- [UC-A17](./UC-A17-review-pending-registrations.md), [UC-A18](./UC-A18-print-open-mandates.md) — accepting ahead of the signature and printing the open mandates, which is why a member can hold a card before they can buy
- [UC-A04](./UC-A04-change-email.md) — the admin equivalent this mirrors
- [Notifications & the Mail Outbox](../../docs/notifications-and-mail.md)
