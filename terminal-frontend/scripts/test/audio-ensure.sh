#!/usr/bin/env bash
#
# audio-ensure.sh — the two ways ruderbar went silent on 2026-09-19, held.
#
#   1. The WirePlumber rule kiosk-session-setup.sh installs was never loaded.
#      Its regexes escaped a dot as `\.`, WirePlumber parses a quoted string as
#      JSON, `\.` is not a JSON escape, and the whole section was refused with
#      "section 'monitor.alsa.rules' has no value" — in a journal nobody reads.
#   2. WirePlumber probes a card's profiles once, at start. The display was not
#      up yet, vc4 refuses to open the HDMI PCM with nothing attached, and the
#      card kept "off" and "pro-audio" only for the following nine days.
#
# `audio-ensure-hdmi.sh` is the answer to the second; it is run here against a
# stub pactl and systemctl, so this passes on any machine with bash.
#
# Usage: ./audio-ensure.sh      (exit 0 = all cases pass)

set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
ENSURE="$HERE/../audio-ensure-hdmi.sh"
SETUP="$HERE/../kiosk-session-setup.sh"

FAILURES=0
pass() { printf '[ OK ]  %s\n' "$1"; }
fail() { FAILURES=$((FAILURES + 1)); printf '[FAIL]  %s\n' "$1"; }

WORK="$(mktemp -d "${TMPDIR:-/tmp}/clubbar-audio-ensure.XXXXXX")"
trap '[ -n "$WORK" ] && [ -d "$WORK" ] && rm -rf "$WORK"' EXIT

mkdir -p "$WORK/bin"

# pactl: `info` succeeds unless told otherwise; `list short sinks` prints the
# fixture. systemctl: records the call, and lets a restart "recover" the sink
# by swapping in sinks.after — which is what a re-probe does on the real thing.
cat > "$WORK/bin/pactl" <<'STUB'
#!/usr/bin/env bash
case "$*" in
  info) [ ! -e "$STUB_DIR/pactl-down" ] ;;
  "list short sinks") cat "$STUB_DIR/sinks" 2>/dev/null ;;
  *) exit 0 ;;
esac
STUB
cat > "$WORK/bin/systemctl" <<'STUB'
#!/usr/bin/env bash
echo "$*" >> "$STUB_DIR/systemctl.log"
[ -e "$STUB_DIR/sinks.after" ] && cp "$STUB_DIR/sinks.after" "$STUB_DIR/sinks"
exit 0
STUB
chmod +x "$WORK/bin/pactl" "$WORK/bin/systemctl"

JACK='57	alsa_output.platform-fe00b840.mailbox.stereo-fallback	PipeWire	s16le 2ch 48000Hz	SUSPENDED'
HDMI='58	alsa_output.platform-fef00700.hdmi.hdmi-stereo	PipeWire	s16le 2ch 48000Hz	SUSPENDED'

# A fresh world per case: which sinks exist, and what the connector reports.
scenario() {
  local sinks="$1" connector="$2"
  rm -rf "$WORK/stub" "$WORK/drm" "$WORK/state"
  mkdir -p "$WORK/stub" "$WORK/drm/card1-HDMI-A-1" "$WORK/state"
  printf '%s\n' "$sinks" > "$WORK/stub/sinks"
  printf '%s\n' "$connector" > "$WORK/drm/card1-HDMI-A-1/status"
}

run_ensure() {
  PATH="$WORK/bin:$PATH" \
  STUB_DIR="$WORK/stub" \
  CLUBBAR_DRM_STATUS_GLOB="$WORK/drm/card*-HDMI-A-*/status" \
  CLUBBAR_AUDIO_STATE_DIR="$WORK/state" \
  CLUBBAR_AUDIO_SETTLE_SECONDS=0 \
  bash "$ENSURE" > "$WORK/out" 2>&1
}

restarts() {
  if [ -e "$WORK/stub/systemctl.log" ]; then
    grep -c 'restart wireplumber' "$WORK/stub/systemctl.log"
  else
    echo 0
  fi
}

assert_restarts() {
  local expected="$1" label="$2" actual
  actual="$(restarts)"
  if [ "$actual" = "$expected" ]; then
    pass "$label"
  else
    fail "$label — $actual restart(s), expected $expected"
    sed 's/^/        /' "$WORK/out"
  fi
}

echo "== the recovery =="

scenario "$JACK"$'\n'"$HDMI" connected
run_ensure; rc=$?
assert_restarts 0 "HDMI sink present: nothing is touched"
[ "$rc" = 0 ] && pass "  …and it exits 0" || fail "  …exit $rc, expected 0"

scenario "$JACK" disconnected
run_ensure
assert_restarts 0 "no sink and no display: a restart would re-probe into the same nothing"

scenario "$JACK" connected
printf '%s\n%s\n' "$JACK" "$HDMI" > "$WORK/stub/sinks.after"
run_ensure; rc=$?
assert_restarts 1 "no sink, display connected: WirePlumber is restarted to re-probe"
[ "$rc" = 0 ] && pass "  …and a recovered sink exits 0" || fail "  …exit $rc, expected 0"
run_ensure
assert_restarts 1 "  …and the next tick, finding the sink, leaves it alone"

# A display with no speakers never grows the profile. Without a ceiling the
# timer would bounce the sound server every two minutes for as long as the
# terminal is up.
scenario "$JACK" connected
run_ensure; run_ensure; run_ensure; run_ensure; run_ensure
assert_restarts 3 "a sink that never appears: three attempts per boot, then it stops"
if grep -qi 'giving up' "$WORK/out"; then
  pass "  …and says that it has given up"
else
  fail "  …giving up must be logged, or it reads as a timer that does nothing"
fi

scenario "$JACK" connected
touch "$WORK/stub/pactl-down"
run_ensure; rc=$?
assert_restarts 0 "no sound server reachable: not this script's fault to fix"
[ "$rc" = 0 ] && pass "  …and it still exits 0" || fail "  …exit $rc, expected 0"

echo "== the rule WirePlumber has to be able to parse =="

# Everything between the heredoc markers is the file as installed.
rule="$(sed -n "/<<'WPEOF'/,/^WPEOF/p" "$SETUP")"
if [ -z "$rule" ]; then
  fail "could not find the WirePlumber rule in kiosk-session-setup.sh"
elif printf '%s\n' "$rule" | grep -v '^#' | grep -q '\\'; then
  fail "the rule contains a backslash — not a JSON escape, so WirePlumber refuses the section"
else
  pass "no backslash escapes in the installed rule"
fi

echo
if [ "$FAILURES" -eq 0 ]; then echo "all cases pass"; else echo "$FAILURES failure(s)"; exit 1; fi
