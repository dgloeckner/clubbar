#!/usr/bin/env bash
#
# kiosk-doctor.sh — check a terminal Pi against everything we have learned the
# hard way about running one.
#
# Read-only. It changes nothing, so it is safe on a till that is serving.
#
# Every check here exists because its absence cost a terminal an outage. The
# reasons are in terminal-frontend/INSTALL.md §§3-4; the one-line summaries
# below are deliberately enough to act on without opening it.
#
# Usage:
#   ./kiosk-doctor.sh          # human-readable report
#   ./kiosk-doctor.sh --quiet  # only WARN and FAIL lines
#
# Exit codes:
#   0  no FAIL (warnings may be present)
#   1  at least one FAIL — the terminal is misconfigured
#   2  could not run the checks at all (no compositor, wrong host)

set -uo pipefail   # not -e: a failing probe is a finding, not a crash

QUIET=0
for arg in "$@"; do
  case "$arg" in
    --quiet) QUIET=1 ;;
    -h|--help) sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown argument: $arg" >&2; exit 2 ;;
  esac
done

export WAYLAND_DISPLAY="${WAYLAND_DISPLAY:-wayland-0}"
export XDG_RUNTIME_DIR="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"

CONFIG="$HOME/.local/share/de.clubbar.clubbar_terminal/config.json"
LABWC_AUTOSTART=/etc/xdg/labwc/autostart
FAILURES=0
WARNINGS=0

ok()   { [ "$QUIET" = 1 ] || printf '[ OK ]   %s\n' "$1"; }
warn() { WARNINGS=$((WARNINGS + 1)); printf '[WARN]   %s\n' "$1"; }
fail() { FAILURES=$((FAILURES + 1)); printf '[FAIL]   %s\n' "$1"; }
note() { [ "$QUIET" = 1 ] || printf '         %s\n' "$1"; }
head2() { [ "$QUIET" = 1 ] || printf '\n== %s ==\n' "$1"; }

# ---------------------------------------------------------------- the session

head2 "Session"

# One toplevel, or the app loses the input that wakes a blanked screen. The
# desktop takes card scans into its type-ahead find box; the panel is remapped
# above the fullscreen window by lwrespawn whenever it dies.
# pgrep -c prints 0 AND exits non-zero when nothing matches, so counting via
# `|| echo 0` yields "0\n0" and breaks the arithmetic. wc -l always answers.
count_procs() { { pgrep "$@" 2>/dev/null || true; } | wc -l | tr -d ' '; }

competitors=$(( $(count_procs -x wf-panel-pi) + $(count_procs -f 'pcmanfm --desktop') ))
if [ "$competitors" -eq 0 ]; then
  ok "no panel or desktop running — nothing competes for focus"
else
  fail "$competitors focus competitor(s) running (wf-panel-pi / pcmanfm --desktop)"
  note "a blanked screen will not wake: the input goes to them, not the app"
  note "fix: sudo ./kiosk-session-setup.sh"
fi

if [ -r "$LABWC_AUTOSTART" ]; then
  if grep -qE '^[[:space:]]*/usr/bin/lwrespawn[[:space:]]+/usr/bin/(wf-panel-pi|pcmanfm-pi)' "$LABWC_AUTOSTART"; then
    fail "$LABWC_AUTOSTART still starts the panel and/or desktop at boot"
    note "an apt upgrade restores this file; re-run kiosk-session-setup.sh"
  else
    ok "$LABWC_AUTOSTART does not start a panel or desktop"
  fi
else
  warn "$LABWC_AUTOSTART not readable — is this a labwc session?"
fi

# labwc runs with -m (--merge-config) on Raspberry Pi OS, so a user-level
# autostart is executed IN ADDITION to the packaged one, not instead of it.
if [ -e "$HOME/.config/labwc/autostart" ]; then
  warn "$HOME/.config/labwc/autostart exists; labwc merges it with the packaged file"
  note "entries in both are started twice; the panel can return at boot"
fi

# ----------------------------------------------------------------- the output

head2 "Display"

if command -v wlr-randr >/dev/null 2>&1; then
  current=$(wlr-randr 2>/dev/null | grep -m1 'current' | awk '{print $1}')
  preferred=$(wlr-randr 2>/dev/null | grep -m1 'preferred' | awk '{print $1}')
  if [ -n "$current" ] && [ "$current" = "$preferred" ]; then
    ok "output at its preferred mode ($current)"
  elif [ -n "$current" ]; then
    warn "output at $current, preferred is ${preferred:-unknown}"
  fi
else
  note "wlr-randr not installed — cannot read the output mode"
fi

# An empty kanshi config is the Pi OS default, with the profile it should hold
# sitting unused beside it. Nothing then pins the mode, so the output returns
# from standby at whatever is negotiated: the app logs a frame-size timeout and
# the monitor flashes its own OSD on every wake.
kanshi_conf="$HOME/.config/kanshi/config"
if [ -s "$kanshi_conf" ]; then
  ok "kanshi config is populated ($(wc -c < "$kanshi_conf") bytes)"
  pgrep -x kanshi >/dev/null 2>&1 || warn "kanshi is not running, so its profile is not applied"
elif [ -e "$kanshi_conf" ]; then
  fail "kanshi config is empty — the output mode is not pinned"
  [ -s "$kanshi_conf.init" ] && note "the profile is in ${kanshi_conf}.init; kiosk-session-setup.sh copies it"
else
  warn "no kanshi config — the output mode is not pinned"
fi

# ------------------------------------------------------------------- the app

head2 "Terminal app"

if systemctl --user list-unit-files clubbar-terminal.service >/dev/null 2>&1; then
  state=$(systemctl --user is-active clubbar-terminal.service 2>/dev/null)
  restarts=$(systemctl --user show -p NRestarts --value clubbar-terminal.service 2>/dev/null)
  if [ "$state" = "active" ]; then
    ok "clubbar-terminal.service active (NRestarts=${restarts:-0})"
    [ "${restarts:-0}" -gt 3 ] && warn "it has restarted ${restarts} times — check the journal"
  else
    fail "clubbar-terminal.service is $state"
  fi
else
  fail "clubbar-terminal.service is not installed — see INSTALL.md §4"
fi

# ---------------------------------------------------------------- the config

head2 "config.json"

if [ ! -r "$CONFIG" ]; then
  fail "$CONFIG not readable"
else
  # Development-only switches must never be true on a terminal in service.
  for key in seedTestData demoMode; do
    if grep -qE "\"$key\"[[:space:]]*:[[:space:]]*true" "$CONFIG"; then
      fail "$key is true — this is a development-only setting"
    else
      ok "$key is not enabled"
    fi
  done

  if grep -q '"fullscreen"[[:space:]]*:[[:space:]]*true' "$CONFIG"; then
    ok "fullscreen enabled"
  else
    warn "fullscreen is not enabled — the app will not fill the screen"
  fi

  # Reader monitoring is off unless the reader is described, and a terminal
  # whose reader dies unnoticed keeps inviting scans it cannot read.
  if grep -q '"rfidReader"' "$CONFIG"; then
    ok "rfidReader configured"
    if [ -r /proc/bus/input/devices ]; then
      vid=$(grep -oE '"vendorId"[^,]*' "$CONFIG" | grep -oE '[0-9a-fA-F]{4}' | head -1)
      pid=$(grep -oE '"productId"[^,]*' "$CONFIG" | grep -oE '[0-9a-fA-F]{4}' | head -1)
      if [ -n "$vid" ] && [ -n "$pid" ]; then
        if grep -qiE "Vendor=0*$vid .*Product=0*$pid" /proc/bus/input/devices; then
          ok "the configured reader ($vid:$pid) is present"
        else
          fail "no input device matches the configured reader $vid:$pid"
          note "a replacement reader usually has different ids — read them from"
          note "/proc/bus/input/devices and update config.json"
        fi
      fi
    fi
  else
    warn "no rfidReader block — a dead reader will go unnoticed (INSTALL.md §8)"
  fi

  # Blanking. output-power is the only mode that saves power, and on a panel
  # whose digitizer sleeps with it, it is also card-wake-only.
  mode=$(grep -oE '"mode"[[:space:]]*:[[:space:]]*"[a-z-]+"' "$CONFIG" | grep -oE '[a-z-]+"$' | tr -d '"')
  timeout=$(grep -oE '"timeoutSeconds"[[:space:]]*:[[:space:]]*[0-9]+' "$CONFIG" | grep -oE '[0-9]+$')
  if [ -n "$mode" ]; then
    ok "screen blanking: mode=$mode timeout=${timeout:-unset}s"
    if [ "$mode" = "output-power" ]; then
      note "on a panel whose touchscreen sleeps with it, a card wakes this"
      note "terminal and a touch does not — expected, see INSTALL.md §3"
    fi
    if [ -n "$timeout" ] && [ "$timeout" -lt 300 ]; then
      warn "a ${timeout}s timeout blanks during service; production uses 3600"
    fi
  fi
fi

# ------------------------------------------------------------- health telemetry

head2 "Health telemetry"

# vcgencmd is how the runbook answers "has it been overheating?" and "is the PSU
# coping?". It needs /dev/vcio, and that node is only group-readable if a udev
# rule matches it. raspberrypi-sys-mods 1:20260612 ships rules for the newer
# vcio_gencmd/vcio_crypto names only, so on a 6.12 kernel — which still creates
# plain /dev/vcio — the node stays 0600 root:root and every check below fails
# for the kiosk user. Silently: the terminal runs fine, and the one tool that
# would reveal undervoltage or throttling is simply unavailable.
if ! command -v vcgencmd >/dev/null 2>&1; then
  warn "vcgencmd not installed — no temperature or throttling telemetry"
elif ! vcgencmd get_throttled >/dev/null 2>&1; then
  fail "vcgencmd cannot read /dev/vcio — temperature and throttling are unavailable"
  note "$(ls -l /dev/vcio 2>/dev/null || echo '/dev/vcio missing')"
  note "fix: a udev rule granting the video group access, e.g."
  note "  KERNEL==\"vcio\", GROUP=\"video\", MODE=\"0660\" in /etc/udev/rules.d/"
else
  throttled=$(vcgencmd get_throttled | cut -d= -f2)
  temp=$(vcgencmd measure_temp | cut -d= -f2)
  if [ "$throttled" = "0x0" ]; then
    ok "temperature $temp, throttling $throttled (clean)"
  else
    # bit 0 undervoltage now, bit 16 undervoltage has occurred,
    # bit 3/19 soft temperature limit now/since boot.
    warn "throttling word is $throttled (temperature $temp) — not clean"
    [ $(( $(printf '%d' "$throttled") & 0x10000 )) -ne 0 ] && \
      note "undervoltage has occurred since boot — suspect the PSU or cable"
    [ $(( $(printf '%d' "$throttled") & 0x80000 )) -ne 0 ] && \
      note "the soft temperature limit was reached — the till slows under load"
  fi
fi

# ------------------------------------------------------------------- packages

head2 "Packages"

# Pi OS 64-bit enables armhf multiarch, which is what let a single
# `apt-get install -y` remove 397 packages including labwc and NetworkManager.
if dpkg --print-foreign-architectures 2>/dev/null | grep -q armhf; then
  note "armhf multiarch is enabled (the Pi OS default)"
  note "never 'apt-get install -y' here — simulate first, see INSTALL.md"
  stray=$(dpkg -l 2>/dev/null | grep -c ':armhf' || true)
  [ "${stray:-0}" -gt 0 ] && warn "$stray armhf package(s) installed — unusual on a terminal"
fi

# --------------------------------------------------------------------- result

printf '\n'
if [ "$FAILURES" -gt 0 ]; then
  printf '%s failure(s), %s warning(s).\n' "$FAILURES" "$WARNINGS"
  exit 1
fi
printf 'No failures, %s warning(s).\n' "$WARNINGS"
exit 0
