# SEPA Mandate Document Upload — Design Spec

**Date**: 2026-03-26
**Status**: Approved

---

## Overview

Admins need to store scanned SEPA mandate documents alongside member records. The feature supports both desktop file uploads (drag-and-drop / file picker) and mobile camera capture. Files are compressed and converted to PDF client-side before upload; the server stores a single PDF per member. A placeholder for future LLM extraction of member fields is included in the data model.

---

## Scope

**In scope:**
- Upload mandate scan (JPEG, PNG, HEIC, PDF) for any existing member
- Client-side image compression and HEIC → JPEG conversion before upload
- Server-side image → PDF conversion (dompdf)
- View (stream to browser) and replace existing document
- GDPR: deletion of document when member is anonymized
- E2E tests for all file formats and sizes

**Out of scope (future):**
- LLM extraction of member fields from the scan (data model placeholder included)
- "Create member from SEPA scan" flow (upload first → LLM pre-fills creation form)
- Document versioning / history (one document per member; replacement overwrites)
- Role-based access control beyond authenticated admin

---

## Data Model

### New table: `mandate_documents`

| Column | Type | Notes |
|--------|------|-------|
| `id` | `BINARY(16)` | UUID, PK |
| `member_id` | `BINARY(16)` | FK → `members.id`, UNIQUE (one per member) |
| `file_path` | `VARCHAR(255)` | Relative path, e.g. `mandates/{member-uuid}.pdf` |
| `original_filename` | `VARCHAR(255)` | Original name for display only |
| `file_size_bytes` | `INT UNSIGNED` | Size of stored PDF |
| `extraction_status` | `ENUM('pending','completed','failed')` | NULL until first LLM extraction attempt |
| `extracted_data` | `JSON` | NULL until extraction runs; will hold all extractable member fields |
| `uploaded_by_admin_id` | `BINARY(16)` | FK → `admin_users.id`, audit trail |
| `created_at` | `DATETIME` | |
| `updated_at` | `DATETIME` | |

**File storage**: `storage/mandates/{member-uuid}.pdf` (flat directory, one file per member).
Re-uploading replaces the existing file and updates the `mandate_documents` row.

### Members table — no changes

Member identity fields are unchanged. `mandate_documents` is a separate resource linked by `member_id`.

### Migration

A new migration script creates the `mandate_documents` table and adds the `storage/mandates/` directory (with `.gitkeep`).

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
- **Behavior**: Creates or replaces the document. Converts images to PDF via dompdf. Stores at `storage/mandates/{member-uuid}.pdf`.
- **Response** `200`: `{ uploaded_at, file_size_bytes, original_filename }`

#### `GET /admin/members/{memberId}/mandate-document`

- Streams the PDF with `Content-Type: application/pdf`, `Content-Disposition: inline`
- Returns `404` if no document exists

#### `DELETE /admin/members/{memberId}/mandate-document`

- Deletes the file and the `mandate_documents` row
- Called automatically by `POST /admin/members/{memberId}/anonymize` (GDPR)

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

---

## Backend Architecture

Follows existing backend patterns (ADR-0018 modular architecture):

- **Module**: `Members` (document endpoints live in the Members module)
- **Controller**: `MandateDocumentController` — thin, delegates to service
- **Service**: `MandateDocumentService` — handles upload, conversion, storage, deletion
- **Repository**: `MandateDocumentRepository` — DB access for `mandate_documents`
- **Form Request**: `UploadMandateDocumentRequest` — validates file type and size
- **Conversion**: Image → PDF via dompdf (wraps image in minimal HTML template)

### Conversion pipeline (server-side)

1. Receive uploaded file (JPEG or PNG — HEIC handled client-side)
2. If PDF: store directly
3. If image: wrap in HTML `<img src="data:...">`, render to PDF with dompdf
4. Write PDF to `storage/mandates/{member-uuid}.pdf`
5. Store metadata in `mandate_documents`

### GDPR integration

`POST /admin/members/{memberId}/anonymize` (existing) is extended to call `MandateDocumentService::deleteForMember($memberId)` — deletes the file and DB row if they exist.

---

## Frontend

### Libraries added

- `browser-image-compression` — client-side JPEG/PNG compression (max 2 MB, max 2000px)
- `heic2any` — HEIC → JPEG conversion before compression

### Component: `MandateDocumentSection`

A self-contained section rendered at the bottom of the edit member modal. Has three states:

**Empty state** — dashed drop zone with file input (`accept="image/*,.pdf"`, `capture="environment"` for mobile camera). Shows accepted formats.

**File selected state** — shows filename, original size, estimated compressed size, "Will be converted to PDF" note. Upload and cancel buttons. Upload fires immediately (independent of the member Save button).

**Document stored state** — shows filename, stored size, upload date. "View" button (opens PDF in new tab) and "Replace" button (re-enters empty state).

### Upload is independent of Save

The file upload (`POST .../mandate-document`) fires immediately when the admin clicks Upload, not when they click Save member. This separates the heavy file transfer from the lightweight member data save.

### Mobile camera

`<input type="file" accept="image/*,.pdf" capture="environment">` presents the camera as an option on mobile without requiring any additional library.

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
| `test-mandate-large.jpg` | 10MB+ image — compression verification |

### Test cases

| Test | Assertion |
|------|-----------|
| Upload JPEG → document stored | Section shows stored state; GET returns PDF content-type |
| Upload PNG → document stored | Same |
| Upload PDF → stored as-is | GET returns original PDF |
| Upload HEIC → converted and stored | GET returns PDF |
| Upload large image → compressed | Stored `file_size_bytes` significantly smaller than input |
| Replace existing document | New document shown; old file gone |
| View document | GET streams PDF inline |
| GDPR anonymize → document deleted | After anonymize, GET returns 404 |
| Upload with invalid type (e.g. .docx) | Validation error shown, no file stored |

Tests use `page.setInputFiles()` for programmatic file injection. Each test creates a unique member to satisfy Pattern 001 (test data isolation).

---

## Open Questions

None — all design decisions resolved.
