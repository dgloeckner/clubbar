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

**Touch the screen before doing anything else.** It should wake within a second.
A card scan wakes it too — the reader is a keyboard-wedge device, so presenting
a card both wakes the terminal and logs the member in.

If it does not wake, carry on below.

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

# Has it been overheating? 0x0 is clean; bit 19 (0x80000) is the soft limit.
vcgencmd measure_temp
vcgencmd get_throttled

# What happened before it stopped?
journalctl --user -u clubbar-terminal.service -n 50 --no-pager
journalctl -t wifi-watchdog --no-pager | tail
```

A terminal that keeps working but cannot reach the backend is a different
problem — see the terminal's own status modal, and
[`ADR-0035`](../adr/0035-terminal-backend-instance-pairing.md) if it reports
being paired with a different backend.

## 5. Open gap

None of the above is reachable from the touchscreen. A staff-facing service
screen — SSID, IP, backend reachability, unsynced count, exit-to-desktop, behind
a PIN — would remove the need for most of §3. Not yet filed.
