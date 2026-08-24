# UC-A83: Database Backup

**Actor**: Admin (only — see [ADR-0044](../../adr/0044-tiered-admin-roles.md))
**Trigger**: A scheduled job, nightly. No human starts a backup.
**Related**: [ADR-0049](../../adr/0049-encrypted-offsite-backups-on-shared-hosting.md), [#686](https://github.com/dgloeckner/clubbar/issues/686)

---

## Goal

The club can get its data back after a mistake, a failed migration, a lost
hosting account, or a compromised server — without anyone having remembered to
do anything.

## Why this is an Admin use case and not a Kassenwart one

A backup contains the audit log, every admin's encrypted second factor and the
database password. The Kassenwart legitimately sees member banking data, and
holds a copy of the IBAN private key to collect by SEPA — but that is the office,
not a licence to hold everything. Backups belong to whoever holds the server.

The Getränkewart sees no member at all and therefore nothing here.

## Main flow — the club does almost nothing

1. **At install**, the Admin generates two keypairs offline, puts both public
   halves in the configuration, and keeps one private half themselves, giving the
   other to a second board member. Two holders, so one volunteer moving away does
   not take the archives with them — and neither of them is the Kassenwart, for
   the reason this use case opens with.
2. **The Admin schedules the backup job**, alongside the existing mail one. The
   installer prints both lines on the same screen.
3. **Nightly, unattended**: the job dumps the database, seals it so that only the
   configured key holders can open it, keeps a local copy for the "undo an hour
   ago" case, and uploads it to the club's own storage.
4. **Nothing is reported when this works.** Silence means the condition does not
   hold. A recipient who receives "0 problems" fifty times files the fifty-first
   unread.

## Alternate flows

**No encryption key is configured** — no backup is written at all, and the
security check reports it as a failure. A plaintext dump is never written as a
fallback: on this host that would be the easiest possible route to every member's
data.

**The upload fails or is cut short** — the sealed archive still exists locally
and the transfer resumes on the next run. A slow or broken network delays a
backup; it never produces a corrupt one.

**The dump cannot finish inside the host's execution limit** — the run aborts and
leaves no partial file, and the failure is recorded. A half-written archive that
looks complete is the one outcome worse than no archive.

**Backups stop happening** — a scheduled job that was never added, or was dropped
in a hosting change, reads as *"no backup ever"* on the security check rather than
as silence, and the Admin is emailed. This is the whole reason a second scheduled
job is allowed to exist.

**The storage quota would be exceeded** — the oldest archives are pruned first;
if that is not enough the run refuses rather than filling the webspace. A full
disk breaks logging and mandate storage, so a backup must never cause one.

## Restoring — the part that has to work on a host with no shell

1. Download an archive from the admin panel, or from the club's storage.
2. Decrypt it on a trusted machine with the offline browser tool, using one of
   the archived private keys.
3. Import the resulting SQL through whatever database tool the host offers.

No step requires SSH, because the reference host does not offer it.

## Acceptance criteria

- [ ] A backup is produced without human action, on a host with no shell and no crontab.
- [ ] The archive cannot be read by anyone holding only the server — including the operator of the storage it is uploaded to.
- [ ] With no key configured, nothing is written and the security check says so.
- [ ] A dump that cannot complete leaves no file behind.
- [ ] An interrupted upload resumes rather than restarting.
- [ ] Archives are pruned by both age and total size.
- [ ] A backup that has stopped happening is visible without anyone going to look.
- [ ] A restore has been performed end to end, by a person, at least once.
- [ ] Only the Admin can list or download archives; Kassenwart and Getränkewart are refused.

## Recurring duties this creates

| Rhythm | Duty |
|---|---|
| Quarterly | Download one archive to a medium the server cannot write to — the copy no leaked credential can reach |
| Annually | The restore drill: actually import an archive into a scratch database, and confirm the archived keys still open it |
| On a handover | Rotate: add the successor's key, prove it opens a real archive, then remove the departing one. **Delete no archives** — retention retires the old key by itself, and discarding its private half early destroys archives that still exist |

## Out of scope

- Backing up mandate documents — [ADR-0037](../../adr/0037-mandate-documents-not-retained.md) retains none.
- Point-in-time recovery. The granularity is one archive per night, deliberately.
- Restoring a single member or a single table. A restore is a whole-database operation into a scratch schema, from which the operator takes what they need.
