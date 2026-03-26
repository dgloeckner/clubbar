# SEPA Mandate Template Download Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "Download SEPA Template" button to the Members page that generates and downloads a pre-filled SEPA mandate form PDF, ready to print and hand to new members.

**Architecture:** Backend generates a PDF from an HTML template by substituting SEPA config values (read from the existing `sepa_config` table) using dompdf. A new `generateMandateTemplatePdf()` method on the existing `SepaConfigService` handles the generation. The frontend button calls `downloadFile()` with the new `GET /admin/sepa-mandate-template` endpoint.

**Tech Stack:** PHP 8.3, dompdf/dompdf ^2.0, Slim 4, React/TypeScript, Playwright

---

## Chunk 1: Backend

### Task 1: Add dompdf dependency

**Files:**
- Modify: `backend/composer.json`

- [ ] **Step 1: Add dompdf to composer.json**

In `backend/composer.json`, add to the `require` object:

```json
"dompdf/dompdf": "^2.0"
```

The `require` section should look like:
```json
"require": {
    "php": ">=8.3",
    "chillerlan/php-qrcode": "^6.0",
    "digitick/sepa-xml": "^3.0",
    "dompdf/dompdf": "^2.0",
    "league/openapi-psr7-validator": "^0.22.0",
    "robthree/twofactorauth": "^3.0",
    "slim/psr7": "^1.0",
    "slim/slim": "^4.0",
    "symfony/yaml": "^7.0"
}
```

- [ ] **Step 2: Install the dependency**

```bash
cd backend && composer require dompdf/dompdf:^2.0
```

Expected: `./composer.json has been updated` and `vendor/dompdf/` directory created.

- [ ] **Step 3: Verify dompdf class is loadable**

```bash
cd backend && php -r "require 'vendor/autoload.php'; \$d = new \Dompdf\Dompdf(); echo 'OK';"
```

Expected: `OK`

- [ ] **Step 4: Commit**

```bash
git add backend/composer.json backend/composer.lock
git commit -m "chore(backend): add dompdf/dompdf ^2.0 for PDF generation"
```

---

### Task 2: Create the HTML template

**Files:**
- Create: `backend/resources/templates/sepa-mandate.html`

- [ ] **Step 1: Create the templates directory and HTML file**

Create `backend/resources/templates/sepa-mandate.html` with this content:

```html
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
* { margin: 0; padding: 0; }
body {
  font-family: Arial, sans-serif;
  font-size: 10pt;
  color: #111;
  padding: 15mm 18mm;
}

.header-table { width: 100%; }
.header-app-name { font-size: 20pt; font-weight: bold; color: #1d4ed8; }
.header-creditor { font-size: 9pt; color: #555; margin-top: 2px; }
.header-title { text-align: right; font-size: 14pt; font-weight: bold; }

.intro-box {
  border: 1px solid #cbd5e1;
  background-color: #f0f4ff;
  padding: 7px 10px;
  font-size: 8.5pt;
  color: #334155;
  margin-bottom: 10px;
  text-align: center;
}
.hint { font-size: 8pt; color: #64748b; font-style: italic; margin-bottom: 12px; }

.section-header {
  background-color: #2563eb;
  color: #fff;
  font-size: 10.5pt;
  font-weight: bold;
  padding: 5px 10px;
  margin-bottom: 8px;
}

.field-label { font-size: 8.5pt; font-weight: bold; margin-bottom: 2px; }
.field-optional { font-weight: normal; font-size: 8pt; color: #64748b; }
.field-box { border: 1px solid #94a3b8; height: 24px; width: 100%; margin-bottom: 6px; }

.two-col-left { width: 49%; vertical-align: top; padding-right: 6px; }
.two-col-right { width: 49%; vertical-align: top; padding-left: 6px; }

.creditor-box {
  background-color: #eff6ff;
  border: 1px solid #bfdbfe;
  padding: 5px 10px;
  margin-bottom: 8px;
  font-size: 9pt;
}
.creditor-box-label { font-weight: bold; color: #1d4ed8; margin-bottom: 2px; }

.legal-box {
  border: 1px solid #e2e8f0;
  padding: 7px;
  font-size: 8.5pt;
  line-height: 1.4;
  margin-bottom: 10px;
}

.sig-table { width: 100%; border-collapse: collapse; }
.sig-cell {
  border: 1px solid #94a3b8;
  height: 44px;
  padding: 3px 6px;
  vertical-align: bottom;
  font-size: 8pt;
  color: #94a3b8;
  width: 50%;
}

.footer-table { width: 100%; margin-top: 14px; border-top: 1px solid #e2e8f0; padding-top: 5px; }
.footer-text { font-size: 8pt; color: #94a3b8; }
.footer-right { text-align: right; font-size: 8pt; color: #94a3b8; }
</style>
</head>
<body>

<table class="header-table" cellspacing="0" cellpadding="0">
<tr>
  <td>
    <div class="header-app-name">{{APP_NAME}}</div>
    <div class="header-creditor">{{CREDITOR}}</div>
  </td>
  <td class="header-title">Mitglieds-Anmeldung</td>
</tr>
</table>
<hr style="border:0; border-top:2px solid #2563eb; margin:8px 0 10px;">

<div class="intro-box">
{{APP_NAME}} ist das bargeldlose Kassensystem von {{CREDITOR}}. Mitglieder zahlen Leistungen, die &uuml;ber das
{{APP_NAME}}-Terminal per RFID-Karte gebucht wurden. Der Ausgleich erfolgt per SEPA-Lastschrift.
</div>
<div class="hint">Bitte in Druckbuchstaben ausf&uuml;llen &middot; Pflichtfelder sind mit * markiert</div>

<div class="section-header">1 &nbsp; Pers&ouml;nliche Daten</div>

<table width="100%" cellspacing="0" cellpadding="0" style="margin-bottom:2px;">
<tr>
  <td class="two-col-left">
    <div class="field-label">Vorname *</div>
    <div class="field-box"></div>
  </td>
  <td class="two-col-right">
    <div class="field-label">Nachname *</div>
    <div class="field-box"></div>
  </td>
</tr>
</table>

<table width="100%" cellspacing="0" cellpadding="0" style="margin-bottom:2px;">
<tr>
  <td class="two-col-left">
    <div class="field-label">E-Mail *</div>
    <div class="field-box"></div>
  </td>
  <td class="two-col-right">
    <div class="field-label">Karten-UID * <span class="field-optional">(&uuml;r Terminal-Zugang)</span></div>
    <div class="field-box"></div>
  </td>
</tr>
</table>

<div class="field-label">Stra&szlig;e &amp; Hausnummer *</div>
<div class="field-box"></div>

<table width="100%" cellspacing="0" cellpadding="0" style="margin-bottom:8px;">
<tr>
  <td style="width:28%; vertical-align:top; padding-right:6px;">
    <div class="field-label">PLZ *</div>
    <div class="field-box"></div>
  </td>
  <td style="width:72%; vertical-align:top; padding-left:6px;">
    <div class="field-label">Ort *</div>
    <div class="field-box"></div>
  </td>
</tr>
</table>

<div class="section-header">2 &nbsp; SEPA-Lastschriftmandat</div>

<div class="creditor-box">
  <div class="creditor-box-label">Creditor Identifier (CI):</div>
  {{CREDITOR_ID}} &middot; {{CREDITOR}}, {{ADDRESS}}
</div>

<div class="field-label">IBAN * <span class="field-optional">(SEPA)</span></div>
<div class="field-box"></div>

<table width="100%" cellspacing="0" cellpadding="0" style="margin-bottom:2px;">
<tr>
  <td class="two-col-left">
    <div class="field-label">Kontoinhaber <span class="field-optional">(optional)</span></div>
    <div class="field-box"></div>
  </td>
  <td class="two-col-right">
    <div class="field-label">Mandatsdatum * <span class="field-optional">(SEPA)</span></div>
    <div class="field-box"></div>
  </td>
</tr>
</table>
<div style="font-size:8pt; color:#64748b; margin-bottom:8px;">Nur ausf&uuml;llen, wenn Kontoinhaber vom Mitglied abweicht (z.B. Elternteil zahlt f&uuml;r Kind)</div>

<div class="section-header">3 &nbsp; Einzugsvollmacht &amp; Unterschrift</div>

<div class="legal-box">
Ich erm&auml;chtige {{CREDITOR}}, Zahlungen von meinem Konto mittels Lastschrift einzuziehen. Zugleich weise ich mein Kreditinstitut an, die von
{{CREDITOR}} auf mein Konto gezogenen Lastschriften einzul&ouml;sen. <strong>Hinweis:</strong> Ich kann innerhalb von acht Wochen, beginnend mit dem
Belastungsdatum, die Erstattung des belasteten Betrages verlangen. Es gelten dabei die mit meinem Kreditinstitut vereinbarten Bedingungen. Diese
Erm&auml;chtigung gilt bis auf Widerruf.
</div>

<table class="sig-table">
<tr>
  <td class="sig-cell">Ort, Datum</td>
  <td class="sig-cell">Unterschrift Kontoinhaber</td>
</tr>
</table>

<table class="footer-table" cellspacing="0" cellpadding="0">
<tr>
  <td class="footer-text">{{FOOTER}}</td>
  <td class="footer-right">{{APP_NAME}} POS &middot; SEPA-Onboarding v1.0</td>
</tr>
</table>

</body>
</html>
```

- [ ] **Step 2: Commit**

```bash
git add backend/resources/templates/sepa-mandate.html
git commit -m "feat(backend): add SEPA mandate HTML template"
```

---

### Task 3: Add generateMandateTemplatePdf() to SepaConfigService

**Files:**
- Modify: `backend/src/Modules/Settlements/Services/SepaConfigService.php`

The existing class is at `App\Modules\Settlements\Services\SepaConfigService` with constructor:
```php
public function __construct(
    private SepaConfigRepository $sepaConfigRepository,
    private AuditService $auditService,
) {}
```

- [ ] **Step 1: Add the use statement and method**

Add `use App\Shared\Exceptions\BusinessRuleException;` to the imports at the top of the file (after the existing `use` statements).

Then add this method to the class (after the `isConfigured()` method):

```php
public function generateMandateTemplatePdf(): string
{
    $config = $this->getConfig();

    if (
        !$config ||
        empty($config->creditorId) ||
        empty($config->creditorName) ||
        empty($config->creditorAddressStreet) ||
        empty($config->creditorAddressCity) ||
        empty($config->creditorAddressCountry)
    ) {
        throw new BusinessRuleException(
            'SEPA configuration is incomplete. Please configure creditor details in Settings first.'
        );
    }

    $address = htmlspecialchars(
        $config->creditorAddressStreet . ' · ' . $config->creditorAddressCity . ', ' . $config->creditorAddressCountry,
        ENT_QUOTES,
        'UTF-8'
    );
    $footer = htmlspecialchars(
        $config->creditorName . ' · ' . $config->creditorAddressStreet . ' · ' . $config->creditorAddressCity,
        ENT_QUOTES,
        'UTF-8'
    );

    $templatePath = __DIR__ . '/../../../../resources/templates/sepa-mandate.html';
    $html = (string) file_get_contents($templatePath);

    $html = str_replace(
        ['{{APP_NAME}}', '{{CREDITOR}}', '{{CREDITOR_ID}}', '{{ADDRESS}}', '{{FOOTER}}'],
        [
            'Club Bar',
            htmlspecialchars($config->creditorName, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($config->creditorId, ENT_QUOTES, 'UTF-8'),
            $address,
            $footer,
        ],
        $html
    );

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'Arial');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return (string) $dompdf->output();
}
```

Note on template path: `__DIR__` is `.../backend/src/Modules/Settlements/Services`, so `../../../../resources/templates/` resolves to `.../backend/resources/templates/`. Count: Services → Settlements → Modules → src → backend.

- [ ] **Step 2: Verify syntax**

```bash
cd backend && php -l src/Modules/Settlements/Services/SepaConfigService.php
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add backend/src/Modules/Settlements/Services/SepaConfigService.php
git commit -m "feat(backend): add generateMandateTemplatePdf() to SepaConfigService"
```

---

### Task 4: Add downloadMandateTemplate() to Members AdminController

**Files:**
- Modify: `backend/src/Modules/Members/Controllers/AdminController.php`

The existing constructor at `App\Modules\Members\Controllers\AdminController`:
```php
public function __construct(
    private MembersService $membersService,
    private Validator $validator,
) {}
```

- [ ] **Step 1: Add SepaConfigService import and constructor parameter**

Add this use statement after the existing `use` statements in the file:
```php
use App\Modules\Settlements\Services\SepaConfigService;
```

Update the constructor to add `SepaConfigService`:
```php
public function __construct(
    private MembersService $membersService,
    private Validator $validator,
    private SepaConfigService $sepaConfigService,
) {}
```

- [ ] **Step 2: Add the controller action method**

Add this method to the class (after any existing export/anonymize methods, before the private `json()` helper):

```php
public function downloadMandateTemplate(Request $request, Response $response): Response
{
    $pdf = $this->sepaConfigService->generateMandateTemplatePdf();

    $response->getBody()->write($pdf);

    return $response
        ->withHeader('Content-Type', 'application/pdf')
        ->withHeader('Content-Disposition', 'attachment; filename="sepa-mandate-template.pdf"');
}
```

- [ ] **Step 3: Verify syntax**

```bash
cd backend && php -l src/Modules/Members/Controllers/AdminController.php
```

Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add backend/src/Modules/Members/Controllers/AdminController.php
git commit -m "feat(backend): add downloadMandateTemplate() to Members AdminController"
```

---

### Task 5: Register route and wire ServiceFactory

**Files:**
- Modify: `backend/src/routes.php`
- Modify: `backend/src/ServiceFactory.php`

- [ ] **Step 1: Register the route in routes.php**

In `backend/src/routes.php`, find the SEPA config routes section:
```php
// SEPA config
$group->get('/sepa-config', [SepaConfigController::class, 'show']);
$group->put('/sepa-config', [SepaConfigController::class, 'update']);
```

Add the new route after the SEPA config block:
```php
// SEPA mandate template
$group->get('/sepa-mandate-template', [MembersAdminController::class, 'downloadMandateTemplate']);
```

`MembersAdminController` is already imported at the top of `routes.php` (line 7) as:
```php
use App\Modules\Members\Controllers\AdminController as MembersAdminController;
```

No new import needed.

- [ ] **Step 2: Update ServiceFactory**

In `backend/src/ServiceFactory.php`, find `getMembersAdminController()` (around line 365):
```php
public function getMembersAdminController(): MembersAdminController
{
    return $this->resolve(MembersAdminController::class, fn() => new MembersAdminController($this->getMembersService(), $this->getValidator()));
}
```

Update it to inject `SepaConfigService`:
```php
public function getMembersAdminController(): MembersAdminController
{
    return $this->resolve(MembersAdminController::class, fn() => new MembersAdminController(
        $this->getMembersService(),
        $this->getValidator(),
        $this->getSepaConfigService(),
    ));
}
```

`getSepaConfigService()` already exists in `ServiceFactory` (around line 246) — no new method needed.

- [ ] **Step 3: Verify PHP syntax on both files**

```bash
cd backend && php -l src/routes.php && php -l src/ServiceFactory.php
```

Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Restart PHP-FPM and verify the endpoint responds**

```bash
docker compose exec backend supervisorctl restart php-fpm:php-fpmd
sleep 2
```

Test with missing SEPA config (expect 409):
```bash
curl -s -c /tmp/cookies.txt -b /tmp/cookies.txt \
  http://localhost:8080/api/admin/sepa-mandate-template | jq .
```

You will likely get a 401 (unauthenticated) or 409 (missing config). Either is correct at this stage — it confirms routing works.

To test authenticated, first get a session:
```bash
# Login to get session cookie
curl -s -c /tmp/cookies.txt -X POST http://localhost:8080/api/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"password"}' | jq .

# Get CSRF token from previous response and test endpoint
curl -s -c /tmp/cookies.txt -b /tmp/cookies.txt \
  'http://localhost:8080/api/admin/sepa-mandate-template' -I
```

Expected: Either `HTTP/1.1 409` (config incomplete) or `HTTP/1.1 200` (if config is seeded).

- [ ] **Step 5: Configure SEPA and test PDF download**

```bash
# Set up SEPA config with address fields (adjust credentials from auth.setup.ts if needed)
CSRF=$(curl -s -c /tmp/cookies.txt -b /tmp/cookies.txt \
  http://localhost:8080/api/auth/me | jq -r '.csrf_token // empty')

curl -s -c /tmp/cookies.txt -b /tmp/cookies.txt -X PUT \
  http://localhost:8080/api/admin/sepa-config \
  -H 'Content-Type: application/json' \
  -H "X-CSRF-Token: $CSRF" \
  -d '{
    "creditor_id": "DE98ZZZ09999999999",
    "creditor_name": "Test Club e.V.",
    "creditor_iban": "DE89370400440532013000",
    "creditor_address_street": "Musterstrasse 1",
    "creditor_address_city": "Berlin",
    "creditor_address_country": "DE"
  }' | jq .

# Download PDF
curl -s -c /tmp/cookies.txt -b /tmp/cookies.txt \
  'http://localhost:8080/api/admin/sepa-mandate-template' \
  -H "X-CSRF-Token: $CSRF" \
  -o /tmp/test-mandate.pdf

# Verify it's a real PDF (starts with %PDF)
xxd /tmp/test-mandate.pdf | head -1
```

Expected: First line contains `2550 4446` (hex for `%PDF`). File size should be > 5000 bytes.

```bash
wc -c /tmp/test-mandate.pdf
```

Expected: A number > 5000.

- [ ] **Step 6: Commit**

```bash
git add backend/src/routes.php backend/src/ServiceFactory.php
git commit -m "feat(backend): register GET /admin/sepa-mandate-template route and wire ServiceFactory"
```

---

## Chunk 2: API Spec, Frontend, and E2E

### Task 6: Update OpenAPI specification

**Files:**
- Modify: `api/admin.yaml`

- [ ] **Step 1: Add the endpoint to admin.yaml**

Find the `/admin/members/import/confirm` path in `api/admin.yaml` (around line 708). After that path's closing content (around line 756), add the new endpoint. It should appear before the `# TRANSACTIONS` section divider that follows.

Add this block:

```yaml
  /admin/sepa-mandate-template:
    get:
      summary: Download SEPA mandate template PDF
      description: Returns a pre-filled PDF form for new member SEPA mandate onboarding. Requires SEPA creditor config including address fields to be set up in Settings.
      tags: [Members]
      security:
        - sessionAuth: []
      responses:
        '200':
          description: PDF template with org config pre-filled, ready to print
          content:
            application/pdf:
              schema:
                type: string
                format: binary
        '401':
          $ref: '#/components/responses/Unauthorized'
        '409':
          description: SEPA configuration incomplete (missing creditor ID, name, or address fields)
```

Be careful with YAML indentation — the path key `  /admin/sepa-mandate-template:` needs 2 spaces of indentation (matching other path keys in the file).

- [ ] **Step 2: Verify the YAML is valid**

```bash
cd e2etests && node -e "
const fs = require('fs');
const yaml = require('js-yaml');
try {
  yaml.load(fs.readFileSync('../api/admin.yaml', 'utf8'));
  console.log('YAML valid');
} catch(e) {
  console.error('YAML error:', e.message);
}"
```

Expected: `YAML valid`

If `js-yaml` is not available: `npm install js-yaml --no-save` then re-run, or use Python:
```bash
python3 -c "import yaml; yaml.safe_load(open('api/admin.yaml'))" && echo "valid"
```

- [ ] **Step 3: Commit**

```bash
git add api/admin.yaml
git commit -m "feat(api): add GET /admin/sepa-mandate-template to OAS spec"
```

---

### Task 7: Add download button to MembersPage.tsx

**Files:**
- Modify: `admin-frontend/src/pages/MembersPage.tsx`

- [ ] **Step 1: Add the downloadFile import**

In `MembersPage.tsx`, the imports near the top include:
```typescript
import { getMembers as getMembersFactory } from '../api/generated/members/members'
```

Add this import after the existing api imports:
```typescript
import { downloadFile } from '../api/client'
```

- [ ] **Step 2: Add the handler function**

Find the `handleExportData` function (around line 192). Add this handler nearby (before or after `handleExportData`):

```typescript
const handleDownloadSepaTemplate = async () => {
  try {
    await downloadFile('/admin/sepa-mandate-template', 'sepa-mandate-template.pdf')
  } catch {
    alert('SEPA-Konfiguration unvollständig. Bitte Gläubigerdaten unter Einstellungen konfigurieren.')
  }
}
```

- [ ] **Step 3: Add the button to the page header**

Find the page header area (around line 447–479):
```tsx
<div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '28px' }}>
  <div>
    <h1 ...>{t('members.title')}</h1>
    <p data-testid="members-count-summary" ...>...</p>
  </div>
  <button
    data-testid="members-create-button"
    onClick={() => { ... }}
    style={{ ... }}
  >
    <PlusIcon size={18} />
    {t('common.add')}
  </button>
</div>
```

Replace the single button on the right with a button group:
```tsx
<div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: '28px' }}>
  <div>
    <h1 ...>{t('members.title')}</h1>
    <p data-testid="members-count-summary" ...>...</p>
  </div>
  <div style={{ display: 'flex', gap: '8px' }}>
    <button
      data-testid="members-sepa-template-download-button"
      onClick={handleDownloadSepaTemplate}
      style={{
        display: 'flex',
        alignItems: 'center',
        gap: '7px',
        padding: '10px 20px',
        borderRadius: '8px',
        border: '1px solid rgba(255,255,255,0.15)',
        background: 'transparent',
        color: 'rgba(255,255,255,0.7)',
        fontSize: '14px',
        fontWeight: 600,
        cursor: 'pointer',
        transition: 'background 0.15s',
      }}
    >
      <DownloadIcon size={18} />
      SEPA-Formular
    </button>
    <button
      data-testid="members-create-button"
      onClick={() => {
        setEditingMember(null)
        setFormData({ first_name: '', last_name: '', email: '', iban: '', account_holder_name: '', mandate_reference: '', mandate_signed_at: '', preferred_language: 'de', card_uid: '' })
        setFormErrors({})
        setShowModal(true)
      }}
      style={{
        display: 'flex',
        alignItems: 'center',
        gap: '7px',
        padding: '10px 20px',
        borderRadius: '8px',
        border: 'none',
        background: '#3b82f6',
        color: '#fff',
        fontSize: '14px',
        fontWeight: 600,
        cursor: 'pointer',
        transition: 'background 0.15s',
      }}
    >
      <PlusIcon size={18} />
      {t('common.add')}
    </button>
  </div>
</div>
```

`DownloadIcon` is already imported at line 14:
```typescript
import { UsersIcon, BankIcon, CalendarIcon, EditIcon, PlusIcon, DownloadIcon } from '../components/icons'
```

- [ ] **Step 4: Build the frontend and verify no TypeScript errors**

```bash
cd admin-frontend && npm run build 2>&1 | tail -20
```

Expected: Build completes without TypeScript errors. Warnings about unused variables are acceptable.

- [ ] **Step 5: Commit**

```bash
git add admin-frontend/src/pages/MembersPage.tsx
git commit -m "feat(frontend): add Download SEPA Template button to Members page header"
```

---

### Task 8: Add page object method to MembersPage.ts

**Files:**
- Modify: `e2etests/pages/MembersPage.ts`

- [ ] **Step 1: Add private locator and public method**

In `e2etests/pages/MembersPage.ts`, find the existing private locators section (where `formExportBtn` and similar locators are defined). Add this private locator alongside the others:

```typescript
private readonly sepaTemplateDownloadBtn = () =>
  this.page.getByTestId('members-sepa-template-download-button')
```

Then find the public methods section. Add this method (near other download/export methods):

```typescript
async clickSepaTemplateDownloadButton(): Promise<void> {
  await this.sepaTemplateDownloadBtn().click()
}
```

- [ ] **Step 2: Verify no TypeScript errors**

```bash
cd e2etests && npx tsc --noEmit 2>&1
```

Expected: No errors.

- [ ] **Step 3: Commit**

```bash
git add e2etests/pages/MembersPage.ts
git commit -m "feat(e2e): add clickSepaTemplateDownloadButton() to MembersPage page object"
```

---

### Task 9: Write and verify the E2E test

**Files:**
- Create: `e2etests/tests/admin/members-sepa-template.spec.ts`

- [ ] **Step 1: Write the test**

Create `e2etests/tests/admin/members-sepa-template.spec.ts`:

```typescript
import { test, expect } from '../../fixtures/auth.fixture'
import { MembersPage } from '../../pages/MembersPage'
import * as fs from 'fs'

/**
 * SEPA Mandate Template Download
 *
 * Tests that the "Download SEPA Template" button on the Members page
 * triggers a PDF download with the correct filename and non-empty content.
 *
 * Note: sepa_config is a singleton row shared across tests. The config is
 * re-applied at the start of the test body to guard against parallel test
 * contamination (same pattern as journal-and-settlements.spec.ts lines 165-172).
 * Do NOT use test.beforeAll — authenticatedRequest is test-scoped and cannot
 * be used in beforeAll hooks.
 */

const SEPA_CONFIG = {
  creditor_id: 'DE98ZZZ09999999999',
  creditor_name: 'Test Club e.V.',
  creditor_iban: 'DE89370400440532013000',
  creditor_address_street: 'Musterstrasse 1',
  creditor_address_city: 'Berlin',
  creditor_address_country: 'DE',
}

test.describe('Members SEPA Mandate Template Download', () => {
  test('clicking Download SEPA Template button downloads a PDF', async ({ page, authenticatedRequest }) => {
    // Configure SEPA with all required fields (including address) right before download
    // to guard against parallel test contamination (sepa_config is a singleton row)
    const configResp = await authenticatedRequest.put('/api/admin/sepa-config', {
      data: SEPA_CONFIG,
    })
    expect(configResp.status()).toBe(200)

    const membersPage = new MembersPage(page)
    await membersPage.navigate()
    await membersPage.expectPageVisible()

    const downloadPromise = page.waitForEvent('download')
    await membersPage.clickSepaTemplateDownloadButton()

    const download = await downloadPromise
    expect(download.suggestedFilename()).toBe('sepa-mandate-template.pdf')

    const path = await download.path()
    expect(path).toBeTruthy()
    if (path) {
      const stats = fs.statSync(path)
      expect(stats.size).toBeGreaterThan(5000)
    }
  })
})
```

- [ ] **Step 2: Run the test with 1 worker to verify it passes**

```bash
cd e2etests && npm test -- tests/admin/members-sepa-template.spec.ts --workers=1
```

Expected: `1 passed`. If it fails:
- 409 error: SEPA config setup failed — check the `authenticatedRequest.put()` call at the start of the test body
- Download event never fires: check that the button has the correct `data-testid` and that the frontend is built/served
- File too small: dompdf rendered an empty PDF — check PHP logs: `docker compose exec backend cat /app/logs/$(date +%Y-%m-%d).log | jq 'select(.level=="ERROR")'`

- [ ] **Step 3: Run with 4 workers to verify parallel safety**

```bash
cd e2etests && npm test -- tests/admin/members-sepa-template.spec.ts --workers=4
```

Expected: `1 passed` (same test, verifies no resource issues under parallelism).

- [ ] **Step 4: Run full test suite to verify no regressions**

```bash
cd e2etests && npm test -- --workers=4
```

Expected: All tests pass. If any pre-existing test fails, investigate — do not proceed to commit if the failure is related to SEPA config contamination. The SEPA config re-apply pattern in this test guards against this, but verify.

- [ ] **Step 5: Commit**

```bash
git add e2etests/tests/admin/members-sepa-template.spec.ts
git commit -m "feat(e2e): add SEPA mandate template download test"
```
