# Terminal hardware

**What the terminals actually run on, and what that hardware can and cannot do.**

This is an operations reference, not a shopping list. Every figure here was
measured on a machine in service rather than read from a datasheet, because the
things that decide how a terminal behaves — whether the touchscreen survives
standby, whether the panel has a brightness control — are exactly the things
datasheets are silent or wrong about.

Setting a terminal up is [`terminal-frontend/INSTALL.md`](../terminal-frontend/INSTALL.md).
Recovering one is [`runbook-terminal-pi.md`](./runbook-terminal-pi.md). This page
answers a different question: *given this hardware, what is possible?*

---

## 1. Variants in service

| Variant | Compute | Display | Reader | Status |
|---|---|---|---|---|
| **A — Ruderbar / küche** | Raspberry Pi 4 Model B Rev 1.5, 4 GB | RTK CX101, 10.1″ 1280×800, HDMI + USB touch | Sycreader `ffff:0035` | In service (2 terminals) |

Both terminals in service are variant A. Add a row per distinct combination —
[§6](#6-qualifying-a-new-variant) is the checklist that fills one in.

---

## 2. Variant A — compute

| Property | Value | Why it matters |
|---|---|---|
| Board | Raspberry Pi 4 Model B Rev 1.5 (`c03115`) | Pi 5 differs in one operationally relevant way: its wired interface is `end0`, not `eth0` |
| SoC / cores | BCM2711, 4 × Cortex-A72 | The idle screen must render **zero frames** (#760); an animating idle screen cost 27.7 % of a core and ~7 °C |
| Memory | 4 GB (3.7 GiB usable) | Comfortable. The app, compositor and sync fit with room to spare |
| Storage | 59.5 GB SD card — `/boot/firmware` 512 MB, `/` 59 GB | SD cards are the usual cause of a terminal that will not boot. Undervoltage corrupts them |
| Swap | 2 GB zram | In RAM; it does not wear the card |
| OS | Debian 13 (trixie), 64-bit | INSTALL.md's prerequisites also allow Bookworm |
| Kernel | 6.12.47+rpt-rpi-v8, `aarch64` | |
| Compositor | labwc 0.9.8, Wayland | Started with `-m` (`--merge-config`) — see the trap in [§5](#5-software-traits-that-bite) |
| Multiarch | `armhf` enabled (the Pi OS default) | The single most dangerous property of this machine. See [§5](#5-software-traits-that-bite) |

### Power and thermal

| Property | Value | Why it matters |
|---|---|---|
| Throttling word | `vcgencmd get_throttled` — `0x0` is clean. Bit 0/16: undervoltage now/since boot. Bit 3/19: soft temperature limit now/since boot | Resets at boot, so it describes the *current* boot only |
| Temperature | `vcgencmd measure_temp` — **59.9 °C** measured idle, in a room | Crossing the 80 °C soft limit slows the till exactly when the bar is busiest. A bar in summer has less headroom than a desk |
| Undervoltage | Reported in the same word, and in `dmesg` | The leading cause of SD-card corruption. Suspect the PSU before the software |
| Both, without `/dev/vcio` | `/sys/class/thermal/thermal_zone0/temp` (milli-°C) and `in0_lcrit_alarm` of the `hwmon` device whose `name` is `rpi_volt` (`1` = undervoltage) | World-readable, no privileges, no packages — so this is the path that survives the `/dev/vcio` breakage below. The terminal's status modal reads these two (#767). Find the hwmon device **by name**: the numbering is not stable across boots |

> **`vcgencmd` can lose access to `/dev/vcio` after a package change, and does
> so silently.** `raspberrypi-sys-mods` 1:20260612 ships udev rules naming only
> the newer `vcio_gencmd`/`vcio_crypto` nodes, while kernel 6.12.47 still
> creates plain `/dev/vcio` — which then matches no rule and stays `0600
> root:root`. The terminal runs perfectly; the only tool that would reveal
> undervoltage or throttling just stops working for the kiosk user, and a
> reboot does not fix it. Restore it with a local rule:
>
> ```
> # /etc/udev/rules.d/10-vcio-compat.rules
> KERNEL=="vcio", GROUP="video", MODE="0660"
> ```
>
> `scripts/kiosk-doctor.sh` checks for this.

---

## 3. Variant A — display

| Property | Value |
|---|---|
| EDID identity | `RTK CX101`, manufactured 2020 week 26, serial `0x00000001` |
| Panel size | 220 × 130 mm (≈10.1″) |
| Native mode | **1280×800 @ 59.996 Hz** (EDID-preferred) |
| Other modes offered | 1920×1080, 1680×1050, 1600×900, 1440×900, 1360×768, 1280×720, 1024×768 and below |
| Connection | HDMI (`HDMI-A-1`, DRM `card1-HDMI-A-1`) for video, USB for touch |
| Touch controller | `eeef:2828 TSTP MTouch`, two input nodes (an absolute/multitouch one and a keyboard-ish one) |

### What this display can and cannot do

This is the table that matters, and every row was probed on the panel itself:

| Capability | Available? | Consequence for operations |
|---|---|---|
| **Panel standby** (`zwlr_output_power_manager_v1`, `wlopm`) | ✅ Yes | The only mechanism that saves power or heat. This is `screenBlanking.mode: "output-power"` |
| **Touch while the panel sleeps** | ❌ **No** | The digitizer stops emitting while the panel is in standby, so a blanked terminal **wakes on a card, not on a touch**. It stays enumerated throughout (`lsusb`, `/proc/bus/input/devices`, its `event*` node all persist), so only reading the device reveals it |
| **Backlight control** (`/sys/class/backlight`) | ❌ No device exists | There is no brightness to turn down. A "dim" phase can only be an overlay |
| **DDC/CI brightness** (VCP `0x10`) | ❌ Refused | `ddcutil` reads the EDID but the panel is unresponsive at I²C `0x37` |
| **`vcgencmd set_backlight`** | ❌ `Invalid arguments` | It drives the DSI connector, not HDMI |
| **Gamma ramp** (`zwlr_gamma_control_manager_v1`) | ⚠️ Advertised by labwc | Real, but scales pixel values only — the backlight stays at 100 %, so it saves nothing. Do **not** install `gammastep` to use it ([§5](#5-software-traits-that-bite)) |
| **Mode stability across standby** | ⚠️ Only if pinned | Unpinned, the output returns at a negotiated mode; the app logs `Timed out waiting for OpenGL frame of size …` and the monitor flashes its own OSD on every wake. `kanshi` pins it |
| **Sleeps on signal loss** | ✅ Yes | Panels that instead sit on a "no signal" message need `"mode": "overlay"` |

**The operational summary of that table:** on variant A, dimming is impossible
and blanking is card-wake-only. A member arriving with a chip never notices;
staff walking up to touch a dark screen will, so tell them.

---

## 4. Variant A — peripherals

| Device | USB id | Notes |
|---|---|---|
| Touchscreen | `eeef:2828` | Mapped to the output in `~/.config/labwc/rc.xml` by device name, with `mouseEmulation="yes"` |
| RFID reader | `ffff:0035` — *Sycreader SYC ID&IC USB Reader* | Keyboard-wedge: a card arrives as keystrokes plus Enter. Unaffected by panel standby |
| Internal hub | `2109:3431` VIA Labs | |

The reader's identity goes in `config.json` so the terminal can notice it
being unplugged:

```json
"rfidReader": {
  "monitor": true,
  "vendorId": "ffff",
  "productId": "0035",
  "namePattern": "SYC ID&IC USB Reader",
  "pollIntervalSeconds": 5
}
```

> **`event*` numbers are not stable.** Unplugging a keyboard renumbers them —
> the same touchscreen was `event9` and `event11` on the same machine hours
> apart. Always resolve the node from `/proc/bus/input/devices` at the moment
> you need it; never hardcode one into a script or a runbook step. This is why
> the terminal matches its reader on vendor/product id and name, not on a path.

---

## 5. Software traits that bite

Not display properties, but they belong with the hardware because they are
properties of *this platform* and each one has cost a terminal an outage.

| Trait | Consequence |
|---|---|
| **`armhf` multiarch is enabled by default** on Pi OS 64-bit | A package with no `arm64` build resolves to the 32-bit one, drags in 32-bit core libraries, and apt clears the conflict by **removing the 64-bit stack**. `apt-get install -y gammastep` removed 397 packages including `labwc` and `network-manager`. Never `-y`; simulate first — [INSTALL.md](../terminal-frontend/INSTALL.md#installing-packages-on-a-terminal-pi) |
| **`labwc` runs with `-m` (`--merge-config`)** | `~/.config/labwc/autostart` is executed *in addition to* `/etc/xdg/labwc/autostart`, not instead of it. A user-level override duplicates entries and lets a disabled panel return at boot |
| **The desktop session autostarts a panel and a desktop** | Both take the input that lifts screen blanking. `scripts/kiosk-session-setup.sh` removes them |
| **NetworkManager owns DHCP and `/etc/resolv.conf`** | Remove it and the Pi has no IPv4 at all — but the kernel still brings **IPv6** up by SLAAC, so SSH over IPv6 reaches a host that fails every IPv4 probe. That is the recovery route |
| **Wired interface naming** | `eth0` on a Pi 4, commonly `end0` on a Pi 5. A command naming the wrong one fails with "Cannot find device" and looks like a dead NIC |

---

## 6. Qualifying a new variant

Before a new Pi or panel goes into service, measure these. They take about ten
minutes and each one has a wrong answer that is expensive to discover later.

| # | Question | Command | A bad answer means |
|---|---|---|---|
| 1 | Board and OS | `cat /proc/device-tree/model`, `. /etc/os-release` | — |
| 2 | Output name and native mode | `wlopm`, `wlr-randr` | The mode must be pinned in kanshi |
| 3 | Does the panel honour standby? | `wlopm --off <out> && sleep 5 && wlopm --on <out>` | Sits on "no signal" → `"mode": "overlay"` |
| 4 | **Does touch survive standby?** | Blank it, then `timeout 20 cat /dev/input/eventN \| wc -c` while touching (resolve `N` from `/proc/bus/input/devices` first) | `0` bytes → card-wake only, or trade power saving for `"overlay"` |
| 5 | Any brightness control? | `ls /sys/class/backlight/`, `sudo ddcutil detect` | Both empty → dimming is impossible; do not design for it |
| 6 | Reader identity | `grep -A5 Reader /proc/bus/input/devices` | Goes into `rfidReader` in `config.json` |
| 7 | Session is clean | `terminal-frontend/scripts/kiosk-doctor.sh` | Any FAIL — fix before service |

Do **not** substitute a synthetic `uinput` device for a finger in step 4. It
bypasses the digitizer, wakes the screen in every mode, and will tell you the
hardware is fine when it is not.

Then add a row to [§1](#1-variants-in-service) and, if any answer differs from
variant A, a section of its own.
