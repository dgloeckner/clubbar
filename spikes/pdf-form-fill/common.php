<?php
/**
 * Spike: fill an AcroForm SEPA mandate in pure PHP on shared hosting (clubbar#777).
 * Shared helpers: access token, template resolution, raw-PDF field enumeration,
 * sample data for the two render variants.
 */

declare(strict_types=1);

// Change this before uploading if you like; every request must carry ?t=<token>.
const SPIKE_TOKEN = 'sp1ke-9f3ab27c41d6';

const MAX_UPLOAD_BYTES = 2 * 1024 * 1024;

function require_token(): void
{
    if (!hash_equals(SPIKE_TOKEN, (string)($_GET['t'] ?? ''))) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not found.\n";
        exit;
    }
}

function template_path(string $which): string
{
    $own = __DIR__ . '/uploads/own.pdf';
    if ($which === 'own' && is_file($own)) {
        return $own;
    }
    return __DIR__ . '/template.pdf';
}

/**
 * Enumerate AcroForm text-field widgets by scanning the raw (uncompressed) PDF.
 * Returns ['fields' => [name => ['rect' => [x1,y1,x2,y2]]], 'warnings' => [...]].
 * This is the same mechanism a real upload-time validation would use, so its
 * failure modes are exactly what the spike needs to surface.
 */
function enumerate_fields(string $path): array
{
    $raw = (string)file_get_contents($path);
    $fields = [];
    $warnings = [];

    if (preg_match_all('~\d+\s+0\s+obj(.*?)endobj~s', $raw, $objects)) {
        foreach ($objects[1] as $obj) {
            if (!str_contains($obj, '/Widget')) {
                continue;
            }
            if (!preg_match('~/T\s*\(([^)]*)\)~', $obj, $t)) {
                continue;
            }
            if (!preg_match('~/Rect\s*\[\s*([0-9.\s-]+)\]~', $obj, $r)) {
                continue;
            }
            $rect = array_map('floatval', preg_split('~\s+~', trim($r[1])));
            if (count($rect) === 4) {
                // PDF allows any two opposite corners (WeasyPrint writes top-down);
                // normalize to [x1,y1,x2,y2] with x1<x2, y1<y2.
                $fields[$t[1]] = ['rect' => [
                    min($rect[0], $rect[2]), min($rect[1], $rect[3]),
                    max($rect[0], $rect[2]), max($rect[1], $rect[3]),
                ]];
            }
        }
    }

    if ($fields === []) {
        if (str_contains($raw, '/ObjStm')) {
            $warnings[] = 'No fields found and the file uses compressed object streams (/ObjStm). '
                . 'Re-export as PDF 1.4 / without stream compression.';
        } elseif (!str_contains($raw, "\nxref") && !str_contains($raw, "\rxref")) {
            $warnings[] = 'No classic xref table found (cross-reference stream?). '
                . 'The free FPDI parser and this scanner need a classic xref — re-export as PDF 1.4.';
        } else {
            $warnings[] = 'No AcroForm text-field widgets found in this PDF.';
        }
    }

    return ['fields' => $fields, 'warnings' => $warnings];
}

/**
 * The field vocabulary a valid mandate template must provide (clubbar#777 draft).
 * Member-specific data only: the creditor block may be printed statically by the
 * club's template (the FRGS one does), and Ort/Datum is always written by hand
 * at signature — never machine-filled. Creditor fields are filled when present.
 */
function required_fields(): array
{
    return ['mandatsreferenz', 'vorname', 'nachname', 'iban', 'iban_last4'];
}

/**
 * Sample data, deliberately with umlauts/ß to test encoding on core fonts.
 * 'member' = self-download variant (full IBAN); 'admin' = printed at the bar
 * (IBAN blank, hand-written at signature; last4 as printed hint).
 */
function sample_data(string $variant): array
{
    $common = [
        // Filled only when the template carries these fields (a club template
        // may print its creditor block statically instead).
        'glaeubiger_name' => 'FRGS v. 1879 e.V. (Spike)',
        'glaeubiger_id'   => 'DE98ZZZ09999999999',
        'mandatsreferenz' => 'c0ffee1234spike9d41d8cd98f00b204',
        'vorname'         => 'Jürgen',
        'nachname'        => 'Müller-Lüdenscheidt',
        'geburtsdatum'    => '23.11.1979',
        'email'           => 'juergen@example.org',
        // No datum_ort: Ort/Datum is written by hand at signature.
    ];
    if ($variant === 'admin') {
        return $common + ['iban' => '', 'iban_last4' => 'endet auf ****3000'];
    }
    return $common + ['iban' => 'DE89 3704 0044 0532 0130 00', 'iban_last4' => '****3000'];
}

function pdf_download_headers(string $filename): void
{
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
