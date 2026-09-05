#!/usr/bin/env bash
#
# Carry apt's downloaded .deb archives between CI runs.
#
# `npx playwright install --with-deps` apt-installs WebKit's system libraries
# (gstreamer, libwoff, libmanette, fonts...) on every run. That fetch is ~100 MB
# from whichever Azure mirror the runner is near, and it is what turned the E2E
# job into 13m40 on one run and 39s an hour later.
#
# The obvious fix — pointing actions/cache at /var/cache/apt/archives — does not
# work, and fails silently enough that it looked like it did. apt's cache holds
# a root-only `partial/` directory and a `lock` file; the post-job save runs
# `tar` as the unprivileged runner user, so every single run ended with
#
#   /usr/bin/tar: ../../../../../var/cache/apt/archives/partial: Cannot open: Permission denied
#   /usr/bin/tar: ../../../../../var/cache/apt/archives/lock: Cannot open: Permission denied
#   ##[warning]Failed to save: "/usr/bin/tar" failed with error: ... exit code 2
#
# buried in the post-job section, while the *restore* said the honest thing at
# the top of the job — "Cache not found for input keys" — on every run since the
# step was added. The entry was never written, so it could never be read.
#
# So the cached directory is one the runner user owns, containing nothing but
# .deb files, and this script moves them in and out of apt's own cache:
#
#   apt-archive-cache.sh restore DIR   # DIR -> /var/cache/apt/archives
#   apt-archive-cache.sh save    DIR   # /var/cache/apt/archives -> DIR
#
# `save` keeps only archives whose package *and* version are installed right
# now. Without that the directory would grow without bound: a key that rotates
# with the runner image restores the previous entry, and re-saving everything it
# restored would accumulate every superseded .deb Ubuntu has ever shipped.
set -euo pipefail
shopt -s nullglob

APT_CACHE=/var/cache/apt/archives

usage() {
    echo "usage: $(basename "$0") {restore|save} DIRECTORY" >&2
    exit 2
}

restore() {
    local dir=$1

    # apt-get is not required to keep what it downloads, and an image that
    # cleans up after itself would leave `save` with nothing to collect —
    # a cache that stays empty for a second, quieter reason. Say it outright
    # rather than inheriting whatever the runner's apt.conf.d happens to hold.
    printf 'APT::Keep-Downloaded-Packages "true";\n' \
        | sudo tee /etc/apt/apt.conf.d/99keep-downloaded-packages > /dev/null

    local debs=("$dir"/*.deb)
    if [ ${#debs[@]} -eq 0 ]; then
        echo "apt-archive-cache: no cached archives in $dir; apt will download them"
        return 0
    fi

    sudo install -d -m 0755 "$APT_CACHE"
    local deb
    for deb in "${debs[@]}"; do
        # Never overwrite an archive apt already holds. apt validates every
        # file against the package index anyway and re-downloads a mismatch,
        # so a stale entry costs a fetch, never a wrong install.
        [ -e "$APT_CACHE/$(basename "$deb")" ] || sudo cp "$deb" "$APT_CACHE/"
    done
    echo "apt-archive-cache: seeded apt with ${#debs[@]} archive(s) from $dir"
}

save() {
    local dir=$1

    mkdir -p "$dir"
    rm -f "$dir"/*.deb

    local kept=0 deb name version installed
    for deb in "$APT_CACHE"/*.deb; do
        name=$(dpkg-deb -f "$deb" Package 2> /dev/null) || continue
        version=$(dpkg-deb -f "$deb" Version 2> /dev/null) || continue
        installed=$(dpkg-query -W -f='${Version}' "$name" 2> /dev/null || true)
        [ "$installed" = "$version" ] || continue
        cp -f "$deb" "$dir/"
        kept=$((kept + 1))
    done

    echo "apt-archive-cache: kept $kept archive(s) in $dir"
}

[ $# -eq 2 ] || usage

case "$1" in
    restore) restore "$2" ;;
    save) save "$2" ;;
    *) usage ;;
esac
