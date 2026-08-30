#!/usr/bin/env bash
#
# kiosk-session-setup.sh — turn a stock Raspberry Pi OS desktop session into a
# kiosk session the terminal can actually run in.
#
# Raspberry Pi OS autostarts a panel and a file-manager desktop. Both break a
# terminal that blanks its screen, and neither failure is obvious:
#
#   * pcmanfm-pi takes keyboard focus. Powering the output down makes the
#     compositor re-add it on wake, and focus does not necessarily return to
#     the app — after which card scans go into the desktop's type-ahead find
#     box and the terminal never sees the keystroke that lifts blanking. It
#     stays black with the panel lit, and reads as a dead till.
#   * wf-panel-pi is remapped by lwrespawn whenever it dies, and a layer-shell
#     panel mapped after the fullscreen window sits above it: the menu bar
#     reappears mid-service and swallows touches along the top strip.
#
# It also pins the output mode. Pi OS ships a zero-byte ~/.config/kanshi/config
# with the profile it should hold sitting unused beside it as config.init, so
# nothing pins the mode and the output returns from standby at whatever is
# negotiated then — a frame-size timeout in the app log and a mode-change OSD
# from the monitor on every wake.
#
# Finally it pins the audio output. The speakers are in the HDMI display and
# the 3.5 mm jack is unused, but WirePlumber's stock priorities pick the jack
# often enough that the till comes up silent after some boots and not others.
# Nothing reports an error, because playing into an unconnected port succeeds
# at every layer.
#
# Idempotent: re-running changes nothing and re-verifies. Safe to run on a
# terminal in service, though the session changes only take effect at the next
# session start (--now stops the running panel and desktop as well).
#
# Usage:
#   sudo ./kiosk-session-setup.sh          # apply, effective next boot
#   sudo ./kiosk-session-setup.sh --now    # also stop the running panel/desktop
#   ./kiosk-session-setup.sh --check       # report only, changes nothing
#
# Verify afterwards with ./kiosk-doctor.sh.

set -euo pipefail

LABWC_AUTOSTART=/etc/xdg/labwc/autostart
BACKUP="$LABWC_AUTOSTART.orig"
APPLY_NOW=0
CHECK_ONLY=0

for arg in "$@"; do
  case "$arg" in
    --now) APPLY_NOW=1 ;;
    --check) CHECK_ONLY=1 ;;
    -h|--help) sed -n '2,42p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) echo "unknown argument: $arg" >&2; exit 2 ;;
  esac
done

say()  { printf '  %s\n' "$1"; }
step() { printf '\n%s\n' "$1"; }

# Run a command in the kiosk user's session — the script runs under sudo, so
# both the user and XDG_RUNTIME_DIR have to be handed over explicitly or every
# systemctl --user and pactl call talks to root's (nonexistent) session.
run_as_kiosk() {
  if [ "$(id -un)" = "$KIOSK_USER" ]; then
    XDG_RUNTIME_DIR="/run/user/$(id -u "$KIOSK_USER")" "$@"
  else
    sudo -u "$KIOSK_USER" XDG_RUNTIME_DIR="/run/user/$(id -u "$KIOSK_USER")" "$@"
  fi
}

# The user whose session this is — the script runs under sudo, so $HOME is
# root's. Everything under a home directory must go to the real user's.
KIOSK_USER="${SUDO_USER:-$USER}"
KIOSK_HOME=$(getent passwd "$KIOSK_USER" | cut -d: -f6)
[ -n "$KIOSK_HOME" ] && [ -d "$KIOSK_HOME" ] || {
  echo "cannot determine the home directory of '$KIOSK_USER'" >&2
  exit 2
}

if [ "$CHECK_ONLY" = 0 ] && [ "$(id -u)" -ne 0 ]; then
  echo "this needs root to edit $LABWC_AUTOSTART — re-run with sudo" >&2
  echo "(or use --check to report without changing anything)" >&2
  exit 2
fi

# ------------------------------------------------- 1. panel and desktop, off

step "1. Panel and desktop"

if [ ! -f "$LABWC_AUTOSTART" ]; then
  echo "$LABWC_AUTOSTART does not exist — is this a labwc session?" >&2
  exit 2
fi

# Edit the PACKAGED file, not a user copy: labwc runs with -m (--merge-config)
# on Pi OS, so ~/.config/labwc/autostart is executed IN ADDITION to this one.
# A user-level override duplicates the remaining entries and lets the panel
# come back at the next boot.
if [ -e "$KIOSK_HOME/.config/labwc/autostart" ]; then
  say "WARNING: $KIOSK_HOME/.config/labwc/autostart exists"
  say "         labwc -m merges it with the packaged file; entries run twice"
fi

needs_edit=0
grep -qE '^[[:space:]]*/usr/bin/lwrespawn[[:space:]]+/usr/bin/(wf-panel-pi|pcmanfm-pi)' \
  "$LABWC_AUTOSTART" && needs_edit=1

if [ "$needs_edit" = 0 ]; then
  say "already disabled — nothing to do"
elif [ "$CHECK_ONLY" = 1 ]; then
  say "WOULD disable wf-panel-pi and pcmanfm-pi in $LABWC_AUTOSTART"
else
  [ -f "$BACKUP" ] || { cp "$LABWC_AUTOSTART" "$BACKUP"; say "kept the packaged file as $BACKUP"; }
  # Comment the two lines, leaving a reason in place of a silent deletion.
  tmp=$(mktemp)
  awk '
    /^[[:space:]]*\/usr\/bin\/lwrespawn[[:space:]]+\/usr\/bin\/wf-panel-pi/ {
      print "# Kiosk: wf-panel-pi disabled. lwrespawn remaps it whenever it dies, and a"
      print "# layer-shell panel mapped after the terminal window sits above it."
      print "# " $0; next
    }
    /^[[:space:]]*\/usr\/bin\/lwrespawn[[:space:]]+\/usr\/bin\/pcmanfm-pi/ {
      print "# Kiosk: pcmanfm-pi disabled. Its desktop window takes keyboard focus, so"
      print "# card scans land in its find box and a blanked screen never wakes."
      print "# " $0; next
    }
    { print }
  ' "$LABWC_AUTOSTART" > "$tmp"
  cat "$tmp" > "$LABWC_AUTOSTART"   # preserve the file's mode and ownership
  rm -f "$tmp"
  say "disabled wf-panel-pi and pcmanfm-pi"
fi

if [ "$APPLY_NOW" = 1 ] && [ "$CHECK_ONLY" = 0 ]; then
  # Kill the respawner before the process, or lwrespawn simply starts it again.
  pkill -f 'lwrespawn /usr/bin/wf-panel-pi' 2>/dev/null || true
  pkill -f 'lwrespawn /usr/bin/pcmanfm-pi' 2>/dev/null || true
  sleep 1
  pkill -x wf-panel-pi 2>/dev/null || true
  pkill -f 'pcmanfm --desktop' 2>/dev/null || true
  say "stopped the running panel and desktop"
fi

# ------------------------------------------------------- 2. pin the output mode

step "2. Output mode"

KANSHI_DIR="$KIOSK_HOME/.config/kanshi"
KANSHI_CONF="$KANSHI_DIR/config"

if [ -s "$KANSHI_CONF" ]; then
  say "already pinned ($(wc -c < "$KANSHI_CONF") bytes)"
elif [ -s "$KANSHI_CONF.init" ]; then
  if [ "$CHECK_ONLY" = 1 ]; then
    say "WOULD copy $KANSHI_CONF.init over the empty $KANSHI_CONF"
  else
    install -o "$KIOSK_USER" -g "$KIOSK_USER" -m 0644 "$KANSHI_CONF.init" "$KANSHI_CONF"
    say "pinned the mode from config.init:"
    sed 's/^/      /' "$KANSHI_CONF"
  fi
else
  say "no $KANSHI_CONF.init to copy — pin the mode by hand, e.g.:"
  say '    profile { output HDMI-A-1 enable mode 1280x800@59.996 position 0,0 }'
fi

# ------------------------------------------------------------ 3. audio output

step "3. Audio output"

# The speakers on this hardware are in the HDMI display; the 3.5 mm jack is
# unused. WirePlumber's stock priorities pick the jack often enough that the
# terminal comes up silent after some boots and not others — and nothing
# reports an error, because playing into an unconnected port succeeds at every
# layer. Sink the analog device below HDMI so the choice cannot go wrong even
# when ~/.local/state/wireplumber is empty (a fresh user, a reset, an upgrade).
#
# Diagnosed on ruderbar 2026-08-30. See docs/audio-dropout-debugging.md §G.

WP_DIR="$KIOSK_HOME/.config/wireplumber/wireplumber.conf.d"
WP_RULE="$WP_DIR/50-clubbar-hdmi-priority.conf"

read -r -d '' WP_CONTENT <<'WPEOF' || true
# Club Bar terminal — installed by kiosk-session-setup.sh.
#
# The 3.5 mm jack has nothing plugged into it. Left to its own priorities
# WirePlumber picks it as the default sink on some boots, and the terminal is
# then silent with no error anywhere: pactl, the ALSA substream and GStreamer
# all report success while the sound goes to a dead port.
#
# Sink the analog device below HDMI so the display speakers win even when no
# default has been persisted.
monitor.alsa.rules = [
  {
    matches = [ { node.name = "~alsa_output.platform-.*\.mailbox.*" } ]
    actions = {
      update-props = {
        priority.session = 100
        priority.driver  = 100
      }
    }
  }
  {
    matches = [ { node.name = "~alsa_output.platform-.*\.hdmi.*" } ]
    actions = {
      update-props = {
        priority.session = 2000
        priority.driver  = 2000
      }
    }
  }
]
WPEOF

if [ -r "$WP_RULE" ] && [ "$(cat "$WP_RULE")" = "$WP_CONTENT" ]; then
  say "HDMI priority rule already installed"
elif [ "$CHECK_ONLY" = 1 ]; then
  say "WOULD install $WP_RULE (HDMI above the unused analog jack)"
else
  install -d -o "$KIOSK_USER" -g "$KIOSK_USER" -m 0755 "$WP_DIR"
  printf '%s\n' "$WP_CONTENT" > "$WP_RULE"
  chown "$KIOSK_USER:$KIOSK_USER" "$WP_RULE"
  chmod 0644 "$WP_RULE"
  say "installed $WP_RULE"

  # Apply it now. WirePlumber reads its config at start, so without this the
  # rule does nothing until the next session — and a terminal in service stays
  # silent for the rest of the evening.
  if run_as_kiosk systemctl --user restart wireplumber 2>/dev/null; then
    sleep 3
    say "restarted wireplumber"
  else
    say "NOTE: could not restart wireplumber; the rule applies at next login"
  fi
fi

# Persist the choice as well as biasing it. The rule decides a fresh pick; the
# saved default is what an already-running session honours.
if [ "$CHECK_ONLY" = 0 ]; then
  hdmi_sink=$(run_as_kiosk pactl list short sinks 2>/dev/null | awk '/hdmi/ {print $2; exit}')
  if [ -n "$hdmi_sink" ]; then
    run_as_kiosk pactl set-default-sink "$hdmi_sink" 2>/dev/null || true
    run_as_kiosk pactl set-sink-mute "$hdmi_sink" 0 2>/dev/null || true
    say "default sink set to $hdmi_sink (unmuted)"
  else
    say "NOTE: no HDMI sink visible — is the display connected and awake?"
  fi
fi

# ------------------------------------------------------------------- 4. verify

step "4. Verify"

# pgrep -c prints 0 and exits non-zero when nothing matches; wc -l always answers.
# `|| true` inside the group matters: pgrep exits 1 when nothing matches, and
# with `set -e -o pipefail` that would abort the script mid-verify.
count_procs() { { pgrep "$@" 2>/dev/null || true; } | wc -l | tr -d ' '; }
competitors=$(( $(count_procs -x wf-panel-pi) + $(count_procs -f 'pcmanfm --desktop') ))

if grep -qE '^[[:space:]]*/usr/bin/lwrespawn[[:space:]]+/usr/bin/(wf-panel-pi|pcmanfm-pi)' "$LABWC_AUTOSTART"; then
  say "FAIL: $LABWC_AUTOSTART still starts a panel or desktop"
  exit 1
fi
say "OK: nothing starts a panel or desktop at boot"

if [ "$competitors" -gt 0 ]; then
  say "NOTE: $competitors still running from the current session"
  say "      re-run with --now, or reboot, before relying on wake-on-input"
else
  say "OK: no panel or desktop running"
fi

if [ -s "$KANSHI_CONF" ]; then say "OK: output mode pinned"; else say "NOTE: output mode not pinned"; fi

printf '\nRun ./kiosk-doctor.sh for the full check.\n'
