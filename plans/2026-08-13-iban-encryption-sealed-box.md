# IBAN Encryption at Rest (Sealed Boxes), Credential Lifecycle, Mandate-Scan Non-Retention

**Epic**: [#388](https://github.com/dgloeckner/clubbar/issues/388) · **ADRs**: [0035](../adr/0036-iban-encryption-sealed-box.md), [0036](../adr/0037-mandate-documents-not-retained.md) · **Branch**: `claude/iban-storage-security-dlg0ts`

Status legend: `[ ]` not started · `[~]` in progress · `[x]` passed (test verified) · `[!]` failed (reason documented)

## Why

IBANs sit in plaintext in `mandates.iban` while the DB password sits in `config.php` on the shared host — a DB-only compromise (SQLi, stolen dump/backup, co-tenant) exposes every member's bank account. Agreed architecture (security handoff 2026-08-12, verified on IONOS PHP 8.4.24): libsodium sealed boxes — the server holds only the public key and can encrypt but never decrypt; the private key stays offline with the club and is supplied temporarily (fresh TOTP step-up) only for SEPA export, exceptional full-IBAN view, and rotation. See ADR-0036 for the full threat model and deviations.

Key implementation constraint discovered up front: `MembersRepository::applyMandateChange()` compares submitted vs. stored IBAN to tell bank changes from corrections — with undecryptable ciphertext this needs the keyed fingerprint, and a regression test that an unchanged-IBAN save opens no new mandate.

## Milestones

### P0 — ADRs + plan scaffolding ([#389](https://github.com/dgloeckner/clubbar/issues/389))

- [x] ADR-0036, ADR-0037 written; ADR-0005 and ADR-0029 amended in place; `adr/README.md` index updated (incl. missing 0034 row)
- [x] This plan file + `plans/INDEX.md` row
- Verify: docs review only; no code

### P1 — Crypto foundation + key management ([#390](https://github.com/dgloeckner/clubbar/issues/390))

- [x] `Shared/Security/IbanSealedBox` (seal/open/fingerprint, `v1:` format, fail-closed without sodium, dev-key blocklist)
- [x] Migration `017_encryption_keys.sql`; `EncryptionKeyRepository`/`EncryptionKeyService` (PENDING→ACTIVE, one-ACTIVE invariant, revoke/compromise), `PrivateKeyValidator`, `CredentialLifecycleService` (90/30/7 tiers)
- [x] Step-up endpoints POST/GET `admin/encryption-keys`, activate/revoke; new AuditActions; `maskSensitiveFields` covers key material
- [x] `IBAN_FINGERPRINT_KEY` in install.php/upgrade.php/index.php/docker-compose; `tools/keypair-generator.html`
- Verify: PHPUnit in container — roundtrip, tamper, wrong key, fingerprint determinism, state machine, expiry math, published-key refusal

### P2 — Encrypt-on-write + existing data ([#391](https://github.com/dgloeckner/clubbar/issues/391))

- [x] Migration `018_mandates_encrypted_iban.sql` (ciphertext/last4/fingerprint/key-id/bank_name columns; legacy `iban` nullable)
- [x] `MembersRepository`: openMandate seals; applyMandateChange compares fingerprints (**regression test: unchanged IBAN ⇒ no new mandate**); reads expose last4/bank_name
- [x] Bank name resolved at write time; no-ACTIVE-key blocks IBAN writes with clear message
- [x] Step-up admin batch action "encrypt existing IBANs" (100/batch, idempotent, optimistic update, nulls legacy plaintext per row); SecuritySelfCheck finding for plaintext remnants
- Verify: repository feature tests incl. raw-SQL ciphertext assertions; batch run twice; full member CRUD e2e green

### P3 — last4 API surfaces + leak closure ([#392](https://github.com/dgloeckner/clubbar/issues/392))

- [x] `MemberAdminDto` drops `iban`, gains `iban_last4`/`bank_name`; `api/admin.yaml` + orval regenerated (4 schemas, no stray diffs)
- [x] Overwrite-only edit form (`****3000`, empty = keep); settlement preview/CSV + GDPR export → last4; sepa-config GET masked with overwrite-only PUT
- [x] Blank IBAN leaves `BLANK_MEANS_NULL` on update — it now arrives on every save that was about something else, so blank means "keep" and an explicit `null` revokes
- Verify: **passed** — `member-blank-fields.spec.ts` covers keep/revoke and asserts no member response carries a full IBAN; settlement XML and CSV assert the fixture constant; 35 member-controller + 41 SEPA-config PHPUnit green

### P4 — SEPA export with private-key flow ([#393](https://github.com/dgloeckner/clubbar/issues/393))

- [x] Export endpoint is now a POST: step-up + `{private_key}` JSON body (no multipart), validation against the registered public half, per-member decrypt closure in `SepaExportService`, `Cache-Control: no-store`, audit `SEPA_EXPORT` (count + settlement only); expired key ⇒ 409
- [x] `EncryptionKeyService::withActivePrivateKey` owns the key's whole life on the server and wipes it in `finally`
- [x] Frontend export dialog: `StepUpConfirmDialog` gained a slot, `PrivateKeyInput` takes file or paste, `downloadFile` POSTs a body
- [ ] Optional privileged full-IBAN view (`IBAN_FULL_VIEW`) — deferred; the enum case exists, no UI yet
- Verify: **passed** — `sepa-export-encryption.spec.ts` proves the end-to-end path (create → last4-only across every surface → settle → export with the dev key → XML carries the plaintext), plus wrong key 422, malformed key 422, no step-up 401, no session 401, `no-store`, and an audit entry with no account data

### P5 — Key rotation + Security & Credentials page ([#394](https://github.com/dgloeckner/clubbar/issues/394))

- [x] `KeyRotationService` + `SealedIbanRepository`: 100-row batches selected by "still on the old key" (never by offset, so a batch is resumable by construction), optimistic `WHERE encryption_key_id = :old` per row, old private key supplied per request and wiped in `finally`, `KEY_ROTATION_BATCH_COMPLETED` / `KEY_ROTATION_COMPLETED` / `KEY_RETIRED` with `affected_record_count`
- [x] `POST /admin/encryption-keys/{id}/rotate-batch` and `/complete-rotation` (step-up + rate-limit dimension); the listing gained `sealed_record_count` — the backlog is what makes it a status board. Completion re-counts rather than trusting the last batch's `remaining`
- [x] Compromise path: a COMPROMISED or REVOKED key is drained by the same machinery and keeps its status — finishing the clean-up does not resolve the incident
- [x] Settings → Credentials tab: key cards with status, warning tier, days remaining and backlog; register / activate / revoke / mark-compromised, all behind step-up; rotation wizard that loops batches and then retires. Dashboard banner from a new `alerts.encryption_key` (request-time tiers, `missing` loudest)
- [x] `findActive()` is now ordered and limited: the one-ACTIVE rule lives in the service, not the schema, and a re-applied `seed.sql` after a rotation left two ACTIVE rows — the write path sealing under one key while the export validated against the other. `seed.sql` stands the others down first
- Verify: **passed** — 14 `KeyRotationServiceTest` cases (re-seal roundtrip, old key can no longer open, resume across an aborted batch, concurrent member edit survives, refused keys consume nothing, state transitions, compromise path, two-ACTIVE resolution) + 12 `EncryptionKeysHttpTest`; `key-rotation.spec.ts` in `api-ordered` rotates the whole installation and proves the export works with the new key only; `settings-credentials.spec.ts` covers the page. Full API + admin suites green (841 specs)

### P6 — Terminal-token lifecycle ([#395](https://github.com/dgloeckner/clubbar/issues/395))

- [x] `API_TOKEN_TTL_DAYS` default 90 → 365 everywhere it is written down (`AppConfig`, `.env.example`, `docker-compose.yml`, the shared-hosting package's `config.sample.php`/`install.php`/`index.php`, seed). The repository's own fallback and `AppConfig`'s now both read `CredentialLifecycle::LIFETIME_DAYS`, so the figure has one home
- [x] Overlap rotation: migration `021_terminal_pending_token.sql` adds `pending_token_{hash,issued_at,expires_at}` — three columns rather than a `terminal_tokens` table, because a terminal has at most two live credentials and a child table would turn the lookup that authenticates every sync into a join. `TerminalTokenAuthenticator` owns the lookup order (active → pending → expired) and promotes in one `UPDATE` guarded on the hash, so two syncs arriving together cannot both promote. Recorded in ADR-0036 §Terminal tokens (not ADR-0035, which is instance pairing — the issue's reference was to the credential-lifecycle ADR)
- [x] Step-up (own password + own TOTP) on `POST /admin/terminals` and `/rotate-token`, both on the step-up rate-limit dimension; revoke deliberately stays session-only so withdrawing access is never the harder path. Raw token still shown exactly once
- [x] Terminal tokens on Settings → Credentials with the shared 90/30/7 tiers, `last_sync_at` as the last-used stamp, and a "rotation pending" badge. The Terminals tab's own 14-day rule is gone — it now reads the server's `lifecycle_state`, so the two surfaces cannot disagree about the same token
- [x] `TERMINAL_TOKEN_CREATED/ACTIVATED/ROTATED/REVOKED/EXPIRED` (migration `022`). `EXPIRED` is observed rather than performed, so `AuditService::logOnceSince` writes it once per expiry instead of once per poll
- [x] Terminal UI: `NetworkException` carries the backend's error code, `SyncProvider.credentialExpired` follows the last cycle, and an undismissable `CredentialExpiredBanner` plus a blocked checkout button say "sales disabled — an administrator must rotate the credential". Clears itself on the first successful sync after the new token is entered
- Verify: **passed** — PHPUnit 1599 (the one red, `ServiceFactoryTest::test_getRateLimitMiddleware_is_active_by_default`, is the dev compose's `DISABLE_LOGIN_RATE_LIMITING` and predates this work); `terminals.spec.ts` + `terminal-token-expiry.spec.ts` 31/31 including the overlap window, the promotion, and both step-up gates; `settings-terminals.spec.ts` + `settings-credentials.spec.ts` 22/22. Flutter tests were written but **not executed** — no Flutter SDK in this environment; CI runs them

### P7 — Mandate scans: extraction without retention ([#396](https://github.com/dgloeckner/clubbar/issues/396))

- [x] Stateless extract endpoint accepts PDF (`ExtractionController` allowlist; `DirectExtractionService` forwards PDF straight to the LLM, skipping the raster-only EXIF/enhance steps; `ExtractionService`'s Vision pipeline still declines PDF, unchanged); storage routes/controller/service/repository/DTO removed; `MigrationRunner` learns `*.php` (`callable(PDO $pdo, array $context): void`, same checksum bookkeeping, `$context['storageDir']` threaded from `install.php`); migration `023_drop_mandate_documents.php` (issue said `017`, already taken by `017_encryption_keys.sql`) drops `mandate_documents` + `mandates.document_id`/its FK and deletes `storage/mandates/*`, idempotently (announced destructive step in `docs/deployment.md`, both the manual and automated upgrade paths + new `SecuritySelfCheck` `mandate_documents_purged` finding)
- [x] `MandateDocumentSection` extraction-only (pick → extract → fill the parent form via `onExtractionComplete`; no stored state, no download/delete/replace; HEIC conversion unchanged); `api/admin.yaml` storage paths + `MandateDocument` schema + `Member.mandate_document` removed, orval regenerated, orphaned generated files pruned by hand (orval doesn't clean stale outputs); `MembersPage.tsx`'s post-create "attach the scan to the new member" upload step removed (nothing left to attach it to); affected specs adapted: `mandate-document.spec.ts` deleted (its endpoints are gone), `mandate-document-section.spec.ts` rewritten for the extraction-only UI, `mandate-document-extraction.spec.ts` gets PDF-parity + MIME-sniffing tests and loses its upload-based describe block, `anonymize-member.spec.ts` loses its three document-specific tests (the generic audit-scrub property they partly duplicated is already covered by 4.2), `package-smoke.spec.ts`'s CSP test needed no testid changes (it never exercised the upload/extract button, only the picker)
- Verify: **passed** — PHPUnit 1626/1626 on a clean container (the container's `docker compose exec` itself is flaky in this sandbox — proc_open/exec instability unrelated to this diff — a stale run showed 1-18 failures, all in `ServiceFactoryTest`/`RuntimeHardeningFatalTest`/`CheckCoverageScriptTest`/`CheckPatchCoverageScriptTest`, none touching Members/Extraction/SecuritySelfCheck; a fresh container reproduced 1626/1626 or 1625/1626 with only the pre-existing `test_getRateLimitMiddleware_is_active_by_default` flake); migration `023` applied cleanly against the dev DB (`mandate_documents` table and `mandates.document_id` both confirmed gone); `admin-chromium` 55/55 on the members-related suite plus 4/4 on the rewritten `mandate-document-section.spec.ts` and 10/16 on `mandate-document-extraction.spec.ts` (6 skipped — no `LLM_API_KEY` in this environment, including the new PDF-parity tests); `api-tests` 9/9 on `anonymize-member.spec.ts`; `tsc --noEmit` and `eslint` clean on the admin frontend; `npm run build` succeeds

### P8 — Docs + closure ([#397](https://github.com/dgloeckner/clubbar/issues/397))

- [x] `docs/erm-master.md`: new `encryption_keys` entity (mermaid block, column table, indexes, "no plaintext ever" note, audit-without-FK note), `mandates.encryption_key_id` FK row, `encryption_keys ||--o{ mandates : "seals"` relationship (erDiagram + flowchart + narrative table), two new Data Integrity Rules (single-ACTIVE-key invariant, RESTRICT on delete), `Related ADRs` gained 0029/0036/0037, and a stale `mandates` `iban` column reference in the GDPR retention paragraph corrected to `iban_ciphertext`
- [x] `docs/deployment.md`: dumps now piped through `gpg -c` (routine + pre-upgrade backup commands) with a note on why (ciphertext protects the IBAN, not the rest of the row); new "The Private Key Is the Other Half of This Backup Story" section (offline generation, archive-like-the-safe-key, periodic recovery-copy testing against the ACTIVE key's fingerprint, immediate revoke-on-compromise regardless of remaining lifetime); new "Key Rotation" operational runbook (register → activate → batch → complete, with the `retiring`-vs-`revoked/compromised` status fork spelled out since a revoked key never becomes `retired`); a "first deploy of an encryption release" note correcting the plan/issue's assumed "register key → batch-encrypt" step — there is no batch-encryption step for a fresh install, since migration `020` already established nothing had shipped with plaintext IBANs to convert
- [x] Follow-up issues: plaintext-column-drop turned out to be **already done** (migration `020`, predates this plan) — not filed, since there is nothing left to do; filed [#437](https://github.com/dgloeckner/clubbar/issues/437) (TotpService onto a shared crypto abstraction) and [#438](https://github.com/dgloeckner/clubbar/issues/438) (optional email notifications for expiry warning tiers); `plans/INDEX.md` current-plan row updated to P8 done
- Verify: **passed** — this milestone is docs + issue-filing only, no code changed. PHPUnit 1629/1629 on a clean container (only the pre-existing, unrelated `ServiceFactoryTest::test_getRateLimitMiddleware_is_active_by_default` flake — predates this plan). `api-tests` + `api-ordered` + `api-rotation` + `admin-chromium` together: 838/840 in a 4-worker run, 6 skipped (LLM/webkit-gated), 6 did not run (blocked by the two failures below); both failures reproduced as **pre-existing, unrelated to this branch** when re-run in isolation with 1 worker: `csrf-protection.spec.ts` (a session-state race under 4-worker parallelism — passes alone) and `settings-instance-branding.spec.ts` (that spec's last test depends on module state a prior test in the *same file* sets — passes 5/5 when the file runs as a unit, fails when `--grep`-isolated or reordered by parallel scheduling). `admin-mobile`/`terminal-touch` (webkit) and the `PACKAGE_TEST`-gated suite were not run — no WebKit build and no package-build environment in this sandbox, same limitation noted on every prior milestone's verify line

## Merge with main (2026-08-13)

main shipped ADR-0035 (terminal-backend instance pairing, [#411](https://github.com/dgloeckner/clubbar/issues/411)) and migrations `015_instance_id` / `016_terminal_repair_audit_action` while this branch was open, colliding with both of this plan's numbers:

- ADR-0035 (sealed boxes) → **0036**, ADR-0036 (mandate scans) → **0037**
- `015_encryption_keys` → **017**, `016_mandates_encrypted_iban` → **018**, `017_audit_log_key_actions` → **019** (relative order preserved)
- `019_audit_log_key_actions` restates the whole `audit_log.action` ENUM and now runs *after* main's `016`, so it had to absorb `terminal_repair` — otherwise applying it silently un-adds the value and every pairing repair dies on "Data truncated for column 'action'". Git reports no conflict for this; only the test suite does.

## Rotation is installation-wide state (2026-08-13)

`key-rotation.spec.ts` changes which key the *whole installation* seals under,
so from its second step onwards every other spec's member writes would land on
the new key mid-run. It therefore lives in `api-ordered` (alone, after the
parallel suite) and is excluded from `api-tests` — leaving it in that project
once was enough to rotate the database out from under 500 specs.

Two consequences worth knowing before touching it:

- The successor is a **committed** keypair (`dev-key-rotated` in
  `fixtures/encryption.ts`, blocklisted in `IbanSealedBox` like the first). A
  keypair invented inside the test process would vanish with it and leave a
  database nothing could read. The export fixture resolves the private half
  from whichever key the server reports as ACTIVE, so the export specs are
  correct on either side of a rotation.
- A rotation cannot be undone through the API, and a public key registers only
  once, so the spec needs a database that has not been rotated. Every CI shard
  seeds its own; locally that is `docker compose down -v && scripts/dev-setup.sh`.
  It is not gated on a runtime check — ruling #146 bans data-dependent skips —
  so the registration assertion says this instead.

## Test isolation (2026-08-13)

Making the export step-up require a TOTP code exposed how many specs log in as the shared seeded admin. `totp_last_timestep` ([#338](https://github.com/dgloeckner/clubbar/issues/338)) hands out one code per 30-second step per account, so those logins cannot run concurrently:

- The specs that log in as that admin are serial within their file.
- `auth-mfa-lockout.spec.ts` moved into a new **`api-ordered`** project that depends on `api-tests`, so it runs alone after the parallel suite. It is ordered rather than skipped — the per-session MFA cap needs no flag change and passes against a plain dev stack, unlike the two specs in `rate-limit`, which need `DISABLE_*_RATE_LIMITING` removed and therefore stay out of the default run.
- `auth.setup.ts` retries its MFA across time-steps; failing there took every dependent project with it.

## Release coupling & rollback

- P1+P2 ship together (schema + write path). P3–P7 are individually shippable.
- After the P2 batch encryption, code rollback to a pre-encryption release is unsafe — restore the pre-upgrade DB backup instead.
- P7's file deletion is destructive and announced in upgrade notes; paper originals remain with the treasurer.
