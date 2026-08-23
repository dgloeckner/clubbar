#!/usr/bin/env bash
#
# audio-diagnose.sh — capture the state of terminal audio while it is broken.
#
# The terminal sometimes goes silent with no configuration or binary change and
# comes back after a reboot. A reboot destroys every piece of evidence that
# could say why, so run this FIRST, while the fault is live, and reboot after.
#
# Everything here is read-only apart from the report file it writes. It makes
# noise on purpose in the last section (that is the test); pass --no-play to
# skip it.
#
# Usage:
#   ./audio-diagnose.sh                 # writes ~/audio-diagnose-<timestamp>.txt
#   ./audio-diagnose.sh /tmp/report.txt # writes there instead
#   ./audio-diagnose.sh --no-play       # no playback tests, no noise
#
# Read the result with docs/audio-dropout-debugging.md next to it.

set -uo pipefail   # deliberately not -e: every probe is best effort

PLAY=1
REPORT=""
for arg in "$@"; do
  case "$arg" in
    --no-play) PLAY=0 ;;
    -h|--help) sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    *) REPORT="$arg" ;;
  esac
done
[ -n "$REPORT" ] || REPORT="$HOME/audio-diagnose-$(date +%Y%m%d-%H%M%S).txt"

exec > >(tee "$REPORT") 2>&1

APP_BIN=clubbar_terminal
CONFIG_DIR="$HOME/.local/share/de.clubbar.clubbar_terminal"
TMP="${TMPDIR:-/tmp}"
WAV=/usr/share/sounds/alsa/Front_Center.wav

section() { printf '\n\n===== %s =====\n' "$1"; }
run() { printf '\n$ %s\n' "$*"; "$@" 2>&1 | sed 's/^/  /'; }
sh_run() { printf '\n$ %s\n' "$1"; sh -c "$1" 2>&1 | sed 's/^/  /'; }
have() { command -v "$1" >/dev/null 2>&1; }

section "0. When and where"
run date -Is
run uptime
sh_run "uname -a; . /etc/os-release 2>/dev/null && echo \"\$PRETTY_NAME\""

section "1. The terminal process"
# Was it started at boot and never restarted? A process older than every sound
# server on the box cannot be talking to the one running now.
# The bracket in the pattern keeps pgrep from matching this script's own
# command line; the binary name is over 15 chars, so -f is required.
PID="$(pgrep -f '[c]lubbar_terminal' | head -1)"
if [ -z "$PID" ]; then
  echo "  clubbar_terminal is NOT running — start it before reading anything below."
else
  echo "  pid=$PID"
  run ps -o pid,lstart,etime,stat,rss,cmd -p "$PID"
  # The env the app actually got. GST_AUDIO_SINK is set in the .desktop entry;
  # if it is missing here, the entry was not the thing that launched this.
  sh_run "tr '\\0' '\\n' < /proc/$PID/environ | grep -E '^(GST_|PULSE|XDG_RUNTIME|WAYLAND|DISPLAY|TMPDIR|TERMINAL_SOUNDS)' | sort"
  # Which audio path it holds open right now: an ALSA device, or a socket to a
  # sound server. This is what decides the whole rest of the investigation.
  sh_run "ls -l /proc/$PID/fd 2>/dev/null | grep -E 'snd|pulse|pipewire|native' || echo '(no audio fd open right now: normal between sounds)'"
fi

section "2. Sound cards as the kernel sees them"
run cat /proc/asound/cards
have aplay && run aplay -l
# state / owner_pid per playback substream: 'closed' vs RUNNING, and who has it.
sh_run "for f in /proc/asound/card*/pcm*p/sub*/status; do [ -e \"\$f\" ] || continue; echo \"--- \$f\"; cat \"\$f\"; done"

section "3. Who is holding the audio device"
if have fuser; then
  sh_run "fuser -v /dev/snd/* 2>&1"
elif have lsof; then
  sh_run "lsof /dev/snd/* 2>&1"
else
  echo "  neither fuser nor lsof installed (sudo apt install psmisc)"
fi

section "4. Sound servers, and when they started"
# A server that started AFTER the terminal is the classic cause: the app's
# GStreamer sink was bound to the old one and never reconnects.
sh_run "pgrep -a -f '[p]ipewire|[w]ireplumber|[p]ulseaudio' || echo '(none running: the app talks to ALSA directly)'"
sh_run "for p in \$(pgrep -f '[p]ipewire|[w]ireplumber|[p]ulseaudio'); do ps -o pid,lstart,cmd -p \$p --no-headers; done"
have pactl && run pactl info
have pactl && run pactl list short sinks
have wpctl && run wpctl status

section "5. Mixer state (muted? zero volume?)"
if have amixer; then
  sh_run "for c in \$(sed -n 's/^ *\\([0-9]\\+\\) \\[.*/\\1/p' /proc/asound/cards); do echo \"--- card \$c\"; amixer -c \$c scontents 2>&1 | head -30; done"
else
  echo "  amixer not installed (sudo apt install alsa-utils)"
fi

section "6. The clips the app extracted at startup"
# audioplayers copies each asset out of the bundle into the temp dir once, as
# <tmp>/<uuid>/sounds/<name>.mp3. Missing files here means the app cannot play
# anything regardless of the audio stack.
sh_run "ls -l $TMP/*/sounds/ 2>&1 | head -30"

section "7. The terminal's own log"
sh_run "ls -l '$CONFIG_DIR/logs/' 2>&1"
sh_run "tail -60 '$CONFIG_DIR/logs/error.log' 2>&1"
sh_run "tail -60 '$CONFIG_DIR/logs/stdout.log' 2>&1 || true"

section "8. Kernel and session messages"
sh_run "dmesg 2>&1 | grep -iE 'vc4|hdmi|snd|audio|alsa|xrun' | tail -30 || echo '(nothing, or dmesg needs root: try sudo dmesg)'"
have journalctl && sh_run "journalctl -b -p warning -n 60 --no-pager 2>&1 | tail -60"
have journalctl && sh_run "journalctl --user -b -n 60 --no-pager 2>&1 | grep -iE 'pipewire|pulse|wireplumber|alsa|gst' | tail -30"

if [ "$PLAY" -eq 1 ]; then
  section "9. Playback tests (these make noise)"
  echo "  Legend:"
  echo "    exit 0 and audible          → that path works"
  echo "    'Device or resource busy'   → something else holds the card."
  echo "                                  While the terminal is running that can be normal;"
  echo "                                  after stopping it, it is the fault."
  echo "    exit 0 and NOT audible      → the stream went somewhere you cannot hear"
  echo "                                  (wrong card, muted, HDMI link down)"
  [ -f "$WAV" ] || echo "  (missing $WAV — sudo apt install alsa-utils)"

  if have aplay && [ -f "$WAV" ]; then
    sh_run "aplay -D default '$WAV'; echo exit=\$?"
    sh_run "aplay -D plughw:0,0 '$WAV'; echo exit=\$?"
  fi

  if have gst-launch-1.0 && [ -f "$WAV" ]; then
    # 1) what the .desktop entry asks for
    sh_run "GST_AUDIO_SINK=alsasink gst-launch-1.0 -q playbin uri=file://$WAV; echo exit=\$?"
    # 2) what audioplayers actually builds: playbin with an audiopanorama +
    #    autoaudiosink bin as its audio-sink. autoaudiosink picks a sink itself,
    #    so this is the only test that reproduces the app's real path.
    sh_run "gst-launch-1.0 -q playbin uri=file://$WAV audio-sink='audiopanorama ! autoaudiosink'; echo exit=\$?"
    # 3) one of the app's own clips, decoded the same way
    CLIP="$(ls "$TMP"/*/sounds/checkout_success.mp3 2>/dev/null | head -1)"
    if [ -n "$CLIP" ]; then
      sh_run "gst-launch-1.0 -q playbin uri=file://$CLIP audio-sink='audiopanorama ! autoaudiosink'; echo exit=\$?"
    fi
  fi
fi

section "Done"
cat <<EOF
  Report written to: $REPORT

  Next, before you reboot:
    1. Stop only the app  ->  pkill -f $APP_BIN
    2. Re-run the playback tests above (they now have the card to themselves)
    3. Start the app again and try a scan
       If sound is back WITHOUT a reboot, the fault was inside the process.
       If it is still silent, it is the audio stack or the HDMI link.
  Write down which of the two it was — that answer halves the search.

  See terminal-frontend/docs/audio-dropout-debugging.md.
EOF
