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

To launch fullscreen automatically, you can also set the window manager to
open it maximised. The app manages its own window size via `window_manager`
(fixed at 1280×720), so no additional flags are needed.

---

## 5. First-time setup

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
| App doesn't fill the screen | The app targets 1280×720; check display resolution matches or adjust in `main.dart` `windowManager.setSize()` |
| RFID scanner not detected | Ensure reader is in keyboard-emulation mode (sends UID + Enter); test with `evtest` |
