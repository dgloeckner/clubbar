<?php
/**
 * Spike entry page (clubbar#777): environment report, field enumeration,
 * fill links for both strategies/variants, and an upload slot to test your
 * own template export (e.g. from LibreOffice).
 */

declare(strict_types=1);
require __DIR__ . '/common.php';
require_token();

$uploadMsg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['tpl'])) {
    $f = $_FILES['tpl'];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $uploadMsg = 'Upload failed (error ' . (int)$f['error'] . ').';
    } elseif ($f['size'] > MAX_UPLOAD_BYTES) {
        $uploadMsg = 'File too large (max 2 MB).';
    } elseif (strncmp((string)file_get_contents($f['tmp_name'], false, null, 0, 5), '%PDF-', 5) !== 0) {
        $uploadMsg = 'Not a PDF.';
    } else {
        @mkdir(__DIR__ . '/uploads', 0775);
        move_uploaded_file($f['tmp_name'], __DIR__ . '/uploads/own.pdf');
        $uploadMsg = 'Uploaded. The "own template" links below now use it.';
    }
}

$t = SPIKE_TOKEN;
$builtin = enumerate_fields(template_path('builtin'));
$own = is_file(__DIR__ . '/uploads/own.pdf') ? enumerate_fields(template_path('own')) : null;
$required = required_fields();

function field_table(array $scan, array $required): void
{
    if ($scan['fields'] === []) {
        foreach ($scan['warnings'] as $w) {
            echo '<p class="bad">⚠ ' . h($w) . '</p>';
        }
        return;
    }
    echo '<table><tr><th>Feld</th><th>Rect</th><th>benötigt?</th></tr>';
    foreach ($scan['fields'] as $name => $info) {
        $req = in_array($name, $required, true) ? '✓' : '–';
        echo '<tr><td><code>' . h($name) . '</code></td><td><code>'
            . h(implode(', ', array_map(fn($v) => (string)round($v, 1), $info['rect'])))
            . '</code></td><td>' . $req . '</td></tr>';
    }
    echo '</table>';
    $missing = array_diff($required, array_keys($scan['fields']));
    echo $missing === []
        ? '<p class="ok">✓ Alle Pflichtfelder vorhanden — Upload-Validierung würde dieses Template akzeptieren.</p>'
        : '<p class="bad">⚠ Fehlende Pflichtfelder: <code>' . h(implode(', ', $missing)) . '</code></p>';
}
?>
<!doctype html>
<meta charset="utf-8">
<meta name="robots" content="noindex,nofollow">
<title>clubbar PDF-Fill Spike</title>
<style>
  body{font:15px/1.5 system-ui,sans-serif;max-width:820px;margin:2rem auto;padding:0 1rem;color:#1a1a2e}
  h1{font-size:1.3rem} h2{font-size:1.05rem;margin-top:2rem}
  table{border-collapse:collapse;font-size:.85rem} td,th{border:1px solid #ccc;padding:.25rem .6rem;text-align:left}
  code{background:#f4f6fa;padding:0 .25rem} .ok{color:#1a7a3a} .bad{color:#b3261e}
  a.btn{display:inline-block;margin:.2rem .4rem .2rem 0;padding:.45rem .8rem;background:#1a1a2e;color:#fff;
        text-decoration:none;border-radius:6px;font-size:.9rem}
  li{margin:.2rem 0}
</style>
<h1>clubbar PDF-Fill Spike (#777)</h1>
<p>Prüft, ob ein AcroForm-SEPA-Mandat auf diesem Hosting in reinem PHP befüllt werden kann.
   Nach dem Test dieses Verzeichnis löschen.</p>

<h2>1 · Umgebung</h2>
<ul>
  <li>PHP <?= h(PHP_VERSION) ?> (<?= PHP_INT_SIZE * 8 ?>-bit)</li>
  <?php foreach (['zlib', 'iconv', 'mbstring'] as $ext): ?>
    <li>ext/<?= h($ext) ?>: <?= extension_loaded($ext) ? '<span class="ok">geladen</span>' : '<span class="bad">FEHLT</span>' ?></li>
  <?php endforeach; ?>
  <li>Template lesbar: <?= is_readable(template_path('builtin')) ? '<span class="ok">ja</span>' : '<span class="bad">NEIN</span>' ?></li>
  <li>uploads/ beschreibbar: <?= (is_dir(__DIR__ . '/uploads') ? is_writable(__DIR__ . '/uploads') : is_writable(__DIR__)) ? '<span class="ok">ja</span>' : '<span class="bad">NEIN</span>' ?></li>
</ul>

<h2>2 · Feld-Enumeration (mitgelieferte Vorlage)</h2>
<?php field_table($builtin, $required); ?>

<h2>3 · Befüllen &amp; herunterladen</h2>
<p><b>Overlay-Fill</b> — FPDI importiert die Seite (Formularfelder werden dabei konstruktionsbedingt
   entfernt), die Werte werden als Text an den Feld-Positionen gezeichnet. Ergebnis ist flachgedrückt.
   Abhängigkeiten: nur <code>setasign/fpdf</code> + <code>setasign/fpdi</code> (stabil, gepflegt).</p>
<a class="btn" href="fill.php?t=<?= h($t) ?>&v=member">Mitglied-Variante (IBAN gefüllt)</a>
<a class="btn" href="fill.php?t=<?= h($t) ?>&v=admin">Admin-Ausdruck (IBAN leer)</a>

<h2>4 · Eigene Vorlage testen (optional)</h2>
<p>Eigenen Export hochladen (z.&nbsp;B. aus LibreOffice: Datei → Exportieren als PDF → „PDF-Formular erzeugen",
   Feldnamen wie oben). Max 2 MB.</p>
<?php if ($uploadMsg !== ''): ?><p><b><?= h($uploadMsg) ?></b></p><?php endif; ?>
<form method="post" action="index.php?t=<?= h($t) ?>" enctype="multipart/form-data">
  <input type="file" name="tpl" accept="application/pdf">
  <button>Hochladen</button>
</form>
<?php if ($own !== null): ?>
  <h3>Feld-Enumeration (eigene Vorlage)</h3>
  <?php field_table($own, $required); ?>
  <p>
    <a class="btn" href="fill.php?t=<?= h($t) ?>&v=member&tpl=own">Befüllen · eigene Vorlage</a>
  </p>
<?php endif; ?>

<h2>5 · Was prüfen?</h2>
<ul>
  <li>Beide Downloads öffnen: Werte an den richtigen Stellen? Umlaute korrekt (Jürgen Müller-Lüdenscheidt)?</li>
  <li>Admin-Variante: IBAN-Zeile leer, Hinweis „endet auf ****3000" gefüllt?</li>
  <li>Ergebnis flachgedrückt: keine editierbaren Felder mehr (erwartet: ja)?</li>
  <li>Eigener LibreOffice-Export: findet die Enumeration die Felder, oder kommt die Kompressions-Warnung?</li>
</ul>
</body>
</html>
