# UC-A04: Change Own Email Address

**Implementation Status**: Implemented

## Actor
Admin

## Preconditions
- Admin is logged in

## Trigger
Admin edits the email field on their profile page and saves

## Why this is a credential change

The email address is the **login identifier**, not a contact detail. Changing it
changes who can sign in to the account, which makes it a quieter form of the
same takeover the cross-account resets are guarded against — and it used to need
nothing beyond a live session.

It is therefore gated exactly like those resets, and only when the address
actually moves: saving a changed display name or switching the interface
language asks for nothing, because the profile form and the language switcher
both PATCH this same endpoint.

## Main Flow
1. Admin opens their profile
2. Admin types a new address into the email field
3. Admin presses Save
4. System sees the address differs and opens the step-up dialog instead of saving
5. Admin enters their own current password and their own current TOTP code
6. Admin confirms
7. System verifies the credential and writes the new address
8. System notifies the **previous** address that it is no longer the login
9. System invalidates every other session on this account
10. System shows the profile as saved

## Postconditions
- `admin_users.email` holds the new address; the next login uses it
- The previous address has a queued notice (best effort — see below)
- The change is in the audit log as `email_changed`, carrying old and new values
- The acting session still works; every other session on the account is refused
- Display name and locale submitted alongside are saved too

## Business Rules

| Rule | Behaviour |
|------|-----------|
| Address unchanged | No credential asked for. Re-submitting the current address is not a change |
| Case-only difference | Not a change. `admin_users.email` is UNIQUE under a case-insensitive collation, so the database would not record one either |
| Address held by another admin | 422 naming the field, before any credential is asked for |
| Display name / locale only | No credential asked for |
| Notification fails | The change still succeeds. It is already committed; the failure is recorded rather than raised |

## The notice to the old address

Sent **to the address the account was changed away from** — the only channel
that still reaches the previous owner if the change was not theirs.

It names the former address, the time, and what to do if this was unexpected
(contact another admin — recovery is admin-to-admin, ADR-0026). It deliberately
does **not** name the new address: whoever reads this can no longer sign in, and
if the change was a takeover, printing the attacker's address into the victim's
mailbox helps nobody. It carries no undo link and no token; a self-service
reversal reachable from an email would be a second way in, defeating the step-up
that gates the change.

It is queued, not sent (ADR-0038): a scheduler drains the outbox, and an
installation with no `mail.dsn` discards it at the transport. This is why it is
**never a gate** — the change must succeed on installations that cannot send
mail at all.

## Error Cases

### E1: Wrong Password or TOTP Code
- 401; the dialog stays open with the typed address intact so the credential can be retried
- The admin is **not** signed out — a rejected credential is not a dead session
- Counted against the login rate limiter (5 per 15 min, per account) and audited as a failed step-up

### E2: Address Already In Use
- 422 naming the `email` field
- Checked before the credential prompt, so a duplicate does not cost a password entry

### E3: Rate Limited
- 429 after five failed credential attempts within fifteen minutes
- Also blocks the harmless half of this endpoint for that window — a locale switch answering 429 is the accepted cost of not leaving an unmetered guessing oracle

## Test Derivation
- Email change without a credential: 401, and the address is unchanged
- Email change with a full step-up: 200, and the **new address authenticates**
- Wrong credential: 401, dialog stays open, admin stays signed in
- Locale-only update: 200 with no credential
- Display-name-only update: 200 with no credential
- Unchanged address submitted with the rest of the form: 200 with no credential
- Case-only difference: 200 with no credential
- After a change, a second session on the account is refused; the acting one works
- After a change, the audit log holds `email_changed` and `mail_enqueued`, and no `notification_failed`

## Related
- [UC-A03](./UC-A03-change-password.md): Change own password
- [UC-A63](./UC-A63-reset-admin-password.md): Reset another admin's password
- [ADR-0015](../../adr/0015-authentication-and-authorization-strategy.md): Self-service credential changes
- [ADR-0026](../../adr/0026-mandatory-totp-two-factor-authentication.md): Mandatory TOTP; sessions after a credential change
- [ADR-0038](../../adr/0038-transactional-mail-outbox-on-shared-hosting.md): The outbox the notice is queued to
