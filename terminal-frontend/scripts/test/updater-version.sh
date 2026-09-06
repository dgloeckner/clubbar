#!/usr/bin/env bash
#
# updater-version.sh — the version rules the Pi's updater decides on its own.
#
# `clubbar-update.sh` has to answer "is this a version?" and "which of two is
# newer?" with no PHP and no Dart on the machine, so it implements in shell what
# `App\Shared\Version\ReleaseVersion` implements in PHP. Two implementations of
# the same rule is how a terminal ends up moving backwards, so this file asserts
# the *same table of cases* as `backend/tests/Unit/Shared/Version/ReleaseVersionTest.php`.
# Add a case to one, add it to the other.
#
# Usage: ./updater-version.sh      (exit 0 = all cases pass)

set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
UPDATER="$HERE/../clubbar-update.sh"

# Extract the pure functions and evaluate them, rather than sourcing the script,
# which would run a nightly update against this machine. The three are the whole
# of the version rules; nothing else in the updater decides them.
eval "$(sed -n '/^is_release_tag()/,/^}/p;/^compare_tags()/,/^}/p;/^compare_pre_release()/,/^}/p' "$UPDATER")"

FAILURES=0

pass() { printf '[ OK ]  %s\n' "$1"; }
fail() { FAILURES=$((FAILURES + 1)); printf '[FAIL]  %s\n' "$1"; }

assert_tag() {
  if is_release_tag "$1"; then pass "release tag: $1"; else fail "expected '$1' to parse as a release tag"; fi
}

assert_not_tag() {
  if is_release_tag "$1"; then fail "expected '$1' NOT to parse as a release tag"; else pass "not a tag: ${1:-<empty>}"; fi
}

assert_order() {
  local a="$1" expected="$2" b="$3" actual
  actual="$(compare_tags "$a" "$b")"
  if [ "$actual" = "$expected" ]; then
    pass "compare $a $b = $expected"
  else
    fail "compare $a $b = $actual, expected $expected"
  fi
}

echo "== what counts as a release tag =="
assert_tag v1.0.7
assert_tag v0.1.18
assert_tag v1.0.10
assert_tag v1.0.8-rc.1

# The two a club deployed from git reports forever. Fail-closed: an unknown
# version is not an infinite ceiling.
assert_not_tag dev
assert_not_tag dev-4f2a9c1
assert_not_tag ""
assert_not_tag "   "
assert_not_tag 1.0.7
assert_not_tag v1.0
assert_not_tag v1.0.7.1
assert_not_tag main
assert_not_tag latest
# These two are why the check runs *before* the tag reaches a path or a URL.
assert_not_tag 'v1.0.7/../../etc'
assert_not_tag 'v1.0.7; rm -rf /'

echo
echo "== which of two is newer =="
# The case a string comparison gets wrong, and the reason this exists.
assert_order v1.0.10 1 v1.0.9
assert_order v1.0.9 -1 v1.0.10
assert_order v1.0.0 1 v0.9.99
assert_order v1.1.0 1 v1.0.99
assert_order v1.0.7 0 v1.0.7
# A leading zero is a number, not an octal literal. A bare $((08)) aborts the
# shell — 8 is not valid octal — and $((010)) quietly evaluates to 8. Both are
# read as decimal here, matching what (int) does in PHP, so a zero-padded tag
# compares equal to its unpadded self on both sides rather than differing.
assert_order v1.0.8 0 v1.0.08
assert_order v1.0.10 0 v1.0.010

echo
echo "== pre-releases sort before the release they precede =="
assert_order v1.0.8-rc.1 -1 v1.0.8
assert_order v1.0.8 1 v1.0.8-rc.1
assert_order v1.0.8-rc.1 -1 v1.0.8-rc.2

echo
echo "== pre-release identifiers follow SemVer 11.4, not string order =="
# The case a string comparison gets wrong, and the one that would matter: a
# terminal on rc.9 offered rc.10 would read it as a downgrade, log "Never
# downgrading" and never move again.
assert_order v1.0.8-rc.9 -1 v1.0.8-rc.10
assert_order v1.0.8-rc.10 1 v1.0.8-rc.9
# Leading zeros are still the same number.
assert_order v1.0.8-rc.9 0 v1.0.8-rc.09
# A numeric identifier ranks below an alphanumeric one.
assert_order v1.0.8-1 -1 v1.0.8-alpha
assert_order v1.0.8-alpha 1 v1.0.8-1
# All preceding identifiers equal: the longer set wins.
assert_order v1.0.8-alpha -1 v1.0.8-alpha.1
assert_order v1.0.8-alpha.1 1 v1.0.8-alpha
# The worked example from the specification.
assert_order v1.0.0-alpha -1 v1.0.0-alpha.1
assert_order v1.0.0-alpha.1 -1 v1.0.0-alpha.beta
assert_order v1.0.0-alpha.beta -1 v1.0.0-beta
assert_order v1.0.0-beta -1 v1.0.0-beta.2
assert_order v1.0.0-beta.2 -1 v1.0.0-beta.11
assert_order v1.0.0-beta.11 -1 v1.0.0-rc.1
assert_order v1.0.0-rc.1 -1 v1.0.0
# This project's own convention, which never reaches any of the above: a
# constant suffix with the counter in the patch field.
assert_order v0.1.18-beta -1 v0.1.19-beta
assert_order v1.0.0 1 v0.1.19-beta

echo
if [ "$FAILURES" -gt 0 ]; then
  echo "$FAILURES case(s) failed"
  exit 1
fi
echo "all cases passed"
