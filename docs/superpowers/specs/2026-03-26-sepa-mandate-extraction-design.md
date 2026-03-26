# SEPA Mandate LLM Extraction — Design Spec

**Date**: 2026-03-26
**Status**: Approved

---

## Overview

After a scanned SEPA mandate document is uploaded, an LLM vision API automatically extracts member fields (IBAN, name, mandate date, etc.) and returns them with per-field confidence levels (high / medium / low). Extracted values pre-fill the member form so the admin can review, edit, and save. The same extraction service powers a "create member from scan" flow where no member record exists yet.

---

## Scope

**In scope:**
- Automatic synchronous LLM extraction triggered on mandate document upload (existing member)
- Per-field confidence levels: `high`, `medium`, `low`
- Extracted values pre-fill the member edit form (highlighted yellow); admin reviews and saves normally
- New endpoint `POST /admin/mandate-document/extract` for extraction without storage (create-from-scan flow)
- "New from scan" button on Members page header → pre-filled create-member modal
- Support for Anthropic (Claude) and OpenAI (GPT-4o) configurable via `.env`
- `LLM_PROVIDER`, `LLM_API_KEY`, `LLM_MODEL` added to `AppConfig` and documented in `.env.example`

**Out of scope:**
- Async/queued extraction (shared hosting constraint; synchronous only)
- Storing extracted data history or audit trail beyond `extracted_data` JSON column
- Extraction from multi-page PDFs (first page only, or Anthropic document blocks)
- Admin UI for configuring the LLM key (`.env` only)

---

## Extracted Fields

Fields extracted from the scanned mandate form, mapped to `members` table columns:

| Field | Member column | Notes |
|-------|--------------|-------|
| `first_name` | `first_name` | |
| `last_name` | `last_name` | |
| `email` | `email` | Often absent from scan |
| `iban` | `iban` | High priority — clean up spaces before storing |
| `account_holder_name` | `account_holder_name` | May differ from member name |
| `mandate_signed_at` | `mandate_signed_at` | Normalise to `YYYY-MM-DD` |

---

## Configuration

Three new `.env` variables, all optional (extraction silently skipped if unset):

```
LLM_PROVIDER=anthropic        # anthropic | openai
LLM_API_KEY=sk-ant-...
LLM_MODEL=                    # optional; defaults: claude-haiku-4-5-20251001 / gpt-4o-mini
```

`AppConfig` gains three nullable string properties: `llmProvider`, `llmApiKey`, `llmModel`.

---

## Data Model

No schema changes. The existing `mandate_documents` table already has:

- `extraction_status` — `NULL` (not attempted) → `'completed'` or `'failed'`
- `extracted_data` — JSON column; `NULL` until extraction runs

`extracted_data` structure:

```json
{
  "fields": {
    "first_name":          { "value": "Max",                    "confidence": "high" },
    "last_name":           { "value": "Mustermann",             "confidence": "high" },
    "iban":                { "value": "DE89370400440532013000", "confidence": "high" },
    "account_holder_name": { "value": "Max Mustermann",         "confidence": "medium" },
    "mandate_signed_at":   { "value": "2026-01-15",             "confidence": "high" },
    "email":               { "value": null,                     "confidence": null }
  }
}
```

Confidence values: `"high"`, `"medium"`, `"low"`, or `null` (field not found).

---

## API

### Modified endpoint

**`POST /admin/members/{memberId}/mandate-document`** (existing upload endpoint)

Response body gains an `extraction` field:

```json
{
  "uploaded_at": "2026-03-26T10:15:00Z",
  "file_size_bytes": 420000,
  "original_filename": "scan_mandat.jpg",
  "extraction_status": "completed",
  "extraction": {
    "fields": {
      "first_name":        { "value": "Max",                    "confidence": "high" },
      "last_name":         { "value": "Mustermann",             "confidence": "high" },
      "iban":              { "value": "DE89370400440532013000", "confidence": "high" },
      "account_holder_name": { "value": "Max Mustermann",      "confidence": "medium" },
      "mandate_signed_at": { "value": "2026-01-15",            "confidence": "high" },
      "email":             { "value": null,                     "confidence": null }
    }
  }
}
```

`extraction` is `null` if LLM is not configured or extraction failed.

### New endpoint

**`POST /admin/mandate-document/extract`**

- **Content-Type**: `multipart/form-data`, field: `file`
- **Purpose**: Extract fields from a scan without storing anything. Used by the create-from-scan flow.
- **Auth**: Session cookie (same as all admin endpoints)
- **Response `200`**:
```json
{
  "fields": {
    "first_name":        { "value": "Max",                    "confidence": "high" },
    "last_name":         { "value": "Mustermann",             "confidence": "high" },
    "iban":              { "value": "DE89370400440532013000", "confidence": "high" },
    "account_holder_name": { "value": "Max Mustermann",      "confidence": "medium" },
    "mandate_signed_at": { "value": "2026-01-15",            "confidence": "high" },
    "email":             { "value": null,                     "confidence": null }
  }
}
```
- **Response `409`**: `{ "error": "LLM extraction is not configured." }` — if `LLM_PROVIDER` or `LLM_API_KEY` absent
- **Response `422`**: file missing, wrong type, or too large

---

## Backend Architecture

### New files (`Members` module)

| File | Purpose |
|------|---------|
| `src/Modules/Members/Contracts/LlmClientInterface.php` | `extractFromImage(string $base64, string $mimeType): array` |
| `src/Modules/Members/LlmClients/AnthropicClient.php` | Calls Anthropic `messages` API with image or document content block |
| `src/Modules/Members/LlmClients/OpenAiClient.php` | Calls OpenAI `chat/completions` with base64 image |
| `src/Modules/Members/Factories/LlmClientFactory.php` | Reads `LLM_PROVIDER`, returns correct client |
| `src/Modules/Members/Services/ExtractionService.php` | Builds prompt, calls client, parses JSON → `ExtractionResult` |
| `src/Modules/Members/Controllers/ExtractionController.php` | Handles `POST /admin/mandate-document/extract` |

### Modified files

- `AppConfig` — add `llmProvider`, `llmApiKey`, `llmModel` (all nullable)
- `MandateDocumentService` — after file stored, call `ExtractionService::extract($originalBytes, $mimeType)`, write result to DB
- `ServiceFactory` — register `ExtractionService`, `ExtractionController`, `LlmClientFactory`
- `routes.php` — add `POST /api/admin/mandate-document/extract`
- `api/admin.yaml` — add `ExtractionResult` schema; update upload response and add new endpoint

### Extraction pipeline (MandateDocumentService)

1. Receive uploaded file bytes (before PDF conversion)
2. If LLM not configured → skip, leave `extraction_status NULL`
3. Call `ExtractionService::extract($bytes, $mimeType)`
4. On success → write `extracted_data`, set `extraction_status = 'completed'`
5. On failure → set `extraction_status = 'failed'`, log error; upload still returns `200`

### Image handling per provider

| Upload type | Anthropic | OpenAI |
|-------------|-----------|--------|
| JPEG / PNG | Base64 image block | Base64 image in message |
| PDF | Base64 document block (native support) | Rasterise page 1 via `imagick` if available; else skip with `extraction_status: 'failed'` |

---

## Frontend

### Flow 1 — Upload for existing member

1. Admin selects file → client compresses (existing behaviour)
2. Upload button shows "Uploading & extracting…"
3. On response: if `extraction` present, extracted field values populate the relevant form inputs (highlighted yellow) with confidence badge per field
4. Admin reviews, edits any field, clicks Save member (normal flow — no new save action)
5. "Discard extracted values" button resets highlighted fields to their pre-upload values (frontend retains original values in state from the member load response, before extraction overwrote them)

### Flow 2 — Create member from scan

1. "New from scan" button in Members page header (alongside existing "New member" and "SEPA template" buttons)
2. File picker → upload to `POST /admin/mandate-document/extract`
3. Spinner: "Extracting…"
4. On response: create-member modal opens pre-filled with extracted values + confidence badges
5. Admin reviews, fills missing fields, clicks Create member
6. After member is created, frontend automatically uploads the same file to `POST /admin/members/{newId}/mandate-document`

### Confidence display

| Level | Field border | Badge |
|-------|-------------|-------|
| `high` | Yellow (`#fde047`) | Green `● High` |
| `medium` | Yellow (`#fde047`) | Amber `● Medium` |
| `low` | Red (`#fca5a5`) | Red `● Low` |
| `null` (not found) | Dashed grey | Grey `—` / italic placeholder text |

All extracted fields are editable in place. The confidence badge is display-only.

---

## Error Handling

| Scenario | Behaviour |
|---|---|
| LLM not configured | Extraction silently skipped; upload/extract endpoints work normally; no badges shown |
| LLM API error (network / auth) | Upload still returns `200`; `extraction_status: 'failed'`; UI shows amber notice "Extraction failed — check LLM config" |
| Unparseable LLM response | Same as above; raw response logged |
| PDF + OpenAI + no `imagick` | `extraction_status: 'failed'`; log note: "PDF extraction requires imagick extension with OpenAI provider" |
| `POST .../extract` with LLM not configured | `409 Conflict`: "LLM extraction is not configured." |

---

## Internationalisation

All new UI strings go through `i18next` (de/en locale files at `admin-frontend/public/locales/`). New keys to add to both `de.json` and `en.json`:

| Key (under `members` namespace) | EN | DE |
|---|---|---|
| `newFromScan` | `New from scan` | `Neu aus Scan` |
| `extracting` | `Uploading & extracting…` | `Hochladen & Extrahieren…` |
| `extractionComplete` | `Extraction complete` | `Extraktion abgeschlossen` |
| `extractionFailed` | `Extraction failed — check LLM config` | `Extraktion fehlgeschlagen — LLM-Konfiguration prüfen` |
| `extractedValue` | `Extracted from scan` | `Aus Scan extrahiert` |
| `discardExtracted` | `Discard extracted values` | `Extrahierte Werte verwerfen` |
| `notFoundInScan` | `Not found in scan` | `Nicht im Scan gefunden` |
| `extractionReviewHint` | `Fields pre-filled from scan. Review before saving.` | `Felder aus Scan vorausgefüllt. Bitte vor dem Speichern prüfen.` |
| `confidenceHigh` | `High` | `Hoch` |
| `confidenceMedium` | `Medium` | `Mittel` |
| `confidenceLow` | `Low` | `Niedrig` |

---

## Testing

### PHPUnit unit tests
- `ExtractionService` with mock `LlmClientInterface` — field parsing, confidence mapping, null fields
- `AnthropicClient` / `OpenAiClient` with mock HTTP — verify correct request shape per provider

### E2E tests (Playwright)
- Upload JPEG for existing member → extracted fields appear highlighted in form
- Upload with LLM not configured → no extraction badges, no error
- `POST /admin/mandate-document/extract` → returns expected field structure
- Create-member-from-scan flow → modal pre-filled → save → member created + document attached
- LLM extraction failure → upload still succeeds, amber notice shown
