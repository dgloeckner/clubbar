# Club Bar Terminal — Installation Guide

This guide covers deploying the Club Bar terminal app on a Raspberry Pi running
Raspberry Pi OS (Bookworm). The terminal is a Flutter desktop app targeting
embedded Linux, typically running fullscreen on a touchscreen display.

---

## Prerequisites

- Raspberry Pi 4 or 5, 2 GB RAM minimum
- Raspberry Pi OS Bookworm (64-bit, desktop)
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

Raspberry Pi OS Bookworm ships with **squeekboard**, a Wayland on-screen
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

### Why not DPMS?

Most cheap touchscreens (and some official Raspberry Pi displays) do not
respond to DPMS power-management commands, so standard approaches like
`xset dpms force off` or `vcgencmd display_power 0` have no effect.

Instead, `scripts/screen-idle.py` monitors all input devices for activity and
covers the display with a full-screen black window after a configurable timeout.
Any touch or click dismisses it.

### Install the dependency

The black-screen overlay uses GTK3 via PyGObject. On a standard Raspberry Pi OS
desktop this is already installed, but if not:

```bash
sudo apt install python3-gi gir1.2-gtk-3.0
```

### Grant input device access

The script reads from `/dev/input/event*`. Add your user to the `input` group:

```bash
sudo usermod -aG input $USER
```

Log out and back in for the group change to take effect. Verify with:

```bash
groups | grep input
```

### Test it manually

```bash
python3 /opt/clubbar-terminal/scripts/screen-idle.py
```

Leave the screen untouched for 5 minutes — the black overlay should appear.
Touch the screen to dismiss it.

To test with a shorter timeout, edit `TIMEOUT` at the top of `screen-idle.py`:

```python
TIMEOUT = 30  # seconds — change back to 300 for production
```

### Autostart via `.desktop` file

Create the autostart directory if it doesn't exist:

```bash
mkdir -p ~/.config/autostart
```

Create `~/.config/autostart/clubbar-screen-idle.desktop`:

```ini
[Desktop Entry]
Type=Application
Name=Club Bar Screen Idle Monitor
Exec=python3 /opt/clubbar-terminal/scripts/screen-idle.py
Hidden=false
X-GNOME-Autostart-enabled=true
```

Alternative - add to `~/.config/labwc/autostart`.

Reboot and verify the process is running:

```bash
pgrep -a python3
```

You should see `screen-idle.py` in the output.

---

## 4. Autostart the terminal app

Create `~/.config/autostart/clubbar-terminal.desktop`:

```ini
[Desktop Entry]
Type=Application
Name=Club Bar Terminal
Exec=env GST_AUDIO_SINK=alsasink /opt/clubbar-terminal/clubbar_terminal
Hidden=false
X-GNOME-Autostart-enabled=true
```

The app reads its window mode from `config.json` on startup.

### Fullscreen / kiosk mode

To run the app fullscreen (recommended for production kiosk deployments), add
`"fullscreen": true` to the terminal's `config.json` (see
[Configuration reference](#5-configuration-reference) for the full schema):

```json
{
  "terminalId": "Club Bar-Kühlschrank",
  "apiUrl": "https://club.example.com/api",
  "apiToken": "a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2",
  "fullscreen": true
}
```

Alternatively, set the environment variable `TERMINAL_FULLSCREEN=true` in the
`.desktop` file:

```ini
[Desktop Entry]
Type=Application
Name=Club Bar Terminal
Exec=env TERMINAL_FULLSCREEN=true /opt/clubbar-terminal/clubbar_terminal
Hidden=false
X-GNOME-Autostart-enabled=true
```

> **Development machines:** Leave `fullscreen` unset (defaults to `false`) so
> the app opens in a normal window. Only set it on deployed kiosk hardware.

---

## 5. Configuration reference

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
    "xs":    13,
    "sm":    14,
    "base":  16,
    "lg":    18,
    "xl":    20,
    "xxl":   22,
    "xxxl":  26
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

## 6. First-time setup

The app requires a valid `config.json` before it will start. If the file is
missing or incomplete the app prints the expected path to stderr and exits
with code 1 — it will not launch into the UI.

Create the config file at the path printed in the error message (see
[Configuration reference](#5-configuration-reference)) with at least the
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

## 7. Optional: Token Dispenser

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
| App exits immediately with "configuration missing" | Create `config.json` at the path shown in the error — see [First-time setup](#6-first-time-setup) |
| On-screen keyboard still appears | Check `ls /etc/xdg/autostart/` for other keyboard entries (e.g. `onboard.desktop`) and rename them |
| Black screen never dismisses | Verify `python3-gi` is installed; check `pgrep -a python3` |
| `screen-idle.py` can't open input devices | Run `sudo usermod -aG input $USER` and re-login |
| App doesn't fill the screen | Set `"fullscreen": true` in `config.json` or `TERMINAL_FULLSCREEN=true` env var |
| RFID scanner not detected | Ensure reader is in keyboard-emulation mode (sends UID + Enter); test with `evtest` |
| Idle screen says "Scanner nicht verbunden" with the reader plugged in | The configured `rfidReader` ids/name no longer match the device — re-read them from `cat /proc/bus/input/devices` (a replacement reader often has different ids) |
| Reader dies unnoticed — screen keeps inviting scans | Reader monitoring is off: describe the reader under `rfidReader` in `config.json`, see [Reader health monitoring](#reader-health-monitoring) |
| No sound / audio not working | See [Audio setup on Raspberry Pi](docs/audio-setup-raspberry-pi.md) for GStreamer and ALSA configuration |
