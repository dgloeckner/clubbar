# SEPA Mandate Document Upload — Design Spec

**Date**: 2026-03-26
**Status**: Approved

---

## Overview

Admins need to store scanned SEPA mandate documents alongside member records. The feature supports both desktop file uploads (file picker) and mobile camera capture. Files are compressed client-side before upload; images are converted to PDF server-side via dompdf. The server stores a single PDF per member. A placeholder for future LLM extraction of member fields is included in the data model.

---

## Scope

**In scope:**
- Upload mandate scan (JPEG, PNG, HEIC, PDF) for any existing member
- Client-side image compression and HEIC → JPEG conversion before upload
- Server-side image → PDF conversion (dompdf, already installed)
- View (stream PDF to browser) and replace existing document
- GDPR: deletion of document and DB row when member is anonymized
- OAS schema update (`api/admin.yaml`) for new endpoints and modified member response
- E2E tests for all file formats, sizes, and error cases

**Out of scope (future):**
- LLM extraction of member fields from the scan (data model placeholder included)
- "Create member from SEPA scan" flow (upload first → LLM pre-fills creation form)
- Document versioning / history (one document per member; replacement overwrites)
- Role-based access control beyond authenticated admin

---

## Data Model

### New table: `mandate_documents`

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | `BINARY(16)` | No | UUID, PK |
| `member_id` | `BINARY(16)` | No | FK → `members.id`, UNIQUE (one per member) |
| `file_path` | `VARCHAR(255)` | No | Relative path, e.g. `mandates/{member-uuid}.pdf` |
| `original_filename` | `VARCHAR(255)` | No | Original name for display only |
| `file_size_bytes` | `INT UNSIGNED` | No | Size of stored PDF |
| `extraction_status` | `ENUM('pending','completed','failed')` | Yes | `NULL` = LLM extraction never attempted; `'pending'` = job queued; set by future extraction pipeline, never by upload |
| `extracted_data` | `JSON` | Yes | `NULL` until extraction runs; holds all extractable member fields |
| `uploaded_by_admin_id` | `BINARY(16)` | No | FK → `admin_users.id`, audit trail |
| `created_at` | `DATETIME` | No | |
| `updated_at` | `DATETIME` | No | |

**`extraction_status` state machine**: `NULL` (never attempted) → `'pending'` (job queued by future LLM pipeline) → `'completed'` or `'failed'`. The upload itself never sets this column; it remains `NULL` after upload.

**File storage**: Outside the web-accessible document root at `storage/mandates/{member-uuid}.pdf`. The `storage/` directory is not reachable via HTTP. On IONOS shared hosting, mandate documents are stored under the private `storage/` path; no `.htaccess` exception is needed because the directory is not under `public/`. Files are stored flat — one PDF per member UUID.

Re-uploading replaces the existing file and updates the `mandate_documents` row (upsert).

### Members table — no changes

Member identity fields are unchanged. `mandate_documents` is a separate resource linked by `member_id`.

### Migration

A new migration script creates the `mandate_documents` table. The `storage/mandates/` directory is created with a `.gitkeep`.

---

## API

### New endpoints

| Method | Path | Purpose |
|--------|------|---------|
| `POST` | `/admin/members/{memberId}/mandate-document` | Upload or replace mandate document |
| `GET` | `/admin/members/{memberId}/mandate-document` | Stream stored PDF to browser |
| `DELETE` | `/admin/members/{memberId}/mandate-document` | Delete document (GDPR) |

#### `POST /admin/members/{memberId}/mandate-document`

- **Content-Type**: `multipart/form-data`
- **Field**: `file` — JPEG, PNG, or PDF (HEIC converted to JPEG client-side before upload)
- **Max size**: 10 MB (post-compression; client compresses before upload)
- **Behavior**: Upserts the document record (creates on first upload, replaces on subsequent uploads). Converts images to PDF via dompdf. Stores at `storage/mandates/{member-uuid}.pdf`.
- **Response codes**:
  - `200 OK` — document created or replaced (upsert semantics; `201` is not used here because the resource identity `/mandate-document` is stable and pre-determined by the member ID)
  - `401 Unauthorized` — unauthenticated
  - `404 Not Found` — member does not exist
  - `422 Unprocessable Entity` — file missing, wrong type, or exceeds size limit
- **Response body** `200`: `{ uploaded_at, file_size_bytes, original_filename }`

#### `GET /admin/members/{memberId}/mandate-document`

- Streams the PDF with `Content-Type: application/pdf`, `Content-Disposition: inline`
- **Response codes**:
  - `200 OK` — PDF streamed
  - `401 Unauthorized` — unauthenticated
  - `404 Not Found` — member does not exist, OR member exists but has no document (same code; no information leakage)

#### `DELETE /admin/members/{memberId}/mandate-document`

- Deletes the file from disk and the `mandate_documents` row
- Called automatically by `POST /admin/members/{memberId}/anonymize` (GDPR)
- **Response codes**:
  - `204 No Content` — deleted successfully
  - `401 Unauthorized`
  - `404 Not Found` — member does not exist, or no document to delete

### Modified endpoint

**`GET /admin/members/{memberId}`** response gains an optional `mandate_document` field:

```json
{
  "mandate_document": {
    "uploaded_at": "2026-03-24T10:15:00Z",
    "file_size_bytes": 913408,
    "original_filename": "scan_mandat.jpg",
    "extraction_status": null
  }
}
```

`mandate_document` is `null` if no document has been uploaded.

### OAS schema changes required (`api/admin.yaml`)

- Add `MandateDocument` component schema with fields: `uploaded_at`, `file_size_bytes`, `original_filename`, `extraction_status` (nullable string)
- Add the three new endpoint definitions
- Update the `Member` response schema to include `mandate_document` as a nullable `$ref: '#/components/schemas/MandateDocument'`

---

## Backend Architecture

Follows existing backend patterns (ADR-0018 modular architecture):

- **Module**: `Members`
- **Controller**: `MandateDocumentController` — a dedicated controller alongside the existing `AdminController`. This follows the same pattern as the Settlements module, which has both `AdminController` and `SepaConfigController`. Three new HTTP verbs on a sub-resource warrant their own controller to keep `AdminController` focused on member CRUD.
- **Service**: `MandateDocumentService` — handles upload, conversion, storage, deletion
- **Repository**: `MandateDocumentRepository` — DB access for `mandate_documents`
- **Form Request**: `UploadMandateDocumentRequest` — validates MIME type and size
- **Conversion**: Image → PDF via dompdf (wraps image in minimal HTML template)

### Conversion pipeline (server-side)

1. Receive uploaded file (JPEG or PNG — HEIC handled client-side)
2. If PDF: store directly
3. If image: embed as base64 in HTML `<img>` tag, render to PDF with dompdf
4. Write PDF to `storage/mandates/{member-uuid}.pdf`
5. Upsert row in `mandate_documents`

### Audit logging

Uploading a mandate document produces an audit log entry via `AuditService::log()`:
- **Action**: `MANDATE_DOCUMENT_UPLOAD` (new `AuditAction` enum value)
- **Entity**: `MEMBER`
- **Entity ID**: `{memberId}`
- **Actor**: `{adminId}`

Deletion (manual or via anonymization) produces:
- **Action**: `MANDATE_DOCUMENT_DELETE`
- **Entity**: `MEMBER`
- **Entity ID**: `{memberId}`
- **Actor**: `{adminId}`

### GDPR integration

`POST /admin/members/{memberId}/anonymize` (existing) is extended to call `MandateDocumentService::deleteForMember($memberId)` before the member record is anonymized. This deletes the file and the `mandate_documents` row (including `original_filename`, which may contain member PII). The row deletion fully covers GDPR erasure of the document and its metadata.

---

## Frontend

### Libraries added

- `browser-image-compression` — client-side JPEG/PNG compression (max 2 MB, max 2000px)
- `heic2any` — HEIC → JPEG conversion before compression

### Component: `MandateDocumentSection`

A self-contained section rendered at the bottom of the edit member modal. Has three states:

**Empty state** — file input (`accept="image/*,.pdf"`, `capture="environment"` for mobile camera). Shows accepted formats.

**File selected state** — shows filename, original size, estimated compressed size, "Will be converted to PDF" note. Upload and cancel buttons. Upload fires immediately (independent of the member Save button).

**Document stored state** — shows filename, stored size, upload date. "View" button (opens PDF in new tab) and "Replace" button (re-enters empty state).

### Upload is independent of Save

The file upload (`POST .../mandate-document`) fires immediately when the admin clicks Upload, not when they click Save member. This separates the heavy file transfer from the lightweight member data save.

### Mobile camera

`<input type="file" accept="image/*,.pdf">` — no `capture` attribute. On mobile, the OS presents both "camera" and "choose file" options natively. Adding `capture="environment"` would suppress the file picker on many browsers, forcing camera-only input, which is not the intent.

### No upload during member creation

The `MandateDocumentSection` is only rendered in edit mode (when a `memberId` exists). During the create member flow, the section is absent.

---

## Future: Create from SEPA Scan

A separate entry point (not part of this spec) will allow:
1. Admin uploads scan first (no member yet)
2. LLM extracts member fields → pre-fills creation form
3. Admin reviews, completes, saves member
4. Document automatically associated with the new member

The `extracted_data` JSON column and `extraction_status` enum in `mandate_documents` are placeholders for this flow.

---

## E2E Tests

Test fixtures stored in `e2etests/fixtures/files/`:

| File | Purpose |
|------|---------|
| `test-mandate.jpg` | Standard JPEG upload |
| `test-mandate.png` | PNG upload |
| `test-mandate.pdf` | Direct PDF upload |
| `test-mandate.heic` | HEIC (Apple) — client-side conversion |
| `test-mandate-large.jpg` | 10 MB+ image — compression verification |

### Test cases

| Test | Assertion |
|------|-----------|
| Upload JPEG → document stored | Section shows stored state; GET returns `Content-Type: application/pdf` |
| Upload PNG → document stored | Same |
| Upload PDF → stored as-is | GET returns original PDF |
| Upload HEIC → converted and stored | GET returns PDF |
| Upload large image → compressed | Stored `file_size_bytes` significantly smaller than input |
| Replace existing document | New document shown; old file gone |
| View document | GET streams PDF inline |
| GDPR anonymize → document deleted | After anonymize, GET returns `404` |
| Upload with invalid type (e.g. `.docx`) | `422` returned; validation error shown in UI; no file stored |
| Upload to non-existent member UUID | `404` returned |

Tests use `page.setInputFiles()` for programmatic file injection. Each test creates a unique member to satisfy Pattern 001 (test data isolation).
