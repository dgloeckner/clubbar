# ADR-0048: Shared Symmetric Crypto Abstraction for TOTP Secrets

**Status**: Accepted

**Date**: 2026-08-14

**Amends**: [ADR-0036](./0036-iban-encryption-sealed-box.md) (closes the residual risk it left open: "`TotpService` still uses its own AES-256-CBC path")

---

## Context

ADR-0036 moved IBANs to libsodium sealed boxes (`IbanSealedBox`) and explicitly deferred one thing: `TotpService` kept its own hand-rolled AES-256-CBC path, encrypting each admin's TOTP secret with a server-resident key (`TOTP_ENCRYPTION_KEY`). #437 asked to either close that gap with a real shared primitive, or document why the two stay separate.

They cannot simply merge into one class. The two secrets have different threat models:

| | TOTP secret | IBAN |
|---|---|---|
| Who must decrypt it | The server itself, synchronously, on every login | Never online — only offline, by the club, with a key the server never holds |
| Key residency | Server-resident is inherent to the design | Server-resident was rejected (see ADR-0036 "Alternatives Considered → Symmetric key in `config.php`") |

Porting `TotpService` onto `IbanSealedBox`'s asymmetric sealed-box construction is not an option: the server would no longer be able to verify a TOTP code at all. What ADR-0036 actually flagged as unfinished was narrower — `TotpService` and `IbanSealedBox` had independently reimplemented the same *category* of boilerplate: a 32-byte/64-hex-character key format, the same "reject the published dev key outside dev/test environments" safety check, and the same key-decode error handling. That duplication was real (nearly identical code, retested independently in both suites), and `TotpService`'s AES-256-CBC additionally had no authentication — a tampered ciphertext wasn't detected, only guaranteed not to recover the original plaintext.

## Decision

**Both classes now share one audited primitive family under `App\Shared\Security`, without collapsing their different threat models into one construction:**

- **`HexSecretKey`** — the shared, stateless key-handling helper: decodes a 64-hex-character key to 32 raw bytes with a consistent error message, and rejects a key that matches a list of published/fixture values outside development/test environments. Both `IbanSealedBox` (fingerprint key, public key) and the new `SymmetricSecretBox` (TOTP key) call into it instead of maintaining their own copies.
- **`SymmetricSecretBox`** — a new primitive for the "server holds the key and decrypts synchronously" case. Uses libsodium's `crypto_secretbox` (XSalsa20-Poly1305, authenticated) instead of raw AES-256-CBC. Format: `v1:base64(nonce . ciphertext)`, mirroring `IbanSealedBox`'s own `v1:` versioned-prefix convention. `TotpService::encrypt()`/`decrypt()` now delegate to it; its public contract (`string` in, `string|false` out) is unchanged, so no caller (`AuthController`, `StepUpAuthService`) needed to change.
- **`IbanSealedBox`** keeps its sealed-box construction and asymmetric key model exactly as ADR-0036 specified — it now just gets its key decoding and published-key rejection from `HexSecretKey` instead of a private copy.

`TOTP_ENCRYPTION_KEY` keeps its existing 64-hex-character format and config.php location — only the algorithm behind it changes, from AES-256-CBC to secretbox. This is not a downgrade of the model ADR-0036 already accepted for TOTP (a server-resident symmetric key protecting a secret the server must decrypt itself); it is the same model with an authenticated cipher and less duplicated code.

### Ciphertext format cutover

The new `v1:` secretbox format is not readable by the old AES-256-CBC decrypt path, and vice versa. Rather than carry a dual-read branch indefinitely, this is a **hard cutover**: no production database exists yet for this project, so migration `023_totp_secretbox_reset.sql` resets every existing `totp_secret` to `NULL` (`totp_enabled = 0`, `totp_last_timestep = NULL`) instead of attempting to reinterpret old ciphertext. This has the same effect as the existing `AdminUsersRepository::clearTotp()`, and the mandatory-2FA login flow (ADR-0026) already turns `totp_enabled = 0` into a forced re-enrollment on the affected admin's next login — no application-code change was needed to make the reset land safely. Seed/fixture data (`db/seed.sql`, `db/reset_test_data.sql`, `EncryptionKeysHttpTest`) was regenerated under the new format rather than reset, since those are fixtures rather than real installs.

A future deployment that upgrades past this ADR with real admin accounts already enrolled would need a different plan (e.g. a maintenance-window notice before the reset) — this ADR only covers the situation as it stands today, pre-launch.

## Consequences

### Positive

- ✅ Closes the ADR-0036 residual risk: one audited key-handling implementation instead of two, each independently tested for the same edge cases.
- ✅ TOTP secrets gain authenticated encryption (AEAD) for free — a tampered ciphertext is now rejected outright rather than merely failing to reproduce the original plaintext.
- ✅ No behavior change to enrollment/verification/login flows or their tests; `TotpService`'s public API is unchanged.
- ✅ `SymmetricSecretBox` is available as a ready-made primitive for any future secret with the same "server must decrypt it itself" shape, without re-deriving the key-validation boilerplate.

### Negative

- ❌ Every existing TOTP secret is invalidated by the migration; affected admins must re-enroll on next login. Acceptable only because no production database exists yet — a later cutover of this kind would need explicit operator communication.
- ❌ `HexSecretKey::rejectIfPublished()` takes an exception class as a parameter to preserve `IbanSealedBox::rejectPublishedPublicKey()`'s existing `InvalidArgumentException` (vs. `RuntimeException` for the two key-decode paths) — a small wrinkle to keep the shared helper from erasing an existing, deliberate distinction between "misconfigured deployment" and "bad input to a call".

## Related Decisions

- [ADR-0036](./0036-iban-encryption-sealed-box.md) — the sealed-box design this ADR intentionally does not extend to TOTP secrets, and the residual risk this ADR closes
- [ADR-0026](./0026-mandatory-totp-two-factor-authentication.md) — mandatory 2FA login flow, whose existing `totp_enabled = 0` re-enrollment path absorbs the migration's reset with no code change
- #437 — the issue this ADR resolves
- #107 — prior work establishing the "reject published/fixture keys outside dev environments" pattern this ADR now shares between both classes
