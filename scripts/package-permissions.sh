#!/usr/bin/env bash
#
# package-permissions.sh — the file modes the shared hosting package ships with,
# and the ownership the local Docker package test needs (#248, ADR-0031
# decision 4).
#
# Usage:
#   ./scripts/package-permissions.sh <command> [PACKAGE_DIR]
#
# Commands:
#   harden          Apply the production modes: 0700 on storage/ and logs/,
#                   0755 on the document root, 0600 on a config.php that is
#                   somehow present.
#   verify          Fail if any path under PACKAGE_DIR is world-writable. This
#                   is the gate: whatever the build did, the artifact does not
#                   ship a path every other customer on a shared host can write.
#   container-user  Give the tree to the uid the package-test container serves
#                   it as (see PACKAGE_TEST_UID below).
#   builder-user    Give it back to the user running the build.
#
# PACKAGE_DIR defaults to dist/package.
#
# Why the two ownership commands exist
# ------------------------------------
# The package test serves dist/package out of a container, and the application
# inside it runs as uid 1000 (`PUID` in docker-compose.yml) while the build ran
# as whoever you are. The installer has to write config.php, storage/ and logs/,
# so *something* has to give — and the choice matters: the package used to be
# chmod'ed 0777 to paper over it, which then shipped to every production
# install. Handing the tree to the container's uid instead keeps the modes the
# release actually carries, so the smoke test measures 0700 and 0600 rather than
# the loose modes it was asked to tolerate.
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# The uid/gid the backend container runs the application as: `PUID: ${UID:-1000}`
# in docker-compose.yml, and nothing exports UID to compose in CI.
PACKAGE_TEST_UID="${PACKAGE_TEST_UID:-1000}"
PACKAGE_TEST_GID="${PACKAGE_TEST_GID:-1000}"

# Directories the application writes to, relative to the package root. They hold
# scanned SEPA mandates, sessions and logs — nothing in them is meant to be
# readable by another account on the machine.
WRITABLE_DIRS=(backend/storage backend/logs)

COMMAND="${1:-}"
PKG_DIR="${2:-$PROJECT_ROOT/dist/package}"

if [ ! -d "$PKG_DIR" ]; then
  echo "package-permissions: $PKG_DIR does not exist — run scripts/build-package.sh first." >&2
  exit 1
fi

# sudo only where it is both needed and available: CI owns the tree as the
# runner but the container writes into it as another uid, so taking it back
# needs privilege. A local root shell needs none.
maybe_sudo() {
  if [ "$(id -u)" = "0" ]; then
    "$@"
  elif command -v sudo > /dev/null 2>&1; then
    sudo "$@"
  else
    "$@"
  fi
}

harden() {
  chmod 0755 "$PKG_DIR"

  for dir in "${WRITABLE_DIRS[@]}"; do
    [ -d "$PKG_DIR/$dir" ] || continue
    chmod 0700 "$PKG_DIR/$dir"
  done

  # Never shipped — but a local build that was installed against, or a CI run
  # whose cleanup is yet to happen, has one, and it holds the database password.
  for config in "$PKG_DIR/config.php" "$PKG_DIR/backend/config.php"; do
    [ -f "$config" ] || continue
    chmod 0600 "$config"
  done

  echo "package-permissions: hardened $PKG_DIR (0755 root, 0700 ${WRITABLE_DIRS[*]})"
}

verify() {
  # -perm -o+w, not a mode comparison: the question is only ever "can someone
  # who is not us write here", whatever the rest of the bits say. Symlinks are
  # skipped because their own mode is meaningless — the target is what counts,
  # and the target is in this list if it is in this tree.
  local offenders
  offenders="$(find "$PKG_DIR" ! -type l -perm -o+w -printf '%M %p\n' 2>/dev/null || true)"

  if [ -n "$offenders" ]; then
    echo "package-permissions: world-writable paths would ship in the package:" >&2
    echo "$offenders" >&2
    exit 1
  fi

  echo "package-permissions: no world-writable path in $PKG_DIR"
}

case "$COMMAND" in
  harden)
    harden
    ;;
  verify)
    verify
    ;;
  container-user)
    maybe_sudo chown -R "$PACKAGE_TEST_UID:$PACKAGE_TEST_GID" "$PKG_DIR"
    echo "package-permissions: $PKG_DIR now owned by $PACKAGE_TEST_UID:$PACKAGE_TEST_GID (the package-test container's user)"
    ;;
  builder-user)
    maybe_sudo chown -R "$(id -u):$(id -g)" "$PKG_DIR"
    echo "package-permissions: $PKG_DIR handed back to $(id -u):$(id -g)"
    ;;
  *)
    echo "usage: $0 <harden|verify|container-user|builder-user> [PACKAGE_DIR]" >&2
    exit 2
    ;;
esac
