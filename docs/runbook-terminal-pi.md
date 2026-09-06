# Runbook: Terminal Raspberry Pi

**A terminal has stopped responding. What now.**

Setting one up is a different job and lives in
[`terminal-frontend/INSTALL.md`](../terminal-frontend/INSTALL.md) — §§5–7 there
are the hardening this runbook assumes is in place. This page is only for the
moment something is wrong.

---

## 1. Check the obvious one first: it may just be asleep

Since #763 the terminal powers its **panel** down after
`screenBlanking.timeoutSeconds` (300 s by default), rather than covering the
screen with a black window. A sleeping panel has no backlight and no "no
signal" message once it settles, so **a blanked terminal is indistinguishable
from a dead one.**

**Hold a card to the reader before doing anything else.** That is the wake that
always works: the reader is its own USB device and a keyboard-wedge, so a card
both wakes the terminal and logs the member in.

**A touch may not wake it, and that is not a fault.** Under `"mode":
"output-power"` the panel's touchscreen sleeps with the panel on this hardware —
it stays enumerated on USB and emits nothing, measured — so the screen answers a
card and ignores a finger. See [*A sleeping panel takes its touchscreen with
it*](../terminal-frontend/INSTALL.md#a-sleeping-panel-takes-its-touchscreen-with-it)
before concluding the terminal is dead.

If a card does not wake it, carry on below.

## 2. Why a terminal strands itself

The three defaults that caused the 2026-08-29 incident, kept here because they
are the first things to re-check on a terminal that fell off the network:

| Default | Effect |
|---|---|
| `connection.autoconnect-retries` = 4 | After four failed attempts NetworkManager **gives up on that profile until reboot**. A router reboot or a slow AP is enough. Radio stays on, device reads `disconnected`, nothing ever retries. |
| `wifi.powersave` = enabled | The link drops during idle periods and does not always come back. Classic "worked yesterday". |
| No RTC + `fake-hwclock` absent | The Pi boots with a stale clock and only corrects once NTP is reachable — which needs the network that is broken. |

All three are fixed by INSTALL.md §§5–6. A terminal showing one of them was
provisioned before that, or by hand.

## 3. Recovering a terminal you cannot reach

The app runs fullscreen (`windowManager.setFullScreen(true)`) with no in-app
exit, but it is an ordinary fullscreen window, not a locked kiosk:

- **Ctrl+Alt+F2** → console, log in, `sudo nmtui`. `Ctrl+Alt+F7`/F1 returns.
- **Alt+F4** closes the app. It will be restarted by its systemd unit.
- **Ethernet cable** — NM brings `eth0` up on DHCP unconfigured; needs no keyboard.
- **USB tethering from a phone** — NM picks up `usb0` automatically.

Keep SSH enabled (`sudo systemctl enable --now ssh`) and give each terminal a
fixed DHCP lease on the router so its address never moves.

## 4. Diagnosis

**Temperature and undervoltage are on the touchscreen** (#767) — tap the status
pill in the header, and the *Overview* tab's **System health** section carries
both, with no SSH and no keyboard:

| Reading | Means | Do |
|---|---|---|
| below 60 °C | Normal — the terminal idles at ~59 °C in a room | Nothing |
| 60–80 °C | Warm. Working, with less headroom than it looks | Improve airflow while it is cheap to |
| 80 °C and above | Throttling. The soft limit; the till is slower exactly when the bar is busiest (#760) | Airflow, now |
| Undervoltage | The PSU is sagging below the SoC's low-critical threshold | **Replace the power supply** — it corrupts the SD card until somebody does |

Over SSH, start with the one script that checks everything this page assumes:

```bash
./scripts/kiosk-doctor.sh      # in terminal-frontend/, read-only, safe while serving
```

Then, by hand:

```bash
# Is it on the network, and does it know what time it is?
nmcli device status
nmcli -f NAME,AUTOCONNECT,AUTOCONNECT-PRIORITY connection show
timedatectl                       # "System clock synchronized: yes"

# Is the app running, and has it been crashing?
systemctl --user status clubbar-terminal.service
systemctl --user show -p NRestarts --value clubbar-terminal.service

# Is the display asleep, or is something wrong with it?
WAYLAND_DISPLAY=wayland-0 XDG_RUNTIME_DIR=/run/user/1000 wlopm
cat /sys/class/drm/card1-HDMI-A-1/{dpms,enabled}

# Is anything competing with the app for focus? Must be 0 — a panel or desktop
# window takes the input that wakes a blanked screen, and the terminal then
# stays black with its output switched on. (kiosk-doctor.sh checks this too.)
ps -eo cmd | grep -cE '[w]f-panel-pi|[p]cmanfm'

# Has it been overheating? 0x0 is clean; bit 19 (0x80000) is the soft limit.
vcgencmd measure_temp
vcgencmd get_throttled

# …and the same two from sysfs, which needs no /dev/vcio — use these when
# vcgencmd has lost it (terminal-hardware.md §"Power and thermal"). This is
# what the touchscreen readout above reads.
cat /sys/class/thermal/thermal_zone0/temp                  # milli-°C
grep -l rpi_volt /sys/class/hwmon/hwmon*/name |            # 1 = undervoltage
  xargs -r dirname | xargs -r -I{} cat {}/in0_lcrit_alarm

# What happened before it stopped?
journalctl --user -u clubbar-terminal.service -n 50 --no-pager
journalctl -t wifi-watchdog --no-pager | tail
```

A terminal that keeps working but cannot reach the backend is a different
problem — see the terminal's own status modal, and
[`ADR-0035`](../adr/0035-terminal-backend-instance-pairing.md) if it reports
being paired with a different backend.

## 5. It boots to a text console, or has no network at all

Both symptoms together mean packages were removed — almost always by an
`apt-get install` that resolved a conflict by deleting the 64-bit desktop.
`labwc` gone is the console; `network-manager` gone is the missing network, and
`nmcli: command not found` confirms it in one line.

**You can still get in.** The kernel brings IPv6 up by SLAAC with no client at
all, so `ssh <user>@<host>` works over IPv6 while every IPv4 ping and scan
fails — the host looks dead and is not. From there, INSTALL.md's
[recovery steps](../terminal-frontend/INSTALL.md#recovering-a-terminal-whose-packages-were-removed)
rebuild the exact package list from `/var/log/apt/history.log`.

Two things that make this less frightening than it looks: NetworkManager's
profiles survive removal with their PSKs, so wifi returns by itself once the
package is back; and `/etc/xdg/labwc/autostart` is a conffile, so kiosk edits
survive too if the reinstall passes `--force-confold`.

## 6. Updates: a terminal that is behind, or stuck

A terminal installs **exactly the version its backend reports**
([ADR-0054](../adr/0054-terminal-runs-its-backends-version.md),
[#318](https://github.com/dgloeckner/clubbar/issues/318)). Upgrading the backend
is the single act that moves terminals; nothing is decided per terminal and
there is no release to bless.

The admin panel's *Settings → Terminals* page is where you look. Its **Version**
column shows one of five states, and only one of them is a problem.

| State | What it means | Do |
|-------|---------------|-----|
| the bare tag | Running the backend's version. The invariant holds. | Nothing |
| **Behind** | Older than the backend, and it checks nightly at 04:00 (±1 h). | **Nothing.** This is the normal state of every terminal in the club for the hours after a backend upgrade. Only worry if it is still behind after two nights |
| **Stuck at `<tag>`** | An update to that tag failed here, was rolled back, and will never be retried. Exact-match means it is also the only tag this terminal would consider — so it updates no further until a **newer** release ships | Read the journal, below, then either wait for the next release or clear the block deliberately |
| **Ahead** | Newer than the backend. Hand-installed, or the backend was rolled back | Nothing is blocked and the terminal keeps selling. Bring the backend forward, or reinstall the matching bundle by hand |
| **Not reported** | No version has arrived. A build older than the header, a proxy that strips it, or a backend on `dev` — a club deployed from git never auto-updates its terminals | Nothing, unless you expected otherwise |

### Why is it behind?

Every refusal is logged, and none of them is an error:

```bash
journalctl --user -u clubbar-update.service -n 100 --no-pager
/opt/clubbar-terminal/current/scripts/clubbar-update.sh --status
/opt/clubbar-terminal/current/scripts/clubbar-update.sh --check   # changes nothing
```

`--check` names the reason in one line. The five that come up:

- **the backend was unreachable** — the club's internet was down at 04:00;
- **the backend reports `dev` or `dev-<sha>`** — no update, ever, on that
  backend, deliberately;
- **the release has no arm64 bundle, or no `.sha256`** — nothing to install, or
  nothing verifiable;
- **sales had not synced** — an update must never lose a booking, so it waits;
- **pinned, opted out, or blocked** — see below.

### Recovering a terminal that is stuck

The updater will never un-block a tag on its own. It blocked that version
because installing it produced a till that did not come back within five
minutes, and retrying that automatically is the one thing worse than standing
still. Clearing it is a human act:

```bash
# What failed, and why. The rollback is in the journal in full.
journalctl --user -u clubbar-update.service --since -7d --no-pager | grep -i 'rolling back\|blocked'
cat /opt/clubbar-terminal/blocked

# Deliberately allow that version to be tried again.
/opt/clubbar-terminal/current/scripts/clubbar-update.sh --clear-block
systemctl --user start clubbar-update.service   # or wait for 04:00
```

Usually the better answer is to **do nothing**: the club ships a fix, the
backend rolls forward under the same single-release policy, and the terminal
follows on its own. Clear the block only when you know what went wrong and have
reason to think it will not happen again.

### Pinning a terminal, or opting one out

Two keys in `config.json` (see
[INSTALL.md §8](../terminal-frontend/INSTALL.md#8-configuration-reference)):

```json
{
  "updatePin": "v1.0.6",
  "updateEnabled": false
}
```

`updatePin` holds the terminal at one release; `updateEnabled: false` opts it
out entirely. Neither is remembered anywhere but on that Pi — a terminal that
was pinned during an investigation stays pinned until somebody removes the key,
and the Terminals page will read *Behind* for as long as it does.

### Rolling the app back by hand

The updater keeps one generation and the pre-update databases:

```bash
cd /opt/clubbar-terminal
ls -l current previous              # what is installed, and what it replaced
ls -1t ~/.local/share/de.clubbar.clubbar_terminal/update-backup/

systemctl --user stop clubbar-terminal.service
ln -sfn "$(readlink -f previous)" .current.new && mv -Tf .current.new current
# Restore the database only if the version you are going back to predates a
# migration the newer one ran — a database migrated forward will not open on
# the older build.
cp -a ~/.local/share/de.clubbar.clubbar_terminal/update-backup/<file>.db \
      ~/.local/share/de.clubbar.clubbar_terminal/clubbar_terminal.db
systemctl --user start clubbar-terminal.service
```

Add the version you came from to `/opt/clubbar-terminal/blocked` if you do not
want tonight's run to install it straight back.

## 7. Open gap

Almost none of the above is reachable from the touchscreen — §4's System health
section is the first slice, and only the first. A staff-facing service screen —
SSID, IP, backend reachability, unsynced count, exit-to-desktop, behind a PIN —
would remove the need for most of §3. Not yet filed.
