# Runbook: an admin cannot get in

Covers the three ways an admin account becomes unusable, in the order you should
try them. The first two are self-service from the admin panel. The third needs
database access and exists because the supported path has a hole in it.

**Prevention, before anything else:** keep at least two admin accounts, both
with 2FA enrolled. Every recovery below except the last is one admin helping
another, so a single-admin installation has no recovery path inside the
application. There is no technical guard forcing a second admin — the system
only refuses to deactivate the *last active* one — so this is an operational
habit, not something the software will remind you about.

---

## 1. Lost or forgotten password

Any signed-in admin can reset another's.

Settings → Admin Users → the row → **Reset password**. You will be asked for
*your own* password and 2FA code (a step-up, ADR-0036), not the target's. A new
random password is generated and shown **once** — copy it before closing the
dialog; it is not stored anywhere and cannot be shown again. Hand it over
out-of-band and have the owner change it under Profile.

The reset ends every session the target account had open (UC-A63). They sign in
again with the new password and their existing authenticator.

## 2. Lost authenticator (phone gone, app wiped, backup key lost)

This is the case ADR-0026's recovery mechanism is built for.

Settings → Admin Users → the row → **Reset 2FA**, again behind your own
step-up. This clears the account's TOTP secret and enrolment flag. On their next
login the account meets the mandatory enrolment gate and sets up a fresh
authenticator; every session it had open is refused in the meantime.

The reset is recorded in the audit log as `totp_reset`, naming both you and the
target.

> If the admin still has the **backup key** shown under the QR code during
> enrolment, they do not need this: entering that key into a new authenticator
> app reproduces the same codes. That is what the key is for, and why the
> README tells people to store it in a password manager.

## 3. The last admin is locked out

**There is no in-application recovery for this.** The installer creates the
first admin only during a fresh install — it refuses to run once
`backend/storage/.installed` exists — and there is no CLI recovery command. If
the only admin loses their authenticator, or every admin does, the way back in
is the database.

You need whatever database access your hosting gives you: phpMyAdmin or an
equivalent web client on shared hosting, otherwise a `mysql`/`mariadb` shell.
The connection details are in `backend/.env` (`DB_HOST`, `DB_NAME`, `DB_USER`,
`DB_PASS`).

### Clearing 2FA on an account

This is the smaller hammer and usually enough — the password is still known,
only the second factor is lost:

```sql
UPDATE admin_users
   SET totp_secret = NULL,
       totp_enabled = 0,
       totp_last_timestep = NULL
 WHERE email = 'admin@example.org';
```

Sign in with the existing password. The mandatory enrolment gate will ask you to
set up an authenticator before anything else is reachable — that is expected,
and it is the same screen a new admin sees.

### If the password is also gone

A password hash cannot be reversed, so it has to be replaced. Generate a bcrypt
hash rather than inventing one — the application will not accept anything else:

```bash
docker compose exec backend php -r \
  'echo password_hash("choose-a-strong-temporary-password", PASSWORD_BCRYPT, ["cost" => 12]), "\n";'
```

On a shared host with no container, run the same one-liner through whatever PHP
CLI the host provides.

```sql
UPDATE admin_users
   SET password_hash = '<paste the hash>',
       totp_secret = NULL,
       totp_enabled = 0,
       totp_last_timestep = NULL,
       is_active = 1
 WHERE email = 'admin@example.org';
```

Sign in, enrol a fresh authenticator, then **change the password from the
Profile page** so the temporary one does not stay in your shell history.

### If no admin row is usable at all

Reactivate one rather than inserting a new row — an insert has to satisfy the
`id` (UUID) and `email` (UNIQUE) constraints by hand, and gets the audit trail's
foreign keys wrong:

```sql
SELECT id, email, is_active, totp_enabled FROM admin_users;
```

Pick a row, set `is_active = 1`, and apply the password/2FA reset above.

### Afterwards

Two things, neither optional:

1. **Create a second admin** and enrol it, so the next lockout is case 1 or 2
   rather than this one.
2. **Note what you did.** A direct database write bypasses the audit log
   completely: the application has no record that the credential changed, which
   is precisely the gap the audit log exists to close. Nothing in the system
   will tell a later reader that this happened.

Editing `admin_users` by hand does **not** advance `credentials_changed_at`, so
sessions that were open before the edit keep working. If you are recovering
because you suspect the account was taken over, set it explicitly to cut them:

```sql
UPDATE admin_users SET credentials_changed_at = NOW() WHERE email = 'admin@example.org';
```

---

## Related

- [ADR-0026](../adr/0026-mandatory-totp-two-factor-authentication.md) — mandatory TOTP, and why there are no recovery codes
- [ADR-0015](../adr/0015-authentication-and-authorization-strategy.md) — the flat admin model and session handling
- [UC-A63](../use-cases/admin/UC-A63-reset-admin-password.md) — admin resets another admin's password
- [Deployment Guide](./deployment.md) — installation and database configuration
