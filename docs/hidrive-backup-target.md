# Provisioning the HiDrive backup target

**Scope**: setting up and maintaining the IONOS HiDrive destination that the
`hidrive://` backup transport pushes archives to.

**Audience**: whoever holds IT for the club — and, more importantly, whoever
holds it *next*. Write the values from §3 down somewhere a successor will find
them.

**Companion documents**: [ADR-0049](../adr/0049-encrypted-offsite-backups-on-shared-hosting.md)
(why backups look the way they do), [`m365-backup-target.md`](./m365-backup-target.md)
(the other supported destination), [`deployment.md`](./deployment.md) (the
install this plugs into), [`procedures.md`](./procedures.md) (the restore drill
this is all for).

---

## 0. Which destination should this club use?

**Microsoft 365** if the club already has a tenant *and* the webspace can reach
`login.microsoftonline.com`. It is the stronger option on paper: a separate
vendor from the hosting, and a site retention policy that makes a delete
recoverable for 93 days.

**HiDrive** if it cannot. That is not hypothetical — it is why this document
exists. On the reference host, Microsoft's edge answers `404 NotFound` to
*every* request from the webspace's egress address, including an anonymous fetch
of the public OpenID metadata document that returns `200` from anywhere else
([#825](https://github.com/dgloeckner/clubbar/issues/825)). No credential, no
tenant and no retry policy can move that, and an unreachable off-site copy
protects nothing at all.

Read §6 before choosing. HiDrive is the **same vendor as the hosting**, and that
costs something real.

---

## 1. Enable WebDAV

It is off by default, and it is a **per-user** switch — turning it off for one
user is indistinguishable, from the server's side, from a wrong password.

1. Open the HiDrive web app and sign in as the account owner.
2. **Settings → Access rights and protocols**.
3. Click the pencil icon and enable **WebDAV**.

## 2. Create the backup user

**Do not use the account owner's credentials.** The credential ends up in
`config.php` on the webspace, so what it can reach is what a compromised
webspace can reach. A dedicated user with access to one folder is the boundary
that makes that survivable — and it is a boundary the Microsoft 365 target
cannot offer, because `Sites.Selected` has no add-only role and its `write`
includes delete.

1. **Administration → Users → add a user**. Call it something a successor will
   recognise: `clubbar-backup`.
2. Give it its own password. Record it with the club's other credentials — it is
   not recoverable from the installation, because `config.php` is on a webspace
   a restore may be replacing.
3. Enable **WebDAV** for this user too (§1 is per user).

## 3. Create the folder, and grant only it

1. As the backup user — or as the owner, sharing into it — create the folder
   archives will live in, e.g. `archives` inside that user's home.
2. Confirm the backup user can read *and* write it. The nightly job needs
   `PUT`, `PROPFIND` and `DELETE`: the last one is remote retention, and without
   it archives accumulate until the tariff is full (§6).
3. Note the folder's full WebDAV path. A user's WebDAV root is
   `/users/<username>`, so the folder above is `/users/clubbar-backup/archives`.

## 4. Compose the DSN

```
hidrive://clubbar-backup@webdav.hidrive.ionos.com/users/clubbar-backup/archives
```

| Part | What it is |
|---|---|
| `clubbar-backup` | the backup user from §2 — the *only* place its name appears |
| `webdav.hidrive.ionos.com` | the WebDAV host. Written out rather than assumed, so a STRATO-hosted account or a per-user host works without a release |
| `/users/clubbar-backup/archives` | the folder from §3, as an **absolute** path. Nothing is prepended to it, so a folder *shared* into this user from elsewhere is addressable too |

**The password never goes in the DSN.** A DSN is the value that gets pasted into
support threads and screenshots; a DSN carrying a password is refused rather
than accepted.

## 5. Configure it

```php
'backup' => [
    'recipient_public_keys' => ['…'],   // the on-switch: no key, no archive
    'dsn'                   => 'hidrive://clubbar-backup@webdav.hidrive.ionos.com/users/clubbar-backup/archives',
    'remote_secret'         => '…',     // the backup user's password
    // 'client_secret_expires_at' is msgraph:// only — a HiDrive password does
    // not expire, so the self-check shows no expiry row for this scheme.
],
```

`remote_secret` was called `client_secret` before
[#825](https://github.com/dgloeckner/clubbar/issues/825). The old name is still
read, so an existing `config.php` needs no edit; the self-check names the new
one once, in a row that is otherwise a pass.

Then run one backup and read the log line:

```bash
php backend/bin/backup.php --force
```

A good night says `Backup upload finished` with `"verified": true` — the
transport asked for the file back and compared its length against the local
archive, because a `201` on its own is a claim rather than evidence.

---

## 6. What this destination does not give you

Stated here rather than discovered during a restore.

**It is the same vendor as the hosting.** ADR-0049 requirement (a) asks for a
failure domain separate from the host, and this only half satisfies it: losing
the webspace does not touch HiDrive, but a suspended or unpaid *account* takes
both. **The periodic manual copy therefore remains a standing duty** — it is the
copy that survives losing the vendor, and it is the reason this trade is
acceptable rather than reckless.

**A delete is less recoverable than on SharePoint.** HiDrive keeps file
versions, and they can be restored from the Backups area — but there is no
recycle bin, no file-history list, and how much is kept depends on the tariff. A
SharePoint library's 93-day retention is a stronger net. Remote retention still
deletes archives past `backup.remote_retention_days`, deliberately: ADR-0049
requirement 5 outranks recoverability here, because a backup outliving an
ADR-0029 erasure defeats the erasure.

**The folder is never created for you.** A `PUT` into a folder that does not
exist fails, loudly, naming the folder. Creating it automatically would turn a
mistyped DSN into a *successful* upload into a folder nobody is watching, which
is the belief this whole feature exists to prevent, reached by a typo.

**An upload is not resumable.** A WebDAV `PUT` is one shot. A night that runs
out of budget re-sends the whole archive next run rather than continuing — the
archive is streamed from disk, so this costs time, never memory. A failed night
is *not* a lost night: the run leaves a marker beside the archive and the next
run comes back to it.

---

## 7. When it fails

The nightly log line names the thing to look at. The four that happen:

| Symptom | What it means | What to do |
|---|---|---|
| `The backup user was refused … (HTTP 401)` | wrong password, **or** WebDAV switched off for that user | Check `backup.remote_secret`, then §1 — for the *user*, not just the account |
| `The folder … does not exist on the remote` | the path in `backup.dsn` is not there | Compare it against §3. A user's root is `/users/<username>` |
| `No space left …` | the tariff is full | Free space, or lower `backup.remote_retention_days` |
| `Could not reach webdav.hidrive.ionos.com` | network, DNS, or an outage | Nothing to fix; the archive is on the webspace and the next run retries it |

A verification mismatch — *"the remote reports N bytes where the archive has
M"* — leaves the remote file alone on purpose. `PUT` is idempotent, so the next
run overwrites it; nothing is deleted on the strength of a size comparison,
because that would be a delete path firing on a bug in our own arithmetic.
