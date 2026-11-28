/**
 * Just enough PDF reading to assert on a generated document.
 *
 * Not a PDF library, and deliberately not one: the two questions the specs ask
 * are "how many pages" and "does this value appear on a page", and both can be
 * answered from the bytes of the documents this project produces.
 *
 * - **FPDF/FPDI output** (the filled Anmeldung, the mandate batch) uses a
 *   classic cross-reference table and one object per page, so `/Type /Page`
 *   occurs once per page as literal bytes. The values it draws are written with
 *   a Latin-1 core font as `(text) Tj` — literal too, once the Flate-compressed
 *   content stream around them is inflated.
 * - **The club's template** is a WeasyPrint `--uncompressed-pdf` build, which
 *   is readable the same way.
 *
 * A PDF using object streams or a cross-reference stream would defeat both
 * helpers. The backend refuses such a template for its own reasons
 * (`TemplateProblem::NO_CLASSIC_XREF`), so one never reaches these.
 */

import { inflateSync } from 'node:zlib'

/** How many pages a PDF has. */
export function pdfPageCount(pdf: Buffer): number {
  // `/Type /Pages` is the page *tree*; the lookahead keeps it out of the count.
  return (pdf.toString('latin1').match(/\/Type\s*\/Page(?![A-Za-z])/g) ?? []).length
}

/**
 * A stream's bytes, inflated — or null when they are not Flate data.
 *
 * Tried as matched first, and only then without a trailing carriage return.
 * The order is the whole point: see {@link pdfText}.
 */
function inflate(raw: string): string | null {
  for (const candidate of raw.endsWith('\r') ? [raw, raw.slice(0, -1)] : [raw]) {
    try {
      return inflateSync(Buffer.from(candidate, 'latin1')).toString('latin1')
    } catch {
      // Not Flate as it stands; the next candidate, if there is one.
    }
  }

  return null
}

/**
 * Everything a PDF's streams carry, inflated where they are compressed.
 *
 * Returned as one string so a test can ask whether a value appears anywhere in
 * the document. Crude on purpose — see the file header.
 *
 * ### The delimiter is `\nendstream`, deliberately not `\r?\nendstream`
 *
 * The obvious pattern tolerates a writer that ends its stream data with CRLF,
 * and it is a flake. Compressed data is arbitrary bytes, and a zlib stream
 * ends with an Adler-32 checksum whose last byte is `0x0D` about one time in
 * 256; the optional `\r` then takes that byte for part of the delimiter, the
 * stream arrives one byte short, inflating it throws, and the text it drew is
 * silently missing. To a spec that reads as "the IBAN hint is not on the
 * page". It was measured (10 of 3000 fills of the club fixture) and it was
 * met: a full local run failed exactly that assertion once.
 *
 * So the data is taken up to the bare LF, and a trailing CR is given up only
 * when the stream does not inflate with it.
 */
export function pdfText(pdf: Buffer): string {
  const raw = pdf.toString('latin1')
  let text = raw

  for (const match of raw.matchAll(/stream\r?\n([\s\S]*?)\nendstream/g)) {
    // A stream that is not Flate has its raw bytes in `text` already.
    text += inflate(match[1]) ?? ''
  }

  return text
}

/** `DE89 3704 0044 0532 0130 00` — the way an IBAN is printed on the form. */
export function groupedIban(iban: string): string {
  return (iban.replace(/\s+/g, '').match(/.{1,4}/g) ?? []).join(' ')
}
