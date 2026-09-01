#!/usr/bin/env sh
# Rebuild the mandate-template fixtures (#780, ADR-0052 decision 5).
#
#   pip install weasyprint    # v69 verified
#   ./build.sh
#
# Both flags are load-bearing, and neither is a preference:
#   --pdf-forms         turns <input name="..."> into native AcroForm fields.
#                       Headless Chromium renders the same HTML with ZERO form
#                       fields, so it cannot substitute for this.
#   --uncompressed-pdf  writes a classic cross-reference table. The free FPDI
#                       parser cannot follow a cross-reference stream, and the
#                       field enumerator cannot see inside an object stream.
#
# The PDFs are committed. This script is how they are regenerated, and running
# it is the only supported way to change them — a fixture edited by hand would
# stop being an example of what the documented pipeline produces, which is the
# entire reason it is the fixture.
set -eu
cd "$(dirname "$0")"

python3 -m weasyprint --pdf-forms --uncompressed-pdf club-anmeldung.html club-anmeldung.pdf

# The vocabulary a valid template must carry. A rebuild that silently dropped
# one of these would leave the suite asserting against a template no club could
# use — so it fails here instead.
for field in mandatsreferenz vorname nachname iban iban_last4; do
    grep -q "/T ($field)" club-anmeldung.pdf || {
        echo "MISSING REQUIRED FIELD: $field" >&2
        exit 1
    }
done

# Fields this fixture carries *because a test needs them to stay empty* (#784).
# They are not required of a club's template — most designers leave the
# signature line as a printed rule. What they prove is that a template which
# does make them fillable comes back with them unfilled: the member signs in
# person, and a machine-printed place and date is a claim nobody made. A
# rebuild that dropped them would make that test vacuous rather than red, which
# is why the check is here and not only in the test.
for field in ort_datum unterschrift; do
    grep -q "/T ($field)" club-anmeldung.pdf || {
        echo "MISSING FIXTURE FIELD: $field (needed by the never-filled assertion)" >&2
        exit 1
    }
done

echo "club-anmeldung.pdf rebuilt; all required fields present."
