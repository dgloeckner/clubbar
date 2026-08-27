# Encrypted Off-Site Database Backups

**Epic**: [#686](https://github.com/dgloeckner/clubbar/issues/686) ·
**ADR**: [ADR-0049](../adr/0049-encrypted-offsite-backups-on-shared-hosting.md) ·
**Started**: 2026-08-24

## The gap

The installation had **no backups**, while `README.md` and `docs/deployment.md`
described a procedure involving `mysqldump` and `gpg` — neither of which exists
on the reference host ([ADR-0031](../adr/0031-mass-hosting-deployment-target.md):
shared hosting, no shell, no client binaries). So the documented backup story was
not merely absent, it was *unrunnable*, which is worse: a club reading it had
every reason to believe they were covered.

Six requirements, none optional:

| # | Requirement | Why |
|---|---|---|
| 1 | Backups happen **automatically** | There were none. This is the actual gap |
| 2 | **Encrypted**, key not on the server | `config.php` plus an unencrypted backup is the whole member database, from one file |
| 3 | **Off the host** | A suspended or deleted account takes the webspace with it |
| 4 | Someone has **actually restored one** | An untested backup is a belief |
| 5 | **Bounded retention** | A backup outliving an [ADR-0029](../adr/0029-two-tier-retention-and-erasure.md) erasure defeats it |
| 6 | Someone **notices when it stops** | The silent-stall failure ADR-0038 already fears for mail |

## Branching

Every slice is cut from and merged into the long-lived integration branch
`feat/686-encrypted-offsite-backups`. **Tier 1 reaches `main` as a single PR from
that branch once M6 has proved a restore** — nothing ships before then, because
without a proved restore the rest is a file-writing feature.

"Proved a restore" means **proved in CI**, which M6 does. It cannot mean the
manual runbook walk: integration is deployed only from `main`
(`deploy-integration` is guarded by `github.ref == 'refs/heads/main'`), so a gate
requiring the walk first would be waiting on a deployment that the merge is what
produces. The walk is the step *after* the merge.

---

## Milestones

Legend: `[ ]` not started · `[~]` in progress · `[x]` passed (test verified) ·
`[!]` failed, with reason

### M1 — ADR-0049, and correcting the false claims ([#687](https://github.com/dgloeckner/clubbar/issues/687))

- [x] ADR-0049 recorded, indexed in `adr/README.md`
- [x] The unrunnable `mysqldump`/`gpg` procedure and the false `README.md` claims corrected

**Verified**: `BackupDocumentationTest` (19 tests) — the guards are documentation
tests, so the claims cannot silently come back.

### M2 — PHP-side database dumper ([#688](https://github.com/dgloeckner/clubbar/issues/688), [#699](https://github.com/dgloeckner/clubbar/issues/699))

- [x] `DatabaseDump` walks `information_schema`, streams rows through PDO to a callback
- [x] Consistent snapshot (`START TRANSACTION WITH CONSISTENT SNAPSHOT`), unbuffered streaming
- [x] Binary columns found from `information_schema`, emitted as hex literals
- [x] Base tables only — a view dumped as a table breaks the restore
- [x] Both sessions pinned to UTC; the archive pins `SQL_MODE` and `time_zone`
- [x] Per-table markers and named columns, so one section is restorable alone

**Verified**: `DatabaseDumpTest`, `SqlValueEmitterTest` against a real MariaDB.

### M2b — the archive is the record ([#703](https://github.com/dgloeckner/clubbar/issues/703))

- [x] `backup_runs` / `backup_keys` / `backup_config` removed; no backup state in the database
- [x] Every base table dumped in full — no classification, no skip list, no schema-only class
- [x] `bank_codes` repopulation step deleted along with the class that needed it

**Verified**: `BackupDocumentationTest` asserts no migration creates a backup
table and the ERM documents none.

### M3 — sealed container and offline decryptor ([#689](https://github.com/dgloeckner/clubbar/issues/689))

- [x] `BackupSealedBox`: multi-recipient sealed keys, XChaCha20-Poly1305 secretstream
- [x] Header readable with **no key** — instance, schema version, manifest, plaintext checksum
- [x] `tools/backup-decryptor.html` opens an archive entirely client-side
- [x] Golden fixture read by **both** implementations, neither its own witness

**Verified**: `BackupSealedBoxTest`, `BackupSealedBoxGoldenFixtureTest` (PHP) and
`backup-decryptor-interop.test.mjs` (JS).

### M4 — config, cron entrypoint, local retention ([#690](https://github.com/dgloeckner/clubbar/issues/690))

- [x] `bin/backup.php` and `/api/cron/backup`; configuring a recipient key *is* the on-switch
- [x] Atomic write (`.part` → `fsync` → `rename`), 0700 directory, flock against overlap
- [x] Local retention by count and by bytes; the journal beside the archives
- [x] The run never throws — every caller is a scheduler nobody is watching

**Verified**: `BackupServiceTest`, `BackupCronHttpTest`, `BackupJournalTest`,
`BackupRetentionTest`.

### M5 — Microsoft Graph upload transport ([#691](https://github.com/dgloeckner/clubbar/issues/691))

- [x] Outbound HTTP seam that returns status and body, with a fake for tests
- [x] `BackupTransport` behind `backup.dsn`; absent DSN = local-only, logged, never throwing
- [x] OAuth2 client credentials, resumable upload sessions, progress in a sidecar beside the archive
- [x] Sealed body compressed, with the decryptor's inflate side in the same slice
- [x] Remote retention enforced by **listing** the store
- [x] Client-secret expiry rides `CredentialExpiryNotifier` (migration `055`)
- [x] **Verify against a real tenant** — done 2026-08-26, through the
      application's own transport

The task was two questions and one exercise. All three are now answered, and
one of the answers is "cannot be observed from here", which is recorded as such.

**Observed already**, from the owner's live-tenant provisioning run and recorded
in [`docs/m365-backup-target.md`](../docs/m365-backup-target.md):

- **`Sites.Selected` `write` does permit delete.** `scripts/setup-msgraph-backup.ps1`
  ends with a real upload-then-delete probe, so this is measured rather than
  inferred — and it is the premise of the gap the epic names, which makes it the
  one worth having measured.
- **A delete is recoverable, not impossible.** Deleted items land in the site
  recycle bin for **93 days** and keep consuming quota there; Graph v1.0 has no
  permanent delete for `driveItems`, and second-stage emptying needs SharePoint
  PowerShell. So remote retention reduces what the library *shows* long before it
  reduces what the library *costs* — which is why §5 caps version history and §4.1
  sets a storage limit.

**Observed 2026-08-26**, driving `MsGraphTransport` itself against the live
library — the half a fake cannot stand in for, since a fake agrees with whatever
the code believes. Full table in §6.1 of
[`docs/m365-backup-target.md`](../docs/m365-backup-target.md); the three results
that carry weight:

- **Resume is exact.** A 1-second budget stopped the run after precisely one
  `CHUNK_BYTES` fragment (3,276,800 bytes); the next run sent 9,312,743; the two
  sum to 12,589,543, which is exactly the archive. No range re-sent, none
  skipped — the sidecar-versus-`nextExpectedRanges` reconciliation is right.
- **The remote size equals the local size**, which is the observable form of the
  rule that the final fragment must not be padded. A padded upload would list as
  larger, and that corruption is otherwise found only by attempting a restore.
- **`Sites.Selected` `write` permits delete**, now via the application's own
  path as well as the setup script's probe.

**Recorded as unverified rather than assumed**: whether that delete is
recoverable. Graph v1.0 exposes no read of the site recycle bin for
`driveItems`, so the 93-day retention cannot be confirmed through the API the
application uses; it needs the SharePoint UI or PowerShell. The quarterly
offline copy is the mitigation that does not depend on the answer.

Egress was not the obstacle by the end: `graph.microsoft.com` and
`login.microsoftonline.com` both answer 200. Note that **egress policy binds at
session start**, so a session older than the allowlist edit still gets a 403 on
that host and the remedy is a new session, never a retry.

PR [#706](https://github.com/dgloeckner/clubbar/pull/706) carries the rest.

### M6 — prove the restore ([#692](https://github.com/dgloeckner/clubbar/issues/692)) ← **current**

- [x] **CI round-trip**: seed → real backup path → decrypt → restore into a second schema → row-for-row equality across every table, including `mandates.iban_ciphertext`
- [x] **Schema equality**: normalised `SHOW CREATE TABLE` per table — row equality alone would pass against a dumper that dropped every secondary index and foreign key
- [x] **Header claims asserted**: manifest counts and `plaintext_sha256` against what the restore actually produced
- [x] **`mariadb-dump` oracle**: both dumps restored into scratch schemas, restored *state* compared — never dump text
- [x] **`config.php` inside the sealed archive**, as inert SQL comments so the payload stays one importable `.sql`
- [x] `docs/runbook-backup-recovery.md` — restore, repair one table, rotation on handover, compromise
- [x] `docs/procedures.md` gains exactly two rhythms — the annual restore drill and the quarterly offline copy
- [x] This plan and `plans/INDEX.md`
- [ ] The runbook walked once by hand against the integration installation —
      **owner action, and it happens _after_ the merge to `main`**, see below

**Verified** (PR [#707](https://github.com/dgloeckner/clubbar/pull/707)):

| Suite | Result |
|---|---|
| `RestoreRoundTripTest` | 4 tests, 103 assertions |
| `DumpOracleTest` | 1 test, 6 assertions |
| `ScratchSchemaTest` | 3 tests, 6 assertions |
| `ConfigSnapshotTest` | 4 tests, 18 assertions |
| Backend Unit + Feature | **3264 tests, 11,054 assertions** (after merging #691) |
| `--project=tools` over `file://` | 4/4 |
| JS decryptor interop | 11/11 |

Both new assertions were **mutation-tested rather than assumed**: a probe
stripping plain `KEY` lines from the emitted DDL turns the round trip red naming
the first affected table and the oracle red naming all eighteen, and no other
assertion in either file notices. The probe was reverted.

Two duties that had no owner were adopted into the annual drill rather than
becoming rhythms of their own: the audit-log restore test
([ADR-0013](../adr/0013-audit-logging.md)) and the private-key archive test
(`docs/deployment.md`).

**Still owner action, and the ordering here was wrong when first written.**
#692's *Verify* asks for the runbook to be walked against the integration
installation *before* the merge to `main`. That gate cannot be satisfied:
`deploy-integration` in `.github/workflows/build.yaml` is guarded by
`github.ref == 'refs/heads/main'`, so integration only ever runs code that has
already merged. Requiring the walk first makes the merge wait on a deployment
that the merge is what produces.

So the real order is: **merge, deploy, walk, then close #692.** The merge is not
the finish line for this milestone; it is the step that makes the last check
possible.

What still protects `main` is that the *code* path is proved without a human:
the round trip restores into a second schema and compares rows, schema DDL and
the header's own claims; the oracle diffs the whole thing against
`mariadb-dump`; and both assertions were mutation-tested rather than trusted.
The walk tests the **prose** — the host's phpMyAdmin, its upload limit, its
session time zone — and a wrong step there is a documentation fix, not a code
defect. That asymmetry is why merging first costs little and waiting costs
everything.

Walk both sections when the time comes. §1 is the obvious one; §2 (repair one
table) is the commoner disaster and the one with a wrong-looking-right failure
mode — it is exercised in CI, so the manual walk is about the *host's* panel
rather than the archive format.

### M7 — self-check, failure mail, backups page ([#693](https://github.com/dgloeckner/clubbar/issues/693))

- [x] **Measured self-check rows** — `BackupStatusCheck`, four rows read from the
      journal and the archives on disk: whether the job has ever run at all, the
      age of the newest archive, the age of the last off-site copy, and local
      size against the cap. Until this, every `backup` row read `config.php` and
      reported what the club *intended*; a cron never added was indistinguishable
      from one running nightly. Verified: 12 unit tests, container suite 3332/0.
      ([PR #721](https://github.com/dgloeckner/clubbar/pull/721))
- [x] **The panel banner prints both cron lines** — `SchedulerStatusService` gains
      a backup section and the banner carries one notice per missing job. The
      drain keeps its "collections are blocked"; the backup notice does not
      borrow it, because nothing is blocked. Silent when backups are switched
      off, since no recipient key is a legitimate state (ADR-0049 decision 2).
      Admin-only: `GET /api/admin/security-check` is `ADMIN_ONLY`, so the section
      mirrors it and never reaches a Kassenwart.
      ([PR #724](https://github.com/dgloeckner/clubbar/pull/724))
- [x] **Failure mail to the Admin** — requirement 6, *"someone notices when it
      stops"*. `MailKind::BACKUP_HEALTH_WARNING`, `[ADMIN]`, riding the **mail**
      tick rather than the backup cron: a notice sent by the backup job is
      silent when the backup job is what was never added, which is the failure
      the risk table calls most likely. Reads `BackupStatusCheck`'s `fail` rows
      so the mail, the self-check row and the banner cannot disagree; queues
      nothing at all while healthy; at most one a day per admin
      (`dedup_key` = `stale:<date>:<adminUserId>`). Migration 056 widens the
      outbox enum by one value — still no backup table, no backup row, no audit
      vocabulary.
- [ ] A backups page reading the archive headers, locally and on the remote

Until the page ships, "which keys are still needed" is answered by opening each
archive's header in the decryptor. The runbook says so rather than describing a
screen that does not exist.

**No cron expression is shipped anywhere in this milestone.** Triggering is
external — a hosting panel fires both jobs and the application never reads a
cadence — so the recommended `0 3 * * *` lives in the panel's own strings rather
than in a payload field that would read as configuration we honour. The same
argument produced [#723](https://github.com/dgloeckner/clubbar/issues/723)
against the drain's existing `recommended_interval_minutes`.

### M8 — pre-migration backup during upgrade ([#694](https://github.com/dgloeckner/clubbar/issues/694))

- [ ] Take a backup before migrations run during an upgrade

---

## The honest gaps

Recorded here because a plan that only lists what works is a sales document.

1. **There is no add-only app role on Microsoft 365.** `Sites.Selected` restricts
   *which* site; the per-site role is a fixed `read`/`write` enum and `write`
   includes delete. So the app holds a credential that can delete what it wrote.
   The remedies are library **retention** — which makes a delete *recoverable*,
   not impossible, and only where the tenant allows it — and the **quarterly
   offline copy**, which is why that is a duty and not a suggestion. Retention is
   documented as "if your tenant allows it", **never** as an install
   precondition: most clubs have Business Basic and blocking the install would
   help nobody.
2. **Push-only cannot survive a host takeover on its own.** An attacker holding
   the webspace holds the upload credential.
3. **Retention is a window, not a wall.** An attacker who waits it out wins.
   Accepted.
4. **The archive is now the whole installation.** Since M6 it carries
   `config.php`, so one archive plus one private key is everything except member
   IBANs — which stay sealed to the Kassenwart's separate keypair
   ([ADR-0036](../adr/0036-iban-encryption-sealed-box.md)). The runbook states
   the composition risk in `docs/deployment.md`'s own words: the key archive and
   one backup must never be the only two copies in the same building.

## Test commands

```bash
# The restore proof and the oracle need a reachable server and a dump binary.
# The backend container has no database client, so run these from the host:
cd backend
DB_HOST=127.0.0.1 BACKUP_ORACLE_REQUIRED=1 php8.3 vendor/bin/phpunit \
  --filter 'RestoreRoundTrip|DumpOracle|ScratchSchema|ConfigSnapshot'

# Everything else, in the container:
docker compose exec -w /app backend ./vendor/bin/phpunit --filter Backup

# The two implementations of the container format:
cd e2etests && node --test scripts/backup-decryptor-interop.test.mjs
```
