# Credential Expiry Notifications

**Issue**: [#438](https://github.com/dgloeckner/clubbar/issues/438) — follow-up from [#397](https://github.com/dgloeckner/clubbar/issues/397), epic [#388](https://github.com/dgloeckner/clubbar/issues/388)
**ADRs**: [ADR-0036](../adr/0036-iban-encryption-sealed-box.md) (the deferred deviation, now corrected), [ADR-0038](../adr/0038-transactional-mail-outbox-on-shared-hosting.md) (the outbox and the mandatory scheduler)
**Status**: Implemented (M1–M5 done; 1866 backend unit + 574 feature tests green, 3 `mail-credentials` E2E green)

---

## Why

ADR-0036 computes the 90/30/7-day expiry warnings for the IBAN encryption key
and terminal tokens at request time, and shows them on the dashboard and in
Settings → Schlüssel. That is unmissable for an admin who opens the panel, and
completely silent for one who does not — and the failure it is silent about is
the expensive kind: a lapsed key blocks the SEPA export, a lapsed terminal token
locks a till out of the bar on an evening somebody is standing at it.

## The one decision worth stating

**#438 proposed the wrong trigger, and its own acceptance criterion is what
shows it.** The issue suggests piggybacking on an authenticated admin request,
because ADR-0031 promises no cron on shared hosting. But *"an admin who does not
open the admin panel still receives a warning"* and *"the warning is queued by
an admin opening the admin panel"* cannot both be true. That version warns
exactly the people who did not need warning.

The premise had also expired. When #438 was written the scheduler was optional;
ADR-0038 made it **mandatory** — it is the only sending path, the install gate
(#405) refuses to finalize a direct debit without one, and the heartbeat notices
when it stops. So the scan rides the tick, beside the two already there.

Everything else the issue asks for was already in the codebase and needed
finding rather than building: `NotificationsService::warnAdmins()` has existed
since #438 was filed (with nothing able to render what it queued), both
`MailKind` cases were reserved for it, and the outbox's
`UNIQUE (kind, subject_id, dedup_key)` is a stronger answer to "idempotent
notification storage" than the `logOnceSince` precedent the issue names — that
one is a time window, this one is a constraint.

---

## Milestones

### M1 — The tier [x]

- [x] `CredentialLifecycle::WARNING_TIERS` — 90/30/7 as one ordered list, with the
      existing `INFO_/WARNING_/CRITICAL_DAYS` constants derived from it so the
      dashboard's states and the mail's tiers cannot drift apart
- [x] `CredentialLifecycle::warningTier()` — the tier **entered**, not the days
      left. A value that moved with the clock would queue a fresh mail on every
      tick for ninety days
- [x] Null past expiry: these are advance warnings, and expiry is already an
      error in the UI and a hard stop in the code

**Verified**: `CredentialLifecycleTest` — 4 new cases, including one asserting
every tier is a non-`OK` dashboard state.

### M2 — The scan [x]

- [x] `CredentialExpiryNotifier` — the ACTIVE key plus active terminal tokens,
      one `warnAdmins()` call per credential in a tier, occasion `90d`/`30d`/`7d`
- [x] Skips terminals that are switched off, and terminals with a **pending
      token**: issuing a replacement is exactly what the mail would ask for
- [x] Declines entirely when `MailConfigService::canSend()` is false — the
      "stays fully optional" half of the acceptance criterion, and not merely
      tidiness: `NullTransport` reports a *permanent failure*, so without the
      gate every warning would land in the Notifications page as a red row on an
      installation whose owner never asked for mail
- [x] Never throws; the caller's other job is draining the queue
- [x] `EnqueueResultDto::$alreadyQueued` — a repeating caller needs "already
      said" and "nothing to say" to be distinguishable; both are zero queued

**Verified**: `CredentialExpiryNotifierTest` — 10 cases.

### M3 — The message [x]

- [x] `CredentialExpiryMail` + `CredentialExpiryDataDto` — one template for both
      credentials; what differs is four translation keys
- [x] de/en strings naming the remedy **as the admin panel labels it** —
      Einstellungen → Schlüssel, and „Token rotieren" — rather than a menu path
      that does not exist
- [x] The tier changes the volume, never the content: the 7-day subject carries
      the urgency, all three carry the same four facts
- [x] No key material, no token, no fingerprint — and the message says so, so a
      reader has a rule to apply to the next mail that *does* ask for one
- [x] `CredentialExpiryMailBuilder` — claims both kinds, reads the tier from the
      `dedup_key`, recomputes the days at send time

**Verified**: `CredentialExpiryMailTest` (8 cases, including the negative
assertions and HTML escaping) and `CredentialExpiryMailBuilderTest` (7 cases).

### M4 — The wiring [x]

- [x] Builder registered in `MailContentRegistry`; both services in `ServiceFactory`
- [x] `bin/cron.php` runs the scan before the drain, so a warning raised by a
      tick leaves on that tick

**Verified**: `CronScriptTest` (feature suite) drives the real entrypoint; a
manual run prints `Credential expiry scan: …`.

### M5 — The chain, asserted end to end [x]

- [x] New Playwright project `mail-credentials`, after `mail-statement`
- [x] `credential-expiry.spec.ts` — a token five days from expiry, one real
      `bin/cron.php` run, and the delivered message read back from Mailpit
      (Pattern 010): the terminal's name, the deadline, the consequence, the
      remedy
- [x] Idempotency asserted **at the mailbox**, after a second run — a duplicate
      row is our bookkeeping, a duplicate message is what the admin lives with
- [x] The delivered bytes carry neither the token the terminal was actually
      issued nor anything shaped like a hash of one
- [x] An already-expired token produces no further warning
- [x] `utils/sql.ts` — the suite's one SQL escape hatch, and why it is drawn
      here: a token's lifetime comes from `AppConfig::$tokenTtlDays`, not from
      the create request, so no sequence of API calls stands a credential seven
      days from expiry

**Verified**: `npx playwright test --project=mail-credentials` — 3/3 green,
three consecutive runs.

---

## Deliberately not done

| | |
|---|---|
| A mail once the credential has **expired** | Advance warnings only. Expiry is enforced where it bites and is already an error on the dashboard; a fourth channel saying so would be the least urgent |
| A warning for a club with **no active key at all** | Not an expiry. It is the loudest error the dashboard has, and it means IBANs cannot be stored *now* rather than on some future date |
| An admin-facing **setting** to switch the warnings off | The switch already exists and is the mail configuration itself. A second one would be a way to turn off the only proactive signal the credential lifecycle has |
| Changing the **off-by-one** in "expires on 21.08. — in 4 days" | `daysUntilExpiry()` floors whole days, and the dashboard has always floored the same way. Mail and UI agreeing matters more than either being rounded the way a calendar counts |

## Follow-ups

None open. [#437](https://github.com/dgloeckner/clubbar/issues/437) is the other
half of the #388 epic's tail and is unaffected by this work.
