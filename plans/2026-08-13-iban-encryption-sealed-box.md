# IBAN Encryption at Rest (Sealed Boxes), Credential Lifecycle, Mandate-Scan Non-Retention

**Epic**: [#388](https://github.com/dgloeckner/clubbar/issues/388) · **ADRs**: [0035](../adr/0035-iban-encryption-sealed-box.md), [0036](../adr/0036-mandate-documents-not-retained.md) · **Branch**: `claude/iban-storage-security-dlg0ts`

Status legend: `[ ]` not started · `[~]` in progress · `[x]` passed (test verified) · `[!]` failed (reason documented)

## Why

IBANs sit in plaintext in `mandates.iban` while the DB password sits in `config.php` on the shared host — a DB-only compromise (SQLi, stolen dump/backup, co-tenant) exposes every member's bank account. Agreed architecture (security handoff 2026-08-12, verified on IONOS PHP 8.4.24): libsodium sealed boxes — the server holds only the public key and can encrypt but never decrypt; the private key stays offline with the club and is supplied temporarily (fresh TOTP step-up) only for SEPA export, exceptional full-IBAN view, and rotation. See ADR-0035 for the full threat model and deviations.

Key implementation constraint discovered up front: `MembersRepository::applyMandateChange()` compares submitted vs. stored IBAN to tell bank changes from corrections — with undecryptable ciphertext this needs the keyed fingerprint, and a regression test that an unchanged-IBAN save opens no new mandate.

## Milestones

### P0 — ADRs + plan scaffolding ([#389](https://github.com/dgloeckner/clubbar/issues/389))

- [x] ADR-0035, ADR-0036 written; ADR-0005 and ADR-0029 amended in place; `adr/README.md` index updated (incl. missing 0034 row)
- [x] This plan file + `plans/INDEX.md` row
- Verify: docs review only; no code

### P1 — Crypto foundation + key management ([#390](https://github.com/dgloeckner/clubbar/issues/390))

- [x] `Shared/Security/IbanSealedBox` (seal/open/fingerprint, `v1:` format, fail-closed without sodium, dev-key blocklist)
- [x] Migration `015_encryption_keys.sql`; `EncryptionKeyRepository`/`EncryptionKeyService` (PENDING→ACTIVE, one-ACTIVE invariant, revoke/compromise), `PrivateKeyValidator`, `CredentialLifecycleService` (90/30/7 tiers)
- [x] Step-up endpoints POST/GET `admin/encryption-keys`, activate/revoke; new AuditActions; `maskSensitiveFields` covers key material
- [x] `IBAN_FINGERPRINT_KEY` in install.php/upgrade.php/index.php/docker-compose; `tools/keypair-generator.html`
- Verify: PHPUnit in container — roundtrip, tamper, wrong key, fingerprint determinism, state machine, expiry math, published-key refusal

### P2 — Encrypt-on-write + existing data ([#391](https://github.com/dgloeckner/clubbar/issues/391))

- [x] Migration `016_mandates_encrypted_iban.sql` (ciphertext/last4/fingerprint/key-id/bank_name columns; legacy `iban` nullable)
- [x] `MembersRepository`: openMandate seals; applyMandateChange compares fingerprints (**regression test: unchanged IBAN ⇒ no new mandate**); reads expose last4/bank_name
- [x] Bank name resolved at write time; no-ACTIVE-key blocks IBAN writes with clear message
- [x] Step-up admin batch action "encrypt existing IBANs" (100/batch, idempotent, optimistic update, nulls legacy plaintext per row); SecuritySelfCheck finding for plaintext remnants
- Verify: repository feature tests incl. raw-SQL ciphertext assertions; batch run twice; full member CRUD e2e green

### P3 — last4 API surfaces + leak closure ([#392](https://github.com/dgloeckner/clubbar/issues/392))

- [ ] `MemberAdminDto` drops `iban`, gains `iban_last4`/`bank_name`; `api/admin.yaml` + orval regenerated
- [ ] Overwrite-only edit form (`****3000`, empty = keep); settlement preview/CSV + GDPR export → last4; sepa-config GET masked with overwrite-only PUT
- Verify: e2e — list payload has no `iban` key; settlement XML asserts fixture constant; sepa-config keep-on-save flow

### P4 — SEPA export with private-key flow ([#393](https://github.com/dgloeckner/clubbar/issues/393))

- [ ] Export endpoint: step-up + `{private_key}` JSON body (no multipart), validator, streaming decrypt closure in `SepaExportService`, `Cache-Control: no-store`, audit `SEPA_EXPORT`; expired key ⇒ 409
- [ ] Frontend export dialog (key input, step-up prompt, `downloadBlob`); optional privileged full-IBAN view (`IBAN_FULL_VIEW`)
- Verify: e2e end-to-end proof (create member → raw SQL ciphertext-only → settle → export with dev key → XML has plaintext); wrong key 422, expired 409, no step-up 401/403

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

## Release coupling & rollback

- P1+P2 ship together (schema + write path). P3–P7 are individually shippable.
- After the P2 batch encryption, code rollback to a pre-encryption release is unsafe — restore the pre-upgrade DB backup instead.
- P7's file deletion is destructive and announced in upgrade notes; paper originals remain with the treasurer.
