# Member Lifecycle Mail

**ADR**: [ADR-0051](../adr/0051-member-lifecycle-mail.md) · **Use case**: [UC-A67](../use-cases/admin/UC-A67-member-lifecycle-mail.md)

## Goal

Write to a member when their card is assigned and when their address moves —
the first mail this system sends a member for a reason other than money.

Two gaps close together. #362 made `members.email` mandatory *because* the
Vorabankündigung is a § 7 Abs. 3 promise, and then only format-checked it: a
typo surfaces in November, seven days before a collection. And an address can be
moved by any Kassenwart with no notice to anybody — a member has no session to
steal, so the likely cause is the wrong row in a long table, which makes it more
probable and equally silent.

The card is the gate. **The welcome is the first message a member ever
receives**, so nothing member-addressed goes out before it.

## Milestones

- [x] **M1 — Schema.** Migration `057` restating the whole `kind` ENUM plus four
      values, and its rollback. `settlement_announcements` deliberately untouched.
      *Verified*: enum reads back all four from the live database.
- [x] **M2 — Enum and retention.** Four `MailKind` cases, an arm in each of the
      four exhaustive `match`es, `MailRetention::sentDaysFor()` at the 90-day
      default. *Verified*: `MailKindTest` green; the compiler enforces the rest.
- [x] **M3 — Queue side.** `MailRequestDto::forMemberOccasion()`,
      `NotificationsService::notifyMemberCard()` and
      `::notifyMemberAddressChange()`, each auditing `MAIL_ENQUEUED`.
- [x] **M4 — Render side.** `MemberCardMail`, `MemberEmailChangeMail`,
      `MemberLifecycleMailBuilder`, 38 `MailStrings` keys in de and en, wired in
      `ServiceFactory`. *Verified*: `MemberLifecycleMailTest` (unit) 11/11,
      `MemberLifecycleMailBuilderTest` 5/5, de/en key parity 277 = 277.
- [x] **M5 — Triggers.** `MembersService::createMember()` and `::updateMember()`,
      with the transition rules and the card gate. *Verified*:
      `MemberLifecycleMailTest` (feature) 27/27 in the container.
- [x] **M6 — Registry guard.** A test asserting the *wired* registry renders
      every `MailKind::cases()`, read out of `ServiceFactory` so an unregistered
      builder fails it too. *Verified*: fails with a named kind when the builder
      is unregistered, passes when it is.
- [x] **M7 — Surfaces.** OpenAPI `MailKind` enum, the Notifications filter
      dropdown and both locale label maps — each was missing ten existing kinds,
      so a Jugendschutz notice or backup alarm could not be filtered for at all.
      *Verified*: all four lists identical at 21 entries; 510 frontend tests, 0
      type errors, 0 lint errors.
- [x] **M8 — E2E.** Project `mail-member`, last in the `chain` lane, registered
      in CI and in CLAUDE.md. *Verified*: 5/5 against a real `bin/cron.php` drain
      and Mailpit; delivered messages read by hand in both languages.
- [x] **M9 — Docs.** ADR-0051 + index, UC-A67 + index, the mail catalogue,
      CONTEXT.md's Notifications glossary, and the stale email statements in
      UC-A11 (*"Email — Required: No"*) and UC-A12.

## Decisions worth remembering

**Welcome vs. replacement is read from the transition, not the queue.** A card
replacing another is a replacement whatever the outbox holds — otherwise a
welcome pruned at ninety days turns every later replacement back into a
greeting. Only the cleared-then-reassigned case falls back to attempting the
welcome and treating a refused insert as the answer, which is the unique index
rather than a lookup, so two overlapping requests cannot both decide they are
first.

**Neither address notice names the other address.** Bodies render at send time
(ADR-0038 rule 5), so a printed address could have moved again between a
greylisted attempt and the one that succeeds. Each copy proves its own address
by arriving there.

**`member_email_activated` is not a verification** — no token, nothing gated on
it, and the message says so. Its value is the bounce.

## Verification

```bash
cd backend && php8.3 vendor/bin/phpunit -c phpunit.xml --testsuite Unit
docker compose exec -w /app backend ./vendor/bin/phpunit
cd e2etests && npx playwright test --project=mail-member --project=api-tests
```

Result: backend 3446 tests with only the 2 pre-existing `BackupHealthNotifier`
failures (a hardcoded `2026-08-27`, red on the clean tree too); `mail-member`
5/5; `api-tests` 743/743; frontend 510/510.

## Not done, deliberately

- **No on/off setting.** Event-triggered like `ADMIN_EMAIL_CHANGED`, and only
  able to fire on cards assigned *after* the upgrade — so unlike ADR-0039's
  statement, no migration can mail an existing membership.
- **No email verification.** A gate would mean an unconfirmed member stops
  receiving the announcement the club is obliged to send. See ADR-0051's
  alternatives.
- **No farewell** when an inactive member's address is cleared. Only a move *to*
  a new address notifies.
