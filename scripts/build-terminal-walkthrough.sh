#!/usr/bin/env bash
#
# build-terminal-walkthrough.sh — Terminal walkthrough: Flutter integration test → screenshots → crossfade video
#
# Usage:
#   ./scripts/build-terminal-walkthrough.sh                  # full pipeline: capture screenshots → build video
#   ./scripts/build-terminal-walkthrough.sh --video-only     # skip capture, build video from existing PNGs
#   ./scripts/build-terminal-walkthrough.sh --hold 4         # hold each screenshot for 4 seconds (default: 3)
#   ./scripts/build-terminal-walkthrough.sh --fade 0.8       # crossfade duration in seconds (default: 0.5)
#
# Requirements: ffmpeg, jq, flutter

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
TERMINAL_DIR="$PROJECT_ROOT/terminal-frontend"
# macOS sandboxes the Flutter app — screenshots land in the container.
# Bundle ID from macos/Runner/Configs/AppInfo.xcconfig: PRODUCT_BUNDLE_IDENTIFIER = de.clubbar.clubbarTerminal
MACOS_CONTAINER="$HOME/Library/Containers/de.clubbar.clubbarTerminal/Data/walkthrough-screenshots"
SCREENSHOTS_DIR="$TERMINAL_DIR/walkthrough-screenshots"
MANIFEST_JSON="$SCREENSHOTS_DIR/manifest.json"
OUTPUT_DIR="$PROJECT_ROOT/terminal-walkthrough-output"
FINAL_VIDEO="$PROJECT_ROOT/terminal-walkthrough.mp4"

# Timing defaults
HOLD_SEC="3"
FADE_SEC="0.5"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log()  { echo -e "${BLUE}[terminal-walkthrough]${NC} $*"; }
ok()   { echo -e "${GREEN}[terminal-walkthrough]${NC} $*"; }
warn() { echo -e "${YELLOW}[terminal-walkthrough]${NC} $*"; }
err()  { echo -e "${RED}[terminal-walkthrough]${NC} $*" >&2; }

# ---------- Preflight checks ----------

check_deps() {
  local missing=()
  command -v ffmpeg  >/dev/null 2>&1 || missing+=(ffmpeg)
  command -v jq      >/dev/null 2>&1 || missing+=(jq)
  command -v flutter >/dev/null 2>&1 || missing+=(flutter)

  if [[ ${#missing[@]} -gt 0 ]]; then
    err "Missing required tools: ${missing[*]}"
    err "Install with: brew install ${missing[*]}"
    exit 1
  fi
}

# ---------- Step 1: Run Flutter integration test to capture screenshots ----------

run_walkthrough() {
  log "Running terminal walkthrough integration test..."

  cd "$TERMINAL_DIR"
  flutter test integration_test/walkthrough_test.dart --device-id macos
  cd "$PROJECT_ROOT"

  # Copy screenshots from macOS sandbox container to project tree
  if [[ ! -d "$MACOS_CONTAINER" ]]; then
    err "Screenshots not found at $MACOS_CONTAINER"
    err "The walkthrough test may not have produced screenshots."
    exit 1
  fi

  rm -rf "$SCREENSHOTS_DIR"
  cp -r "$MACOS_CONTAINER" "$SCREENSHOTS_DIR"
  log "Copied screenshots from macOS container to $SCREENSHOTS_DIR"

  if [[ ! -f "$MANIFEST_JSON" ]]; then
    err "Manifest not found at $MANIFEST_JSON"
    exit 1
  fi

  local count
  count=$(jq '.screenshots | length' "$MANIFEST_JSON")
  ok "Walkthrough complete: $count screenshots captured."
}

# ---------- Step 2: Build slideshow video (same logic as admin walkthrough) ----------

build_video() {
  log "Building slideshow video..."

  if [[ ! -f "$MANIFEST_JSON" ]]; then
    err "Manifest not found at $MANIFEST_JSON"
    exit 1
  fi

  local count
  count=$(jq '.screenshots | length' "$MANIFEST_JSON")

  if [[ "$count" -eq 0 ]]; then
    err "No screenshots found in manifest."
    exit 1
  fi

  mkdir -p "$OUTPUT_DIR"

  log "Processing $count screenshots (hold=${HOLD_SEC}s, fade=${FADE_SEC}s)..."

  # Step 2a: Create individual video clips from each screenshot with subtitle
  for i in $(seq 0 $((count - 1))); do
    local entry
    entry=$(jq -c ".screenshots[$i]" "$MANIFEST_JSON")

    local filename subtitle
    filename=$(echo "$entry" | jq -r '.file')
    subtitle=$(echo "$entry" | jq -r '.subtitle')

    local input_png="$SCREENSHOTS_DIR/$filename"
    local output_clip="$OUTPUT_DIR/clip-$(printf '%03d' $((i+1))).mp4"

    if [[ ! -f "$input_png" ]]; then
      warn "Screenshot not found: $input_png (skipping)"
      continue
    fi

    # Escape special chars for ffmpeg drawtext
    local escaped_subtitle
    escaped_subtitle=$(echo "$subtitle" | sed "s/'/\\\\\\\\'/g" | sed 's/:/\\:/g')

    log "  [$((i+1))/$count] $subtitle"

    # Duration = HOLD_SEC + FADE_SEC (extra for crossfade overlap, except last)
    local clip_duration
    if [[ $i -lt $((count - 1)) ]]; then
      clip_duration=$(echo "$HOLD_SEC + $FADE_SEC" | bc)
    else
      clip_duration="$HOLD_SEC"
    fi

    # crop=trunc(iw/2)*2:trunc(ih/2)*2 ensures even dimensions for libx264
    ffmpeg -y \
      -loop 1 -i "$input_png" \
      -t "$clip_duration" \
      -vf "crop=trunc(iw/2)*2:trunc(ih/2)*2,drawtext=text='${escaped_subtitle}':fontsize=28:fontcolor=white:borderw=2:bordercolor=black:box=1:boxcolor=black@0.6:boxborderw=8:x=(w-text_w)/2:y=h-text_h-40" \
      -c:v libx264 -preset fast -crf 18 \
      -pix_fmt yuv420p \
      -r 30 \
      "$output_clip" 2>/dev/null
  done

  # Step 2b: Build xfade filter chain to crossfade all clips
  local clips=()
  for f in "$OUTPUT_DIR"/clip-*.mp4; do
    [[ -f "$f" ]] && clips+=("$f")
  done

  local num_clips=${#clips[@]}

  if [[ "$num_clips" -eq 0 ]]; then
    err "No clips were produced."
    exit 1
  fi

  if [[ "$num_clips" -eq 1 ]]; then
    cp "${clips[0]}" "$FINAL_VIDEO"
  else
    local inputs=""
    local filter_complex=""
    local prev_label="[0:v]"

    for i in $(seq 0 $((num_clips - 1))); do
      inputs+=" -i ${clips[$i]}"
    done

    for i in $(seq 0 $((num_clips - 2))); do
      local next_label="[$((i+1)):v]"
      local out_label
      if [[ $i -eq $((num_clips - 2)) ]]; then
        out_label="[outv]"
      else
        out_label="[v$((i+1))]"
      fi

      local offset
      offset=$(echo "$HOLD_SEC * ($i + 1)" | bc)

      filter_complex+="${prev_label}${next_label}xfade=transition=fade:duration=${FADE_SEC}:offset=${offset}${out_label};"
      prev_label="$out_label"
    done

    # Remove trailing semicolon
    filter_complex="${filter_complex%;}"

    log "Crossfading $num_clips clips..."

    eval ffmpeg -y $inputs \
      -filter_complex "'${filter_complex}'" \
      -map "'[outv]'" \
      -c:v libx264 -preset fast -crf 18 \
      -pix_fmt yuv420p \
      -movflags +faststart \
      "'$FINAL_VIDEO'" 2>/dev/null
  fi

  ok "Final video: $FINAL_VIDEO"

  local duration
  duration=$(ffprobe -v error -show_entries format=duration \
    -of default=noprint_wrappers=1:nokey=1 "$FINAL_VIDEO" 2>/dev/null || echo "unknown")
  local size
  size=$(du -h "$FINAL_VIDEO" | cut -f1)

  echo ""
  ok "Terminal walkthrough video complete!"
  echo "   Screenshots: $num_clips"
  echo "   Hold:        ${HOLD_SEC}s per image"
  echo "   Crossfade:   ${FADE_SEC}s"
  echo "   Duration:    ${duration}s"
  echo "   Size:        $size"
  echo "   File:        $FINAL_VIDEO"
  echo ""
}

# ---------- Cleanup ----------

cleanup() {
  if [[ -d "$OUTPUT_DIR" ]]; then
    log "Cleaning up intermediate files..."
    rm -rf "$OUTPUT_DIR"
  fi
}

# ---------- Main ----------

main() {
  check_deps

  local video_only=false

  while [[ $# -gt 0 ]]; do
    case "$1" in
      --video-only) video_only=true; shift ;;
      --hold)       HOLD_SEC="$2"; shift 2 ;;
      --fade)       FADE_SEC="$2"; shift 2 ;;
      *) err "Unknown option: $1"; exit 1 ;;
    esac
  done

  if [[ "$video_only" == false ]]; then
    run_walkthrough
  fi

  build_video
  cleanup

  ok "Done!"
}

main "$@"
