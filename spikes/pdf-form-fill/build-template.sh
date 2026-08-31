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
# Sanity: the required field names must survive the render.
for f in glaeubiger_name glaeubiger_id mandatsreferenz vorname nachname iban iban_last4 datum_ort; do
    grep -q "/T ($f)" template.pdf || { echo "MISSING FIELD: $f" >&2; exit 1; }
done
echo "template.pdf built, all required fields present."
