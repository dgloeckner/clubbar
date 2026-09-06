# Club Bar Terminal — Installation Guide

This guide covers deploying the Club Bar terminal app on a Raspberry Pi running
Raspberry Pi OS. The terminal is a Flutter desktop app targeting embedded Linux,
typically running fullscreen on a touchscreen display.

**Work through it in order.** Sections 5 to 7 are not optional extras: an
unattended till that cannot rejoin its wifi, keeps the wrong time, or never
restarts its own app is one somebody has to drive to. Each of them exists
because of a failure that actually happened.

Once a terminal is running, [`docs/runbook-terminal-pi.md`](../docs/runbook-terminal-pi.md)
is the other half: what to do when one stops responding.

---

## Prerequisites

> The hardware in service, with its measured capabilities and limits, is
> [`docs/terminal-hardware.md`](../docs/terminal-hardware.md). Read it before
> choosing a panel — whether the touchscreen survives standby is decided there,
> not here.

- Raspberry Pi 4 or 5, 2 GB RAM minimum
- Raspberry Pi OS Bookworm or Trixie (64-bit, desktop). §§3 and 5 assume the
  Wayland session both ship (labwc); see the notes there for X11
- Official Raspberry Pi touchscreen, or any HDMI touchscreen
- RFID/NFC USB reader (keyboard-emulation mode)
- Network access to the Club Bar backend

---

## 0. Getting a Flutter app binary

You may either build from sources or use a pre-built binary.

### Building from sources

On a development machine with Flutter installed:

```bash
cd terminal-frontend
flutter build linux --release
```

### Downloading from Github

You can download arm64 builds from https://github.com/dgloeckner/clubbar.
Development builds can be found in each CI build run.

## 1. Build and install the Flutter app

The install is laid out in **A/B slots**, because that is what makes an update
atomic and a rollback a single rename ([ADR-0054](../adr/0054-terminal-runs-its-backends-version.md)):

```
/opt/clubbar-terminal/
  releases/v1.0.6/             # each release, fully self-contained
  releases/v1.0.7/
  current  -> releases/v1.0.7  # the ONLY source of truth for what is installed
  previous -> releases/v1.0.6  # rollback target
  blocked                      # versions that failed here, never retried
```

There is deliberately **no `VERSION` file inside a bundle**. A second copy of
the version can contradict the directory it sits in, and the contradiction is
silent — an updater confidently declining to update a terminal that is running
something else. `basename $(readlink current)` is the answer, and the only one.

Copy the app to the Pi, into a slot named after the release it is:

```bash
VERSION=v1.0.7    # the tag you built or downloaded; see §0
ssh pi@<PI_IP> "mkdir -p /opt/clubbar-terminal/releases/$VERSION"
rsync -av build/linux/arm64/release/bundle/ \
      pi@<PI_IP>:/opt/clubbar-terminal/releases/$VERSION/
```

On the Pi, point `current` at it and make the binary executable:

```bash
VERSION=v1.0.7
chmod +x /opt/clubbar-terminal/releases/$VERSION/clubbar_terminal
ln -sfn /opt/clubbar-terminal/releases/$VERSION /opt/clubbar-terminal/current
```

**The whole tree must belong to the kiosk user**, because the update timer runs
as that user and writes every one of these paths (see §4):

```bash
sudo chown -R "$USER":"$USER" /opt/clubbar-terminal
```

To launch manually and verify it works:

```bash
DISPLAY=:0 /opt/clubbar-terminal/current/clubbar_terminal
```

### Converting a terminal that was installed flat

Terminals installed before ADR-0054 have the bundle sitting *at*
`/opt/clubbar-terminal/`, and nothing on the box records which release it is.
So the updater refuses to touch such a machine rather than guess — force-
installing the backend's version over an unknown flat install would silently
reinstall over a terminal somebody had deliberately held back.

**The first slot is installed by hand, never over the air.** A terminal older
than v1.0.8 has no `status.json`: the app that writes it shipped *in* v1.0.8,
and step 3 of every update run refuses to continue without it —
*"cannot tell whether sales are waiting to sync"*, which is the correct reading,
because unknown is not none. So a pre-v1.0.8 terminal cannot make its first hop
over the air; left to the timer it would meet that refusal every night, forever.
Install the release the backend is already on directly into the slot layout
instead. From then on every update is unattended, because the new app writes the
file on its first sync.

That deadlock is deliberately not solved with a flag. A switch that skips the
unsynced-transactions check is precisely the switch somebody reaches for at
19:00 on a Friday.

**Take ownership first.** A terminal installed by the `rsync` in §1 has a
root-owned `/opt/clubbar-terminal`, so every command below fails at the first
`mkdir` with `Permission denied` unless this runs before them:

```bash
sudo chown -R "$USER":"$USER" /opt/clubbar-terminal
```

Download the release the backend reports, and verify it before it goes anywhere
near the slots. The `.sha256` companion names a bare filename, so both files
have to sit in the same directory for `sha256sum -c` to work:

```bash
V=v1.0.8          # what GET /api/health reports; `clubbar-update.sh --status` prints it too
BASE=https://github.com/dgloeckner/clubbar/releases/download/$V
A=clubbar-terminal-linux-arm64-$V.tar.gz

cd ~
curl -fLO "$BASE/$A"
curl -fLO "$BASE/$A.sha256"
sha256sum -c "$A.sha256"        # must print: ...tar.gz: OK
```

`-L` is required — the asset URL redirects to `objects.githubusercontent.com`,
and without it you save a 302 body under the right filename. `-f` turns a 404
into an error rather than a saved error page, which is the failure that reads as
a corrupt archive.

Only once the checksum passes, build the layout. The old build becomes a slot of
its own rather than being deleted: it costs nothing and it is a rollback target
that needs no network. Its tag is what the terminal is actually running — the
status modal shows the compiled-in `APP_VERSION` if you do not know it:

```bash
OLD=v1.0.4        # what this terminal was running
cd /opt/clubbar-terminal
systemctl --user stop clubbar-terminal.service

mkdir -p releases/$OLD releases/$V
# Everything except the new layout's own entries.
find . -maxdepth 1 -mindepth 1 \
     ! -name releases ! -name current ! -name previous ! -name blocked \
     -exec mv {} releases/$OLD/ \;

tar -xzf ~/clubbar-terminal-linux-arm64-$V.tar.gz -C releases/$V
chmod +x releases/$V/clubbar_terminal releases/$V/scripts/*.sh
ln -sfn releases/$V current
```

Check the result before starting anything — `current` must be the only entry
beside `releases/`, and each slot must hold a whole bundle:

```bash
ls -la /opt/clubbar-terminal      # current -> releases/v1.0.8, nothing loose
ls releases/$V                    # clubbar_terminal  data  lib  scripts
ls releases/$OLD                  # clubbar_terminal  data  lib  …
```

Then edit the unit's `ExecStart` to gain the one path segment (§4),
`systemctl --user daemon-reload`, and start it again.

Three things confirm the conversion, in order — do not install the update timer
(§4) until all three hold:

1. The screen shows the new version.
2. `cat ~/.local/share/de.clubbar.clubbar_terminal/status.json` exists, within a
   sync interval of startup, with `"unsynced_transactions": 0`. This is the file
   the old build could not write, and the whole reason the first slot went in by
   hand. Its `app_version` is written by the app while the slot name comes from
   the symlink, so the two disagreeing is an early warning that something was
   installed into the wrong slot.
3. The admin panel's Terminals page moves this terminal from *unknown* to
   *current* on that same sync — the first live proof that `X-Terminal-Version`
   reaches the backend.

`clubbar-update.sh --status` then reports the new version installed, nothing
pinned and nothing blocked. `previous` reads `(none)`, which is correct: the
updater writes it when it swaps, and the slot you kept above is not wired as one.

---

## 2. Disable the on-screen keyboard

Raspberry Pi OS ships with **squeekboard**, a Wayland on-screen
keyboard that pops up automatically when a text field is focused. The Club Bar
terminal is a point-and-click kiosk — the setup screen is filled in once during
commissioning using a physical keyboard, so the on-screen keyboard is never
needed and just gets in the way.

Disable squeekboard by hiding its autostart entry:

```bash
sudo mv /etc/xdg/autostart/squeekboard.desktop \
        /etc/xdg/autostart/squeekboard.desktop.bak
```

Log out and back in (or reboot) for it to take effect.

> **Older Raspberry Pi OS (Bullseye / X11):** The on-screen keyboard may be
> `matchbox-keyboard` or `onboard` instead. Check `ls /etc/xdg/autostart/` and
> rename the relevant `.desktop` file.

---

## 3. Screen blanking

The terminal blanks its own screen after a spell with no input, and wakes on the
next touch or card scan. This is a feature of the app — there is **no helper
script, no `input` group grant and no GTK/PyGObject dependency** to install.

### Real power management, not a black window

Where possible the terminal powers the **panel** down rather than painting it
black. An LCD showing black pixels still has its backlight on, so covering the
screen saves no power and no heat; putting the output to sleep does both.

**Powering the output off is the only thing that saves anything, and this is
worth knowing before designing a gentler alternative.** Everything a Pi can
plausibly do to a display was probed on the terminal hardware:

| Mechanism | Result on the terminal Pi | Saves power? |
|---|---|---|
| `/sys/class/backlight` | **Absent.** No backlight device exists — there is no brightness to turn down | — |
| DDC/CI brightness (VCP `0x10`) | **Refused.** `ddcutil` reads the EDID (`RTK CX101`) but the panel does not answer at I2C `0x37`: *"This monitor does not support DDC/CI"* | — |
| `vcgencmd set_backlight` | **`Invalid arguments`.** It drives the DSI panel connector, not HDMI | — |
| `zwlr_gamma_control_manager_v1` | **Available** — labwc advertises it, `gammastep` speaks it | **No.** It scales pixel values; the backlight stays at 100% |
| A translucent overlay drawn by the app | Always available, no dependencies | **No.** Same reason |
| `zwlr_output_power_manager_v1` (`output-power`) | Working | **Yes** — the panel enters standby |

So a "dim" phase on this hardware can only ever be cosmetic: it can make an
idle terminal *look* idle, and — unlike `output-power` — it leaves the
touchscreen awake (see below), but it draws the same watts as a terminal in
use. Anything that claims to dim for power reasons needs a panel with a real
backlight control, not a software change.

> Do not install `gammastep` on a terminal Pi to try this. There is no `arm64`
> build, and installing it removed 397 packages including the desktop — see
> [Installing packages on a terminal Pi](#installing-packages-on-a-terminal-pi).

This is done through Wayland's `zwlr_output_power_management_v1`, via `wlopm`.
Earlier versions of this guide said DPMS did not work on cheap touchscreens —
that was true of **X11 `xset dpms`**. Under Wayland/labwc the compositor
performs a real atomic modeset: measured on a Pi 4B, the DRM connector goes to
`dpms=Off`, `enabled=disabled`, CRTC `active=0`, the Pi stops driving HDMI, and
the panel sleeps.

Find your output's name:

```bash
wlopm
# HDMI-A-1 on
```

Confirm your panel honours it before enabling the mode:

```bash
wlopm --off HDMI-A-1 && sleep 5 && wlopm --on HDMI-A-1
```

The screen should go dark and come back.

### A sleeping panel takes its touchscreen with it

**On the terminal Pi, `output-power` means the screen wakes on a card and not
on a touch.** This is expected behaviour, not a fault, and it is the one thing
about this mode worth knowing before somebody reports it as a broken till.

The touchscreen is a separate USB device, so it is tempting to conclude that
sleeping the display leaves it alone. Measured, that is false: with the panel in
standby the digitizer **stops emitting events entirely**. It stays enumerated
the whole time — `lsusb` lists it, both nodes stay in
`/proc/bus/input/devices`, `/dev/input/event*` persists — so every check short
of reading the device says it is fine. The controller is bonded to the panel and
simply stops scanning while the panel sleeps.

The RFID reader is its own USB device and is unaffected, which is why the
terminal still wakes on a card — and why a member presenting a chip, the normal
way this terminal is approached, never notices. Staff walking up to touch it
will, so it is worth telling them.

Measure it on your own panel rather than assuming either way. With the screen
blanked, from SSH:

```bash
# Find the touchscreen's event node
grep -A5 'Name="TSTP MTouch"' /proc/bus/input/devices   # your name will differ

# Blank the screen, then touch it repeatedly for ~15 s
timeout 20 cat /dev/input/event9 | wc -c
```

Bytes counted means the digitizer survives standby and touch will wake the
terminal. **Zero means it does not** — the terminal is then card-wake only,
unless you give up the power saving and switch to `"mode": "overlay"` (see
below). Run the same command with the screen on as a control; a non-zero count
there proves the test itself works.

> Do not substitute a synthetic `uinput` device for a finger here. It bypasses
> the digitizer, so it wakes the screen in every mode and will tell you the
> hardware is fine when it is not.

**Wake needs the app to be the window in front.** The compositor hands the card
scan and the touch to whichever surface has focus, and the terminal powers the
panel back on only when it sees that input itself. A desktop panel or a
file-manager desktop window takes both, and the terminal then stays dark with
its output switched on — which reads as a crash and is not one. Strip the
session down before relying on blanking: see [The session must hold exactly one
window](#the-session-must-hold-exactly-one-window).

### Configure it

In `config.json`:

```json
"screenBlanking": {
  "enabled": true,
  "timeoutSeconds": 300,
  "mode": "output-power",
  "output": "HDMI-A-1"
}
```

| Key | Default | Meaning |
|---|---|---|
| `enabled` | `false` | Off by default, like `fullscreen` — a kiosk wants blanking, a development machine does not |
| `timeoutSeconds` | `300` | Idle time before blanking. **Production terminals use `3600`** — an hour of genuine idleness means a closed bar, not a lull, so the panel sleeps when it should and nobody meets the wake delay during service |
| `mode` | `output-power` | `output-power` sleeps the panel; `overlay` only paints black. Under `output-power` the panel's touchscreen may sleep too — see above |
| `output` | — | Wayland output name. **Required for `output-power`**; without it the terminal paints black rather than guessing at a name |

Environment overrides: `TERMINAL_SCREEN_BLANKING`,
`TERMINAL_SCREEN_BLANKING_TIMEOUT`, `TERMINAL_SCREEN_BLANKING_MODE`,
`TERMINAL_SCREEN_BLANKING_OUTPUT`.

### When to choose `overlay`

`"mode": "overlay"` paints a black surface and leaves the output alone. Blanking
still works; the backlight stays on, so the power and heat saving is what you
give up. Choose it when either is true:

- **You need wake on touch and the digitizer sleeps with the panel** (above).
  The terminal Pi keeps `output-power` and accepts card-only wake, since a
  member arrives with a chip in hand; a terminal that is mostly operated by
  staff tapping the screen would trade the other way.
- **The panel ignores signal loss** and sits on a "no signal" message instead of
  sleeping.

Wake is also instant in this mode: there is no modeset, so none of the panel's
HDMI re-lock, and no mode-change OSD from the monitor.

Either way the terminal paints black as well as powering down, so the touch that
wakes the screen never lands on a button underneath.

---

## 4. Autostart and supervision

`~/.config/autostart/clubbar-terminal.desktop` launching the binary directly has
no crash recovery: if `clubbar_terminal` dies, the bar has a dead screen until
somebody notices. The `.desktop` entry is kept — its timing (after the
compositor) is what makes it work — but it now only hands off to a supervised
user unit:

```ini
# ~/.config/autostart/clubbar-terminal.desktop
Exec=systemctl --user start clubbar-terminal.service
```

```ini
# ~/.config/systemd/user/clubbar-terminal.service
[Unit]
Description=Club Bar Terminal (kiosk POS)
After=graphical-session.target
StartLimitIntervalSec=0          # never give up; a dead till cannot sell

[Service]
Type=simple
Environment=GST_AUDIO_SINK=alsasink
ExecStart=/opt/clubbar-terminal/current/clubbar_terminal
Restart=always
RestartSec=3
```

`current/` is the only thing ADR-0054 changed in this unit, and that is
deliberate: the updater swaps a symlink and **never rewrites this file**. Any
per-Pi customisation here — the `GST_DEBUG` variant in
[audio-dropout-debugging.md](docs/audio-dropout-debugging.md), a different
sink, an added environment variable — therefore survives every update for free,
which it would not if the unit were templated and reinstalled.

`StartLimitIntervalSec=0` stays, and the update design is built around it.
Rate limiting is off on purpose — a till that gave up is a till that cannot
sell — so a **restart count can never be a health signal**: a terminal
crash-looping forever never trips a limit that is switched off. That is why the
updater's watchdog reads the app's heartbeat instead (below).

`graphical-session.target` is inactive under labwc, so the unit is *not* hung
off it — the `.desktop` entry is the trigger, and the user manager already has
`WAYLAND_DISPLAY` and `XDG_RUNTIME_DIR` imported, which the service inherits.

Verify by killing it:

```bash
kill -9 $(systemctl --user show -p MainPID --value clubbar-terminal.service)
sleep 8 && systemctl --user show -p NRestarts --value clubbar-terminal.service   # 1
```

### Unattended updates

A terminal runs **exactly the version its backend reports** at `/api/health`
([ADR-0054](../adr/0054-terminal-runs-its-backends-version.md), [#318](https://github.com/dgloeckner/clubbar/issues/318)).
Upgrading the backend is the single act that moves terminals: there is no
release to bless, no channel to pick, and nothing to decide per terminal.

`clubbar-update.sh` ships *inside* the bundle, so each release brings its own
updater — and so do `kiosk-doctor.sh` and `audio-diagnose.sh`, which is why
their documented paths now run through `current/`. A diagnostic that has
drifted from the build it diagnoses is worse than no diagnostic.

Install the timer **as a user unit**, not a system one:

```bash
mkdir -p ~/.config/systemd/user
cp /opt/clubbar-terminal/current/scripts/systemd/clubbar-update.* ~/.config/systemd/user/
systemctl --user daemon-reload
systemctl --user enable --now clubbar-update.timer

# A terminal is normally logged in, but the timer must fire at 04:00 whether or
# not anybody is. Lingering is what keeps the user manager running.
sudo loginctl enable-linger "$USER"
```

**Why a user timer.** The app is a `--user` service with `WAYLAND_DISPLAY` and
`XDG_RUNTIME_DIR` imported from its login session. A root system timer has no
session bus and cannot `systemctl --user restart` it without
`--machine=<user>@.host` — and every other path the updater writes (the slots
under `/opt/clubbar-terminal`, the sqlite backup under `~/.local/share`) belongs
to the kiosk user too. Running as anyone else turns one ownership question into
three, and getting the database's owner wrong once means the app cannot reopen
it.

Verify without waiting for 04:00:

```bash
/opt/clubbar-terminal/current/scripts/clubbar-update.sh --status   # what is installed, pinned, blocked
/opt/clubbar-terminal/current/scripts/clubbar-update.sh --check    # what tonight would do; changes nothing
systemctl --user list-timers clubbar-update.timer
journalctl --user -u clubbar-update.service -n 50
```

**What a run does, and what stops it.** Five conditions mean "no update, try
again tomorrow", and none of them is an error: the backend is unreachable; it
reports something that is not a release tag (`dev`, `dev-<sha>` — a club
deployed from git never auto-updates its terminals); the release has no bundle
for this architecture or no `.sha256`; this terminal is holding sales that have
not synced; or the tag is pinned, opted out, or already blocked. A terminal that
skips a night has lost nothing.

It also **never downgrades**. A backend rolled back to an older tag leaves the
terminal ahead; the updater logs a warning and leaves it, because moving
backwards means running a sqlite migration backwards.

After the swap the app has five minutes to write its heartbeat — the file it
touches once a sync round-trip succeeds. If it does not, `current` goes back,
the pre-update database is restored, the tag is written to `blocked`, and the
app reports it in `X-Terminal-Blocked-Version` so the admin panel's Terminals
page shows *Stuck at &lt;tag&gt;*. That terminal then updates no further until a
newer release ships — see the [runbook](../docs/runbook-terminal-pi.md) for
clearing it.

**The 04:00 window is load-bearing, not a default.** It is what makes the
rollback safe: the run happens on an idle, synced terminal, so a version that
never got healthy booked nothing and restoring the pre-update database loses no
sale. A faster cadence deletes that premise — an update could land at 14:00 on a
Saturday, restart the till mid-service, and then restore a backup taken minutes
earlier, discarding everything rung up in between. The unsynced-transactions
refusal narrows that window but does not close it: a member can tap a card
between the check and the swap.

So a faster cadence is a **development override**, and deliberately *not* a
`config.json` key. A systemd drop-in is a file somebody had to sit down and
create; a configuration key is one an operator copies out of a forum post
without reading the paragraph above it.

**Pinning and opting out** are two keys in `config.json` (§8):

```json
{
  "updateEnabled": false,
  "updatePin": "v1.0.6"
}
```

### Fullscreen / kiosk mode

To run the app fullscreen (recommended for production kiosk deployments), add
`"fullscreen": true` to the terminal's `config.json` (see
[Configuration reference](#8-configuration-reference) for the full schema):

```json
{
  "terminalId": "Club Bar-Kühlschrank",
  "apiUrl": "https://club.example.com/api",
  "apiToken": "a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2",
  "fullscreen": true
}
```

Environment variables can also be set on the service (see §4), but
`config.json` is the normal route.

> **Development machines:** Leave `fullscreen` unset (defaults to `false`) so
> the app opens in a normal window. Only set it on deployed kiosk hardware.

### The session must hold exactly one window

Raspberry Pi OS's labwc session autostarts a panel and a desktop, and both are
actively harmful on a till:

```
# /etc/xdg/labwc/autostart — as shipped
/usr/bin/lwrespawn /usr/bin/pcmanfm-pi &
/usr/bin/lwrespawn /usr/bin/wf-panel-pi &
/usr/bin/kanshi &
/usr/bin/lxsession-xdg-autostart
```

**`pcmanfm-pi` takes keyboard focus.** Powering the output down makes the
compositor re-add the output on wake, and focus does not necessarily return to
the app. Once the desktop holds it, card scans go to *its* type-ahead find box
instead of the terminal — so the terminal never sees the keystroke that lifts
blanking, and stays black with the panel lit. The symptom is unmistakable once
you know it: a black screen with a small white text box in the top-right corner
filling up with the characters of a member's card UID.

**`wf-panel-pi` is remapped by `lwrespawn` every time it dies.** A layer-shell
panel mapped *after* the terminal's fullscreen window sits above it, so the menu
bar reappears mid-service and swallows touches along the top strip. This is the
same z-order failure #762 describes for the overlay that #763 removed, arriving
from the desktop session instead.

**`scripts/kiosk-session-setup.sh` does this and the mode pinning below**, and
is idempotent, so it is also the right thing to re-run after a system upgrade:

```bash
sudo ./scripts/kiosk-session-setup.sh --now   # --now also stops what is running
./scripts/kiosk-session-setup.sh --check      # report only, changes nothing
```

By hand, if you would rather see the edit:

```bash
sudo cp -n /etc/xdg/labwc/autostart /etc/xdg/labwc/autostart.orig
sudo sed -i -E 's|^(/usr/bin/lwrespawn /usr/bin/(pcmanfm-pi\|wf-panel-pi).*)$|# \1|' \
  /etc/xdg/labwc/autostart
```

> **Edit the packaged file, not a user copy.** The session runs `labwc -m`
> (`--merge-config`), so `~/.config/labwc/autostart` is executed *in addition
> to* `/etc/xdg/labwc/autostart`, not instead of it. A user-level override
> duplicates every remaining entry and brings the panel back at the next boot.

Verify — the count must be `0`, and it is worth re-checking after any system
upgrade, since apt can restore the packaged file:

```bash
ps -eo cmd | grep -cE '[w]f-panel-pi|[p]cmanfm'
./scripts/kiosk-doctor.sh          # or this, which checks it and everything else
```

Recovery is unaffected: Ctrl+Alt+F2 still reaches a console and Alt+F4 still
closes the app for its unit to restart, as
[the runbook](../docs/runbook-terminal-pi.md) describes.

### Pin the output mode

Raspberry Pi OS ships kanshi with a **zero-byte** `~/.config/kanshi/config` and
the profile it should contain sitting unused beside it as `config.init`. With
nothing pinning the mode, the output that blanking powers down comes back at
whatever mode is negotiated at that moment rather than the panel's preferred
one. The app logs the mismatch —

```
Timed out waiting for OpenGL frame of size 1920x1080 (have 1280x800)
```

— and the monitor announces the mode change with its own OSD on every single
wake. Put the profile into effect:

```bash
cp ~/.config/kanshi/config.init ~/.config/kanshi/config
cat ~/.config/kanshi/config
# profile {
#     output HDMI-A-1 enable scale 1.000000 mode 1280x800@59.996 position 0,0 transform normal
# }
```

This build of kanshi has no reload command and ignores `SIGHUP`; it is started
from the labwc autostart, so a session restart applies it. To apply without one:

```bash
pkill -x kanshi && setsid nohup /usr/bin/kanshi >/dev/null 2>&1 &
```

With the session stripped and the mode pinned, the app draws the idle screen
**0.91 s** after the input that wakes it (measured on the terminal Pi over
repeated cycles, timing a `grim` capture of the compositor's own buffer). What
you see beyond that is the panel re-locking the HDMI signal, which is inherent
to `output-power` and is the price of putting it into standby. If that wait
matters more than the power saving, `"mode": "overlay"` has no modeset and
therefore no such wait.

---

## 5. Network: two SSIDs that persist

> Sections 5–7 harden the machine rather than configure the app. They are what
> stand between a terminal that recovers on its own and one that strands
> itself — see §1 of the runbook for the failure each prevents.

Run once per terminal. Priority decides which wins when both are in range —
the Verein network should always outrank the maintenance one.

Use `sudo nmtui` if you would rather not put the PSK on a command line — the
commands below land it in shell history.

```bash
VEREIN_SSID="<verein-ssid>"        # where the terminal lives
MAINT_SSID="<maintenance-ssid>"    # home/workshop, for provisioning and repair

sudo nmcli device wifi connect "$VEREIN_SSID" password "<psk>"
sudo nmcli device wifi connect "$MAINT_SSID"  password "<psk>"

for c in "$VEREIN_SSID:100" "$MAINT_SSID:50"; do
  n="${c%:*}"; p="${c#*:}"
  sudo nmcli connection modify "$n" \
    connection.autoconnect yes \
    connection.autoconnect-priority "$p" \
    connection.autoconnect-retries 0 \
    802-11-wireless.powersave 2 \
    802-11-wireless.cloned-mac-address permanent \
    ipv4.dhcp-timeout 60
done
```

What each setting buys:

- **`autoconnect-retries 0`** — retry forever. This is the single most important
  line; it is what prevents the wedged state described above.
- **`powersave 2`** — disabled (the numbering is unintuitive: `2` off, `3` on).
- **`cloned-mac-address permanent`** — no MAC randomisation, so DHCP
  reservations on the router keep working.
- **`autoconnect-priority`** — higher wins.

Verify:

```bash
nmcli -f NAME,AUTOCONNECT,AUTOCONNECT-PRIORITY connection show
sudo ls -l /etc/NetworkManager/system-connections/   # one file per SSID, mode 600
```

### Wifi watchdog

NetworkManager retrying forever covers most failures, but a wedged supplicant
can leave NM believing the link is fine. The watchdog only acts when genuinely
disconnected, so it never disturbs a healthy link.

```bash
sudo tee /usr/local/sbin/wifi-watchdog.sh >/dev/null <<'EOF'
#!/bin/bash
set -u
state=$(nmcli -t -f STATE general 2>/dev/null)
[ "$state" = "connected" ] && exit 0

logger -t wifi-watchdog "state=$state - attempting recovery"
nmcli device wifi rescan >/dev/null 2>&1
sleep 5

# Try known wifi profiles in descending autoconnect-priority order. Derived
# from NetworkManager rather than hardcoded, so adding or renaming an SSID
# needs no edit here.
while IFS=: read -r prio name; do
  [ -n "$name" ] || continue
  if nmcli connection up "$name" >/dev/null 2>&1; then
    logger -t wifi-watchdog "reconnected via $name"
    exit 0
  fi
done < <(nmcli -t -f NAME,TYPE,AUTOCONNECT-PRIORITY connection show \
         | awk -F: '$2 == "802-11-wireless" { print $3 ":" $1 }' | sort -rn)

logger -t wifi-watchdog "recovery failed - no known SSID in range"
EOF
sudo chmod +x /usr/local/sbin/wifi-watchdog.sh
```

```ini
# /etc/systemd/system/wifi-watchdog.service
[Unit]
Description=Club Bar wifi watchdog
After=NetworkManager.service
[Service]
Type=oneshot
ExecStart=/usr/local/sbin/wifi-watchdog.sh
```

```ini
# /etc/systemd/system/wifi-watchdog.timer
[Unit]
Description=Run wifi watchdog every 3 minutes
[Timer]
OnBootSec=2min
OnUnitActiveSec=3min
[Install]
WantedBy=timers.target
```

```bash
sudo systemctl daemon-reload && sudo systemctl enable --now wifi-watchdog.timer
journalctl -t wifi-watchdog --no-pager | tail
```

---

## 6. Clock — install `fake-hwclock`

**A Pi has no RTC.** Without `fake-hwclock`, systemd falls back at boot to the
newest timestamp it can find on disk — typically the build date or the mtime of
`/var/lib/systemd/timesync/clock` — so the clock resumes at some *earlier*
moment and is only corrected once NTP is reachable. On the reference unit this produced
a boot that believed it was 11:44 for 23 minutes, then jumped forward 4h49m the
instant timesyncd reached a server.

```bash
sudo apt-get install -y fake-hwclock
systemctl is-enabled fake-hwclock-load fake-hwclock-save.timer   # both: enabled
```

`fake-hwclock.service` showing `masked` is normal — it is the SysV compat shim,
masked in favour of the native `-load` / `-save` units.

**This is not cosmetic on a POS.** The terminal stamps `created_at` on every
transaction locally. A sale rung up before NTP syncs carries a wrong timestamp
into the sync payload, and the backend's delta sync is timestamp-driven
(ADR-0033). A terminal that is offline — the exact situation where the clock is
never corrected — is also the situation where sales queue locally the longest.
For a terminal handling real money, consider a DS3231 RTC HAT (~5 EUR, I2C);
it removes the failure mode rather than shortening it.

---

## 7. Hardware watchdog and journal retention

A Pi has `/dev/watchdog`; systemd does not use it unless told to. Without it a
hard kernel hang means an unreachable terminal until someone power-cycles it.

```ini
# /etc/systemd/system.conf.d/watchdog.conf
[Manager]
RuntimeWatchdogSec=15s
RebootWatchdogSec=2min
```

### Journal retention

Default journald kept only 2 boots on the reference unit, which is why the original
failure left no evidence. Set it explicitly:

```ini
# /etc/systemd/journald.conf.d/retention.conf
[Journal]
Storage=persistent
SystemMaxUse=200M
MaxRetentionSec=6month
```

---

## 8. Configuration reference

All runtime configuration lives in a single `config.json` file. The location is
platform-specific and resolved automatically by the app:

| Platform | Path |
|----------|------|
| Linux | `~/.local/share/de.clubbar.clubbar_terminal/config.json` |
| macOS | `~/Library/Containers/de.clubbar.clubbarTerminal/Data/Library/Application Support/de.clubbar.clubbarTerminal/config.json` |
| Windows | `%APPDATA%\de.clubbar.clubbar_terminal\config.json` |

Every key is optional except `terminalId`, `apiUrl`, and `apiToken` (required
for the app to connect). Omitted keys fall back to the defaults shown below.

```json
{
  "terminalId": "Club Bar-Kühlschrank",
  "apiUrl": "https://club.example.com/api",
  "apiToken": "a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2",

  "fullscreen": false,
  "soundsEnabled": true,
  "seedTestData": false,
  "demoMode": false,
  "displayName": "Club Bar",

  "fontSizes": {
    "xs":      13,
    "sm":      14,
    "base":    16,
    "lg":      18,
    "xl":      20,
    "xxl":     22,
    "xxxl":    26,
    "display": 55
  },

  "dispenser": {
    "enabled":        false,
    "baseUrl":        "http://dispenser.local",
    "apiKey":         "your-dispenser-api-key",
    "timeoutMs":      3000,
    "pollIntervalMs": 250
  },

  "rfidReader": {
    "monitor":             true,
    "vendorId":            "ffff",
    "productId":           "0035",
    "namePattern":         "USB Reader",
    "pollIntervalSeconds": 5
  }
}
```

### Key descriptions

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `terminalId` | string | — | Human-readable terminal name (shown in admin panel). Alphanumeric, hyphens, underscores, spaces; 1–50 chars. |
| `apiUrl` | string | — | Base URL of the Club Bar backend API, e.g. `https://club.example.com/api`. No trailing slash. |
| `apiToken` | string | — | 64-character hex device token generated in the Admin Panel under *Terminals*. Stored with `chmod 600`. |
| `fullscreen` | bool | `false` | Run the app fullscreen / kiosk mode on startup. Recommended for production deployments. |
| `soundsEnabled` | bool | `true` | Enable audio feedback sounds. Natural/warm UI sounds at key interactions. Set `false` for a silent deployment. |
| `seedTestData` | bool | `false` | Pre-populate the local database with mock members, categories, and products. **Development only — never enable in production.** |
| `demoMode` | bool | `false` | Enable demo mode for showcasing the terminal without a backend connection. **Development only.** |
| `displayName` | string | `"Club Bar"` | The club's name, shown in the terminal header. |
| `updateEnabled` | bool | `true` | Whether the nightly updater may install the version the backend reports (§4, [ADR-0054](../adr/0054-terminal-runs-its-backends-version.md)). `false` opts this terminal out entirely; it then stays wherever it was put by hand. |
| `updatePin` | string | — | Hold this terminal at one release, e.g. `v1.0.6`. The updater installs the backend's version only while it equals the pin, and refuses everything else. Remove the key to follow the backend again. |
| `fontSizes.xs` | number | `13` | Font size in logical pixels for the `xs` scale step. |
| `fontSizes.sm` | number | `14` | Font size for the `sm` scale step. |
| `fontSizes.base` | number | `16` | Base body font size. Used for balance display, labels, and secondary text. |
| `fontSizes.lg` | number | `18` | Large font size — product names, member name in cart, button labels. |
| `fontSizes.xl` | number | `20` | Extra-large — quantity badges, line totals, "Neuer Kontostand" in cart. |
| `fontSizes.xxl` | number | `22` | Used for the "Gesamt" label in the cart footer. |
| `fontSizes.xxxl` | number | `26` | Checkout confirmation title. |
| `fontSizes.display` | number | `55` | Idle screen headline ("Durstig?" / reader-offline title) — the one display-size string in the app. |
| `dispenser.enabled` | bool | `false` | Enable the sauna token dispenser integration. |
| `dispenser.baseUrl` | string | — | Base URL of the dispenser hardware API, e.g. `http://192.168.1.50`. |
| `dispenser.apiKey` | string | — | API key for authenticating with the dispenser. |
| `dispenser.timeoutMs` | integer | `3000` | HTTP request timeout for dispenser calls in milliseconds. |
| `dispenser.pollIntervalMs` | integer | `250` | Polling interval when waiting for a dispense result in milliseconds. |
| `rfidReader.monitor` | bool | `true` | Watch whether the RFID reader is still plugged in. Has no effect until the reader is described by at least one of the three keys below. |
| `rfidReader.vendorId` | string | — | USB vendor id of the reader, e.g. `ffff`. Case and a `0x` prefix are ignored. |
| `rfidReader.productId` | string | — | USB product id of the reader, e.g. `0035`. |
| `rfidReader.namePattern` | string | — | Case-insensitive substring of the reader's device name, e.g. `USB Reader`. |
| `rfidReader.pollIntervalSeconds` | integer | `5` | How often the reader's presence is checked. This interval *is* the detection delay — keep it well under a minute. |

### Reader health monitoring

An unplugged or dead reader is silent: the idle screen would keep inviting
scans that can never arrive. Tell the terminal what its reader looks like and
it will notice within `rfidReader.pollIntervalSeconds`, show a red
*Scanner fehlt* pill in the header and replace the idle screen's invitation
with "Scanner nicht verbunden — bitte Personal informieren". Plugging the
reader back in clears both, with no restart.

Find the ids and the name of the connected reader on the kiosk:

```bash
cat /proc/bus/input/devices
```

Look for the block whose `Handlers=` line contains `kbd` and whose name is the
reader, e.g.:

```
I: Bus=0003 Vendor=ffff Product=0035 Version=0100
N: Name="Sycreader RFID Technology Co., Ltd USB Reader"
H: Handlers=sysrq kbd event3
```

Any combination of `vendorId`, `productId` and `namePattern` may be given; all
of the ones present must match, so a name alone is enough when it is
distinctive. Configure nothing and monitoring stays off — the terminal shows no
reader status rather than one it cannot back up. The same applies on a platform
without `/proc/bus/input/devices` (macOS, Windows): presence is reported as
unknown and the UI is unchanged.

### What the terminal saw of a card tap

A chip that is held to the reader and produces *nothing* — no beep, no error,
no spinner — has failed somewhere between the USB device and the member lookup,
and every one of those steps used to be silent (issue #370). The terminal now
keeps the last card taps and what it made of each, in two places:

- **Status modal → Letzte Chip-Erkennungen** — the last few, newest first.
  Taps that were lost without any feedback are red. Read these out when
  reporting the problem.
- **`error.log`** — the lost ones are written there as `scan: <kind> …`, so
  they survive the session. (The kiosk starts from a `.desktop` entry, so
  stdout goes nowhere; this file is the durable record.)

What the kinds mean, and what to do:

| Line | What happened | What to do |
|------|---------------|------------|
| `uidCaptured <uid> …` | The reader typed a full UID and the terminal took it. Any failure after this one has a message on screen. | — |
| `rejected <uid> unknownCard` | The UID reached the lookup and the local member cache does not have it. | Check the chip is registered to a member in the admin panel, and that the terminal has synced since (status modal → *Letzte Synchronisation*) |
| `partialDiscarded <n> chars, no terminator` | Characters arrived and the closing Enter never did. | Configure the reader to send Enter after the UID; check the cable |
| `unprintableKey hid 0x7005c …` | The reader pressed a key that carries no character. HID usages `0x7005…` are the numeric keypad — a reader typing there with **NumLock off** sends navigation keys, not digits. | Turn NumLock on, or switch the reader to the main key block |
| `modifierSuppressed …` | Keystrokes were ignored because Ctrl/Alt/Meta was held. A modifier the compositor believes is still down silences the reader completely. | Press and release the stuck modifier on an attached keyboard, or restart the session |
| `droppedBusy <uid>` | A tap landed while the previous one was still being resolved. | Harmless in isolation; a run of them means lookups are slow — check the backend connection |
| `emptyTerminator …` | An Enter arrived with nothing buffered. | Usually a stray keypress; a run of them means the reader sends its terminator twice |
| `captureNotReady <uid>` | A card was read before the app finished starting. | Tap again |

> **The `fontSizes` defaults changed in #41.** They used to be phone-sized
> (base `14`), which is a poor fit for a 7″ panel read standing up in bar
> lighting; every step is now roughly 12 % larger. Nothing else needs
> adjusting — the product grid sizes its tiles from this scale, so larger type
> gets taller tiles rather than clipped ones. To go back to the old scale, or
> further up for a large-print deployment, set the steps explicitly.

### Environment variable overrides

All config file values can be overridden via environment variables — useful
for CI, Docker deployments, or `.desktop` file `Exec=env ...` lines:

| Variable | Overrides |
|----------|-----------|
| `TERMINAL_ID` | `terminalId` |
| `TERMINAL_API_URL` | `apiUrl` |
| `TERMINAL_API_TOKEN` | `apiToken` |
| `TERMINAL_FULLSCREEN` | `fullscreen` (`true`/`false`) |
| `TERMINAL_SOUNDS_ENABLED` | `soundsEnabled` (`true`/`false`) |
| `TERMINAL_SEED_TEST_DATA` | `seedTestData` (`true`/`false`) |
| `TERMINAL_DEMO_MODE` | `demoMode` (`true`/`false`) |
| `DISPENSER_ENABLED` | `dispenser.enabled` |
| `DISPENSER_BASE_URL` | `dispenser.baseUrl` |
| `DISPENSER_API_KEY` | `dispenser.apiKey` |
| `RFID_READER_MONITOR` | `rfidReader.monitor` (`true`/`false`) |
| `RFID_READER_VENDOR_ID` | `rfidReader.vendorId` |
| `RFID_READER_PRODUCT_ID` | `rfidReader.productId` |
| `RFID_READER_NAME_PATTERN` | `rfidReader.namePattern` |
| `RFID_READER_POLL_INTERVAL_SECONDS` | `rfidReader.pollIntervalSeconds` |

> **Note:** `fontSizes` cannot be set via environment variables — use the
> config file.

---

## 9. First-time setup

The app requires a valid `config.json` before it will start. If the file is
missing or incomplete the app prints the expected path to stderr and exits
with code 1 — it will not launch into the UI.

Create the config file at the path printed in the error message (see
[Configuration reference](#8-configuration-reference)) with at least the
three required fields:

```json
{
  "terminalId": "Club Bar-Kühlschrank",
  "apiUrl":     "https://club.example.com/api",
  "apiToken":   "<64-char hex token from admin panel>"
}
```

Generate the API token in the Admin Panel under *Terminals*, then copy it
into the config file. On the next launch the app syncs the member and product
database and opens the idle RFID scanning screen.

---

## 10. Optional: Token Dispenser

Club Bar can integrate with a physical token dispenser for venues that use
coin-operated equipment (saunas, laundromats, arcades). The dispenser is an
**ESP8266 microcontroller** (Wemos D1 Mini) driving an **Azkoyen Hopper U-II**
industrial token dispenser. See the
[remote-token-dispenser](https://github.com/dgloeckner/remote-token-dispenser)
repository for firmware, hardware assembly guide, and schematics.

### How it works

```
┌──────────────┐   HTTP/REST   ┌──────────────┐   GPIO    ┌──────────────┐
│   Club Bar   │──────────────▶│   ESP8266    │─────────▶│   Azkoyen    │
│   Terminal   │◀──────────────│  (Wemos D1)  │◀─────────│  Hopper U-II │
│  (Raspberry  │  local WiFi   │              │  opto-   │              │
│     Pi)      │               │              │  coupled │              │
└──────────────┘               └──────────────┘          └──────────────┘
```

1. Member taps RFID card and selects a product flagged with `requires_dispenser`
2. Terminal sends `POST /dispense` to the ESP8266 with a transaction ID and
   quantity
3. ESP8266 activates the hopper motor; optical sensor counts dispensed tokens
4. Terminal polls `GET /dispense/:txId` until the operation completes
5. Transaction is recorded locally only after tokens are physically dispensed

Key properties:
- **Dispense-first, pay-after** — tokens dispense before the transaction is
  recorded, eliminating refund complexity
- **Crash-resilient** — ESP8266 persists state to flash memory; survives power
  loss mid-transaction with exact token counts
- **Idempotent** — client-generated transaction IDs prevent double-dispensing
- **Jam detection** — watchdog timer halts the motor if no token pulse arrives
  within 5 seconds

### Hardware setup

Follow the [assembly guide](https://github.com/dgloeckner/remote-token-dispenser/tree/main/hardware)
in the remote-token-dispenser repository. Key requirements:

- **Azkoyen Hopper U-II** set to **NEGATIVE mode** (active-LOW control signals)
- **4x PC817 optocoupler modules** for galvanic isolation between ESP8266 and
  hopper (3.3V logic ↔ 12V motor)
- **12V DC power supply**, 2A minimum
- **Hardware mod**: Add a 330 Ohm resistor in parallel with the stock 1k Ohm resistor
  on each optocoupler for adequate drive current
- Flash the ESP8266 firmware from the
  [firmware/](https://github.com/dgloeckner/remote-token-dispenser/tree/main/firmware)
  directory

### Network setup

The ESP8266 runs an HTTP server on port 80. The terminal communicates with it
over local WiFi — no internet or cloud dependency required.

- Assign the ESP8266 a **static IP** or **mDNS hostname** (e.g.
  `dispenser.local`) on your local network
- Ensure the Raspberry Pi and ESP8266 are on the **same WiFi network / VLAN**
- The dispenser API requires an **API key** via `X-API-Key` header (configured
  in both the ESP8266 firmware and the terminal `config.json`)

### Terminal configuration

Enable the dispenser in the terminal's `config.json`:

```json
{
  "terminalId": "Club Bar-Kühlschrank",
  "apiUrl": "https://club.example.com/api",
  "apiToken": "...",
  "dispenser": {
    "enabled": true,
    "baseUrl": "http://dispenser.local",
    "apiKey": "your-dispenser-api-key",
    "timeoutMs": 3000,
    "pollIntervalMs": 250
  }
}
```

Or via environment variables:

```bash
DISPENSER_ENABLED=true
DISPENSER_BASE_URL=http://dispenser.local
DISPENSER_API_KEY=your-dispenser-api-key
```

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `dispenser.enabled` | bool | `false` | Enable the dispenser integration. When `false`, products with `requires_dispenser` are sold normally without dispensing. |
| `dispenser.baseUrl` | string | — | Base URL of the ESP8266 HTTP server, e.g. `http://192.168.1.50` or `http://dispenser.local`. |
| `dispenser.apiKey` | string | — | Shared secret for `X-API-Key` authentication. Must match the key configured in the ESP8266 firmware. |
| `dispenser.timeoutMs` | integer | `3000` | HTTP request timeout in milliseconds. Increase if the ESP8266 is on a slow network. |
| `dispenser.pollIntervalMs` | integer | `250` | How often (ms) to poll for dispense completion. Lower values give faster UI feedback but more network traffic. |

### Product setup

In the Admin Panel, mark products that require token dispensing:

1. Go to **Products** → edit or create a product
2. Enable the **Requires dispenser** checkbox
3. The product syncs to terminals on the next sync cycle

When a member purchases a `requires_dispenser` product and the terminal has
`dispenser.enabled: true`, the checkout flow triggers the dispense operation
before recording the transaction.

### Development without hardware

The remote-token-dispenser repository includes a
[Go-based mock server](https://github.com/dgloeckner/remote-token-dispenser/tree/main/dispenser-mock)
that simulates the ESP8266 API without any hardware:

```bash
# Clone and run the mock
git clone https://github.com/dgloeckner/remote-token-dispenser.git
cd remote-token-dispenser/dispenser-mock
go run .
```

Point the terminal's `dispenser.baseUrl` at `http://localhost:8080` (or
wherever the mock is running) to test the full checkout flow.

### Troubleshooting

| Symptom | Fix |
|---------|-----|
| "Dispenser offline" during checkout | Verify ESP8266 is powered and reachable: `curl http://dispenser.local/health` |
| Timeout errors | Increase `dispenser.timeoutMs`; check WiFi signal strength at the ESP8266 location |
| Double-dispensing | Should not happen (idempotent by design). Check that transaction IDs are unique in terminal logs |
| Tokens jam mid-dispense | Hopper's watchdog stops the motor automatically. Clear the jam, then retry — the ESP8266 resumes from the exact count |
| "Unauthorized" from dispenser | API key mismatch between `config.json` and ESP8266 firmware |

---

## Installing packages on a terminal Pi

**Never run `apt-get install -y` on a terminal.** Not with `-q`, and never with
the output piped into `tail`. On this hardware that combination is capable of
uninstalling the terminal.

Raspberry Pi OS 64-bit enables **`armhf` multiarch by default**
(`dpkg --print-foreign-architectures` prints `armhf`). When a package has no
`arm64` build, apt satisfies the request with the 32-bit one — and that pulls
32-bit `libc6`, `libglib2.0-0t64`, `libmount1` and friends, which conflict with
their 64-bit counterparts. Apt resolves the conflict the only way it can: by
**removing the 64-bit stack**. `-y` approves it, `-q` shortens the output, and a
pipe into `tail` hides what is left.

Measured on the terminal Pi on 2026-08-30, from `/var/log/apt/history.log`:

```
Commandline: apt-get install -y -q gammastep
Install: gammastep:armhf, libglib2.0-0t64:armhf, libc6:armhf, ...
Remove:  labwc, libwlroots-0.19, network-manager, polkitd, rpd-wayland-core,
         squeekboard, chromium, ...                        # 397 packages
```

The till was down for over an hour. `labwc` going is why it then boots to a text
console; `network-manager` going is why it has no network at all afterwards —
not even DHCP, because nothing is left to request a lease.

**The rule.** Simulate first, read the plan, and only then apply:

```bash
apt-cache policy <pkg>                              # ":armhf" is the warning sign
sudo apt-get -s install <pkg>                       # -s simulates; changes nothing
sudo apt-get -s install <pkg> 2>&1 | grep '^Remv'   # must print nothing
```

If anything is scheduled for removal, **stop**. Ask for the architecture
explicitly (`apt-get install <pkg>:arm64`); if no such build exists, do not
install that package on this machine.

### Recovering a terminal whose packages were removed

Reversible, because apt records exactly what it did and removal — unlike purge —
keeps `/etc`:

1. **Get in.** SSH still works with no IPv4 at all: the kernel configures IPv6
   by SLAAC without any client, so `ssh <user>@<host>` reaches the Pi over IPv6
   while every IPv4 ping and port scan fails. This is the most useful fact here.
2. **Give it IPv4 by hand** — apt mirrors need it, and nothing is managing the
   interface. Note the wired interface may be `end0`, not `eth0`:

   ```bash
   sudo ip addr add 192.168.1.90/24 dev eth0
   sudo ip route add default via 192.168.1.1
   sudo sh -c 'echo nameserver 192.168.1.1 > /etc/resolv.conf'
   ```
3. **Remove the intruder, then restore the list apt recorded:**

   ```bash
   sudo apt-get purge <pkg>:armhf
   sudo rm -rf /var/lib/apt/lists/* && sudo apt-get update

   sed -n '/^Start-Date: <timestamp>/,/^End-Date/p' /var/log/apt/history.log \
     | grep '^Remove:' | sed 's/^Remove: //' | tr ',' '\n' \
     | awk '{print $1}' | sed 's/:arm64//' | sort -u > /tmp/removed.txt

   sudo apt-get -s install $(cat /tmp/removed.txt | tr '\n' ' ') | grep '^Remv'
   sudo apt-get install -y -o Dpkg::Options::=--force-confold \
     $(cat /tmp/removed.txt | tr '\n' ' ')
   ```

   `--force-confold` preserves local edits to package-owned config files — on a
   kiosk, that is the edited `/etc/xdg/labwc/autostart`.
4. **Wifi returns on its own.** NetworkManager's profiles live in
   `/etc/NetworkManager/system-connections/` and survive removal with their PSKs
   intact, so the terminal re-associates as soon as the package is back. Confirm
   with `nmcli device status` rather than re-provisioning per §5.
5. Reboot, then confirm the session: panel and desktop still disabled, the app
   fullscreen, `systemctl --user is-active clubbar-terminal.service` → `active`.

---

## Troubleshooting

**`scripts/kiosk-doctor.sh` checks a live terminal against every rule in this
guide** — focus competitors, the pinned output mode, the app's unit, the reader
actually being present, and the development-only switches. It is read-only and
safe on a till that is serving; run it before reading the table below.

| Symptom | Fix |
|---------|-----|
| App exits immediately with "configuration missing" | Create `config.json` at the path shown in the error — see [First-time setup](#9-first-time-setup) |
| On-screen keyboard still appears | Check `ls /etc/xdg/autostart/` for other keyboard entries (e.g. `onboard.desktop`) and rename them |
| Screen never blanks | Check `screenBlanking.enabled` in `config.json` |
| Screen blanks but the panel stays lit | The panel ignores signal loss — set `"mode": "overlay"` |
| A card wakes the blanked screen but a touch does not | Expected under `"mode": "output-power"` on a panel whose digitizer sleeps with it — [confirm and decide](#a-sleeping-panel-takes-its-touchscreen-with-it) |
| Screen stays black after a scan, and a small white text box collects the card's characters top-right | The pcmanfm desktop holds keyboard focus, so the app never sees the scan that lifts blanking — [strip the session](#the-session-must-hold-exactly-one-window) |
| The desktop menu bar appears over the terminal mid-service | `lwrespawn` remapped `wf-panel-pi` above the fullscreen window — [strip the session](#the-session-must-hold-exactly-one-window); it also returns after a system upgrade restores the packaged autostart |
| Wake is slow and the monitor flashes its own mode OSD | The output comes back at the wrong mode — [pin it with kanshi](#pin-the-output-mode) |
| The Pi boots to a text console after installing something | An `apt-get install` removed `labwc` — see [Installing packages on a terminal Pi](#installing-packages-on-a-terminal-pi) |
| No network at all after installing something, `nmcli: command not found` | `network-manager` was removed by the same transaction. SSH in **over IPv6**, which still works, and follow the recovery steps there |
| App doesn't fill the screen | Set `"fullscreen": true` in `config.json` or `TERMINAL_FULLSCREEN=true` env var |
| RFID scanner not detected | Ensure reader is in keyboard-emulation mode (sends UID + Enter); test with `evtest` |
| Idle screen says "Scanner nicht verbunden" with the reader plugged in | The configured `rfidReader` ids/name no longer match the device — re-read them from `cat /proc/bus/input/devices` (a replacement reader often has different ids) |
| Reader dies unnoticed — screen keeps inviting scans | Reader monitoring is off: describe the reader under `rfidReader` in `config.json`, see [Reader health monitoring](#reader-health-monitoring) |
| A registered chip is sometimes not recognised — no sound, nothing on screen | Open the status modal and read **Letzte Chip-Erkennungen**, see [What the terminal saw](#what-the-terminal-saw-of-a-card-tap) |
| No sound / audio not working | See [Audio setup on Raspberry Pi](docs/audio-setup-raspberry-pi.md) for GStreamer and ALSA configuration |
| Sound worked, nothing was changed, sound is gone — a reboot brings it back | **Do not reboot yet.** Run `/opt/clubbar-terminal/current/scripts/audio-diagnose.sh` while it is silent, then follow [The terminal went silent by itself](docs/audio-dropout-debugging.md) |
