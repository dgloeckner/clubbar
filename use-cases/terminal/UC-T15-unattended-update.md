# UC-T15: Unattended Terminal Update

**Implementation Status**: Implemented

Implements [#318](https://github.com/dgloeckner/clubbar/issues/318). The design
and its rejected alternatives are in
[ADR-0054](../../adr/0054-terminal-runs-its-backends-version.md); this page is
the requirement and its acceptance criteria.

## Actor

System (the Pi's update timer). **No human is involved at any point** — that is
the requirement, not a convenience. A Pi in a bar has no operator, and an update
that can leave the terminal needing one has failed, because the human arrives at
19:00 on a Friday to a room full of people who want a beer.

## Preconditions

- The terminal is installed in A/B slots, with `current` pointing at a slot
  named after a release tag.
- `clubbar-update.timer` is enabled as a **user** timer, and the install root
  and the app's data directory both belong to the kiosk user.
- `config.json` names the club's backend in `apiUrl`.

## Trigger

`clubbar-update.timer` fires — nightly at 04:00 with up to an hour of
randomised delay, and `Persistent=true`, so a terminal that was switched off
overnight catches up on its next boot instead of waiting for tomorrow.

## Main Flow

1. `GET {apiUrl}/health` → the backend's `version`. This endpoint is public and
   needs no token, which matters: the updater must work when the app is dead,
   its credential has expired, or its sync has been failing for a week.
2. The version parses as a release tag (`vMAJOR.MINOR.PATCH`).
3. It is **newer** than `basename $(readlink current)`.
4. The terminal is neither pinned to another version nor opted out, and the tag
   is not in `blocked`.
5. The app's status file reports **zero** unsynced transactions.
6. `GET /repos/dgloeckner/clubbar/releases/tags/{version}` yields a bundle
   matching this architecture and its `.sha256` companion. Both are downloaded
   and the checksum verified.
7. The bundle is extracted to `releases/{version}/`, via a staging directory so
   that a power loss cannot leave a half-populated slot named after a tag.
8. The app service is stopped, the sqlite database is copied to
   `update-backup/`, `previous` and `current` are swapped by rename, and the
   service is started.
9. Within five minutes the app writes its heartbeat — the file it touches once
   a **sync round-trip succeeds**.
10. Slots below `previous`, and backups beyond the newest three, are removed.

## Postconditions

- `basename $(readlink current)` equals the backend's `version`.
- `previous` points at the slot that was replaced.
- The app reports the new version in `X-Terminal-Version` on its next sync, and
  the admin panel's Terminals page reads *current*.
- No transaction was lost: the update ran only because there were none waiting,
  and the database it opened is the one it closed.

## Variants

### V1: Nothing to do

The backend's version equals the installed one. This is what happens on almost
every night of the year.

### V2: The terminal is behind and catches up

The club upgraded its backend during the day. That evening the terminal reads
*Behind* on the Terminals page; the 04:00 run installs the new version and it
reads *current* the next morning. **"Behind" is not an alarm** — it is the
normal state of every terminal in the club for the hours in between, and a page
that alarms on it teaches the club to stop reading the page.

### V3: The rollback

The new version installs and never writes a heartbeat within the window — it
crash-loops, or it comes up wedged. `current` goes back to `previous`, the
pre-update database is restored, the tag is appended to `blocked`, and the
service is restarted on the version that worked.

The heartbeat is the **sole** health criterion, and it has to be: the app's unit
ships `StartLimitIntervalSec=0` deliberately, so a terminal crash-looping
forever never trips a limit that is switched off, and a restart count therefore
cannot be the signal. One test covers both the crash-loop and the wedge.

Restoring the database is safe *because* of step 5: a version that never got
healthy booked nothing.

### V4: A blocked terminal waits

After V3 the terminal will never retry that tag, and exact-match means it is
also the only tag it would consider — so it stays on the last working version
and does nothing until a release rolls its backend forward. That is intended.

It is also invisible from the Pi, which is why the app reports the blocked tag
in `X-Terminal-Blocked-Version` and the Terminals page must show *Stuck at
`<tag>`* as its own state, distinct from *Behind*.

### V5: Pinned, or opted out

`updatePin` holds the terminal at one release; `updateEnabled: false` opts it
out entirely. Both live in `config.json` on that Pi, so a terminal pinned during
an investigation stays pinned until somebody removes the key.

## Error Cases

Every one of these is **"no update, retry tomorrow"**, logged and exiting 0.
None of them is an error state, and none of them leaves the terminal changed.

| # | Condition | Why it is a refusal |
|---|-----------|---------------------|
| E1 | The backend is unreachable | The club's internet was down at 04:00. Nothing about the terminal is wrong |
| E2 | The backend reports `dev` or `dev-<sha>` | A club deployed from git reports this forever. An unknown version is not an infinite ceiling — the same fail-closed reading [ADR-0044](../../adr/0044-tiered-admin-roles.md) gives an unclassified route |
| E3 | The backend is on an **older** tag | Never downgrade. Moving backwards means running a sqlite migration backwards, and the backup that would make that safe is long gone. Logged as a warning, because it is the one refusal that means somebody did something |
| E4 | The tag has no release, or no bundle for this architecture | Nothing to install. A missing release is logged more loudly: the club is running a backend the project never released |
| E5 | The release publishes no `.sha256`, or the checksum does not match | Refused rather than installed unverified. TLS authenticates the host; the checksum is what catches a truncated download, and a truncated tarball extracts into a bundle that starts and then does not work |
| E6 | Unsynced transactions exist, or the status file is missing | Real purchases that exist nowhere else. A missing file is treated the same way: "unknown" is not "none" |
| E7 | `current` is not a symlink | An install that was never converted to A/B slots. The updater **refuses and exits 2** rather than force-installing over it, which would silently reinstall over a terminal somebody had deliberately held back |

## Test Derivation

`terminal-frontend/scripts/test/updater-flow.sh` drives the real script against
a sandboxed install root, a fake data directory, a `curl` that serves fixtures
and a `systemctl` that can pretend the app came back — or did not.

| Case | Asserts |
|------|---------|
| E1–E7 above, one case each | exit 0 (E7: exit 2), the reason named in the log, `current` unmoved, and — where nothing should have been touched — the service never cycled |
| Main flow | `current` moves, `previous` points at the replaced slot, the bundle is in its own slot, a database backup exists, the service was stopped before the copy and started after, and nothing was blocked |
| V3 | exit 1, `current` back on the old version, the pre-update database byte-for-byte restored, the tag in `blocked`, `update-state.json` naming it, and the failed slot removed |
| V4 | the next run refuses the same tag rather than retrying it |
| Recovery | `--clear-block` lets a subsequent run install it |

`terminal-frontend/scripts/test/updater-version.sh` asserts the release-tag
rules against the same table of cases as
`backend/tests/Unit/Shared/Version/ReleaseVersionTest.php` — two
implementations of "which version is newer" that disagree is exactly how a
terminal moves backwards.

Reporting is covered by `test/services/token_interceptor_test.dart` (the two
headers), `test/services/updater_handshake_test.dart` (the heartbeat file),
`backend/tests/Unit/Modules/Auth/Middleware/TerminalTokenAuthTest.php`
(fail-open recording) and
`admin-frontend/src/components/settings/TerminalVersionCell.test.tsx` (the five
states, and that *behind* and *stuck* do not render alike).

## Related

- [ADR-0054](../../adr/0054-terminal-runs-its-backends-version.md) — the decision
- [ADR-0033](../../adr/0033-terminal-sync-contract.md) — the unversioned sync
  contract that makes "which version?" a correctness question
- [INSTALL.md §4](../../terminal-frontend/INSTALL.md#unattended-updates) — installing the timer
- [Runbook §6](../../docs/runbook-terminal-pi.md) — a terminal that is behind, or stuck
