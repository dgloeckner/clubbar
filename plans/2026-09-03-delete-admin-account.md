# Delete an admin account that never signed in

**Issue**: none — asked for directly ("Super admin should have a button to
delete other admins. this seems to be missing.")
**Use case**: [UC-A61](../use-cases/admin/UC-A61-manage-admins.md)
**ADR**: none new — [ADR-0044](../adr/0044-tiered-admin-roles.md)'s grant table
already reads `/admin-users/*` — create, edit, **delete**, reactivate,
reset-password → `admin` ✅. The capability was decided; only the implementation
was missing.

## Context

Settings → Administrators offered create, edit, reset-password-or-resend-
invitation, reset-2FA and an active toggle — and no way to remove an account. An
invitation sent to the wrong address stayed on the roster forever as a
greyed-out row.

Two things made this more confusing than a plain missing feature:

1. **`DELETE /api/admin/admin-users/{id}` already existed and did not delete.**
   `AdminController::destroy()` called `deactivateAdminUser()`. The panel never
   called it — it deactivates with `PATCH {is_active: false}` — so only API
   tests exercised the verb, and they asserted that DELETE *kept* the row.
2. **"Super admin" is not a thing here.** `CONTEXT.md` bans the word; `admin` is
   the top role and `/admin-users/*` was already `ADMIN_ONLY` in `RouteRoleMap`.
   Nothing in this change introduces a tier.

## Why deletion is narrow

`admin_users.id` is referenced across the schema with two incompatible meanings:

| Referencing column | On delete | Consequence |
|---|---|---|
| `settlements.created_by_admin_id`, `cancelled_by_admin_id` | `RESTRICT` | DB refuses outright |
| `mandate_documents.uploaded_by_admin_id`, `registrations.submitted_by_admin_id` | `RESTRICT` | same |
| `audit_log.admin_user_id` | **no FK at all** | row survives pointing at nothing; the list `LEFT JOIN`s `admin_users`, so the actor column silently goes blank — an ADR-0013 violation |
| `admin_user_roles`, `admin_user_invitations`, `mail_outbox` | `CASCADE` | cleaned up with the account |

One half fails loudly, the other destroys the evidence. **An account that has
never signed in and never authored an audit row can have produced neither**, so
both are out of reach rather than handled. A departed admin who did work is
retired by deactivating them.

Both halves of the rule are needed: accepting an invitation audits under the
*invitee's* name before any `last_login_at` exists (it is the one audit row in
the system written by a request carrying no session), so a guard that only read
the login timestamp would delete that account and strand the row.

## Milestones

- [x] **M1 — Backend.** `AdminUsersService::deleteAdminUser()` with three
  guards (self → `cannot_deactivate_self`; last active `admin` →
  `last_active_admin`; has signed in or acted → new
  `ADMIN_USER_HAS_HISTORY`), audited as `AuditAction::DELETE` **before** the
  delete and carrying the removed email/display name/roles — once the row is
  gone that entry is the only thing that says who was removed. A `PDOException`
  from an unanticipated `RESTRICT` surfaces as the same refusal, not a 500.
  `AdminUsersRepository::deleteById()` and `AuditLogRepository::hasEntriesByActor()`
  are new. `destroy()` now returns 204; the old body moved to a new
  `POST /admin-users/{id}/deactivate`, mirroring the existing `/reactivate`.
  `RouteRoleMap` entry added (the completeness test fails without it).
  *Verified*: backend suite 3777 green in the container.
- [x] **M2 — API spec.** `api/admin.yaml` gained `delete:` and `get:` on
  `/admin/admin-users/{adminUserId}` plus the `/deactivate` and `/reactivate`
  paths — the last two existed in `routes.php` and had never been documented.
  This is what generates `getAdminUsers().deleteAdminUser()`.
  *Verified*: orval regenerates all four; `tsc --noEmit` clean.
- [x] **M3 — Admin panel.** A trash button on the row, desktop and mobile,
  shown only when `last_login_at == null`; `ConfirmDialog` naming the account's
  email; refusals rendered through `useApiError` from the new
  `errors.reasons.admin_user_has_history` key in both locales.
  *Verified*: 557 unit tests green (incl. `reasons.test.ts`, `locales.test.ts`).
- [x] **M4 — Tests.** 9 service unit tests; 4 API specs (delete, refuse after
  invitation accepted, refuse self, invitation link dies with the account); 2 UI
  specs (removes a never-signed-in account; withholds the button from one that
  has signed in); `role-access-matrix` row for the new route. Five existing
  specs that used `DELETE` as a deactivate moved to `POST .../deactivate`.
  *Verified*: `api-tests` 813/813, `admin-chromium` 389/389,
  `admin-mobile` 54/54, `mail-credentials`/`mail-issuance`/`mail-lifecycle`
  10/10 — all at 4 workers on a wiped, freshly seeded database.

## Notes

- **No lifecycle mail on deletion**, matching deactivation, which announces
  nothing today. A new `MailKind` obliges four exhaustive `match` arms; parity
  with deactivate is the defensible default. Raise separately if the club wants it.
- The first draft of the UI test compared `getAdminUserCount()` before and
  after. That fails under 4 workers whenever a sibling test's create lands
  between the two reads — it did. Replaced with a page reload plus an assertion
  on the specific row's absence (E2E Pattern 003).
- `docs/erm-master.md`'s `admin_users` "Access:" note still claimed every admin
  has full access, which predates ADR-0044; corrected in passing since the
  deletion note lands beside it.
