# Runbook: Terminal Raspberry Pi

How to provision a terminal Pi so it reaches **both** the Verein wifi and a
maintenance/home wifi persistently, keeps correct time, restarts its own app,
and recovers unattended — instead of stranding itself where nobody can reach it.

Written after the 2026-08-29 incident on a terminal Pi (Pi 4B, Raspberry Pi OS
Trixie), where the terminal fell off wifi, could not be reached, and could not
be recovered from the touchscreen because the app runs fullscreen with no exit.

---

## 1. Why a terminal strands itself

Three defaults combine badly on an unattended kiosk:

| Default | Effect |
|---|---|
| `connection.autoconnect-retries` = 4 | After four failed attempts NetworkManager **gives up on that profile until reboot**. A router reboot or a slow AP is enough. Radio stays on, device reads `disconnected`, nothing ever retries. |
| `wifi.powersave` = enabled | The link drops during idle periods and does not always come back. Classic "worked yesterday". |
| No RTC + `fake-hwclock` absent | The Pi boots with a stale clock and only corrects once NTP is reachable — which needs the network that is broken. See §7. |

## 2. Configure both SSIDs

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

## 3. Wifi watchdog

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

## 4. Keep the app alive

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

## 5. Hardware watchdog

A Pi has `/dev/watchdog`; systemd does not use it unless told to. Without it a
hard kernel hang means an unreachable terminal until someone power-cycles it.

```ini
# /etc/systemd/system.conf.d/watchdog.conf
[Manager]
RuntimeWatchdogSec=15s
RebootWatchdogSec=2min
```

## 6. Journal retention

Default journald kept only 2 boots on the reference unit, which is why the original
failure left no evidence. Set it explicitly:

```ini
# /etc/systemd/journald.conf.d/retention.conf
[Journal]
Storage=persistent
SystemMaxUse=200M
MaxRetentionSec=6month
```

## 7. Clock — install `fake-hwclock`

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

## 8. Recovering a terminal you cannot reach

The app runs fullscreen (`windowManager.setFullScreen(true)`) with no in-app
exit, but it is an ordinary fullscreen window, not a locked kiosk:

- **Ctrl+Alt+F2** → console, log in, `sudo nmtui`. `Ctrl+Alt+F7`/F1 returns.
- **Alt+F4** closes the app.
- **Ethernet cable** — NM brings `eth0` up on DHCP unconfigured; needs no keyboard.
- **USB tethering from a phone** — NM picks up `usb0` automatically.

Keep SSH enabled (`sudo systemctl enable --now ssh`) and give each terminal a
fixed DHCP lease on the router so its address never moves.

## 9. Temperature and undervoltage — on the touchscreen

Both are in the terminal's own **status modal**: tap the status pill in the
header, and the *Overview* tab carries a **System health** section with the SoC
temperature, its state, and an undervoltage warning when there is one (#767).
It is the first thing here reachable without a keyboard, and it is deliberately
the two values that are worth reading *before* something has gone wrong:

| Reading | What it means | What to do |
|---|---|---|
| below 60 °C | Normal. A terminal idling in a room sits at ~59 °C | Nothing |
| 60–80 °C | Warm. Working, with less headroom than it looks — a bar in summer has less than a desk | Improve airflow while it is cheap to |
| 80 °C and above | Throttling. The SoC's soft limit; it is dropping clock speed to save itself, so the till is slower exactly when the bar is busiest (#760) | Airflow, now |
| Undervoltage | The power supply is sagging below the SoC's low-critical threshold | **Replace the power supply.** It corrupts the SD card until somebody does, and a terminal that will not boot cannot sell |

Over SSH the same two values are:

```bash
cat /sys/class/thermal/thermal_zone0/temp                  # milli-°C
grep -l rpi_volt /sys/class/hwmon/hwmon*/name |            # 1 = undervoltage
  xargs -r dirname | xargs -r -I{} cat {}/in0_lcrit_alarm
```

**Not `vcgencmd`.** `vcgencmd measure_temp` and `get_throttled` need
`/dev/vcio`, which `raspberrypi-sys-mods` 1:20260612 leaves `0600 root:root` —
its udev rules name only the newer `vcio_gencmd`/`vcio_crypto` nodes while
kernel 6.12.47 still creates plain `/dev/vcio`. A reboot does not fix it. The
sysfs paths above are world-readable and need nothing.

## 10. Verification

Everything above was verified by reboot on 2026-08-29 on a live terminal Pi: clock correct at boot (no
NTP jump), wifi reassociated unattended, watchdog armed at 15s, app started
under systemd, and SIGKILL produced a restart in under 8 seconds.

## 11. Open gap

Almost none of the above is reachable from the touchscreen — §9 is the first
slice, and only the first. A staff-facing service screen — SSID, IP, backend
reachability, unsynced count, exit-to-desktop, behind a PIN — would remove the
need for most of §8. Not yet filed.
