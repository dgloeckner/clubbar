<?php
/**
 * Spike: fill the AcroForm mandate template and stream the result (clubbar#777).
 *
 * ?t=<token>&v=member|admin[&tpl=own]
 *
 * Single, minimal strategy on stable dependencies only (setasign/fpdf +
 * setasign/fpdi, both actively maintained):
 *   1. enumerate the template's AcroForm field names + rectangles by scanning
 *      the raw PDF (common.php — the same code a real upload-time validation
 *      would run),
 *   2. import the page with FPDI — annotations (the form fields) are not
 *      imported, so the base is flattened by construction,
 *   3. draw each value as plain text at its field's position.
 *
 * No form-fill library involved: the AcroForm fields act purely as the
 * addressing contract (names + positions) between the club's template and
 * clubbar. Unmaintained fillers (FPDM, last release 2017) were deliberately
 * excluded; commercial SetaPDF-FormFiller is the documented fallback should
 * this approach prove insufficient.
 */

declare(strict_types=1);
require __DIR__ . '/common.php';
require __DIR__ . '/vendor/autoload.php';
require_token();

$variant  = ($_GET['v'] ?? 'member') === 'admin' ? 'admin' : 'member';
$tpl      = template_path((string)($_GET['tpl'] ?? 'builtin'));
$data     = sample_data($variant);
$filename = sprintf('spike-mandat-%s.pdf', $variant);

function fail(string $msg, ?\Throwable $e = null): void
{
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "SPIKE RESULT: FAILED\n\n" . $msg . "\n";
    if ($e !== null) {
        echo "\n" . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
    exit;
}

$scan = enumerate_fields($tpl);
if ($scan['fields'] === []) {
    fail("Field enumeration found no widgets:\n- " . implode("\n- ", $scan['warnings']));
}
$missing = array_diff(required_fields(), array_keys($scan['fields']));
if ($missing !== []) {
    fail('Template rejected — required fields missing: ' . implode(', ', $missing)
        . "\n(This is the upload-time validation working as intended.)");
}

try {
    $pdf = new \setasign\Fpdi\Fpdi('P', 'pt');
    $pageCount = $pdf->setSourceFile($tpl);

    // Page 1 carries the form fields: import it, draw the values, THEN append
    // the remaining pages (a club template may carry Datenschutz/Nutzungs-
    // ordnung pages behind the form page). FPDF cannot revisit an earlier
    // page, so the order matters. Annotations are never imported => flattened.
    $tplId = $pdf->importPage(1);
    $size = $pdf->getTemplateSize($tplId);
    $pdf->AddPage($size['orientation'], [$size['width'], $size['height']]);
    $pdf->useTemplate($tplId);

    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetTextColor(26, 26, 46);
    $fontSize = 10.0;

    foreach ($scan['fields'] as $name => $info) {
        $value = (string)($data[$name] ?? '');
        if ($value === '') {
            continue; // admin variant: IBAN stays a blank line for handwriting
        }
        [$x1, $y1, $x2, $y2] = $info['rect'];
        $h = $y2 - $y1;
        // Core fonts are latin-1; transliterate what cannot be mapped.
        $txt = iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $value) ?: $value;
        $baselineFromBottom = $y1 + ($h - $fontSize) / 2 + 2.0;
        $pdf->Text($x1 + 3, (float)$size['height'] - $baselineFromBottom, $txt);
    }

    for ($pageNo = 2; $pageNo <= $pageCount; $pageNo++) {
        $tplId = $pdf->importPage($pageNo);
        $s = $pdf->getTemplateSize($tplId);
        $pdf->AddPage($s['orientation'], [$s['width'], $s['height']]);
        $pdf->useTemplate($tplId);
    }

    pdf_download_headers($filename);
    $pdf->Output('I', $filename);
} catch (\setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException $e) {
    fail('FPDI (free parser) cannot read this template: it uses a compressed '
        . 'cross-reference stream. Re-export as PDF 1.4 / without compression.', $e);
} catch (\Throwable $e) {
    fail('Overlay fill failed.', $e);
}
