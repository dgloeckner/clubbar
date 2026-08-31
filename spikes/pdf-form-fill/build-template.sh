#!/usr/bin/env sh
# Builds template.pdf from template.html via WeasyPrint (clubbar#777 spike).
#
#   pip install weasyprint   # v69 verified
#   ./build-template.sh
#
# Both flags are load-bearing:
#   --pdf-forms         turns HTML <input name="..."> into native AcroForm fields
#   --uncompressed-pdf  classic xref / no object streams — required by the free
#                       FPDI parser and this spike's field enumerator
set -eu
cd "$(dirname "$0")"
python3 -m weasyprint --pdf-forms --uncompressed-pdf template.html template.pdf
# Sanity: the required field names must survive the render (member-specific
# fields only; creditor may be static in a club template, Ort/Datum is
# always handwritten).
for f in mandatsreferenz vorname nachname iban iban_last4; do
    grep -q "/T ($f)" template.pdf || { echo "MISSING FIELD: $f" >&2; exit 1; }
done
echo "template.pdf built, all required fields present."

# FRGS finals (frgs/): fillable mandate + static Datenschutz document.
if [ -d frgs ]; then
    python3 -m weasyprint --pdf-forms --uncompressed-pdf frgs/mandat.html frgs/mandat-vereinsbar.pdf
    for f in mandatsreferenz vorname nachname iban iban_last4; do
        grep -q "/T ($f)" frgs/mandat-vereinsbar.pdf || { echo "FRGS MISSING FIELD: $f" >&2; exit 1; }
    done
    python3 -m weasyprint frgs/datenschutz.html frgs/datenschutz-vereinsbar.pdf
    echo "frgs finals built."
fi
