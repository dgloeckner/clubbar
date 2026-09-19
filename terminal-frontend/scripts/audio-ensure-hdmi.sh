#!/usr/bin/env bash
#
# audio-ensure-hdmi.sh — give WirePlumber a second look at the HDMI card when
# its first one came too early.
#
# WirePlumber probes a card's profiles once, when it starts, by trying to open
# the PCM. vc4 refuses to open the HDMI PCM while no display is attached, so a
# Pi that boots before its screen is awake ends up with an HDMI card offering
# "off" and "pro-audio" only — no hdmi-stereo, therefore no HDMI sink — and
# nothing ever probes again. The 3.5 mm jack is then the only sink there is, no
# priority rule can choose one that does not exist, and the terminal is silent
# until somebody restarts the sound server.
#
# Measured on ruderbar 2026-09-19: nine days silent after one such boot, the
# HDMI jack reading "on" the whole time. See docs/audio-dropout-debugging.md §H.
#
# Run from clubbar-audio-ensure.timer. It does one thing: if a display is
# connected and no HDMI sink exists, restart WirePlumber so it re-probes.
# Always exits 0 — a terminal with no sound still sells beer, and a failing
# unit on every tick would bury the one line that matters.
#
# Environment:
#   EXPECT_SINK=hdmi                  substring an acceptable sink must match
#   CLUBBAR_AUDIO_MAX_RESTARTS=3      per boot; a display with no speakers
#                                     never grows the profile, and the sound
#                                     server must not bounce forever for it

set -uo pipefail

EXPECT_SINK="${EXPECT_SINK:-hdmi}"
MAX_RESTARTS="${CLUBBAR_AUDIO_MAX_RESTARTS:-3}"
SETTLE_SECONDS="${CLUBBAR_AUDIO_SETTLE_SECONDS:-5}"
DRM_STATUS_GLOB="${CLUBBAR_DRM_STATUS_GLOB:-/sys/class/drm/card*-HDMI-A-*/status}"
# Under XDG_RUNTIME_DIR on purpose: it is a tmpfs, so the count is per boot
# without anybody having to reset it.
STATE_DIR="${CLUBBAR_AUDIO_STATE_DIR:-${XDG_RUNTIME_DIR:-/run/user/$(id -u)}/clubbar-audio}"
ATTEMPTS_FILE="$STATE_DIR/restarts"

log() { printf 'audio-ensure: %s\n' "$1"; }

has_expected_sink() {
  pactl list short sinks 2>/dev/null | awk '{print $2}' | grep -q -- "$EXPECT_SINK"
}

display_connected() {
  local status
  # Unquoted on purpose: the glob has to expand.
  for status in $DRM_STATUS_GLOB; do
    [ -r "$status" ] && [ "$(cat "$status")" = "connected" ] && return 0
  done
  return 1
}

if ! pactl info >/dev/null 2>&1; then
  log "no sound server reachable — nothing to re-probe"
  exit 0
fi

if has_expected_sink; then
  exit 0
fi

if ! display_connected; then
  log "no '$EXPECT_SINK' sink, and no display connected — a re-probe would find the same nothing"
  exit 0
fi

mkdir -p "$STATE_DIR"
attempts=$(cat "$ATTEMPTS_FILE" 2>/dev/null || echo 0)
case "$attempts" in ''|*[!0-9]*) attempts=0 ;; esac

if [ "$attempts" -ge "$MAX_RESTARTS" ]; then
  log "no '$EXPECT_SINK' sink after $attempts restart(s) this boot — giving up; does this display have speakers?"
  exit 0
fi

attempts=$((attempts + 1))
printf '%s\n' "$attempts" > "$ATTEMPTS_FILE"

log "display connected but no '$EXPECT_SINK' sink — restarting wireplumber to re-probe ($attempts/$MAX_RESTARTS)"
systemctl --user restart wireplumber || log "could not restart wireplumber"
sleep "$SETTLE_SECONDS"

if has_expected_sink; then
  log "recovered: $(pactl list short sinks 2>/dev/null | awk '{print $2}' | grep -- "$EXPECT_SINK" | head -1)"
else
  log "still no '$EXPECT_SINK' sink after the restart"
fi
exit 0
