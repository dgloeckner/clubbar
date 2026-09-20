#!/usr/bin/env bash
# Run the dispenser flow suite (L3 of epic #944) against the Go mock.
#
# The mock lives in dgloeckner/remote-token-dispenser, not here. CI checks that
# repository out at a pinned commit and builds it; locally this script finds a
# checkout, builds the binary once and hands it to the suite.
#
# Usage:
#   scripts/flow-test.sh                          # sibling checkout, or $DISPENSER_REPO
#   DISPENSER_REPO=~/src/remote-token-dispenser scripts/flow-test.sh
#   CLUBBAR_DISPENSER_MOCK=/path/to/binary scripts/flow-test.sh
#   scripts/flow-test.sh --plain-name "a clean dispense"   # one scenario
set -euo pipefail

cd "$(dirname "$0")/.."

if [ -z "${CLUBBAR_DISPENSER_MOCK:-}" ]; then
  REPO=${DISPENSER_REPO:-../../remote-token-dispenser}
  SRC="$REPO/dispenser-mock"
  if [ ! -d "$SRC" ]; then
    echo "No dispenser mock found at $SRC." >&2
    echo "Clone dgloeckner/remote-token-dispenser next to this repository," >&2
    echo "or set DISPENSER_REPO / CLUBBAR_DISPENSER_MOCK." >&2
    exit 1
  fi
  BIN="$(mktemp -d)/dispenser-mock"
  echo "Building the mock from $SRC"
  (cd "$SRC" && go build -o "$BIN" .)
  export CLUBBAR_DISPENSER_MOCK="$BIN"
fi

echo "Mock binary: $CLUBBAR_DISPENSER_MOCK"
exec flutter test flow_test/ "$@"
