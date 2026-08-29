# UC-A67: A Member Is Written To About Their Own Record

**Implementation Status**: Implemented ([ADR-0051](../../adr/0051-member-lifecycle-mail.md),
[ADR-0038](../../adr/0038-transactional-mail-outbox-on-shared-hosting.md),
migration `057_mail_outbox_member_lifecycle.sql`)

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
member's `card_uid` or `email`.

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

1. The Kassenwart creates the member record. **No mail is sent.** A member with
   no card cannot start a Session or run up a Deckel; there is nothing yet to
   welcome them to.
2. The Kassenwart reads the UID off the card and enters it in the edit form
   ([ADR-0021](../../adr/0021-rfid-card-assignment-workflow.md)).
3. `member_welcome` is queued inside the same request, addressed to the member.
4. The next scheduler tick renders and sends it.
5. The member receives, in their own language: the card works; how the Deckel
   accrues; that a Vorabankündigung arrives at least seven days before every
   collection; what the club stores and that a reply reaches the Kassenwart.

## Alternate flows

| # | Situation | Outcome |
|---|---|---|
| A1 | The record is created with a card already on it | Welcomed at creation — onboarded in one step |
| A2 | A different card is assigned later | `member_card_replaced`: the old card has stopped working, the Deckel is unchanged. **Not** a second welcome |
| A3 | The card is cleared | Nothing is sent |
| A4 | A card is assigned after being cleared | `member_card_replaced` — the member has been greeted before |
| A5 | The address changes on a member **with** a card | Two messages: `member_email_changed` to the address being left, `member_email_activated` to the one being taken up |
| A6 | The address changes on a member **with no card** | Nothing is sent (see Rules) |
| A7 | A first card and a new address in one request | The welcome only, to the new address |
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
   a card replacing another is a replacement whatever the outbox holds. Only A4
   falls back to attempting the welcome and treating a refused insert as proof
   the member was greeted before.
3. **Neither address notice names the other address.** Bodies render at send
   time (ADR-0038 rule 5), so a printed address could have moved again before
   delivery. Each copy proves its own address by arriving there.
4. **`member_email_activated` is not a verification.** No token, nothing gated
   on it, and the message says so — a member waiting for a confirmation link
   that never comes is worse off than one told there is nothing to do. Its value
   is the bounce.
5. **The welcome carries no Mandatsreferenz and no Gläubiger-ID.** The
   registration form promises those *„mit der Vorabankündigung zum ersten
   Einzug"*, and at card time there is often no mandate on file.
6. **No card UID appears in any message.** The member is holding the card.
7. **A queue failure never fails the write.** The card assignment and the
   address edit are already committed; the notice is best effort.

## Acceptance criteria

- [x] Creating a member without a card queues nothing
- [x] Assigning the first card queues exactly one `member_welcome`
- [x] Creating a member with a card queues the welcome immediately
- [x] A second card queues `member_card_replaced` and no second welcome
- [x] Clearing a card queues nothing; reassigning after a clear is a replacement
- [x] Re-saving the same card queues nothing new
- [x] An address change on a carded member queues both kinds, each snapshotted
      against its own end of the move
- [x] An address change on an uncarded member queues nothing
- [x] A first card plus a new address queues the welcome only, to the new address
- [x] A case-only change queues nothing
- [x] Clearing the address of an inactive member queues nothing
- [x] A move back to a previously used address is announced again
- [x] A member with no address can still be given a card
- [x] Anonymising a member queues nothing
- [x] A real `bin/cron.php` run delivers the welcome to Mailpit, in the member's
      language, carrying no banking details
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
- [UC-A04](./UC-A04-change-email.md) — the admin equivalent this mirrors
- [Notifications & the Mail Outbox](../../docs/notifications-and-mail.md)
