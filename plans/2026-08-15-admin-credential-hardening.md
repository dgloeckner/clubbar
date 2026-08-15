# Admin Credential Self-Service Hardening

**Status**: Implemented (M1–M5 done)
**Date**: 2026-08-15
**Relates to**: [ADR-0015](../adr/0015-authentication-and-authorization-strategy.md), [ADR-0026](../adr/0026-mandatory-totp-two-factor-authentication.md), [ADR-0036](../adr/0036-iban-encryption-sealed-box.md), [ADR-0038](../adr/0038-transactional-mail-outbox-on-shared-hosting.md)
**Use cases**: [UC-A03](../use-cases/admin/UC-A03-change-password.md), [UC-A04](../use-cases/admin/UC-A04-change-email.md), [UC-A63](../use-cases/admin/UC-A63-reset-admin-password.md)

## Context

The step-up credential introduced for [#337](https://github.com/dgloeckner/clubbar/issues/337)
gated the cross-account actions — resetting a peer's 2FA or password — and then
stopped at the account boundary. The asymmetry ran the wrong way:

| Action | Gate before |
|---|---|
| `PATCH /api/auth/profile`, changing **email** — the login identifier | nothing but a live session |
| `PATCH /api/auth/change-password` | `current_password` only |
| Reset a *peer's* 2FA or password | full step-up |

A session-holder could repoint an account's login address silently — a quieter
takeover than the peer resets already guarded — and rotating the password asked
only for the credential a stolen session already implies.

Three further findings shaped the work:

1. **The change-password form could never have worked.** The frontend called
   `POST` with `confirm_password`; the route is `PATCH` and validates
   `new_password_confirmation`. It survived because every password test asserted
   a client-side rejection, all of which return before any HTTP call.
2. **ADR-0026's session-invalidation rationale did not hold.** It cited the cost
   of "server-side session enumeration"; the `sessions` table it assumed is never
   written to, and `SessionRepository` is injected nowhere.
3. **Sole-admin lockout has no in-application recovery**, and no runbook.

## Milestones

### M1 — Fix the broken change-password path `[x]`

- `[x]` `api/admin.yaml`: operation `post` → `patch`, `confirm_password` →
  `new_password_confirmation`, and the 200 body corrected from `{success}` to
  `{message}`. Backend was the source of truth; it was not changed
- `[x]` orval client regenerated; `ProfilePage.tsx` sends the renamed field
- `[x]` E2E test that completes a password change **through the UI** and then
  authenticates with the new password

### M2 — Step-up on self-service credential changes `[x]`

- `[x]` `AuthController::changePassword` verifies via `StepUpAuthService`
  (replacing the bare `verifyCurrentPassword`)
- `[x]` `AuthController::updateProfile` verifies **only when the email actually
  moves**, compared case-insensitively. Load-bearing: the language switcher
  PATCHes this endpoint, so an unconditional gate would demand a password to
  change locale
- `[x]` A null `admin_user` attribute refuses rather than reaching the verifier
- `[x]` Both routes carry `$stepUpRateLimit`
- `[x]` Frontend: a TOTP field on the password form (which already collects the
  password); `StepUpConfirmDialog` for the email change
- `[x]` `api/admin.yaml` request schemas + descriptions
- `[x]` A rejected credential no longer signs the admin out — the axios
  interceptor treated every 401 as a dead session, including
  `invalid_credentials`, which destroyed the dialog kept open for the retry

### M3 — Credentials epoch `[x]`

- `[x]` Migration `029`: `admin_users.credentials_changed_at`, nullable so
  deploying it signs nobody out
- `[x]` Stamped on: self password change, email change, peer password reset,
  peer 2FA reset
- `[x]` `AdminSessionAuth` refuses a session at or before the epoch with
  `401 credentials_changed`. A tie counts as stale, closing the one-second
  window a strict comparison would leave
- `[x]` The acting session survives by stamping itself one second past its own
  write (`SessionTimeout::beginAfterCredentialChange`) and rotating its ID

### M4 — Audit actions and old-address notification `[x]`

- `[x]` Migrations `030`/`031`: `password_changed` + `email_changed` audit
  actions, `admin_email_changed` mail kind
- `[x]` `MailSubject::ADMIN_USER`, `AdminSecurityMailBuilder`,
  `AdminEmailChangedMail`, de/en strings, registry wiring
- `[x]` `NotificationsService::notifyFormerAddress` — one row to the address the
  account moved *away* from, read from the outbox `recipient` snapshot because
  `admin_users` already holds the new address by drain time
- `[x]` Best effort: a failure is caught and audited, never failing the change

### M5 — Documentation `[x]`

- `[x]` ADR-0026 amendment: the enumeration argument was wrong; what the epoch
  applies to; the sole-admin hole stated plainly
- `[x]` ADR-0015: self-service credential changes section + session table row
- `[x]` UC-A04 (new), UC-A03 and UC-A63 updated, both indexes
- `[x]` [Admin Lockout Runbook](../docs/runbook-admin-lockout.md), linked from the README
- `[x]` `docs/erm-master.md`: the new column and audit actions

## Verification

```bash
scripts/dev-setup.sh --with-frontend

# Backend unit tests run in the container (host PHP lacks bcmath)
docker compose exec -w /app backend ./vendor/bin/phpunit tests/Unit/Modules/Auth \
    tests/Unit/Modules/AdminUsers tests/Unit/Modules/Notifications

cd e2etests
npx playwright test tests/api/self-service-credentials.spec.ts --project=api-tests --workers=4
npx playwright test tests/admin/profile-credentials.spec.ts --project=admin-chromium --workers=4
```

**Results:** 272/272 targeted backend unit tests green; 14/14 API credential
tests; 5/5 UI credential tests.

## Known issues, not introduced here

- `ServiceFactoryTest::test_getRateLimitMiddleware_is_active_by_default` fails in
  the dev container, which sets `DISABLE_LOGIN_RATE_LIMITING`. Verified against
  the base commit — it fails there too
- `CronScriptTest` / `DrainServiceTest` spawn processes and destabilise the
  backend container when the full PHPUnit suite runs locally; unrelated to this
  change

## Deliberately out of scope

Recovery codes (ADR-0026 defers them; the runbook was chosen instead), WebAuthn,
a forgot-password flow, roles and permissions (rejected in ADR-0015 and
ADR-0036), and populating the vestigial `sessions` table.

An **email-verification link** was considered and rejected as the gate for an
email change: the outbox is drained by a scheduler rather than sent inline, and
an installation with no `mail.dsn` discards mail at the transport — so a
link-based hard gate would make the address unchangeable on exactly the
installations least able to diagnose why.
