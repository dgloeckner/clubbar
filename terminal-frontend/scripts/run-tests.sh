#!/usr/bin/env bash
# Run the terminal's tests, fail fast, and always leave a readable record.
#
# Two problems this solves, both learned the hard way:
#
# 1. A run whose output scrolled past — or whose compact reporter overwrote the
#    failure with a carriage return — is a run nobody can act on. Every
#    invocation writes, as it goes, so even a killed run leaves what it got to:
#      test-results/latest.log    full expanded output, failures and stacks
#      test-results/latest.json   machine-readable, for picking failures with jq
#
# 2. Integration tests drive a real app over a device channel, and when that
#    channel stalls (a macOS window that never comes to the foreground, say)
#    the per-test timeout never fires — the test framework is not the thing
#    that is stuck. So there is a wall-clock watchdog around the whole run.
#    Twenty minutes of a build agent waiting on a stalled window teaches
#    nobody anything.
#
# Usage:
#   scripts/run-tests.sh                            # unit + widget tests
#   scripts/run-tests.sh integration_test/          # integration tests
#   scripts/run-tests.sh --plain-name "some test"   # one test
#   RUN_TIMEOUT=1200 scripts/run-tests.sh ...       # longer watchdog (seconds)
#   TEST_TIMEOUT=5x scripts/run-tests.sh ...        # per-test timeout
#
# Inspect afterwards:
#   tail -40 test-results/latest.log
#   jq -r 'select(.error) | "\(.error)\n\(.stackTrace)"' test-results/latest.json
#
# The run is silent while it works — the log is the interface, and it is
# written as the run proceeds, so `tail -f test-results/latest.log` in another
# shell follows it live.
set -uo pipefail

cd "$(dirname "$0")/.."
mkdir -p test-results

LOG=test-results/latest.log
JSON=test-results/latest.json

# Wall-clock ceiling for the entire run, and the per-test ceiling handed to the
# test framework. Both are overridable: a genuinely long suite raises them
# deliberately, rather than nobody noticing the next hang.
RUN_TIMEOUT=${RUN_TIMEOUT:-900}
TEST_TIMEOUT=${TEST_TIMEOUT:-90s}

flutter test "$@" \
  --timeout "$TEST_TIMEOUT" \
  --reporter=expanded \
  --file-reporter="json:$JSON" > "$LOG" 2>&1 &
runner=$!

# Watchdog: report where the run stood, then take it down. Killing the process
# group matters — flutter test spawns the app and the device daemon under it.
(
  waited=0
  while kill -0 "$runner" 2>/dev/null; do
    if [ "$waited" -ge "$RUN_TIMEOUT" ]; then
      {
        echo
        echo "!! Watchdog: no completion after ${RUN_TIMEOUT}s — killing the run."
        echo "!! Last line above is where it stalled; see $JSON for the rest."
      } >> "$LOG"
      pkill -P "$runner" 2>/dev/null
      kill -9 "$runner" 2>/dev/null
      break
    fi
    sleep 5
    waited=$((waited + 5))
  done
) &
watchdog=$!

wait "$runner"
status=$?
kill "$watchdog" 2>/dev/null

echo
echo "log:  $(pwd)/$LOG"
echo "json: $(pwd)/$JSON"
exit "$status"
