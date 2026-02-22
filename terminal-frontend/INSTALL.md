# Ruderbar Terminal — Installation Guide

This guide covers deploying the Ruderbar terminal app on a Raspberry Pi running
Raspberry Pi OS (Bookworm). The terminal is a Flutter desktop app targeting
embedded Linux, typically running fullscreen on a touchscreen display.

---

## Prerequisites

- Raspberry Pi 4 or 5, 2 GB RAM minimum
- Raspberry Pi OS Bookworm (64-bit, desktop)
- Official Raspberry Pi touchscreen, or any HDMI touchscreen
- RFID/NFC USB reader (keyboard-emulation mode)
- Network access to the Ruderbar backend

---

## 1. Build and install the Flutter app

On a development machine with Flutter installed:

```bash
cd terminal-frontend
flutter build linux --release
```

Copy the build output to the Pi:

```bash
rsync -av build/linux/x64/release/bundle/ pi@<PI_IP>:/opt/ruderbar-terminal/
```

On the Pi, make the binary executable:

```bash
chmod +x /opt/ruderbar-terminal/ruderbar_terminal
```

To launch manually and verify it works:

```bash
DISPLAY=:0 /opt/ruderbar-terminal/ruderbar_terminal
```

---

## 2. Disable the on-screen keyboard

Raspberry Pi OS Bookworm ships with **squeekboard**, a Wayland on-screen
keyboard that pops up automatically when a text field is focused. The Ruderbar
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
python3 /opt/ruderbar-terminal/scripts/screen-idle.py
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

Create `~/.config/autostart/ruderbar-screen-idle.desktop`:

```ini
[Desktop Entry]
Type=Application
Name=Ruderbar Screen Idle Monitor
Exec=python3 /opt/ruderbar-terminal/scripts/screen-idle.py
Hidden=false
X-GNOME-Autostart-enabled=true
```

Reboot and verify the process is running:

```bash
pgrep -a python3
```

You should see `screen-idle.py` in the output.

---

## 4. Autostart the terminal app

Create `~/.config/autostart/ruderbar-terminal.desktop`:

```ini
[Desktop Entry]
Type=Application
Name=Ruderbar Terminal
Exec=/opt/ruderbar-terminal/ruderbar_terminal
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
  "terminalId": "Ruderbar-Kühlschrank",
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
Name=Ruderbar Terminal
Exec=env TERMINAL_FULLSCREEN=true /opt/ruderbar-terminal/ruderbar_terminal
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
| Linux | `~/.local/share/com.example.ruderbar_terminal/config.json` |
| macOS | `~/Library/Containers/com.example.ruderbar_terminal/Data/Library/Application Support/com.example.ruderbar_terminal/config.json` |
| Windows | `%APPDATA%\com.example.ruderbar_terminal\config.json` |

Every key is optional except `terminalId`, `apiUrl`, and `apiToken` (required
for the app to connect). Omitted keys fall back to the defaults shown below.

```json
{
  "terminalId": "Ruderbar-Kühlschrank",
  "apiUrl": "https://club.example.com/api",
  "apiToken": "a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2",

  "fullscreen": false,
  "seedTestData": false,

  "fontSizes": {
    "xs":    12,
    "sm":    13,
    "base":  14,
    "lg":    16,
    "xl":    18,
    "xxl":   20,
    "xxxl":  24
  },

  "dispenser": {
    "enabled":        false,
    "baseUrl":        "http://dispenser.local",
    "apiKey":         "your-dispenser-api-key",
    "timeoutMs":      3000,
    "pollIntervalMs": 250
  }
}
```

### Key descriptions

| Key | Type | Default | Description |
|-----|------|---------|-------------|
| `terminalId` | string | — | Human-readable terminal name (shown in admin panel). Alphanumeric, hyphens, underscores, spaces; 1–50 chars. |
| `apiUrl` | string | — | Base URL of the Ruderbar backend API, e.g. `https://club.example.com/api`. No trailing slash. |
| `apiToken` | string | — | 64-character hex device token generated in the Admin Panel under *Terminals*. Stored with `chmod 600`. |
| `fullscreen` | bool | `false` | Run the app fullscreen / kiosk mode on startup. Recommended for production deployments. |
| `seedTestData` | bool | `false` | Pre-populate the local database with mock members, categories, and products. **Development only — never enable in production.** |
| `fontSizes.xs` | number | `12` | Font size in logical pixels for the `xs` scale step. |
| `fontSizes.sm` | number | `13` | Font size for the `sm` scale step. |
| `fontSizes.base` | number | `14` | Base body font size. Used for balance display, labels, and secondary text. |
| `fontSizes.lg` | number | `16` | Large font size — product names, member name in cart, button labels. |
| `fontSizes.xl` | number | `18` | Extra-large — quantity badges, line totals, "Neuer Kontostand" in cart. |
| `fontSizes.xxl` | number | `20` | Used for the "Gesamt" label in the cart footer. |
| `fontSizes.xxxl` | number | `24` | Checkout confirmation title. |
| `dispenser.enabled` | bool | `false` | Enable the sauna token dispenser integration. |
| `dispenser.baseUrl` | string | — | Base URL of the dispenser hardware API, e.g. `http://192.168.1.50`. |
| `dispenser.apiKey` | string | — | API key for authenticating with the dispenser. |
| `dispenser.timeoutMs` | integer | `3000` | HTTP request timeout for dispenser calls in milliseconds. |
| `dispenser.pollIntervalMs` | integer | `250` | Polling interval when waiting for a dispense result in milliseconds. |

### Environment variable overrides

All config file values can be overridden via environment variables — useful
for CI, Docker deployments, or `.desktop` file `Exec=env ...` lines:

| Variable | Overrides |
|----------|-----------|
| `TERMINAL_ID` | `terminalId` |
| `TERMINAL_API_URL` | `apiUrl` |
| `TERMINAL_API_TOKEN` | `apiToken` |
| `TERMINAL_FULLSCREEN` | `fullscreen` (`true`/`false`) |
| `TERMINAL_SEED_TEST_DATA` | `seedTestData` (`true`/`false`) |
| `DISPENSER_ENABLED` | `dispenser.enabled` |
| `DISPENSER_BASE_URL` | `dispenser.baseUrl` |
| `DISPENSER_API_KEY` | `dispenser.apiKey` |

> **Note:** `fontSizes` cannot be set via environment variables — use the
> config file.

---

## 6. First-time setup

On first launch the app shows the **Setup Screen**. You will need a physical
USB keyboard for this one-time configuration:

1. **Terminal ID** — a human-readable name, e.g. `Ruderbar-Kühlschrank`
2. **API URL** — base URL of the Ruderbar backend, e.g. `https://club.example.com/api`
3. **API Token** — device token generated in the Admin Panel under *Terminals*

After saving, the app syncs the member and product database and navigates to
the idle RFID scanning screen. The USB keyboard can be unplugged.

---

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| On-screen keyboard still appears | Check `ls /etc/xdg/autostart/` for other keyboard entries (e.g. `onboard.desktop`) and rename them |
| Black screen never dismisses | Verify `python3-gi` is installed; check `pgrep -a python3` |
| `screen-idle.py` can't open input devices | Run `sudo usermod -aG input $USER` and re-login |
| App doesn't fill the screen | Set `"fullscreen": true` in `config.json` or `TERMINAL_FULLSCREEN=true` env var |
| RFID scanner not detected | Ensure reader is in keyboard-emulation mode (sends UID + Enter); test with `evtest` |
