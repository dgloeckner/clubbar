# Terminal Credential Anomaly Detection

**ADR**: [ADR-0041](../adr/0041-terminal-credential-anomaly-detection.md)

**Status**: In Progress (M1–M3, M4.1/4.3 and M5.1 done and verified; the acknowledge UI and the E2E tests are the remaining work — see 4.2/4.4/5.2/5.3)

**Branch**: `claude/terminal-ip-token-security-dd6zfo`

---

## Goal

Detect that a terminal token is being used by more than one device, and tell an admin. Two signals, both recorded server-side from traffic the terminal already sends, both evaluated on the existing cron tick:

1. **Sustained IP overlap** — two source IPs active at the same time for one terminal, ≥15 min of genuine overlap.
2. **Sync-cursor discontinuity** — a presented delta cursor below the recorded high-water mark (`cursor_regression`), or zero when the high-water is not (`cursor_reset`).

Nothing is ever blocked or revoked. Alert only.

---

## Milestones

### M1 — Schema

- [x] **1.1** Migration `030_terminal_anomaly_detection.sql`: `terminal_ip_sightings`, `terminal_sync_cursors`, `terminal_anomalies`; widen `mail_outbox.kind` with `terminal_anomaly_warning`. Rollback file.
  - *Verify*: `docker compose exec backend php public/install.php?action=migrate` equivalent runs clean; `SHOW CREATE TABLE` for all three.
- [x] **1.2** Migration `031_audit_log_terminal_anomaly_actions.sql`: restate the full `audit_log.action` ENUM plus `terminal_anomaly_detected`, `terminal_anomaly_acknowledged`. **Must carry every value from migration 025's list** — omitting one silently un-adds it.
  - *Verify*: insert one row of each new action; existing actions still accepted.

### M2 — Recording on the request path

- [x] **2.1** `TerminalIpSightingsRepository::record(terminalId, ip, now)` — `INSERT … ON DUPLICATE KEY UPDATE request_count = request_count + 1, last_seen_at = ?`, bucket floored to 5 min.
- [x] **2.2** Call it from `TerminalTokenAuth::process()` beside `updateLastSync()`, reading `REMOTE_ADDR` the way the failure path already does. Never let a sighting failure break a sale — wrap and log.
- [x] **2.3** `TerminalSyncCursorService::observe(terminalId, stream, presentedCursor)` — compares against the high-water mark, records regressions, advances the mark. Called from the three delta endpoints (`Members\SyncController::index`, `Products\SyncController::categories|products`).
  - *Verify (PHPUnit)*: monotonic cursors record no regression; a lower cursor records one; zero-with-high-water records a reset; the served delta is byte-identical either way.

### M3 — Detection on the cron tick

- [x] **3.1** `TerminalAnomalyDetector::run()` — sustained-overlap scan over the lookback window, plus regressions/resets recorded since the last tick. Opens or updates one row per (terminal, kind) in `terminal_anomalies`; audits `terminal_anomaly_detected` on open.
- [x] **3.2** Prune `terminal_ip_sightings` older than `TERMINAL_IP_RETENTION_DAYS` (30).
- [x] **3.3** Wire into `backend/bin/cron.php` **before** the drain, inside the existing `FileLock`, and into `CronController::drain`. A detector throw must not stop the mail drain.
- [x] **3.4** Config knobs via the `positiveEnv` pattern, defaults as class constants: lookback 60 min, min overlap 15 min, retention 30 days. `.env.example` + `docker-compose.yml`.
  - *Verify (PHPUnit)*: a clean handover (adjacent, non-overlapping intervals) produces nothing; a 20-minute overlap produces one anomaly; a second tick updates rather than duplicates it.

### M4 — Surfacing

- [x] **4.1** Dashboard: `alerts.terminal_anomaly` in `DashboardService::getDashboard()` as a static method beside `encryptionKeyAlert()`; OpenAPI schema; regenerate the TS client; banner on `DashboardPage.tsx` following the existing inline-banner pattern with `data-testid`/`data-severity`.
- [~] **4.2** Terminals surface: `open_anomaly_count`/`has_open_anomaly` on `TerminalDto` and the marker in `TerminalsTab.tsx` are done. **Remaining**: the same marker on `TerminalCredentials.tsx` (the Security & Credentials board).
- [x] **4.3** Email: `MailKind::TERMINAL_ANOMALY_WARNING`, a `TerminalAnomalyMail` builder + registration in `MailContentRegistry` (today it only carries `SettlementMailBuilder`, so an admin-addressed row would fail to render), `MailStrings` de/en entries, enqueue via `NotificationsService::warnAdmins()`.
- [~] **4.4** Acknowledge: the endpoint, its audit entry and the OpenAPI path are done and unit-tested. **Remaining**: the UI to call it. The marker is currently informational, so an open anomaly can only be cleared through the API — this is the main gap left, because a banner nobody can dismiss is a banner that gets ignored. Needs `GET /api/admin/terminals/{id}/anomalies` (the list DTO carries only a count, so the UI has no anomaly id to acknowledge) plus a small panel behind the marker.

### M5 — Tests

- [x] **5.1** PHPUnit: sighting upsert/bucketing, cursor observer, overlap arithmetic (handover vs clone), detector dedup, pruning. Plus **Feature tests against a real database** for all three repositories — the upsert, the `GREATEST` high-water rule and the guarded acknowledgement UPDATE are SQL, and mocks cannot check any of them. Added after CI's patch-coverage gate failed at 67%.
- [ ] **5.2** E2E API: two IPs via distinct `X-Forwarded-For`… **not possible** — `REMOTE_ADDR` is the only source and all test traffic is `127.0.0.1`. Drive the detector against seeded sighting rows instead, then assert the dashboard alert and the acknowledge endpoint over HTTP.
- [ ] **5.3** E2E admin: banner appears for a seeded anomaly, acknowledging clears it.

---

## Notes / decisions carried from the ADR

- A single cursor regression opens an anomaly. Thresholds do not work here: `SyncCursor::next()` catches a lagging client up on its next poll, so a fork yields one regression per stream per data-change event, not a continuous stream.
- Sightings are bucketed at 5 minutes — one row per request would be ~7,000/terminal/day for no extra answer.
- `X-Forwarded-For` is not trusted. Behind a proxy that hides the client address, `concurrent_ip` cannot fire; the cursor detectors carry the load.
- IPv6 privacy extensions are a known false-positive source; /64 grouping is the fix and is deliberately deferred (ADR-0041 Consequences).
