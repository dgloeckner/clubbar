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

- [ ] Role change carries step-up, gated on the role set *actually* changing
- [ ] `ROLE_GRANTED` / `ROLE_REVOKED` audit actions
- [ ] Out-of-band lifecycle mail on a role change

### M4 — Allow-list `group_by` on `/reports/{reportType}` ([#515](https://github.com/dgloeckner/clubbar/issues/515))

- [ ] `getraenkewart` is restricted to `category`, `product`, `day`, `week`,
      `month`, `year` — an **allow-list**, never a deny-list. Not `member`, which
      turns every row into a named member with their personal spend

### M5 — Club-level notification address for admin lifecycle mail ([#521](https://github.com/dgloeckner/clubbar/issues/521))

- [ ] Admin lifecycle mail also goes to a configured club address, so the alarm
      still fires when there is one admin and they are the problem

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
