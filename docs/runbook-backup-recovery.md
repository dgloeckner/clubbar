# Runbook: Backup Recovery

What to actually do, at a keyboard, when a backup is the answer — and the three
cases where it is not.

This is written for the deployment Club Bar targets: **shared hosting, no shell,
no client binaries** ([ADR-0031](../adr/0031-mass-hosting-deployment-target.md)).
Every step below is performable with a browser, a file manager and phpMyAdmin.
Nothing here needs `mysql`, `mysqldump` or SSH, because on the reference host
none of them exist.

| Section | Use it when |
|---|---|
| [1. Restore end to end](#1-restore-end-to-end) | The database is gone, or you are moving to a new host |
| [2. Repair one table](#2-repair-one-table) | One table is damaged and the rest is fine — **far more common** |
| [3. Rotation on handover](#3-rotation-on-handover) | A key holder leaves the board |
| [4. Compromise](#4-compromise) | A key or the host is believed to be in the wrong hands |

**Before anything else:** a restore is destructive to whatever is there now.
Section 2 exists because restoring everything to fix one table throws away every
booking since the dump — and on a club bar that is a week of real money.

---

## What an archive is

A `.cbb` file in the `backups/` directory of the data directory, plus a
`journal` file beside it.

- **Sealed to public keys**, so the server that wrote it cannot read it back
  ([ADR-0049](../adr/0049-encrypted-offsite-backups-on-shared-hosting.md)).
  Opening one needs a **private key**, which is deliberately not on the host.
- **Self-describing without any key.** The header carries the instance name, the
  database name, the schema version, a manifest of tables and row counts, and
  the checksum of the plaintext. `tools/backup-decryptor.html` shows all of it
  *before* asking for a key.
- **It carries `config.php`** as well as the rows. That matters more than it
  sounds — see [1.4](#14-you-need-configphp-too-when-the-host-is-new).

> **Composition risk, stated plainly.** `docs/deployment.md` already puts it in
> the words this feature inherits: **the DB backup and the key archive should
> never be the *only* two copies of anything sitting in the same building.**
> Since the archive now carries `config.php`, one archive plus one private key
> is the whole installation. Store them apart.
>
> Member IBANs are the exception, and deliberately so: they are sealed to a
> *separate* keypair the Kassenwart holds
> ([ADR-0036](../adr/0036-iban-encryption-sealed-box.md)), which is not in
> `config.php` and not in the archive. A backup key holder cannot read IBANs.

---

## 1. Restore end to end

### 1.1 Get the archive and open it

1. **Download** the newest `.cbb` — from `backups/` on the host, or from the
   Microsoft 365 library if the remote transport is configured.
2. **Open `tools/backup-decryptor.html`** in a browser **on a machine you
   trust**. It runs entirely locally; nothing is uploaded. If you are restoring
   because the host may be compromised, do not do this on the host.
3. **Read the header before you decrypt.** Check the instance name and the
   `created_at` are the ones you meant. The tool shows the table manifest, so
   "this archive has 0 transactions" is visible here rather than after an
   import.
4. **Paste the private key** and decrypt. Download the `.sql`.

The tool verifies the plaintext checksum against the header. If it complains,
**stop** — a mismatch means the header was edited, which is not something that
happens by accident.

### 1.2 Import through phpMyAdmin

1. Create an empty database, or empty the existing one. The archive contains
   `DROP TABLE IF EXISTS` per table, so importing over a populated schema works,
   but starting empty makes "what did I just get" answerable.
2. **Confirm the file starts with the session settings.** Open it and look at
   the first dozen lines; they must include

   ```
   SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
   SET FOREIGN_KEY_CHECKS = 0;
   SET time_zone = '+00:00';
   ```

   **This is the only thing that protects the import, and it needs no action
   from you** — the archive pins its own time zone in its header, ahead of any
   data, whatever zone your panel happens to be in. You cannot set it from
   outside: phpMyAdmin opens a new database connection per request, so a
   `SET time_zone` typed into the SQL tab does not reach the Import tab.

   So there is exactly one way to lose it: importing a **piece** of the file
   that does not carry those lines. A whole `.sql` always does, and so does
   every piece the decryptor cuts. A piece somebody cut by hand may not — and
   that failure is silent. Every `TIMESTAMP` in it lands in the host's own
   zone: settlement dates, announcement distances, audit timestamps and the
   seven-day SEPA window all shifting together, consistently, with nothing
   about the result looking wrong.
3. Import the `.sql`. §1.3 is where you prove the pinning took.

**If the file exceeds phpMyAdmin's upload limit** — which on a club-sized
database it eventually will — import **per table**, and let the decryptor cut
the pieces. Under the download link it offers *Import table by table*: one
ready-to-import `.sql` per table, each already carrying the session settings,
with its size shown so you can see which ones your panel will accept. Import
them in any order; foreign-key checks are off.

Take those rather than cutting the file yourself. Splitting by hand is three
steps, and the third — pasting the header lines in front of **every** piece —
is the one with no symptom when it is forgotten: the piece imports cleanly, in
the host's own zone, and every `TIMESTAMP` in it is silently wrong.

<details>
<summary>Cutting it by hand, if you are not using the decryptor</summary>

The archive is built for this: each table is bracketed by

```
-- >>> TABLE members
...
-- <<< TABLE members
```

and every `INSERT` names its columns. Split the file at those markers, and put
the archive's own header lines (everything above the first `-- >>> TABLE`,
except the `ALTER DATABASE` line, which names no database and would retarget
whichever schema you have open) in front of **each** piece — that is what
carries `SET NAMES`, `SQL_MODE`, `FOREIGN_KEY_CHECKS` and the time zone.

</details>

**If one table alone is still too big**, cut that piece again at any line
beginning `INSERT INTO`, keeping everything above the first `INSERT` at the top
of each part. Every `INSERT` names its columns and carries at most a hundred
rows, so any run of them is valid on its own.

### 1.3 Check afterwards

**First, put this session in UTC** — not to fix anything, but because every
check below is read *through* the session's zone, and a shifted one would
report a good restore as bad and hide the bad one. In the **SQL** tab:

```sql
SELECT TIMEDIFF(NOW(), UTC_TIMESTAMP()) AS utc_offset,
       @@session.time_zone, @@global.time_zone, @@system_time_zone;
```

`utc_offset` must be `00:00:00`. **Judge the offset, not the name** — a session
reads UTC as `UTC`, as `+00:00` or as `SYSTEM` on a UTC-configured server, and
all three are correct, while `SYSTEM` on a Berlin server is not. It is the same
check `DatabaseDump` makes before it will dump at all. If it is not zero:
`SET time_zone = '+00:00';` and stay in this tab for the rest of §1.3.

Then compare against the manifest the decryptor showed you:

```sql
SELECT COUNT(*) FROM members;
SELECT COUNT(*) FROM transactions;
SELECT COUNT(*) FROM settlements;
```

Then check the two things that are wrong-looking-right rather than absent:

```sql
-- Timestamps: does the newest transaction sit where you expect in UTC?
-- `received_at` (when the server learned of it), not `occurred_at` (when the
-- bar sold it) — an offline terminal legitimately backdates the latter.
SELECT MAX(received_at) FROM transactions;

-- Encrypted IBANs: present and non-empty. This does not prove they decrypt —
-- only the Kassenwart's key can — but an empty column here is a mangled import.
SELECT COUNT(*) FROM mandates WHERE iban_ciphertext IS NULL OR LENGTH(iban_ciphertext) = 0;
```

That last query must return **0**.

**And one check for the failure with no symptom.** A shifted import cannot be
seen in a `TIMESTAMP` column by itself: MariaDB converts a `TIMESTAMP` on the
way in *and* on the way out, so reading it back in the same wrong zone gives the
literal straight back. What does not move is `DATETIME`, which is stored exactly
as written. The schema uses both, so the two disagree by exactly the offset:

```sql
-- In the UTC session established at the top of §1.3 — this comparison is
-- meaningless read through a shifted one.
SELECT (SELECT MAX(received_at) FROM transactions) AS timestamp_side,
       (SELECT MAX(created_at)  FROM audit_log)    AS datetime_side;
```

For a club that was in use when the backup was taken these sit close together —
minutes, not hours. **Hours apart, by a round number that looks like a
timezone, means the import ran in a shifted session.** Re-import; there is
nothing to repair in place, because every affected value moved consistently.

### 1.4 You need `config.php` too, when the host is new

Restoring the rows onto a *fresh* installation and keeping the new
`config.php` produces a database **nobody can log in to**.
`security.totp_encryption_key` is what decrypts every admin's TOTP secret. It
lives in `config.php`, not in the database, and it cannot be regenerated — it is
the key the stored secrets were written under. `security.iban_fingerprint_key`
is the same shape of problem one level down: mandate-change detection stops
recognising an IBAN it has seen before.

So the archive carries `config.php`, and the decryptor offers it as a **second
download** whenever it is present. Restoring in place on the same host? You
already have it; ignore the second download. Moving hosts, or rebuilding? Take
it, and reconcile the database credentials by hand — those are the new host's,
everything under `security` is the old host's and must be kept.

The `.sql` contains the config as inert SQL comments, so there is nothing extra
to import and no risk in importing the file whole.

---

## 2. Repair one table

**This is the common case, and section 1 is the wrong remedy for it.** One
damaged table does not justify discarding every transaction booked since the
dump.

### 2.1 First: is it actually damage, or just an index?

A damaged or bloated **index** needs no archive at all:

```sql
ALTER TABLE transactions ENGINE=InnoDB;   -- rebuilds the table and every index in place
OPTIMIZE TABLE transactions;              -- on InnoDB, does the same thing
```

> **`REPAIR TABLE` is MyISAM-only.** It is the first thing an operator reaches
> for and it is **not the answer on this schema**, which is InnoDB throughout.
> It will tell you the storage engine does not support repair, and that message
> is easy to misread as "the table is beyond repair".

### 2.2 When the archive *is* the remedy

Only when a tablespace is unreadable or the table is gone. Then import **that
table's section alone**:

1. Decrypt the archive as in [1.1](#11-get-the-archive-and-open-it).
2. Cut out the section between `-- >>> TABLE <name>` and `-- <<< TABLE <name>`.
3. Put the archive's header lines in front of it (see
   [1.2](#12-import-through-phpmyadmin)) — the time zone especially.
4. Import.

The section drops and recreates just that table, with its indexes and
constraints, and refills it. Everything else is untouched.

**Know what you are choosing.** That table goes back to its state at the time of
the dump while every other table stays current. If you restore `transactions`
alone, bookings made since the dump are gone and the `settlements` that
reference them are not — reconcile before the next settlement run, not after.

This procedure is exercised in CI (`RestoreRoundTripTest`), which drops a table
from a restored schema and brings it back from its section alone. A runbook step
nobody has executed is the same kind of belief as an untested backup.

---

## 3. Rotation on handover

When a key holder leaves the board. There is **no annual rotation** — a chore
nobody performs becomes a warning nobody reads
([ADR-0049](../adr/0049-encrypted-offsite-backups-on-shared-hosting.md)
decision 3).

Backup keys are held by the **Admin** and a **second board member**. Never the
Kassenwart: they hold the IBAN key, and one person holding both would collapse
the separation of custody that decision exists to create.

### The sequence: add → verify → remove

1. **Add.** The incoming holder generates a keypair with
   `tools/keypair-generator.html` — the **hex** output under *Backup archive keys* — and gives you the **public** half. Append it
   to `backup.recipient_public_keys` in `config.php`, keeping the outgoing
   holder's entry.
2. **Verify.** Wait for the next nightly run, then have the *incoming* holder
   actually open that archive with their private key. Not "confirm they saved
   the file" — open it. A key that was corrupted on write or saved with the
   wrong encoding is indistinguishable from a good one until the day it is
   needed.
3. **Remove.** Only now delete the outgoing holder's public key from
   `config.php`.

### Two things that go wrong here

**No archives are deleted during rotation.** Rotation changes who can open
*future* archives. Every existing archive is still sealed to the old key and
always will be — re-sealing is impossible by construction, since it would need
the old *private* key on the server, which is precisely what this design refuses
to allow.

**"Rotated" means dead for writing, not dead for reading.** The outgoing
holder's private key stays **archived** — not destroyed — until no archive you
still keep is sealed to it alone. Discard it at rotation time and every archive
older than the rotation is destroyed silently, in the sense that matters:
the files are there and nothing can ever open them.

To find out when that moment arrives, read the archive headers. They list
recipient fingerprints and need no key:

- **Locally:** open each `.cbb` in `tools/backup-decryptor.html` and look at the
  recipients it names, before entering any key.
- **On the remote:** list the library and do the same for what is still there.

Once local retention and remote retention have both aged past the rotation date,
nothing is sealed to the old key and it can be destroyed.

> [#693](https://github.com/dgloeckner/clubbar/issues/693) adds a backups page
> that answers this by reading the same headers, so it becomes a screen rather
> than a file-by-file check. Until it ships, the check is manual — and it is
> still the check.

**Minute every step in the club's key register.** Custody is tracked there, not
in the application (ADR-0049 decision 4): the software does not know who holds
which key and deliberately never will.

---

## 4. Compromise

A private key, or the host, is believed to be in the wrong hands. This is the
only section where **deleting archives is the remedy** — and it comes last,
after a new key is proved.

### Order matters

1. **Establish a new key first.** Generate a new keypair, add its public half,
   wait for a run, and **open that archive** with the new private key. Until
   that succeeds you have no backups; deleting the old ones first would leave
   the club with none at all, which is a good deal worse than a compromised key
   nobody has yet used.
2. **Remove the compromised public key** from `config.php`.
3. **Produce the purge list from the archive headers** — a scan of the local
   `backups/` directory plus a listing of the remote store. Any archive naming
   the compromised fingerprint among its recipients is on the list. There is no
   database table to consult; the headers are the record (decision 8).
4. **Delete them,** locally and remotely.
5. **Rotate the host's other secrets** if the *host* rather than a key is
   compromised: database password, `cron.secret`, the SMTP DSN, and — because
   the archive carries `config.php` — treat everything under `security` in that
   file as exposed, with the consequences in the [note below](#what-software-cannot-fix).

### What software cannot fix

- **Old archives cannot be re-sealed to the new key.** It would require the old
  private key on the server. That is the same property that makes a stolen
  webspace yield only ciphertext, so it is not a gap to close — but it means
  step 4 is a deletion, never a migration.
- **The retention that protects you also limits you.** Library retention on the
  Microsoft 365 target is what makes a delete by a compromised *host* recoverable
  ([#691](https://github.com/dgloeckner/clubbar/issues/691)). It applies just as
  much to your deliberate purge: within the retention window, a tenant admin can
  restore what you just deleted. Ask them to purge the retained copies too, and
  record whether they could.
- **`security.totp_encryption_key` leaking means every admin's second factor is
  compromised**, not just one account's. Rotating it invalidates every enrolled
  authenticator, so plan for re-enrolling every admin rather than discovering it
  mid-incident.

### The reporting branch

If the **ciphertext may also have been reachable** — the host was compromised,
or an archive left your control — this is a personal-data breach under
Art. 4(12) DSGVO, and two clocks start:

- **Art. 33** — notify the supervisory authority **within 72 hours** of becoming
  aware, unless the breach is unlikely to result in a risk. The 72 hours run
  from awareness, not from resolution: an incomplete report on time beats a
  complete one late.
- **Art. 34** — notify the affected members without undue delay, *if* the risk
  to them is high.

The club's obligations are catalogued in
[`legal-requirements-and-how-we-meet-them.md`](./legal-requirements-and-how-we-meet-them.md);
the annual data-protection review in [`procedures.md`](./procedures.md#annually-data-protection-review)
is where the Verzeichnis that describes this processing is kept current.

**When it cannot be told apart, assume it was.** A backup archive is the whole
member database; the cost of over-reporting is a form, and the cost of
under-reporting is the club's liability.

Encryption is a mitigating factor under Art. 34(3)(a) — if the private key was
*not* also compromised, the data was unintelligible and notifying data subjects
may not be required. That argument only holds if you can say which of the two
happened, which is another reason the key register in section 3 is worth
keeping.

---

## Practising this

Section 1 and section 2 are both walked once a year as part of the **backup
restore drill** in [`procedures.md`](./procedures.md#annually-the-backup-restore-drill).
Walking section 1 alone is not enough: the single-table path is the one you are
statistically far more likely to need, and it is the one with a wrong-looking-
right failure mode.

An untested backup is a belief. So is an unwalked runbook.
