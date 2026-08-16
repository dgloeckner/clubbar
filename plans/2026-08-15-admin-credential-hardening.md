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

**Results:** 314/314 targeted backend unit tests green (`Auth`, `AdminUsers`,
`Notifications`); full Unit suite 1564 tests, one known pre-existing failure
(see below). Full Playwright run (`--project=api-tests --project=admin-chromium
--workers=4`): **917 passed, 0 failed, 0 flaky**, including the 14 API and 5 UI
credential specs added here.

### M6 — PHPUnit coverage for the new backend code `[x]`

CI's `patch-coverage` gate (#168 §3) failed at 36.63% against its 80% minimum,
and it was right to: the backend code here is covered thoroughly by Playwright,
and Playwright contributes nothing to the clover report the gate reads. The gate
exists to catch exactly that.

42 tests, aimed at the decisions rather than the line count:

- `[x]` `AdminEmailChangedMailTest` — addressed to the *former* address, and
  never names the new one. That omission is deliberate and had nothing holding
  it in place
- `[x]` `AdminSecurityMailBuilderTest` — the recipient comes from the outbox
  `recipient` snapshot, asserted with a row whose snapshot and current address
  differ, which is the only way that distinction can fail visibly
- `[x]` `notifyFormerAddress` — including that the dedup key stays inside its
  `VARCHAR(64)`. It already overran once, and because the enqueue failure is
  swallowed by design, the notice vanished in silence
- `[x]` The epoch at all three layers: repository writes and returns it,
  middleware refuses a session at or before it, services advance it.
  `changeOwnPassword` and `resetAdminPassword` had no unit tests at all
- `[x]` The step-up branches, and equally that a locale change, a re-submitted
  address and a case-only difference never reach the verifier
- `[x]` `AdminUsersRepositoryTest` builds its SQLite schema by hand, so it
  needed `credentials_changed_at`; a note now says why that copy must track the
  migrations

**The percentage cannot be measured locally** — the dev container has no
coverage driver (`php -m` shows neither xdebug nor pcov; CI installs `pcov`).
The number only exists in CI.

## Known issues, not introduced here

- `ServiceFactoryTest::test_getRateLimitMiddleware_is_active_by_default` fails in
  the dev container, which sets `DISABLE_LOGIN_RATE_LIMITING`. Verified against
  the base commit — it fails there too
- `CronScriptTest` / `DrainServiceTest` spawn processes and destabilise the
  backend container when the full PHPUnit suite runs locally; unrelated to this
  change

## Rebase onto #361's P6–P9

Main landed P6, P7, P8 and P9 of #361 while this was open, which turned most of
the hand-off below from notes into integration work. What the rebase had to
resolve, and why each answer is the one it is:

| Collision | Resolution |
|---|---|
| Main took migrations **029, 030, 031** | Renumbered to **036, 037, 038** |
| Both ENUM migrations restate the whole list | Rewritten against main's *final* lists (`035` for `audit_log.action`, `030` for `mail_outbox.kind`). `MODIFY COLUMN … ENUM(...)` replaces rather than adds, so my original lists would have silently dropped `mail_retried`, `mail_test_sent`, the two terminal-anomaly actions, `cron_secret_rotated` and `terminal_anomaly_warning` — invalidating every row already written with one |
| `MailRetention::sentDaysFor()` is an **exhaustive `match`** and `pruneDelivered()` iterates `MailKind::cases()` | `ADMIN_EMAIL_CHANGED` gets an arm. Without it the drain's prune pass throws `UnhandledMatchError` on **every tick** — a merge that compiles and breaks the scheduler |
| P7's Notifications page enumerates kinds in three places | Added to the OpenAPI enum, `NotificationsPage`'s `KINDS`, and both locale files. Main's own P7 notes call this out: "a kind the server can queue and the queue page cannot name would render its own slug at somebody". The server's `filters.kinds` derives from `MailKind::cases()`, so it needs nothing |
| `MailKind`, `MailStrings`, `ServiceFactory`, `NotificationsServiceTest` | Both sides added beside each other; kept both. The registry now takes `TerminalAnomalyMailBuilder` **and** `AdminSecurityMailBuilder` |

**P8 got the admin-addressed case right without being asked.** Its erasure keys
on the member (`supersedePendingForMember`, `eraseMemberRecipients`), so an
admin's former address is untouched by a member's Art. 17 request — which was
the risk worth checking, and it is now recorded in #361's plan rather than left
to be rediscovered.

## Hand-off to [#361](https://github.com/dgloeckner/clubbar/issues/361)

The `admin_email_changed` kind lands in an outbox #361 is building out. P6–P9
have now shipped, so most of what were notes became integration work:

- **P8 (#408) — shipped, and right for this kind without being asked.** Its
  erasure keys on the member (`supersedePendingForMember`,
  `eraseMemberRecipients`), so an admin's former address survives a member's
  Art. 17 request. Its pruning is keyed on the kind, which is what forced this
  one to name its own window rather than inherit a default: it takes
  `DEFAULT_SENT_DAYS`, because what it holds is an address and the durable
  record of the change is the `email_changed` audit entry. The reasoning is
  recorded in #361's plan so it is not rediscovered.
- **P7 (#407) — shipped, and it answers the concern generally.** The worry was
  that a failed admin notice has no settlement and so no detail view. P7 built a
  `/notifications` page over the whole outbox precisely "because the
  Deckelauszug has no settlement to appear under", so the surface exists; the
  kind only had to be named in it (OpenAPI enum, `KINDS`, both locales).
- **P9 (#409) — shipped**, which turns the remaining gap into something now
  buildable. See below.

**Open follow-up.** With P9's `utils/mailpit.ts` and
`pattern-010-mail-assertions.md` in place, the notice deserves a `mail-chain`
spec asserting the *delivered* article: that it reaches the **former** address
and does not name the new one. Rendering is covered by
`AdminEmailChangedMailTest` and the queue row by the API spec, but nothing yet
asserts what a mail server received. Left out of this PR deliberately —
`mail-chain` is its own ordered Playwright project and adding to it is a change
to #361's suite rather than to this feature.

**P6 (#406) needs nothing.** `NullTransport::send` returns a *permanent* failure
and `recordResult` maps that to `failed`, so an admin email change on an
installation with no `mail.dsn` does not leave a row in the backlog that P6's
"oldest unsent > 24 h" stall alarm would fire on. Checked rather than assumed.

**#438's reserved kinds are unaffected**: `key_expiry_warning` and
`terminal_token_expiry_warning` are admin-*addressed* but carry `ENCRYPTION_KEY`
/ `TERMINAL` subjects, so they go through `warnAdmins` and will need their own
builder. `AdminSecurityMailBuilder` claims only `MailSubject::ADMIN_USER`.

## Deliberately out of scope

Recovery codes (ADR-0026 defers them; the runbook was chosen instead), WebAuthn,
a forgot-password flow, roles and permissions (rejected in ADR-0015 and
ADR-0036), and populating the vestigial `sessions` table.

An **email-verification link** was considered and rejected as the gate for an
email change: the outbox is drained by a scheduler rather than sent inline, and
an installation with no `mail.dsn` discards mail at the transport — so a
link-based hard gate would make the address unchangeable on exactly the
installations least able to diagnose why.
