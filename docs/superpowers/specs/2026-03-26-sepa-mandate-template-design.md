# SEPA Mandate Template Download

**Date**: 2026-03-26
**Status**: Draft

## Overview

Admins can download a pre-filled SEPA mandate template PDF directly from the Members page. They print it, have new members fill in their personal and banking details, collect the signature, and later create the member record in the system. A future feature will allow uploading the signed mandate and extracting data via AI.

## Scope

**In scope**: Download button on Members page that returns a PDF with org-level placeholders filled in.
**Out of scope**: Member-specific pre-filling, mandate upload, AI data extraction (future features).

## User Workflow

1. Admin clicks "Download SEPA Template" in the Members page header
2. Browser downloads `sepa-mandate-template.pdf`
3. Admin prints it
4. New member fills in personal data, IBAN, signs
5. Admin creates the member record manually in the system

## Architecture

```
Members page header
  → "Download SEPA Template" button
  → GET /admin/sepa-mandate-template
  → Members AdminController::downloadMandateTemplate()
  → SepaConfigService::generateMandateTemplatePdf() (existing service, new method)
  → HTML template → dompdf → PDF response
```

No new database tables or data model changes. Reads from the existing `sepa_config` table.

The Members `AdminController` depends on `SepaConfigService` from the Settlements module. This cross-module dependency through `ServiceFactory` is an established pattern in the project — `DashboardAdminController` already consumes `MembersRepository`, `TransactionsRepository`, `SettlementsRepository`, and `TerminalsRepository` from other modules via the same mechanism.

## Placeholder Mapping

All 5 template placeholders are derived from existing `sepa_config` fields — no schema migration required.

| Placeholder | Source |
|-------------|--------|
| `{{APP_NAME}}` | Hardcoded `"Club Bar"` |
| `{{CREDITOR}}` | `sepa_config.creditor_name` |
| `{{CREDITOR_ID}}` | `sepa_config.creditor_id` |
| `{{ADDRESS}}` | `creditor_address_street · creditor_address_city, creditor_address_country` |
| `{{FOOTER}}` | `creditor_name · creditor_address_street · creditor_address_city` |

## Backend

### Endpoint

```
GET /admin/sepa-mandate-template
```

**Response (success)**:
- `200 OK`
- `Content-Type: application/pdf`
- `Content-Disposition: attachment; filename="sepa-mandate-template.pdf"`
- Body: PDF binary

**Response (missing config)**:
- `409 Conflict` via `BusinessRuleException` (same pattern as `SepaExportService`)
- Body: standard error JSON — message: "SEPA configuration is incomplete. Please configure creditor details in Settings first."

### New files

| File | Purpose |
|------|---------|
| `backend/resources/templates/sepa-mandate.html` | HTML/CSS template (mirrors the provided PDF design) |

### Modified files

| File | Change |
|------|--------|
| `backend/src/Modules/Members/Controllers/AdminController.php` | Add `downloadMandateTemplate()` action method |
| `backend/src/Modules/Settlements/Services/SepaConfigService.php` | Add `generateMandateTemplatePdf(): string` method |
| `backend/src/ServiceFactory.php` | Inject `SepaConfigService` into Members `AdminController` |
| `backend/src/routes.php` | Register new route near existing SEPA routes (~line 127) |

### Service method logic (`SepaConfigService::generateMandateTemplatePdf()`)

1. Call `getConfig()` — returns `SepaConfigDto`
2. If `getConfig()` returns `null`, or if `creditorId`, `creditorName`, or any address field (`creditorAddressStreet`, `creditorAddressCity`, `creditorAddressCountry`) is null/empty, throw `BusinessRuleException('SEPA configuration is incomplete. Please configure creditor details in Settings first.')`
3. Compose `{{ADDRESS}}` as `"{$dto->creditorAddressStreet} · {$dto->creditorAddressCity}, {$dto->creditorAddressCountry}"`
4. Compose `{{FOOTER}}` as `"{$dto->creditorName} · {$dto->creditorAddressStreet} · {$dto->creditorAddressCity}"`
5. Load `backend/resources/templates/sepa-mandate.html` as a string
6. Escape all substitution values with `htmlspecialchars()` before substitution
7. Replace all 5 placeholders via `str_replace()`
8. Instantiate `Dompdf\Dompdf`, load HTML, render, return PDF string via `$dompdf->output()`

### Controller action (`AdminController::downloadMandateTemplate()`)

Delegates entirely to `SepaConfigService::generateMandateTemplatePdf()`. Sets `Content-Type: application/pdf` and `Content-Disposition: attachment; filename="sepa-mandate-template.pdf"` headers and writes the returned string to the response body. The `ErrorHandler` middleware catches `BusinessRuleException` and formats the 409 response.

### Route registration

In `routes.php`, added near the existing SEPA config routes (~line 127):

```php
$group->get('/sepa-mandate-template', [MembersAdminController::class, 'downloadMandateTemplate']);
```

### Dependencies

Add to `composer.json`: `"dompdf/dompdf": "^2.0"`

## Frontend

### Button placement

In `MembersPage.tsx`, added to the page header toolbar alongside the existing "New Member" button.

### Implementation

```typescript
const handleDownloadSepaTemplate = async () => {
  try {
    await downloadFile('/api/admin/sepa-mandate-template', 'sepa-mandate-template.pdf')
  } catch {
    // Show user-facing error: "SEPA configuration is incomplete. Please configure creditor details in Settings first."
  }
}
```

Uses the existing `downloadFile()` helper from `api/client.ts`. No new Orval-generated client needed — it is a direct binary download.

**Test ID**: `data-testid="members-sepa-template-download-button"`

## API Specification

Add to `api/admin.yaml`, grouped with the Members tag:

```yaml
/admin/sepa-mandate-template:
  get:
    summary: Download SEPA mandate template PDF
    tags: [Members]
    security:
      - sessionAuth: []
    responses:
      '200':
        description: PDF template with org config pre-filled
        content:
          application/pdf:
            schema:
              type: string
              format: binary
      '401':
        $ref: '#/components/responses/Unauthorized'
      '409':
        description: SEPA configuration incomplete
```

## Testing

### E2E test

**File**: `e2etests/tests/admin/members-sepa-template.spec.ts`

The test requires SEPA config to be populated. The `beforeEach` (or test setup) must call `PUT /api/admin/sepa-config` with valid creditor data including address fields before the download assertion runs. Alternatively, if `auth.setup.ts` guarantees a seeded SEPA config in the test database, document that dependency explicitly.

```
test: clicking "Download SEPA Template" button triggers PDF download
  - setup: ensure SEPA config has creditor_id, creditor_name, and all address fields set
  - uses authenticatedMembersPage fixture
  - waitForEvent('download')
  - assert: suggestedFilename() === 'sepa-mandate-template.pdf'
  - assert: file size > 5000 bytes (guards against blank/empty PDF regressions)
```

### Page object additions (`e2etests/pages/MembersPage.ts`)

```typescript
private readonly sepaTemplateDownloadButton = () =>
  this.page.getByTestId('members-sepa-template-download-button')

async clickSepaTemplateDownloadButton(): Promise<void> {
  await this.sepaTemplateDownloadButton().click()
}
```

## Implementation Order

1. Add `dompdf/dompdf` to `composer.json`, run `composer install`
2. Create `backend/resources/templates/sepa-mandate.html` (HTML/CSS recreation of the provided design)
3. Add `generateMandateTemplatePdf()` to `SepaConfigService`
4. Add `downloadMandateTemplate()` to Members `AdminController`
5. Register route in `routes.php` near existing SEPA routes
6. Update `ServiceFactory.php` to inject `SepaConfigService` into Members `AdminController`
7. Update `api/admin.yaml` with new endpoint
8. Add button + error handling to `MembersPage.tsx`
9. Add page object methods to `MembersPage.ts`
10. Write and verify E2E test
