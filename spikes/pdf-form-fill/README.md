# Spike: AcroForm mandate fill in pure PHP on shared hosting

Feasibility spike for [clubbar#777](https://github.com/dgloeckner/clubbar/issues/777)
(epic [#776](https://github.com/dgloeckner/clubbar/issues/776), decision 3): can clubbar
fill a club-authored SEPA mandate PDF **on shared hosting** — no `pdftk` binary, no
headless browser — and produce a **flattened** result?

**Delete this directory once the spike has answered its question.**

## Approach: minimal fill, stable dependencies only

- `setasign/fpdf` + `setasign/fpdi` (both actively maintained, permissive licenses) — the
  only dependencies.
- ~40 lines of our own code enumerate the template's AcroForm **field names + rectangles**
  by scanning the raw PDF (`common.php::enumerate_fields`) — the same code an upload-time
  template validation would run.
- FPDI imports the page **without its annotations**, i.e. without the form fields — the
  base is flattened by construction — and the values are drawn as text at the field
  positions. The AcroForm fields are purely the *addressing contract* between the club's
  template and clubbar; no form-fill library is involved.
- Deliberately excluded: **FPDM** (unmaintained, last release 2017). Documented fallback if
  this approach proves insufficient: commercial SetaPDF-FormFiller.

## How to run on your shared hosting

1. (Optional) change `SPIKE_TOKEN` at the top of `common.php`.
2. Upload this whole directory to your webspace under a non-guessable name, e.g.
   `https://example.org/spike-kx83m2/`.
3. Open `https://example.org/spike-kx83m2/index.php?t=<token>` (token from `common.php`;
   as shipped: `sp1ke-9f3ab27c41d6`).
4. The page shows the environment report and the field enumeration, and offers the two
   downloads:
   - **Mitglied-Variante** — all fields incl. full IBAN (`DE89 3704 0044 0532 0130 00`, the
     official test IBAN),
   - **Admin-Ausdruck** — IBAN left blank for hand-writing, `endet auf ****3000` filled as
     the printed hint.
5. Optionally upload your own template export (e.g. LibreOffice: *File → Export as PDF →
   Create PDF form*, field names as below) and fill that instead — this is the test that
   tells us which export settings the real club template must use.

All sample data is fake (test IBAN, invented names with umlauts to exercise encoding).
Uploads land in `uploads/` which is blocked from direct download via `.htaccess`.

## Field vocabulary (draft, finalized in clubbar#777)

`glaeubiger_name`, `glaeubiger_id`, `mandatsreferenz`, `vorname`, `nachname`,
`iban`, `iban_last4`, `datum_ort` — all AcroForm **text fields**. Both `iban` *and*
`iban_last4` must exist (the two render variants).

## Verified template toolchain: WeasyPrint (HTML/CSS → AcroForm PDF)

The club can author the template as **HTML/CSS** — no hand-edited PDF, no Chrome
post-processing. [WeasyPrint](https://weasyprint.org)'s form mode turns HTML
`<input name="...">` elements into native AcroForm text fields:

```bash
pip install weasyprint          # v69 verified
weasyprint --pdf-forms --uncompressed-pdf mandat.html mandat-template.pdf
```

Verified end to end in this sandbox against this spike's own parser and fill:
all 8 fields enumerated with correct rects, both variants filled, output
flattened, umlauts intact. Two constraints, both confirmed:

- **`--uncompressed-pdf` is required** — the default output uses compressed
  object streams, which the enumerator and the free FPDI parser cannot read.
- WeasyPrint writes `/Rect` corners top-down; the enumerator normalizes corner
  order (fixed in `common.php` after this test found it).

`examples/mandat-weasyprint.html` is the verified example template — field
boxes styled in CSS (the writing line is a CSS border, i.e. page content),
field names via `<input name>`. Chrome-rendering the same HTML would *not*
produce form fields; Chrome flattens inputs to graphics.

## Template guidance learned while building this

- **Draw writing lines / boxes as page content, not only as field borders.** Field borders
  are annotations and vanish when the page is flattened — the admin printout needs a
  printed line under the blank IBAN to hand-write on. `tools/make_template.py` does this.

## What the spike must answer

| Question | Where to look |
|---|---|
| Does the fill run at all on the hosting (PHP version, extensions)? | Section 1 of `index.php` |
| Does field enumeration find names + positions? | Section 2 (this is the future upload validation) |
| Are values positioned correctly, umlauts intact? | Both downloads |
| Is the output flattened (no editable fields)? | Open a download, try to click a field |
| Which export settings must the club template use? | Upload an own LibreOffice/other export; compressed cross-reference streams are the known limit of the free FPDI parser (re-export as PDF 1.4 if the enumeration finds nothing) |

## Files

| File | Purpose |
|---|---|
| `template.pdf` | Generated mandate-shaped AcroForm template (uncompressed, classic xref, PDF 1.4) |
| `tools/make_template.py` | Its generator (reportlab), for reproducibility |
| `common.php` | Token guard, field enumeration, sample data |
| `index.php` | Report page: environment, enumeration, downloads, own-template upload |
| `fill.php` | The overlay fill + flatten, streamed as download |
| `vendor/` | `setasign/fpdf` + `setasign/fpdi` (committed so no composer needed on the host) |
