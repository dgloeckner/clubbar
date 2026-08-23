#!/usr/bin/env bash
#
# build-walkthrough-video.sh — Full pipeline: reset DB → record walkthrough → produce final video
#
# Usage:
#   ./scripts/build-walkthrough-video.sh                    # all scenes
#   ./scripts/build-walkthrough-video.sh --scenes 1         # scene 01 only
#   ./scripts/build-walkthrough-video.sh --scenes 1-3       # scenes 01–03
#   ./scripts/build-walkthrough-video.sh --scenes 1,4,7     # specific scenes
#   ./scripts/build-walkthrough-video.sh --video-only       # skip DB reset + recording, just build video
#   ./scripts/build-walkthrough-video.sh --no-reset         # skip DB reset, run recording + video
#   ./scripts/build-walkthrough-video.sh --trim 2.0         # trim first 2s of blank page per clip (default: 1.5)
#
# Requirements: ffmpeg, jq, node/npx

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
E2E_DIR="$PROJECT_ROOT/e2etests"
SUBTITLES_JSON="$E2E_DIR/walkthrough-subtitles.json"
OUTPUT_DIR="$PROJECT_ROOT/walkthrough-output"
FINAL_VIDEO="$PROJECT_ROOT/walkthrough.mp4"

# Trim leading blank frames (page loads before content renders)
TRIM_START_SEC="1.5"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

log()  { echo -e "${BLUE}[walkthrough]${NC} $*"; }
ok()   { echo -e "${GREEN}[walkthrough]${NC} $*"; }
warn() { echo -e "${YELLOW}[walkthrough]${NC} $*"; }
err()  { echo -e "${RED}[walkthrough]${NC} $*" >&2; }

# ---------- Scene filter → Playwright --grep pattern ----------

# Converts --scenes argument into a Playwright --grep regex.
#   "1"     → "^01 —"
#   "1-3"   → "^0[1-3] —"   (only works for single-digit ranges)
#   "1,4,7" → "^(01|04|07) —"
build_grep_pattern() {
  local scenes="$1"

  # Single scene: "1" or "01"
  if [[ "$scenes" =~ ^[0-9]{1,2}$ ]]; then
    printf '^%02d —' "$((10#$scenes))"
    return
  fi

  # Range: "1-3"
  if [[ "$scenes" =~ ^([0-9]{1,2})-([0-9]{1,2})$ ]]; then
    local from="${BASH_REMATCH[1]}"
    local to="${BASH_REMATCH[2]}"
    local parts=()
    for i in $(seq "$((10#$from))" "$((10#$to))"); do
      parts+=("$(printf '%02d' "$i")")
    done
    local joined
    joined=$(IFS='|'; echo "${parts[*]}")
    echo "^($joined) —"
    return
  fi

  # Comma-separated: "1,4,7"
  if [[ "$scenes" =~ ^[0-9]{1,2}(,[0-9]{1,2})+$ ]]; then
    local parts=()
    IFS=',' read -ra nums <<< "$scenes"
    for n in "${nums[@]}"; do
      parts+=("$(printf '%02d' "$((10#$n))")")
    done
    local joined
    joined=$(IFS='|'; echo "${parts[*]}")
    echo "^($joined) —"
    return
  fi

  err "Invalid --scenes format: '$scenes'. Use: 1, 1-3, or 1,4,7"
  exit 1
}

# ---------- Preflight checks ----------

check_deps() {
  local missing=()
  command -v ffmpeg >/dev/null 2>&1 || missing+=(ffmpeg)
  command -v jq    >/dev/null 2>&1 || missing+=(jq)
  command -v npx   >/dev/null 2>&1 || missing+=(npx)

  if [[ ${#missing[@]} -gt 0 ]]; then
    err "Missing required tools: ${missing[*]}"
    err "Install with: brew install ${missing[*]}"
    exit 1
  fi
}

# ---------- Step 1: Reset database ----------

reset_db() {
  log "Resetting database..."
  "$PROJECT_ROOT/scripts/reset-db.sh"
  ok "Database reset complete."
}

# ---------- Step 2: Run walkthrough ----------

run_walkthrough() {
  local grep_pattern="${1:-}"

  log "Running walkthrough tests (recording video)..."
  cd "$E2E_DIR"

  if [[ -n "$grep_pattern" ]]; then
    log "Scene filter: $grep_pattern"
    npx playwright test --project=walkthrough --workers=1 --grep "$grep_pattern"
  else
    npx playwright test --project=walkthrough --workers=1
  fi

  cd "$PROJECT_ROOT"

  if [[ ! -f "$SUBTITLES_JSON" ]]; then
    err "Subtitles JSON not found at $SUBTITLES_JSON"
    err "The subtitle reporter may not have produced output."
    exit 1
  fi

  local clip_count
  clip_count=$(jq '.clips | length' "$SUBTITLES_JSON")
  ok "Walkthrough complete: $clip_count clips recorded."
}

# ---------- Step 3: Build video with subtitles ----------

build_video() {
  log "Building final video..."

  if [[ ! -f "$SUBTITLES_JSON" ]]; then
    err "Subtitles JSON not found at $SUBTITLES_JSON"
    exit 1
  fi

  mkdir -p "$OUTPUT_DIR"

  local clip_count
  clip_count=$(jq '.clips | length' "$SUBTITLES_JSON")

  if [[ "$clip_count" -eq 0 ]]; then
    err "No clips found in subtitles JSON."
    exit 1
  fi

  log "Processing $clip_count clips..."

  # Build concat list
  local concat_list="$OUTPUT_DIR/concat.txt"
  : > "$concat_list"

  for i in $(seq 0 $((clip_count - 1))); do
    local clip_json
    clip_json=$(jq -c ".clips[$i]" "$SUBTITLES_JSON")

    local title video sub_count
    title=$(echo "$clip_json" | jq -r '.testTitle')
    video=$(echo "$clip_json" | jq -r '.video')
    sub_count=$(echo "$clip_json" | jq '.subtitles | length')

    if [[ ! -f "$video" ]]; then
      warn "Video file not found: $video (skipping clip: $title)"
      continue
    fi

    local output_clip="$OUTPUT_DIR/clip-$(printf '%02d' $i).mp4"

    # Build ffmpeg drawtext filter chain for subtitles
    # Subtitle times are adjusted by subtracting TRIM_START_SEC since we
    # trim the leading blank frames from each clip
    local filter=""
    if [[ "$sub_count" -gt 0 ]]; then
      for j in $(seq 0 $((sub_count - 1))); do
        local sub_json
        sub_json=$(echo "$clip_json" | jq -c ".subtitles[$j]")

        local text start_sec duration_sec end_sec
        text=$(echo "$sub_json" | jq -r '.text')
        start_sec=$(echo "$sub_json" | jq -r '.startSec')
        duration_sec=$(echo "$sub_json" | jq -r '.durationSec')

        # Adjust subtitle timing for trimmed start
        start_sec=$(echo "$start_sec - $TRIM_START_SEC" | bc)
        # Skip subtitles that would start before the trim point
        if (( $(echo "$start_sec < 0" | bc -l) )); then
          # Subtitle starts during trimmed portion — show from 0 with reduced duration
          duration_sec=$(echo "$duration_sec + $start_sec" | bc)
          start_sec="0"
          if (( $(echo "$duration_sec <= 0" | bc -l) )); then
            continue  # Entire subtitle is within trimmed portion
          fi
        fi

        end_sec=$(echo "$start_sec + $duration_sec" | bc)

        # Escape special chars for ffmpeg drawtext
        text=$(echo "$text" | sed "s/'/\\\\\\\\'/g" | sed 's/:/\\:/g')

        local dt="drawtext=text='${text}'"
        dt+=":fontsize=28"
        dt+=":fontcolor=white"
        dt+=":borderw=2"
        dt+=":bordercolor=black"
        dt+=":box=1"
        dt+=":boxcolor=black@0.6"
        dt+=":boxborderw=8"
        dt+=":x=(w-text_w)/2"
        dt+=":y=h-text_h-40"
        dt+=":enable='between(t,$start_sec,$end_sec)'"

        if [[ -n "$filter" ]]; then
          filter="$filter,$dt"
        else
          filter="$dt"
        fi
      done
    fi

    log "  [$((i+1))/$clip_count] $title ($sub_count subtitles, trim ${TRIM_START_SEC}s)"

    if [[ -n "$filter" ]]; then
      ffmpeg -y -ss "$TRIM_START_SEC" -i "$video" \
        -vf "$filter" \
        -c:v libx264 -preset fast -crf 23 \
        -c:a aac -b:a 128k \
        -movflags +faststart \
        "$output_clip" 2>/dev/null
    else
      # No subtitles — just transcode to MP4 with trim
      ffmpeg -y -ss "$TRIM_START_SEC" -i "$video" \
        -c:v libx264 -preset fast -crf 23 \
        -c:a aac -b:a 128k \
        -movflags +faststart \
        "$output_clip" 2>/dev/null
    fi

    echo "file '$(basename "$output_clip")'" >> "$concat_list"
  done

  # Concatenate all clips
  local num_clips
  num_clips=$(wc -l < "$concat_list" | tr -d ' ')

  if [[ "$num_clips" -eq 0 ]]; then
    err "No clips were processed successfully."
    exit 1
  fi

  log "Concatenating $num_clips clips into final video..."
  ffmpeg -y -f concat -safe 0 -i "$concat_list" \
    -c copy \
    "$FINAL_VIDEO" 2>/dev/null

  ok "Final video: $FINAL_VIDEO"

  # Show info
  local duration
  duration=$(ffprobe -v error -show_entries format=duration \
    -of default=noprint_wrappers=1:nokey=1 "$FINAL_VIDEO" 2>/dev/null || echo "unknown")
  local size
  size=$(du -h "$FINAL_VIDEO" | cut -f1)

  echo ""
  ok "Walkthrough video complete!"
  echo "   Duration: ${duration}s"
  echo "   Size:     $size"
  echo "   File:     $FINAL_VIDEO"
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
  local no_reset=false
  local scenes=""

  while [[ $# -gt 0 ]]; do
    case "$1" in
      --video-only) video_only=true; shift ;;
      --no-reset)   no_reset=true; shift ;;
      --scenes)     scenes="$2"; shift 2 ;;
      --trim)       TRIM_START_SEC="$2"; shift 2 ;;
      *) err "Unknown option: $1"; exit 1 ;;
    esac
  done

  local grep_pattern=""
  if [[ -n "$scenes" ]]; then
    grep_pattern=$(build_grep_pattern "$scenes")
  fi

  if [[ "$video_only" == false ]]; then
    if [[ "$no_reset" == false ]]; then
      reset_db
    fi
    run_walkthrough "$grep_pattern"
  fi

  build_video
  cleanup

  ok "Done!"
}

main "$@"
