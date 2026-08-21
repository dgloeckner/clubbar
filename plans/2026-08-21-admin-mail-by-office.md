# Admin Mail Reaches an Office, Not an Account

**Issue**: [#633](https://github.com/dgloeckner/clubbar/issues/633)
**Design of record**: [ADR-0044](../adr/0044-tiered-admin-roles.md) (roles),
[ADR-0038](../adr/0038-transactional-mail-outbox-on-shared-hosting.md) (the queue),
[ADR-0045](../adr/0045-age-restricted-products.md) §3 (the one non-`admin` kind)
**Status**: **M1–M4 implemented and verified.**
**Branch**: `claude/issue-633-un2oq2`

---

## What this closes

`AdminNotifier::warnAdmins()` fanned every admin-addressed message out over
`AdminUsersRepository::findActiveRecipients()`:

```sql
SELECT id, email, locale, display_name FROM admin_users WHERE is_active = 1 ORDER BY email ASC
```

No role filter. So an account holding only `getraenkewart` — refused the key
screen, the terminals page, the admin list and the audit log — was mailed the
club's encryption-key fingerprint, which tills had just been issued
credentials, and who had just been promoted. Mail was the one surface
ADR-0044's role model did not reach, and it is the surface an office does not
have to log in to receive.

**After this plan**: a kind states which offices it is for, the fan-out asks for
those offices, and an unstaffed office is escalated rather than swallowed.

---

## Decisions taken

| # | Question the issue asks | Decision | Consequence |
|---|---|---|---|
| 1 | Which roles receive which kind | **Mirror the grant on the surface the mail points at.** Keys, terminals and admin accounts are `admin`-only routes, so their mail is `admin`-only; `jugendschutz_violation` mirrors its `TREASURY` dashboard alert (`admin` + `kassenwart`) | One source of truth beside `RouteRoleMap`, rather than a second table of who-hears-what drifting alongside it. A kind whose route changes has one place to follow |
| 2 | What happens when nobody is eligible | **Escalate to the club address**, and report `nobody_eligible` either way; with no club address configured, queue nothing and log a `WARNING` naming the kind, subject and offices | Never "fall back to every active admin" — that is the leak, arriving exactly when the installation is least able to notice. Reaching this state takes a hand-edited database: `AdminUsersService` refuses to demote or deactivate the last `admin` |
| 3 | Whether the Kassenwart is narrowed too | **Yes**, by the same rule | A key fingerprint and a terminal credential are as far outside a treasurer's remit as a stock keeper's. Their one operational kind is the Jugendschutz notice ADR-0045 addresses to them |
| 4 | Where the filter goes | A **new** `findActiveRecipientsWithAnyRole()`; `findActiveRecipients()` stays unfiltered | The two mail builders resolve a display name for the `admin_user_id` already on an outbox row. A `WHERE` clause on the existing method would silently blank the greeting for anyone whose roles moved after the row was queued |

**Not contained here**: the ADR texts. ADR-0041, ADR-0043 and ADR-0044 each say
"every active admin" in prose that this narrows, and ADRs are not edited
without the user's explicit confirmation (CLAUDE.md). The docs that describe
the *system as built* — `docs/role-based-access.md`,
`docs/notifications-and-mail.md`, `docs/security-concept.md`, UC-A54 — are
updated instead.

---

## Milestones

### M1 — A kind says which offices it is for `[x]`

- `MailKind::recipientRoles(): list<AdminRole>`, beside `addressesMember()` and
  `addressesClub()`, as a `match` with no default so a kind added later fails to
  compile until somebody answers for it.
- Member-addressed kinds answer `[]`; `warnAdmins()` refuses them before it is
  consulted.

**Verified**: `backend/tests/Unit/Modules/Notifications/Enums/MailKindTest.php`
— every kind names its offices, credential mail is `admin`-only, the violation
notice carries the treasury set, and the Getränkewart appears on no fan-out at
all.

```bash
docker compose exec -w /app backend ./vendor/bin/phpunit --filter MailKindTest
```

### M2 — The fan-out asks for offices `[x]`

- `AdminUsersRepository::findActiveRecipientsWithAnyRole()` — a `DISTINCT` join
  on `admin_user_roles`, empty role set selects nobody, an account holding two
  of the offices asked for counts once.
- `AdminNotifier::warnAdmins()` uses it; `findActiveRecipients()` keeps its
  callers and gains a comment saying why it must stay unfiltered.
- The unstaffed case: escalate to the club address, report
  `EnqueueResultDto::$nobodyEligible`, log a `WARNING` when nothing was queued.

**Verified**: `AdminUsersRepositoryTest` (the SQL, against SQLite),
`AdminNotifierTest` (the argument, the escalation, the log line),
`AdminNoticeRolesTest` (the real notifier against MariaDB: which of three
accounts, one per office, each kind is written to).

```bash
docker compose exec -w /app backend ./vendor/bin/phpunit --filter "MailKind|AdminNotifier|AdminUsersRepository|AdminNoticeRoles"
```

### M3 — The builders still name whoever was written to `[x]`

`TerminalAnomalyMailBuilder::recipientName()` and
`JugendschutzViolationMailBuilder::recipientName()` keep resolving through the
unfiltered list, asserted as a constraint (`expects($this->never())` on the
filtered method) rather than left as an accident of which method they happen to
call.

### M4 — A real drain delivers to one office and not the others `[x]`

`e2etests/tests/mail-roles/office-mail.spec.ts`, its own project after
`mail-jugendschutz` (Pattern 010): three accounts, one per office; an admin
lifecycle notice reaches only the `admin` mailbox; a Jugendschutz violation
reaches the `kassenwart` and not the bar. Each test waits for the **positive**
delivery first, so an empty mailbox afterwards means nothing was ever addressed
there rather than that the chain never ran.

```bash
cd e2etests && npx playwright test --project=mail-roles --no-deps
```

### M5 — Documentation `[x]`

- `docs/role-based-access.md` §2a — what each office is *mailed*, beside what it
  can open, with the rule that generates the table and the unstaffed-office
  answer.
- `docs/notifications-and-mail.md` §3 — the audience column now names offices,
  `jugendschutz_violation` joins the catalogue, and the escalation is stated.
- `docs/security-concept.md`, `use-cases/admin/UC-A54` — "every active admin"
  becomes "every active `admin` account".

---

## What a reviewer should check

1. `recipientRoles()` is a `match` with no default — a new kind cannot inherit
   an audience.
2. `findActiveRecipients()` is still unfiltered, and still what both mail
   builders call.
3. The unstaffed case is a decision (escalate, report, log), not a silent
   `[]`.
4. The E2E negatives are preceded by a positive delivery in the same drain.
