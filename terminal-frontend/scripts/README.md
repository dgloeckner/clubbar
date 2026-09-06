# Development Scripts

Utility scripts for development workflows.

## Available Scripts

### `kiosk-session-setup.sh`

Turns a stock Raspberry Pi OS desktop session into a kiosk session: disables the
panel (`wf-panel-pi`) and desktop (`pcmanfm-pi`) in `/etc/xdg/labwc/autostart`,
pins the output mode via kanshi, and pins the audio output to the HDMI display
so WirePlumber cannot pick the unused 3.5 mm jack on the next boot. Idempotent,
so it is also what to re-run after a system upgrade restores the packaged
autostart.

```bash
sudo ./kiosk-session-setup.sh --now    # apply, and stop what is running
./kiosk-session-setup.sh --check       # report only, changes nothing
```

**Why both must go:** the desktop takes keyboard focus, so a card scan lands in
its type-ahead find box and the terminal never sees the keystroke that lifts
screen blanking — it stays black with the panel lit and reads as a dead till.
The panel is remapped above the fullscreen window by `lwrespawn` whenever it
dies. See [`INSTALL.md`](../INSTALL.md#the-session-must-hold-exactly-one-window).

### `kiosk-doctor.sh`

Checks a live terminal against every rule in `INSTALL.md`: focus competitors,
the pinned output mode, `clubbar-terminal.service`, whether the configured RFID
reader is actually present, `seedTestData`/`demoMode`, the blanking mode and
timeout, **where its sound actually goes**, and stray `armhf` packages.
Read-only — safe on a till that is serving.

The audio checks exist because on 2026-08-30 this script gave a terminal a
clean bill of health — *no failures, 0 warnings* — while it had been silent for
hours. The sound was going to the empty 3.5 mm jack, and every layer below
reported success, because playing into an unconnected port *is* success. Set
`EXPECT_SINK` if a terminal is wired to the jack rather than the display.

```bash
./kiosk-doctor.sh            # full report
./kiosk-doctor.sh --quiet    # only WARN and FAIL
```

Exit code `1` means at least one FAIL, so it works in a check script.


### `clubbar-update.sh`

The nightly updater ([ADR-0054](../../adr/0054-terminal-runs-its-backends-version.md),
[#318](https://github.com/dgloeckner/clubbar/issues/318)). It installs **exactly
the version the terminal's backend reports** at `/api/health`, atomically, and
rolls it back if the app does not write a heartbeat within five minutes.

Run by a systemd *user* timer at 04:00 — see
[`INSTALL.md` §4](../INSTALL.md#unattended-updates) for why a user unit and not
a system one, and for the one-off conversion an older flat install needs.

```bash
clubbar-update.sh --status       # installed / previous / blocked / pinned
clubbar-update.sh --check        # what tonight would do; changes nothing
clubbar-update.sh --clear-block  # forget a failed version so it may be retried
```

Exit `0` is "nothing to do, or done"; `1` is "an update failed and was rolled
back"; `2` is "this machine is not set up for unattended updates".

Its two test suites are in [`test/`](test/):

```bash
./test/updater-version.sh   # the release-tag rules, shared with the backend
./test/updater-flow.sh      # the refusals, the happy path and the rollback,
                            # against a sandboxed install root and a fake GitHub
```

`updater-version.sh` asserts the *same table of cases* as
`backend/tests/Unit/Shared/Version/ReleaseVersionTest.php`. Two implementations
of "which version is newer" that disagree is how a terminal ends up moving
backwards, so a case added to one belongs in the other.

**Both need GNU coreutils.** `mv -T`, `date -d` and `sha256sum` are not
portable, so on macOS `updater-flow.sh` fails from the first symlink swap
onward — `mv: illegal option -- T` — and the failures cascade. That is not a
defect: the target is a Raspberry Pi. CI runs both suites on `ubuntu-24.04`
(the `test-updater` job), which is the authority; to run them on a Mac, use a
Linux container.

### `reset-db.sh`

Resets the local SQLite database by removing the app's data file. On the next app launch, the database will be recreated and seeded with fresh mock data.

**Usage:**

```bash
# From project root
./scripts/reset-db.sh

# Or via Makefile
make reset-db

# Or reset and run in one command
make reset-and-run
```

**What it does:**
- Deletes the SQLite database file from the platform-specific app container
- Supports macOS and Linux
- On next app run, mock data is automatically seeded

**After running:**
1. Database is wiped clean
2. Run `flutter run` or `make run` to start the app
3. App will seed default categories and products on startup
4. Test RFID cards available:
   - `card-123` → John Doe
   - `card-456` → Jane Smith

**Platform-specific paths:**
- **macOS**: `~/Library/Containers/de.clubbar.clubbarTerminal/Data/clubbar_terminal.db`
- **Linux**: `~/.local/share/clubbar_terminal/clubbar_terminal.db`

### `audio-diagnose.sh`

Captures the state of terminal audio **while it is broken**, on the Pi. For the
fault where sound worked, nothing was changed, sound is gone, and a reboot
brings it back — the reboot is what destroys the evidence, so run this first.

**Usage:**

```bash
# On the terminal, while it is silent
/opt/clubbar-terminal/current/scripts/audio-diagnose.sh          # ~/audio-diagnose-<timestamp>.txt
/opt/clubbar-terminal/current/scripts/audio-diagnose.sh --no-play  # skip the noise-making tests
```

**What it collects:**
- The terminal process: start time, the environment it actually got, and which
  audio path it holds open (an ALSA device, or a socket to a sound server)
- Cards, playback substream owners, and who is holding `/dev/snd/*`
- Sound servers **with their start times** — one younger than the app is the
  usual cause
- Mixer state, the clips `audioplayers` extracted to the temp dir, `error.log`
- `dmesg`/journal lines for `vc4`, HDMI and ALSA
- Playback tests along three paths, including the one `audioplayers` really
  builds (`audiopanorama ! autoaudiosink`)

Read the result alongside
[docs/audio-dropout-debugging.md](../docs/audio-dropout-debugging.md), which
ranks the causes and says which capture section separates them.

---

---

## Common Development Tasks

### Using Makefile

```bash
make help           # Show all available commands
make reset-db       # Reset database
make run            # Run the app
make test           # Run all tests
make analyze        # Run Flutter analyzer
make clean          # Clean build artifacts
make dev-setup      # Install dependencies
make reset-and-run  # Reset DB and run app
make test-all       # Run analyzer + all tests
```

### Manual Commands

```bash
# Reset database
./scripts/reset-db.sh

# Run tests
flutter test

# Analyze code
flutter analyze

# Clean and rebuild
flutter clean && flutter pub get

# Run app
flutter run
```

---

## Tips

- **Testing after DB reset**: After `make reset-db`, immediately run `make run` or the database state may be unclear
- **Multiple devices**: Use `flutter devices` to see connected devices, then `flutter run -d <device_id>`
- **Watch mode**: Add `-t lib/main.dart` to many commands to watch specific entry points
- **Verbose output**: Add `-v` to any command for verbose logging (e.g., `flutter run -v`)

---

## Extending Scripts

To add new scripts:
1. Create the `.sh` file in this directory
2. Make it executable: `chmod +x script-name.sh`
3. Add a corresponding Makefile target
4. Document it here
