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
 * Everything a PDF's streams carry, inflated where they are compressed.
 *
 * Returned as one string so a test can ask whether a value appears anywhere in
 * the document. Crude on purpose — see the file header.
 */
export function pdfText(pdf: Buffer): string {
  const raw = pdf.toString('latin1')
  let text = raw

  for (const match of raw.matchAll(/stream\r?\n([\s\S]*?)\r?\nendstream/g)) {
    try {
      text += inflateSync(Buffer.from(match[1], 'latin1')).toString('latin1')
    } catch {
      // Not Flate, or not a content stream. Its raw bytes are already in `text`.
    }
  }

  return text
}

/** `DE89 3704 0044 0532 0130 00` — the way an IBAN is printed on the form. */
export function groupedIban(iban: string): string {
  return (iban.replace(/\s+/g, '').match(/.{1,4}/g) ?? []).join(' ')
}
