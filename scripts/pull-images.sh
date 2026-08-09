#!/usr/bin/env bash
#
# pull-images.sh — prefetch the compose stack's images, falling back to our GHCR
# mirror when Docker Hub refuses.
#
# Anonymous Docker Hub pulls are rate limited per source IP, and a cloud session
# shares that IP with every other session on the host. Several agents starting
# the stack at once burn the budget, and from then on `docker compose up` fails
# with "429 Too Many Requests" for hours — a dead end inside the session, since
# the limit is not something you can wait out or authenticate around without
# credentials. The same images are mirrored to a public GHCR namespace, which
# has no such limit (see scripts/mirror-images.sh).
#
# This runs as a best-effort prefetch before `docker compose up`: it always
# exits 0, so a registry problem surfaces as compose's own error rather than as
# a failure in a helper script.
#
# Usage:
#   scripts/pull-images.sh [SERVICE...]     Prefetch images for these services
#                                           (default: every service compose
#                                           resolves for the active profiles)
#
# Environment:
#   CLUBBAR_IMAGE_MIRROR=1   Try the mirror first. Worth setting when several
#                            agents share an egress IP — it keeps the Docker Hub
#                            budget for the images that have no mirror.
#   CLUBBAR_MIRROR_NS        Mirror namespace (default: ghcr.io/dgloeckner)
#   CLUBBAR_PULL_RETRIES     Attempts per registry (default: 4)
#   CLUBBAR_SKIP_PREPULL=1   Do nothing. Honoured by scripts/dev-stack.sh.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
IMAGE_LIST="$SCRIPT_DIR/mirrored-images.txt"

MIRROR_NS="${CLUBBAR_MIRROR_NS:-ghcr.io/dgloeckner}"
RETRIES="${CLUBBAR_PULL_RETRIES:-4}"

cd "$PROJECT_ROOT"

case "${1:-}" in
    -h|--help) sed -n '5,30p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
esac

if [ "${CLUBBAR_SKIP_PREPULL:-0}" = "1" ]; then
    echo "--- image prefetch skipped (CLUBBAR_SKIP_PREPULL=1)"
    exit 0
fi

note() { echo "    $*"; }

# ---------------------------------------------------------------------------
# The host's Docker architecture, in the naming images use (amd64 / arm64).
# Everything hinges on this: a mirror pushed from an Apple Silicon laptop is
# arm64-only, and on an amd64 host it pulls perfectly and then dies at startup
# with "exec format error" — which reads as a broken container, not a bad
# fallback. Better to skip such a mirror and let the upstream error stand.
# ---------------------------------------------------------------------------
host_arch() {
    case "$(docker info --format '{{.Architecture}}' 2>/dev/null)" in
        x86_64|amd64)   echo amd64 ;;
        aarch64|arm64)  echo arm64 ;;
        armv7l|armv7)   echo arm ;;
        "")             echo "" ;;
        *)              docker info --format '{{.Architecture}}' 2>/dev/null ;;
    esac
}

HOST_ARCH="$(host_arch)"

# Upstream references that have a mirror, one per line.
MIRRORED=""
if [ -f "$IMAGE_LIST" ]; then
    MIRRORED="$(sed 's/#.*//' "$IMAGE_LIST" | tr -d '[:blank:]' | grep -v '^$' || true)"
fi

has_mirror() {
    echo "$MIRRORED" | grep -qxF "$1"
}

mirror_ref() {
    local ref="$1" repo tag
    case "$ref" in
        *:*) repo="${ref%:*}"; tag="${ref##*:}" ;;
        *)   repo="$ref";      tag="latest" ;;
    esac
    echo "$MIRROR_NS/${repo##*/}:$tag"
}

image_present() {
    docker image inspect "$1" > /dev/null 2>&1
}

arch_of() {
    docker image inspect --format '{{.Architecture}}' "$1" 2>/dev/null || true
}

# ---------------------------------------------------------------------------
# pull_with_retry <ref> — pull, retrying transient failures with exponential
# backoff (2s, 4s, 8s, 16s).
#
# A rate-limit rejection breaks out immediately instead: Docker Hub's window is
# measured in hours, so retrying it only delays the fallback that would have
# worked. Everything else (a reset connection, a 5xx from the CDN) is exactly
# what backoff is for.
# ---------------------------------------------------------------------------
RATE_LIMITED=0

pull_with_retry() {
    local ref="$1" attempt=1 delay=2 out
    while :; do
        # Progress is captured rather than streamed, so say what is happening
        # first — a silent two-minute pull of a few hundred MB is
        # indistinguishable from a hang.
        note "$ref: pulling..."
        if out="$(docker pull "$ref" 2>&1)"; then
            return 0
        fi
        if echo "$out" | grep -qiE 'toomanyrequests|429 Too Many Requests|rate limit'; then
            RATE_LIMITED=1
            note "$ref: rate limited by the registry — not retrying"
            return 1
        fi
        if [ "$attempt" -ge "$RETRIES" ]; then
            note "$ref: failed after $attempt attempt(s)"
            echo "$out" | tail -3 | sed 's/^/      /'
            return 1
        fi
        note "$ref: attempt $attempt failed, retrying in ${delay}s"
        sleep "$delay"
        attempt=$((attempt + 1))
        delay=$((delay * 2))
    done
}

# ---------------------------------------------------------------------------
# fetch <upstream-ref> — make the reference available locally, from wherever.
#
# When the mirror is what succeeded, it is tagged with the upstream name so
# compose finds it without docker-compose.yml having to know a mirror exists.
# Compose's default pull policy is "missing", so a locally present tag is used
# as is and never re-pulled.
# ---------------------------------------------------------------------------
fetch() {
    local ref="$1" mirror="" sources=() src

    if image_present "$ref"; then
        note "$ref: already present"
        return 0
    fi

    if has_mirror "$ref"; then
        mirror="$(mirror_ref "$ref")"
    fi

    if [ -n "$mirror" ] && [ "${CLUBBAR_IMAGE_MIRROR:-0}" = "1" ]; then
        sources=("$mirror" "$ref")
    elif [ -n "$mirror" ]; then
        sources=("$ref" "$mirror")
    else
        sources=("$ref")
    fi

    for src in "${sources[@]}"; do
        pull_with_retry "$src" || continue

        if [ "$src" = "$ref" ]; then
            note "$ref: ok"
            return 0
        fi

        local got
        got="$(arch_of "$src")"
        if [ -n "$HOST_ARCH" ] && [ -n "$got" ] && [ "$got" != "$HOST_ARCH" ]; then
            note "$src: mirror is $got but this host is $HOST_ARCH — ignoring it"
            note "  (fix the mirror: scripts/mirror-images.sh --check)"
            docker image rm "$src" > /dev/null 2>&1 || true
            continue
        fi

        docker tag "$src" "$ref"
        note "$ref: ok, from the mirror ($src)"
        return 0
    done

    return 1
}

# ---------------------------------------------------------------------------
# What to fetch: whatever compose resolves for the requested services. Services
# that only build (admin-frontend) contribute nothing here, and their base
# images are pulled by the builder.
# ---------------------------------------------------------------------------
IMAGES=()
while read -r img; do
    [ -n "$img" ] && IMAGES+=("$img")
done < <(docker compose config --images "$@" 2>/dev/null || true)

if [ "${#IMAGES[@]}" -eq 0 ]; then
    echo "--- image prefetch: nothing to do"
    exit 0
fi

echo "--- image prefetch (${#IMAGES[@]} image(s), host arch ${HOST_ARCH:-unknown})"

FAILED=()
for image in "${IMAGES[@]}"; do
    fetch "$image" || FAILED+=("$image")
done

if [ "${#FAILED[@]}" -gt 0 ]; then
    echo ""
    echo "WARNING: could not prefetch: ${FAILED[*]}" >&2
    if [ "$RATE_LIMITED" -eq 1 ]; then
        echo "         Docker Hub rate limited this IP. Cloud sessions share an egress" >&2
        echo "         IP, so parallel agents exhaust it together." >&2
        echo "         Mirror the images and retry: scripts/mirror-images.sh --check" >&2
    fi
    echo "         Continuing — 'docker compose up' will report the definitive error." >&2
fi

exit 0
