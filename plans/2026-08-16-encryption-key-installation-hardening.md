# Encryption Key Installation Hardening

**Status**: Implemented (M1–M2 done; 786 backend tests green in the Security/Notifications/Mail scope)

**ADR**: [ADR-0036](../adr/0036-iban-encryption-sealed-box.md) — amendment pending confirmation

---

## Why

Two gaps in the key-installation path, both following from the fact that the
backend can seal an IBAN but never open one.

**`register()` accepted any 32 well-formed bytes.** It checked base64 length, the
committed-dev-key blocklist and fingerprint uniqueness, and nothing else. Bytes out
of a mistyped clipboard activated exactly like a generated key, and every IBAN
sealed afterwards was permanently unreadable — with nothing saying so until the
next SEPA export refused the treasurer's archived key, a settlement cycle later.

**A key swap raised no signal that left the panel.** `CredentialExpiryNotifier`
covers the 90/30/7 tiers only, so a key activated today with 365 days on it is
silent by construction. The dashboard alert fires only for missing/expiring/expired
and names the key by `key_identifier` — free text chosen by whoever registered it.
An admin who did not perform the action learned nothing.

### What each milestone buys — stated precisely

The attack that prompted this work (register a key you control, activate it, rewrite
every IBAN with a checksum-valid fake) is **not** stopped by M1: an attacker
generates their own keypair and proves possession trivially. M1 closes the *typo*
and *key-nobody-holds* cases, which are likelier and were undetectable. **M2 is what
addresses the attack** — mail is the one channel somebody holding an admin session
cannot suppress.

What would *prevent* it is a second admin approving activation. That contradicts the
flat model of [ADR-0015](../adr/0015-authentication-and-authorization-strategy.md),
reaffirmed in ADR-0036 §Deviations, so it is a follow-up needing its own ADR
discussion rather than something smuggled in here.

Detection surfaces that already worked and needed no change: the export fails closed
with `422 private_key_mismatch`, and pre-notification mail carries a freshly minted
`mandate_reference` to every member — unsuppressible, because an IBAN change ends the
old mandate and the `UNIQUE` reference cannot be reused.

---

## Milestones

### M1 — Proof of possession at activation ([commit 74abc6b](../../commit/74abc6b))

- [x] `EncryptionKeyService::activate()` takes the private half of the key being
      activated and validates it through the existing `validatePrivateKeyFor()` —
      a public-key derivation, never a trial decryption — then `sodium_memzero`s it
      immediately. A key whose counterpart nobody holds can be registered but never
      activated, so it can never seal anything
- [x] `PrivateKeyMismatchException` (extends `\InvalidArgumentException`, so the SEPA
      export and `rotate-batch` keep working untouched) so activation can tell "no
      such key" (404) from "wrong sheet out of the safe" (422). Both were the same
      type before, and collapsing them would tell an admin their key had vanished
- [x] `api/admin.yaml`: activate gains a request body and the 422; orval regenerated
      (`ActivateEncryptionKeyBody`)
- [x] `CredentialsTab` activation reuses `PrivateKeyInput` inside the existing
      `StepUpConfirmDialog`; key material is cleared from component state on cancel
      as well as on confirm
- [x] `registerHint` corrected — it claimed no copy of the private key ever reaches
      the server, which activation now contradicts
- [x] Tests: foreign key refused and the key left pending; malformed key refused;
      unknown id is not a mismatch; 404 vs 422 asserted as distinct over HTTP
- [x] `key-rotation.spec.ts` sends `ROTATION_PRIVATE_KEY`; `seed.sql` is unaffected
      because it inserts ACTIVE rows directly, bypassing `activate()`

**Deliberately not a challenge/response.** An earlier draft had the server seal a
nonce so the private key would never arrive at all. Dropped: `KeyRotationDialog`
already posts the old private key on every rotation batch, so key management is not
private-key-free today; and offline verification would mean vendoring a BLAKE2b
implementation beside TweetNaCl (which has `nacl.box` but not the hash
`crypto_box_seal` derives its nonce from) plus a crypto dependency in e2etests. One
exposure per key activation, against a monthly one at export through the same
wipe-in-`finally` code, did not justify that.

### M2 — Key lifecycle leaves by mail ([commit a7cdebd](../../commit/a7cdebd))

- [x] `MailKind::ENCRYPTION_KEY_REGISTERED` / `_ACTIVATED` / `_REVOKED`, subject
      `MailSubject::ENCRYPTION_KEY`, `addressesMember() === false`. Three kinds
      rather than one with a state field: the subject line is what gets read
- [x] Migration `041_mail_outbox_encryption_key_events.sql` restating the whole
      `kind` ENUM (MariaDB has no `ADD VALUE`), plus its rollback, which deletes the
      new rows *before* the `MODIFY` so none is truncated to the empty string
- [x] `EncryptionKeyEventMailBuilder` — a builder of its own, because
      `CredentialExpiryMailBuilder::supports()` claims work by naming two kinds, not
      by subject type; registered in `ServiceFactory`
- [x] Fan-out through the existing `NotificationsService::warnAdmins()`: one message
      per admin with an address, deduped by the unique index. Occasion is the event
      name — a key is registered once, activated once and revoked once, so kind plus
      key id already identify the occurrence
- [x] Content: identifier, **full** SHA-256 fingerprint, timestamp. No key material,
      no ciphertext, no IBAN, no action link. The fingerprint is the only value that
      distinguishes the club's key from someone else's, since the identifier cannot
- [x] Best effort and never a gate — a failure is logged rather than rolling back a
      key operation that already succeeded (ADR-0038 rule 3)
- [x] Tests (`EncryptionKeyEventNoticeTest`, real `NotificationsService`): register
      queues; activate queues a second, distinct kind; **a refused activation
      announces nothing**; one message per addressed admin; the rendered message
      carries the full fingerprint and neither half of the keypair

**The message does not name who acted.** `warnAdmins()` fans one copy out per admin,
so the outbox row's `admin_user_id` is the *reader*, and nothing persists the actor
beside a queued message. A name there would be the reader's own or a guess; the actor
is in the `key_registered` / `key_activated` / `key_revoked` audit entry, and this
message's job is to send someone to look.

---

## Verification

Backend tests run inside the container — host PHP has no bcmath and cannot resolve
the `database` host:

```bash
docker compose exec -w /app backend ./vendor/bin/phpunit --filter 'Security|Notifications|Mail'
```

786 pass. The one failure in that scope, `ServiceFactoryTest::test_getRateLimitMiddleware_is_active_by_default`,
is **pre-existing** — verified by running it against a stashed tree.

```bash
cd e2etests && npx playwright test --project=api-rotation   # key-rotation.spec.ts
```

**Sandbox note**: this environment's overlay mount intermittently stops serving new
`docker exec` processes — the container keeps answering HTTP and health checks stay
green while every exec dies with `no such file or directory` for `/usr/local/bin/php`.
It surfaces as spurious `CronScriptTest` failures ("could not start bin/cron.php")
and coverage-script errors in a full-suite run; those classes pass in isolation.
Recreating the container (`docker compose down && scripts/dev-stack.sh up && wait`)
is the fix, and the database survives because it lives in a named volume.

---

## Follow-ups (not in scope)

- **Second-admin approval on activation** — the only change that would *prevent* the
  posed attack rather than surface it. Needs its own ADR: it contradicts ADR-0015's
  flat admin model, which ADR-0036 §Deviations reaffirmed deliberately.
- **The documented key-archive check does not exist.** `docs/deployment.md:334-337`
  tells admins to verify their archive periodically using the full-IBAN view, which
  was deferred and never built (`IBAN_FULL_VIEW` exists only as an enum case, plan
  `2026-08-13-iban-encryption-sealed-box.md` P4). The one routine that would catch a
  silent key swap early is documented and absent.
- **ADR-0036 amendment** recording the possession proof and the mail channel —
  pending user confirmation, per the project rule that ADRs are not modified without it.
