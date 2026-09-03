# UC-A61: Manage Admin Users

**Implementation Status**: Implemented

## Actor
Admin

## Preconditions
- Admin is logged in

## Trigger
Admin opens Settings → Administrators

## Main Flow
1. Admin clicks "Administrators" in Settings
2. System displays admin user list
3. List shows for each admin:
   - Email/username
   - Display name
   - Status (active/inactive)
   - Last login
4. Admin can add, edit, or deactivate users

## List Columns

| Column | Content |
|--------|---------|
| Username | Login identifier |
| Display Name | Human-readable name |
| Status | Active/Inactive |
| Last Login | Timestamp |
| Actions | Edit, Reset password (or resend invitation), Reset 2FA, Deactivate, Delete |

## Available Actions
- Create new admin (UC-A62)
- Reset password (UC-A63)
- Deactivate admin
- Reactivate admin
- Delete admin — only an account that has never signed in

## Postconditions
- Admin list displayed

## Business Rules
- Cannot deactivate own account
- At least one active admin required
- Cannot delete an account that has signed in or acted — deactivate it instead
- Cannot delete own account

## Own Account
The signed-in admin's own row is marked as such and its active switch is
locked. Deactivating it would end the admin's session on the next request and
leave no way back in, so the control is withheld rather than offered and then
refused. The API enforces the same rule independently (409).

## Deleting an Account

Deactivation retires a colleague and keeps everything they did. **Deletion
removes the row, and is offered only for an account that has never signed in
and never acted** — an invitation sent to the wrong address, or a colleague who
never took up the post. Without it the list accumulates greyed-out rows that
can never be cleared.

The narrowness is a data-integrity rule, not caution. `admin_users.id` is
referenced across the schema with two incompatible meanings:
`settlements.created_by_admin_id` and `mandate_documents.uploaded_by_admin_id`
are `ON DELETE RESTRICT`, so the database refuses outright;
`audit_log.admin_user_id` carries no constraint at all, so the database allows
it and every past action by that admin then renders with a blank actor — the
evidence [ADR-0013](../../adr/0013-audit-logging.md) exists to keep. An account
that never held a session can have produced neither, so both are out of reach
rather than handled.

The button is withheld from rows that do not qualify rather than offered and
then refused, the same treatment the own-row active switch gets. The API
enforces the full rule independently, including the half the panel cannot see
(an account that authored an audit row without ever logging in — accepting an
invitation does exactly that), and refuses with `admin_user_has_history`.

Deleting an account cascades away its outstanding invitation, so a link already
in somebody's inbox stops working rather than pointing at an account nobody can
see. The deletion is audited with the removed account's email, display name and
roles, because once the row is gone that entry is the only thing that still says
who was removed.

## Test Derivation
- List admins: all shown
- Last login: accurate timestamp
- Self-deactivate: prevented
- Own row: marked, active switch disabled
- Other rows: active switch operable
- Last admin: cannot deactivate
- Delete a never-signed-in account: row gone, count decremented, audited
- Delete an account that accepted its invitation: refused, `admin_user_has_history`
- Delete own account: refused
- Delete button: absent on any row that has signed in
- Deleted account's invitation link: no longer accepted
