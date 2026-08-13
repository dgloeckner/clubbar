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

- [ ] `KeyRotationService` (handoff §15 sequence, 100-row batches, optimistic `WHERE encryption_key_id = :old`, browser holds old key per batch, audit per step)
- [ ] Security & Credentials page + dashboard warning banner (request-time tiers); compromise flow
- Verify: resumable-rotation test, concurrent-edit survives, one-ACTIVE enforced; e2e rotate → export only with new key

### P6 — Terminal-token lifecycle ([#395](https://github.com/dgloeckner/clubbar/issues/395))

- [ ] TTL default 90→365 d; PENDING overlap rotation promoted on first successful auth; step-up on token generation; credentials page integration; `TERMINAL_TOKEN_*` audit events; terminal expired-token UX check
- Verify: unit tests for promotion/expiry; terminal sync e2e green

### P7 — Mandate scans: extraction without retention ([#396](https://github.com/dgloeckner/clubbar/issues/396))

- [ ] Stateless extract endpoint accepts PDF; storage routes/controller/service/repository/DTO removed; `MigrationRunner` learns `*.php`; migration `017` drops `mandate_documents` + `mandates.document_id` and deletes `storage/mandates/*` (announced destructive step + self-check finding)
- [ ] `MandateDocumentSection` extraction-only; OAS + orval; affected specs adapted
- Verify: extraction round-trip fills form; adapted e2e suites green

### P8 — Docs + closure ([#397](https://github.com/dgloeckner/clubbar/issues/397))

- [ ] `docs/erm-master.md`, `docs/deployment.md` (ciphertext dumps, key archive = part of backup story, upgrade order, recovery/compromise procedures)
- [ ] Follow-up issues (plaintext column drop, TotpService on shared crypto, threshold emails); INDEX.md final
- Verify: full PHPUnit + full Playwright suite green

## Merge with main (2026-08-13)

main shipped ADR-0035 (terminal-backend instance pairing, [#411](https://github.com/dgloeckner/clubbar/issues/411)) and migrations `015_instance_id` / `016_terminal_repair_audit_action` while this branch was open, colliding with both of this plan's numbers:

- ADR-0035 (sealed boxes) → **0036**, ADR-0036 (mandate scans) → **0037**
- `015_encryption_keys` → **017**, `016_mandates_encrypted_iban` → **018**, `017_audit_log_key_actions` → **019** (relative order preserved)
- `019_audit_log_key_actions` restates the whole `audit_log.action` ENUM and now runs *after* main's `016`, so it had to absorb `terminal_repair` — otherwise applying it silently un-adds the value and every pairing repair dies on "Data truncated for column 'action'". Git reports no conflict for this; only the test suite does.

## Test isolation (2026-08-13)

Making the export step-up require a TOTP code exposed how many specs log in as the shared seeded admin. `totp_last_timestep` ([#338](https://github.com/dgloeckner/clubbar/issues/338)) hands out one code per 30-second step per account, so those logins cannot run concurrently:

- The specs that log in as that admin are serial within their file.
- `auth-mfa-lockout.spec.ts` moved into a new **`api-ordered`** project that depends on `api-tests`, so it runs alone after the parallel suite. It is ordered rather than skipped — the per-session MFA cap needs no flag change and passes against a plain dev stack, unlike the two specs in `rate-limit`, which need `DISABLE_*_RATE_LIMITING` removed and therefore stay out of the default run.
- `auth.setup.ts` retries its MFA across time-steps; failing there took every dependent project with it.

## Release coupling & rollback

- P1+P2 ship together (schema + write path). P3–P7 are individually shippable.
- After the P2 batch encryption, code rollback to a pre-encryption release is unsafe — restore the pre-upgrade DB backup instead.
- P7's file deletion is destructive and announced in upgrade notes; paper originals remain with the treasurer.
