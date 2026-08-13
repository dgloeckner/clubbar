#!/usr/bin/env bash
#
# deploy-request.sh — call one upgrade.php endpoint on a deploy target and turn
# whatever comes back into either the parsed JSON or a diagnosis somebody can
# act on.
#
# Usage:
#   scripts/deploy-request.sh <label> <url> [max-time-seconds]
#
# Exit status:
#   0  the server answered 200 with {"ok": true, ...}; the JSON is on stdout
#   1  anything else, with a ::error:: line naming what actually happened
#
# Why this exists as a script rather than three lines of curl in the workflow:
# the integration deploy spent three days red on nothing but
#
#     jq: parse error: Invalid numeric literal at line 1, column 10
#
# because `curl -sf ... | jq` throws away the status code, the headers and the
# body — the only three things that say *why* a deploy failed. That message is
# jq's way of saying "this is HTML", and HTML has one overwhelmingly likely
# source here: .htaccess sends every path it cannot find on disk to index.php,
# which answers with the SPA shell at HTTP 200 (the same behaviour
# deploy-production.yaml's verify step relies on to prove install.php is gone).
# So an HTML answer to upgrade.php means upgrade.php is not in the document
# root this URL serves — and the deploy should say exactly that.
set -uo pipefail

LABEL="${1:?usage: deploy-request.sh <label> <url> [max-time]}"
URL="${2:?usage: deploy-request.sh <label> <url> [max-time]}"
MAX_TIME="${3:-120}"

# Never echoed: the URL carries the upgrade secret in its query string.
BODY_FILE="$(mktemp)"
HEADER_FILE="$(mktemp)"
trap 'rm -f "$BODY_FILE" "$HEADER_FILE"' EXIT

# Deliberately not `-f`: it hides the response body on 4xx/5xx, which is where
# upgrade.php puts its error message. The status code is checked below instead.
HTTP_CODE="$(curl -sS --max-time "$MAX_TIME" \
  -o "$BODY_FILE" -D "$HEADER_FILE" -w '%{http_code}' "$URL")"
CURL_STATUS=$?

if [ "$CURL_STATUS" -ne 0 ]; then
  echo "::error::$LABEL: the server could not be reached (curl exit $CURL_STATUS)."
  exit 1
fi

echo "$LABEL: HTTP $HTTP_CODE"

# A redirect means the request never reached upgrade.php at all — the forced
# HTTPS rule in .htaccess answering an http:// site URL is the usual reason.
if [ "$HTTP_CODE" -ge 300 ] && [ "$HTTP_CODE" -lt 400 ]; then
  LOCATION="$(grep -i '^location:' "$HEADER_FILE" | tail -n 1 | tr -d '\r' | sed 's/^[Ll]ocation:[[:space:]]*//')"
  echo "::error::$LABEL: the server answered HTTP $HTTP_CODE (redirect to ${LOCATION:-an unnamed location}) instead of running upgrade.php. Check that the site URL matches the scheme and host the server redirects to."
  exit 1
fi

if ! jq -e . >/dev/null 2>&1 < "$BODY_FILE"; then
  echo "::group::$LABEL: response body (first 40 lines)"
  head -c 4000 "$BODY_FILE" | head -n 40
  echo
  echo "::endgroup::"

  CONTENT_TYPE="$(grep -i '^content-type:' "$HEADER_FILE" | tail -n 1 | tr -d '\r' | sed 's/^[Cc]ontent-[Tt]ype:[[:space:]]*//')"

  if head -c 512 "$BODY_FILE" | grep -qi '<!doctype\|<html'; then
    echo "::error::$LABEL: the server served HTML, not JSON. index.php answers any path it cannot find on disk with the SPA shell at HTTP 200, so upgrade.php is not in the document root this site URL serves. Check that the SFTP account's login directory really is that document root and that the upload landed in it."
  else
    echo "::error::$LABEL: the server answered HTTP $HTTP_CODE with ${CONTENT_TYPE:-an unknown content type}, which is not JSON. upgrade.php always answers JSON, so something other than upgrade.php handled this request."
  fi
  exit 1
fi

jq . < "$BODY_FILE"

# upgrade.php's own contract: a package older than the installed one is refused
# with 409 rather than rolled back underneath a newer schema.
if [ "$HTTP_CODE" = "409" ]; then
  echo "::error::$LABEL: the server refused a downgrade. Deploy a newer package, or restore from backup first."
  exit 1
fi

if [ "$(jq -r '.ok' < "$BODY_FILE")" != "true" ] || [ "$HTTP_CODE" != "200" ]; then
  ERROR="$(jq -r '.error // "no error message"' < "$BODY_FILE")"
  echo "::error::$LABEL failed (HTTP $HTTP_CODE): $ERROR"
  exit 1
fi
