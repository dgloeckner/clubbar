#!/usr/bin/env bash
#
# mirror-images.sh — copy the upstream Docker Hub images this project uses into
# our own GHCR namespace, and check what is already mirrored there.
#
# Anonymous Docker Hub pulls are rate limited per source IP. Cloud sessions
# share that IP, so a handful of agents starting the stack at the same time
# turns every subsequent pull into "429 Too Many Requests" with no way to wait
# it out. Public GHCR packages have no equivalent limit, so a mirror under our
# own namespace is a registry that cannot throttle us off.
#
# The images are listed in scripts/mirrored-images.txt — one upstream reference
# per line. The mirror name is the last path segment plus the same tag, so
# `webdevops/php-apache:8.3` becomes `ghcr.io/dgloeckner/php-apache:8.3`.
#
# Usage:
#   scripts/mirror-images.sh --check     Report what each mirror currently holds
#   scripts/mirror-images.sh             Copy every listed image to the mirror
#   scripts/mirror-images.sh mariadb     ... only images matching a substring
#
# Environment:
#   CLUBBAR_MIRROR_NS   Mirror namespace (default: ghcr.io/dgloeckner)
#
# Copying needs push rights on the namespace:
#   echo "$GHCR_TOKEN" | docker login ghcr.io -u <user> --password-stdin
# In CI this is what .github/workflows/mirror-images.yaml does for you.
#
# IMPORTANT — copy, do not pull-and-push. `docker buildx imagetools create`
# copies the whole manifest list, so the mirror keeps every architecture the
# upstream image has. `docker pull` + `docker push` resolves to the *local*
# machine's architecture and silently publishes a single-arch image: mirroring
# that way from an Apple Silicon laptop yields an arm64-only mirror that fails
# on every amd64 cloud session and CI runner with "exec format error".
#
# Exit codes:
#   0  success
#   1  usage error, or at least one image failed
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
IMAGE_LIST="$SCRIPT_DIR/mirrored-images.txt"

MIRROR_NS="${CLUBBAR_MIRROR_NS:-ghcr.io/dgloeckner}"

# Architectures --check insists a mirror carries, when upstream has them:
# amd64 for cloud sessions and GitHub runners, arm64 for Apple Silicon laptops.
REQUIRED_ARCHES="${CLUBBAR_MIRROR_ARCHES:-amd64 arm64}"

CHECK_ONLY=0
FILTERS=()
for arg in "$@"; do
    case "$arg" in
        --check)   CHECK_ONLY=1 ;;
        -h|--help) sed -n '5,30p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0 ;;
        -*)        echo "ERROR: unknown option '$arg'" >&2; exit 1 ;;
        *)         FILTERS+=("$arg") ;;
    esac
done

[ -f "$IMAGE_LIST" ] || { echo "ERROR: image list not found: $IMAGE_LIST" >&2; exit 1; }

# ---------------------------------------------------------------------------
# mirror_ref <upstream> — the mirror reference for an upstream image.
# `mariadb:10.11` -> `<ns>/mariadb:10.11`
# `webdevops/php-apache:8.3` -> `<ns>/php-apache:8.3`
# ---------------------------------------------------------------------------
mirror_ref() {
    local ref="$1" repo tag
    case "$ref" in
        *:*) repo="${ref%:*}"; tag="${ref##*:}" ;;
        *)   repo="$ref";      tag="latest" ;;
    esac
    echo "$MIRROR_NS/${repo##*/}:$tag"
}

# ---------------------------------------------------------------------------
# Every architecture in a reference's manifest, space separated. Empty when the
# reference cannot be read (not pushed yet, or no permission).
# ---------------------------------------------------------------------------
architectures_of() {
    local ref="$1" image arches
    # `--format {{json .Image}}` is the one form that answers this for both
    # shapes: for a manifest list it prints one config per platform, for a
    # single manifest it prints that one config. `--raw` does not work — a
    # single-arch manifest carries no architecture at all, it lives in the
    # config blob, so the mirrors would all read as "MISSING". Attestation
    # manifests (the "unknown/unknown" entries in a raw index) are left out of
    # .Image, so they cannot be mistaken for a platform either.
    image="$(docker buildx imagetools inspect "$ref" --format '{{json .Image}}' 2>/dev/null)" || return 0
    arches="$(echo "$image" | grep -o '"architecture"[[:space:]]*:[[:space:]]*"[^"]*"' \
        | sed 's/.*"\([^"]*\)"$/\1/' | grep -v '^unknown$' | sort -u | tr '\n' ' ')"
    echo "${arches% }"
}

wanted() {
    local ref="$1" f
    [ "${#FILTERS[@]}" -eq 0 ] && return 0
    for f in "${FILTERS[@]}"; do
        case "$ref" in *"$f"*) return 0 ;; esac
    done
    return 1
}

IMAGES=()
while read -r line; do
    line="${line%%#*}"
    line="$(echo "$line" | tr -d '[:space:]')"
    [ -n "$line" ] || continue
    wanted "$line" && IMAGES+=("$line")
done < "$IMAGE_LIST"

if [ "${#IMAGES[@]}" -eq 0 ]; then
    echo "ERROR: no images matched." >&2
    exit 1
fi

FAILED=()

if [ "$CHECK_ONLY" -eq 1 ]; then
    echo "=== Mirror status ($MIRROR_NS) ==="
    echo ""
    printf '%-28s %-22s %s\n' "UPSTREAM" "MIRROR ARCHES" "UPSTREAM ARCHES"
    for upstream in "${IMAGES[@]}"; do
        mirror="$(mirror_ref "$upstream")"
        mirror_arches="$(architectures_of "$mirror")"
        upstream_arches="$(architectures_of "$upstream")"
        printf '%-28s %-22s %s\n' \
            "$upstream" "${mirror_arches:-MISSING}" "${upstream_arches:-unreadable}"
        # An arm64-only mirror is worse than none: it pulls fine and then dies
        # with "exec format error" on every amd64 host, which reads as a broken
        # container rather than a broken mirror.
        #
        # Only the architectures we actually run on count as missing. A mirror
        # created by `imagetools create` carries whatever upstream has, but
        # holding s390x against it would make the check permanently red for a
        # platform nobody here develops or ships on.
        if [ -n "$mirror_arches" ] && [ -n "$upstream_arches" ]; then
            for arch in $REQUIRED_ARCHES; do
                case " $upstream_arches " in *" $arch "*) ;; *) continue ;; esac
                case " $mirror_arches " in
                    *" $arch "*) ;;
                    *) FAILED+=("$upstream: mirror is missing $arch") ;;
                esac
            done
        elif [ -z "$mirror_arches" ]; then
            FAILED+=("$upstream: no mirror at $mirror")
        fi
    done
    echo ""
    if [ "${#FAILED[@]}" -eq 0 ]; then
        echo "All mirrors carry every required architecture ($REQUIRED_ARCHES)."
        exit 0
    fi
    echo "Incomplete (run scripts/mirror-images.sh to fix):"
    for f in "${FAILED[@]}"; do echo "  - $f"; done
    exit 1
fi

echo "=== Mirroring ${#IMAGES[@]} image(s) to $MIRROR_NS ==="
for upstream in "${IMAGES[@]}"; do
    mirror="$(mirror_ref "$upstream")"
    echo ""
    echo "--- $upstream -> $mirror"
    if docker buildx imagetools create --tag "$mirror" "$upstream"; then
        echo "    architectures: $(architectures_of "$mirror")"
    else
        echo "    FAILED" >&2
        FAILED+=("$upstream")
    fi
done

echo ""
if [ "${#FAILED[@]}" -eq 0 ]; then
    echo "=== Done — ${#IMAGES[@]} image(s) mirrored ==="
    exit 0
fi
echo "=== ${#FAILED[@]} of ${#IMAGES[@]} image(s) failed ===" >&2
for f in "${FAILED[@]}"; do echo "  - $f" >&2; done
exit 1
