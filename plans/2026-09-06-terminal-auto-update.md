# Terminal Auto-Update: A Terminal Runs Its Backend's Version

**Issue**: [#318](https://github.com/dgloeckner/clubbar/issues/318)
**Decision**: [ADR-0054](../adr/0054-terminal-runs-its-backends-version.md)
**Use case**: [UC-T15](../use-cases/terminal/UC-T15-unattended-update.md)

Terminals were updated by hand: build or download the arm64 tarball, rsync it to
`/opt/clubbar-terminal/`, restart. A club with three terminals in a room nobody
visits between Tuesdays updates them when somebody remembers to, which in
practice means when something is already wrong.

This makes it unattended, with one rule: **a terminal runs exactly the version
its backend reports at `/api/health`**. Upgrading the backend is the single act
that moves terminals — no release to bless, no channel to pick, nothing decided
per terminal.

---

## Milestones

### M1 — The version rules, in two languages `[x]`

`ReleaseVersion` (PHP) and `is_release_tag`/`compare_tags` (shell) answer the
same two questions: is this a release tag, and which of two is newer. `dev` and
`dev-<sha>` are refused by both — an unknown version is not an infinite ceiling.

Two implementations exist because the Pi has no PHP; they are pinned to the
*same table of cases* so they cannot drift apart quietly.

- `backend/src/Shared/Version/ReleaseVersion.php`, `AppVersion.php`
- `terminal-frontend/scripts/clubbar-update.sh` (`parse`/`compare` section)
- **Verified**: `ReleaseVersionTest` 19/19; `scripts/test/updater-version.sh`
  25/25 cases.

### M2 — Terminals report what they run `[x]`

`X-Terminal-Version` on every terminal-authenticated request, plus
`X-Terminal-Blocked-Version` while an update has failed there. Recorded beside
`last_sync_at` in the same UPDATE, **fail-open**: anything unparseable records
nothing and never refuses a sync.

- Migration `065_terminal_reported_version.sql` (+ rollback), `TerminalsRepository::updateLastSync`
- `TerminalTokenAuth`, `TerminalDto`, `TerminalVersionState`, `TerminalsService`
- `api/admin.yaml`, `api/terminal.yaml`, `docs/erm-master.md`
- `TokenInterceptor` (Dart)
- **Verified**: `TerminalTokenAuthTest` (6 new), `TerminalVersionStateTest` 8/8,
  `TerminalDtoTest` 10/10, `token_interceptor_test.dart` 5/5,
  `tests/api/terminal-version-reporting.spec.ts` 12/12.

### M3 — The heartbeat `[x]`

The app writes `status.json` beside `config.json` on **every** sync cycle,
success or failure: the last successful round-trip, and the unsynced count. The
updater reads it to refuse an update (sales waiting) and to decide whether one
came up healthy.

A restart count cannot be the health signal — the app's unit ships
`StartLimitIntervalSec=0` deliberately, so a crash-looping kiosk never trips a
limit that is switched off.

- `terminal-frontend/lib/services/updater_handshake.dart`, `sync_service.dart`, `main.dart`
- **Verified**: `updater_handshake_test.dart` 11/11; full Flutter suite 1056/1056.

### M4 — The updater, the timer and the rollback `[x]`

`clubbar-update.sh`: A/B slots, atomic symlink swap, SHA-256 verification,
sqlite backup, five-minute heartbeat watchdog, rollback with database restore
and a `blocked` list that is never retried automatically.

A systemd **user** timer, not a system one: the app is a `--user` service, and
every path the updater writes belongs to the kiosk user.

- `terminal-frontend/scripts/clubbar-update.sh`
- `terminal-frontend/scripts/systemd/clubbar-update.{service,timer}`
- **Verified**: `scripts/test/updater-flow.sh` — 63 assertions over 13 refusals,
  the happy path, the rollback, and recovery via `--clear-block`.

### M5 — The panel names the three states `[x]`

*current* renders as the bare tag with no badge; *behind* is named but not
alarmed (it is the normal state of every terminal for hours after a backend
upgrade); *stuck at `<tag>`* is the only one in danger colours, because nothing
on the terminal will ever clear it.

- `admin-frontend/src/components/settings/TerminalVersionCell.tsx`, `TerminalsTab.tsx`, both locale files
- **Verified**: `TerminalVersionCell.test.tsx` 6/6; `tsc` and `eslint` clean.

### M6 — A Release publishes both artifacts or neither `[x]`

`test-package` and `build-terminal` no longer upload to the release. A
`release-gate` job that both feed does, after verifying the checksum. A release
whose arm64 build fails now publishes nothing — under ADR-0054 it would
otherwise point every terminal in every club at a tag with no tarball.

The operator scripts ship inside the bundle, so `current/scripts/…` is replaced
with the binary they diagnose, and each release brings its own updater.

- `.github/workflows/build.yaml`
- **Verified**: YAML parses; job graph is
  `test-package + build-terminal → release-gate`. Not exercised end to end —
  it only runs on a `release` event.

### M7 — Docs `[x]`

- `terminal-frontend/INSTALL.md` §1 (A/B layout, and the one-off conversion an
  older flat install needs), §4 (the timer, and why a user unit), §8 (`updatePin`,
  `updateEnabled`)
- `docs/runbook-terminal-pi.md` §6 — reading the Version column, why a terminal
  is behind, recovering one that is stuck, pinning, rolling back by hand
- `terminal-frontend/scripts/README.md`, `use-cases/terminal/UC-T15`

---

## What was deliberately left out

- **The ADR's status line still reads *Proposed*.** ADRs are not edited without
  the user's say-so (CLAUDE.md), and moving it to *Accepted* is that user's call.
- **No E2E through a real Pi.** The updater's suite drives the real script
  against a sandboxed install root and a fake GitHub; a physical arm64 kiosk is
  outside what CI has.
- **Signing the artifacts** (minisign) stays an open question in the ADR. This
  ships TLS plus a published SHA-256, and refuses a release that has no checksum.
