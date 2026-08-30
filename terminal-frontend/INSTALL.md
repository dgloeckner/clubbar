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

Copy the app to the Pi:

```bash
rsync -av build/linux/x64/release/bundle/ pi@<PI_IP>:/opt/clubbar-terminal/
```

On the Pi, make the binary executable:

```bash
chmod +x /opt/clubbar-terminal/clubbar_terminal
```

To launch manually and verify it works:

```bash
DISPLAY=:0 /opt/clubbar-terminal/clubbar_terminal
```

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

The screen should go dark and come back. Touch input keeps working while the
output is off — the touchscreen is a USB device, unrelated to the display
pipeline — which is what makes wake-on-touch work.

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
| `timeoutSeconds` | `300` | Idle time before blanking |
| `mode` | `output-power` | `output-power` sleeps the panel; `overlay` only paints black |
| `output` | — | Wayland output name. **Required for `output-power`**; without it the terminal paints black rather than guessing at a name |

Environment overrides: `TERMINAL_SCREEN_BLANKING`,
`TERMINAL_SCREEN_BLANKING_TIMEOUT`, `TERMINAL_SCREEN_BLANKING_MODE`,
`TERMINAL_SCREEN_BLANKING_OUTPUT`.

### If your panel ignores signal loss

A few panels show a "no signal" message indefinitely instead of sleeping. Set
`"mode": "overlay"` — the terminal then paints a black surface instead, which is
what it did before. Blanking still works; only the power saving is lost.

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
ExecStart=/opt/clubbar-terminal/clubbar_terminal
Restart=always
RestartSec=3
```

`graphical-session.target` is inactive under labwc, so the unit is *not* hung
off it — the `.desktop` entry is the trigger, and the user manager already has
`WAYLAND_DISPLAY` and `XDG_RUNTIME_DIR` imported, which the service inherits.

Verify by killing it:

```bash
kill -9 $(systemctl --user show -p MainPID --value clubbar-terminal.service)
sleep 8 && systemctl --user show -p NRestarts --value clubbar-terminal.service   # 1
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

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| App exits immediately with "configuration missing" | Create `config.json` at the path shown in the error — see [First-time setup](#9-first-time-setup) |
| On-screen keyboard still appears | Check `ls /etc/xdg/autostart/` for other keyboard entries (e.g. `onboard.desktop`) and rename them |
| Screen never blanks | Check `screenBlanking.enabled` in `config.json` |
| Screen blanks but the panel stays lit | The panel ignores signal loss — set `"mode": "overlay"` |
| App doesn't fill the screen | Set `"fullscreen": true` in `config.json` or `TERMINAL_FULLSCREEN=true` env var |
| RFID scanner not detected | Ensure reader is in keyboard-emulation mode (sends UID + Enter); test with `evtest` |
| Idle screen says "Scanner nicht verbunden" with the reader plugged in | The configured `rfidReader` ids/name no longer match the device — re-read them from `cat /proc/bus/input/devices` (a replacement reader often has different ids) |
| Reader dies unnoticed — screen keeps inviting scans | Reader monitoring is off: describe the reader under `rfidReader` in `config.json`, see [Reader health monitoring](#reader-health-monitoring) |
| A registered chip is sometimes not recognised — no sound, nothing on screen | Open the status modal and read **Letzte Chip-Erkennungen**, see [What the terminal saw](#what-the-terminal-saw-of-a-card-tap) |
| No sound / audio not working | See [Audio setup on Raspberry Pi](docs/audio-setup-raspberry-pi.md) for GStreamer and ALSA configuration |
| Sound worked, nothing was changed, sound is gone — a reboot brings it back | **Do not reboot yet.** Run `/opt/clubbar-terminal/scripts/audio-diagnose.sh` while it is silent, then follow [The terminal went silent by itself](docs/audio-dropout-debugging.md) |
