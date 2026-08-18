# Tiered Admin Roles — `admin` / Kassenwart / Getränkewart

**Epic**: [#513](https://github.com/dgloeckner/clubbar/issues/513)
**Design**: [ADR-0044](../adr/0044-tiered-admin-roles.md)
**Branch**: every task ships as its own PR into `feature/tiered-admin-roles`, which
merges into `main` once the epic is complete. The task order below is the merge
order — each slice rebases on the branch as it grows.

---

## What this buys

**Insider curiosity** — a person who legitimately needs part of the panel
browsing the parts they do not. It is *not* a control against a compromised
session: an attacker takes the account they compromised, and a lesser tier does
nothing about that (step-up and rate limiting from #498 are the controls there).

Contained: capability acquisition, escalation and persistence. A Kassenwart
cannot mint accounts or terminal tokens, install their own encryption key,
redirect the alert mail, or read the audit log to see whether they are watched.

Not contained: IBAN confidentiality against the Kassenwart, who holds a key copy
because SEPA collection requires one. That is the office, not a gap.

---

## Milestones

Ordered by dependency. Status legend: `[ ]` not started · `[~]` in progress ·
`[x]` passed (test verified) · `[!]` failed.

### M1 — Data model, behaviour-preserving migration, installer parity ([#514](https://github.com/dgloeckner/clubbar/issues/514))

Storage only. After this milestone every role still has full access, on purpose:
it makes the foundation deployable and verifiable before enforcement lands.

- [x] Migration `045_admin_user_roles.sql` — a **relation**, not a `role` column,
      because ADR-0044 §4 requires one person to hold both lesser roles at once.
      Role vocabulary is a column `ENUM`; the backfill is `INSERT IGNORE … SELECT`
      so it only ever adds
      — *verified*: `AdminUserRolesSchemaTest` 9/9, including the backfill run
      from the migration file itself and the "removes nothing it finds" property
- [x] `AdminRole` enum (Pattern 002), failing closed on unknown values
      — *verified*: `AdminRoleTest` 5/5
- [x] `AdminUserRolesRepository` — read and write an account's role set
      — *verified*: `AdminUserRolesRepositoryTest` 10/10
- [x] `AdminUsersService::getRoles()` / `::setRoles()`; a created account holds
      `admin`, and an empty role set is refused
      — *verified*: `AdminUsersServiceRolesTest` 5/5
- [x] `GET /api/auth/profile` returns the caller's roles; `api/admin.yaml` updated
      — *verified*: `AuthControllerProfileRolesTest` 4/4, plus the API spec
      `admin-auth.spec.ts` › *should report the roles the caller holds*
- [x] Installer and seed parity — both create their admin *after* the migration
      has run, so both grant their own role
      — *verified*: `AdminUserRolesSchemaTest` › *the seeded admin account holds
      the admin role*
- [x] No behavioural change to any existing endpoint — backend Feature suite
      601/601, Unit suite green apart from one pre-existing environment failure
      (`ServiceFactoryTest::test_getRateLimitMiddleware_is_active_by_default`,
      which fails inside the dev container because `docker-compose.yml` sets
      `DISABLE_LOGIN_RATE_LIMITING=true`)

### M2 — Route→roles map with default-deny enforcement ([#519](https://github.com/dgloeckner/clubbar/issues/519))

- [x] `RouteRoleMap` — one declarative table keyed `"METHOD /pattern"`, consulted
      by `AdminSessionAuth`. Keyed on the **pattern Slim matched**, not the
      concrete path, so the map is a transcription of `routes.php` with no
      regex to get wrong
      — *verified*: `RouteRoleMapTest` 14/14
- [x] **A route with no entry is `admin`-only**, and the completeness test reads
      the real route collector, so adding a route to `routes.php` is what makes
      it fail
      — *verified*: `RouteRoleMapCompletenessTest` 4/4, both directions (no
      unclassified route, no stale entry) plus "admin reaches everything"
- [x] `insufficient_role` — 403, its own error code, documented in `api/admin.yaml`
      as a reusable response and on the `sessionAuth` scheme
      — *verified*: `RoleEnforcementHttpTest` 11/11 through the real stack, and
      `AdminSessionAuthTest`'s role cases (refusal, pattern-not-path, no matched
      route → admin-only, 401-before-403 ordering)
- [x] Existing HTTP fixtures grant `admin` via `HttpTestCase::grantRoles()` — a
      fixture that inserts an `admin_users` row and stops was simulating an
      account that cannot exist after M1, and the gate fails closed on it
- [x] Nothing existing changed: backend Feature 616/616, Unit green apart from
      the pre-existing `ServiceFactoryTest` environment failure, `api-tests`
      637/637

### M3 — Step-up and dedicated audit actions on role grant/revoke ([#520](https://github.com/dgloeckner/clubbar/issues/520))

- [x] `PATCH /admin-users/{id}` carries a step-up when the role set *actually*
      changes, and the step-up limiter is pinned on the route. Closes the hole
      the epic would otherwise have opened: creating an admin cost a password
      and a TOTP code while **promoting** one cost only a live session
      — *verified*: `AdminUserRolesHttpTest` 13/13
- [x] `ROLE_GRANTED` / `ROLE_REVOKED` audit actions (migration 046), written
      from the **diff** — a save that re-sends the same roles is not an
      escalation and must not read like one. A creation writes its grant too,
      so "who gained `admin` last quarter" does not miss accounts created as one
- [x] `roles` on create and update, validated as a whole: a list naming a
      non-role is refused rather than silently filtered
- [x] Out-of-band lifecycle mail on a role change — delivered in M5b, where the
      club-level notification address it has to reach lives

### M4 — Allow-list `group_by` on `/reports/{reportType}` ([#515](https://github.com/dgloeckner/clubbar/issues/515))

- [x] `ReportGroupByPolicy` — `getraenkewart` restricted to `category`,
      `product`, `day`, `week`, `month`, `year`. An **allow-list**: a dimension
      added later is refused until somebody grants it, in a diff a reviewer sees
      — *verified*: `ReportGroupByPolicyTest` 9/9, `ReportGroupByRoleHttpTest` 6/6
- [x] Every role is enumerated, `admin` included, so a new dimension **fails the
      build** until it is classified — the route map's completeness property
      applied to the parameter
- [x] A value that is not a dimension at all stays a 400 for every role: a typo
      is a malformed request, not an authorization failure. (Caught by the
      existing `reports.spec.ts` › *invalid group_by returns 400*, which the
      first version of this slice turned into a 403)
- [x] `summary.unique_member_count` untouched for every role — a count with no
      names attached

### M5 — Club-level notification address for admin lifecycle mail ([#521](https://github.com/dgloeckner/clubbar/issues/521))

**M5a — storage and fan-out (shipped, behaviour-neutral):**

- [x] Migration 047: `mail_config.club_notification_address`, wired through the
      DTO, the repository's writable columns and the controller's validation.
      It lives on `mail_config` because that is `admin`-only in the grant table
      — load-bearing, not convenient: a Kassenwart must not be able to redirect
      the channel that would report their own promotion
- [x] Migration 048: `admin_account_created` and `admin_role_changed` mail kinds,
      with `MailKind::addressesClub()` as an explicit `match` so the next kind
      has to answer the question rather than inherit an answer
- [x] `MailRequestDto::forClub()` — no `admin_user_id`, so migration 026's
      cascade cannot take the club's copy along with the account it is about,
      and a `:club` dedup suffix so it neither collides with an admin's copy nor
      silences one
- [x] `AdminNotifier` fans out to the club address for club-addressed kinds;
      unset degrades to exactly today's behaviour with no error
- [x] Retention classified for both kinds
- [x] Behaviour-neutral: nothing enqueues the new kinds yet. Backend Feature
      635/635, Unit green apart from the pre-existing `ServiceFactoryTest`
      environment failure

**M5b — the messages themselves (shipped):**

- [x] `AdminLifecycleMail` renders both notices in de and en, as branches of
      `AdminSecurityMailBuilder` (which already claims the whole `ADMIN_USER`
      subject). One class rather than two, on the precedent
      `EncryptionKeyEventMail` set for its three events
      — *verified*: `AdminLifecycleMailTest` 7/7
- [x] It names the role set the account holds **now**, not the delta: ADR-0038
      rule 5 renders from current state at send time, and a message queued
      before a second change would otherwise describe a world that no longer
      exists. The delta lives in the audit log, and the message says so
- [x] `AdminUsersService` enqueues on account creation and on a role change,
      best effort — the account is already created and the roles already moved
      when it runs. A creation announces a creation and **not** also a role
      change: `applyRoles()` writes and audits, and the caller that knows which
      event this is owns the announcement
      — *verified*: `AdminLifecycleNoticeTest` 6/6, including the
      zero-other-admins case and the unset-address degradation
- [x] Asserted against what a real drain delivered to Mailpit (E2E Pattern 010)
      in its own `mail-lifecycle` project — creation and promotion, both to the
      club address, both through `bin/cron.php`
- [x] Never contains a password, token or key material — asserted in both
      PHPUnit and against the delivered message, using the *real* generated
      password rather than a pattern resembling one
- [x] `MailRequestDto` learned that a club-addressed row is a legitimate third
      case. It previously refused any row naming neither a member nor an admin —
      correctly, since an unattributed row cannot be found by erasure — and the
      club copy names neither. It is now an explicit flag rather than a loosened
      check, and naming a person *and* claiming club-addressing is refused
- [x] `club_notification_address` documented in `api/admin.yaml` on both the
      read and write shapes of `mail-config`
- [x] The queue the fan-out fills is kept off the delivery chains' path. ADR-0044
      sends these notices to **every active admin** as well as the club address,
      which is a handful of rows in a club and quadratic in this test suite —
      every spec that mints a throwaway admin leaves it active, so a full run
      ended with thousands of rows queued ahead of whatever a chain spec was
      waiting for. `prenotification-chain` then drained, spent its batch and its
      budget on the backlog, and reported *Mailpit should hold exactly 1
      message: received 0* in a file that had done nothing wrong. A
      `quiet mail backlog` setup project — after everything that queues, before
      everything that delivers — discards the unclaimed backlog, and
      `--project=mail-lifecycle` was added to the CI matrix, which M5b had
      created without wiring up
      — *verified*: reproduced locally against a 6,450-row backlog (4 chain
      specs failing), then green with the setup in place

### M6 — Admin frontend: hidden nav, per-role landing, refusal screen ([#516](https://github.com/dgloeckner/clubbar/issues/516))

- [ ] Sections the role cannot reach are **hidden**, not disabled
- [ ] Landing per role — `getraenkewart` → Products, otherwise Dashboard
- [ ] A named `insufficient_role` screen, because hidden navigation is not
      enforcement: a bookmark, a stale tab or a role changed mid-session all
      produce a legitimate 403 for a legitimate user

### M7 — E2E access matrix and per-role flows ([#517](https://github.com/dgloeckner/clubbar/issues/517))

- [ ] One spec per role walking what it may and may not reach

### M8 — Documentation ([#518](https://github.com/dgloeckner/clubbar/issues/518))

- [ ] `backend/patterns/pattern-015-authorization-access-control.md` rewritten
      around the route→roles map
- [ ] Amendment note on ADR-0015 (owner confirmation required before editing it)
- [ ] This plan and `plans/INDEX.md` refreshed

---

## Rules that hold across every milestone

1. **Fail closed, everywhere.** No map entry means `admin`-only. Where a
   boundary lands on a parameter rather than a route, it is an allow-list.
2. **Granting a role is minting authority** — step-up, dedicated audit actions,
   out-of-band mail.
3. **The alarm survives a single admin** — hence `mail-config` is `admin`-only.
4. **The migration never removes access.** Every existing account becomes
   `admin`; demotion is a deliberate, audited, per-person act afterwards.
5. **Hidden navigation is not enforcement.** The server refuses independently.

## Non-goals

Permissions as data · a read-only tier · per-holder keypairs · four-eyes
approval · field-level redaction as the primary mechanism · any change to
`/api/sync/*`.
