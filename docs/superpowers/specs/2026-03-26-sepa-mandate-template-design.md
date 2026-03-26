# SEPA Mandate Template Download

**Date**: 2026-03-26
**Status**: Approved

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
  → MandateTemplateController
  → SepaConfigService (existing)
  → HTML template → dompdf → PDF response
```

No new database tables or data model changes. Reads from the existing `sepa_config` table.

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
- `422 Unprocessable Entity`
- Body: standard error JSON — "SEPA configuration is incomplete. Please configure creditor details in Settings first."

### New files

| File | Purpose |
|------|---------|
| `backend/resources/templates/sepa-mandate.html` | HTML/CSS template (mirrors the provided PDF design) |
| `backend/src/Modules/Members/Controllers/MandateTemplateController.php` | Thin controller (Pattern 006) |

### Template placeholders

| Placeholder | Source field |
|-------------|-------------|
| `{{APP_NAME}}` | `sepa_config.app_name` |
| `{{CREDITOR}}` | `sepa_config.creditor_name` |
| `{{CREDITOR_ID}}` | `sepa_config.creditor_id` |
| `{{ADDRESS}}` | `sepa_config.address` |
| `{{FOOTER}}` | `sepa_config.footer` |

### Controller logic

1. Call `SepaConfigService::getConfig()`
2. If any required placeholder field is null/empty → return `422`
3. Load `sepa-mandate.html`, substitute placeholders via `str_replace()`
4. Instantiate `Dompdf\Dompdf`, load HTML, render
5. Return PDF as streaming response with appropriate headers

### Dependencies

Add to `composer.json`: `"dompdf/dompdf": "^2.0"`

### Route registration

In `routes.php`, under the existing `admin` group:

```php
$group->get('/sepa-mandate-template', [MandateTemplateController::class, 'download']);
```

## Frontend

### Button placement

In `MembersPage.tsx`, added to the page header toolbar alongside the existing "New Member" button.

### Implementation

```typescript
const handleDownloadSepaTemplate = () => {
  downloadFile('/api/admin/sepa-mandate-template', 'sepa-mandate-template.pdf')
}
```

Uses the existing `downloadFile()` helper from `api/client.ts`. No new Orval-generated client needed — it is a direct binary download.

**Test ID**: `data-testid="members-sepa-template-download-button"`

## API Specification

Add to `api/admin.yaml`:

```yaml
/admin/sepa-mandate-template:
  get:
    summary: Download SEPA mandate template PDF
    tags: [Members]
    responses:
      '200':
        description: PDF template with org config pre-filled
        content:
          application/pdf:
            schema:
              type: string
              format: binary
      '422':
        $ref: '#/components/responses/UnprocessableEntity'
```

## Testing

### E2E test

**File**: `e2etests/tests/admin/members-sepa-template.spec.ts`

```
test: clicking "Download SEPA Template" button triggers PDF download
  - uses authenticatedMembersPage fixture (no new fixture needed)
  - waitForEvent('download')
  - assert: suggestedFilename() === 'sepa-mandate-template.pdf'
  - assert: file size > 0
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
2. Create `sepa-mandate.html` template (HTML/CSS recreation of the provided design)
3. Implement `MandateTemplateController`
4. Register route in `routes.php`
5. Update `api/admin.yaml` with new endpoint
6. Add button to `MembersPage.tsx`
7. Add page object methods to `MembersPage.ts`
8. Write and verify E2E test
