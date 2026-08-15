# ADR-0040: Remove Mandate Scan Extraction

**Status**: Accepted

**Date**: 2026-08-15

**Amends**: [ADR-0037](./0037-mandate-documents-not-retained.md) (removes the stateless extraction endpoint that ADR kept; the rest of that ADR's decision — no document retention, treasurer's paper archive as the Beleg — is unaffected)

---

## Context

Since early 2026 the admin panel offered a "New from scan" flow (`POST /admin/mandate-document/extract`): an admin photographed or scanned a signed SEPA mandate, and an LLM — Anthropic or OpenAI, optionally preceded by Google Cloud Vision OCR — read the member's name, IBAN, address and mandate date back as pre-filled form fields with per-field confidence badges, an IBAN-candidate picker for ambiguous MOD-97 repairs, and a review banner asking the admin to check the result before saving. [ADR-0037](./0037-mandate-documents-not-retained.md) already stopped the system from retaining the scanned image itself, but kept this stateless extraction call.

Two problems outweigh the convenience:

- **Datenschutz.** Every scan sends a photograph carrying a member's full name, IBAN and handwritten signature to a third-party API (Anthropic, OpenAI, or Google Cloud Vision) for processing. [ADR-0036](./0036-iban-encryption-sealed-box.md) encrypts the IBAN at rest specifically so the server itself stores only the public key and can encrypt but never decrypt — plaintext exists server-side only at submit time, by design. The extraction flow added a path where the same plaintext left the system entirely, to an external processor, before it ever reached that boundary — a materially larger exposure than anything the sealed-box design accepts.
- **Error rate.** The confidence badges, the IBAN-candidate picker and the discard-and-revert path exist because the extraction is unreliable enough to need them. That puts the entire safety of the feature on the admin re-checking every field as carefully as if they had typed it themselves — at which point scanning saves little, and a wrong IBAN that slips through past a rushed review is not a cosmetic bug but a failed SEPA collection.

## Decision

**The mandate-scan extraction feature is removed entirely, including its LLM and Vision API integration. Admins enter member data — including IBAN — by hand during onboarding, the same as any other field.**

| Aspect | Before | After |
|---|---|---|
| `POST /admin/mandate-document/extract` | Stateless extraction endpoint (JPEG/PNG/PDF in, fields out) | Removed |
| "New from scan" button, `MandateDocumentSection`, confidence badges, IBAN-candidate picker, extraction-review banner | Admin panel (Members) | Removed |
| `LlmClients` (Anthropic, OpenAI), `VisionClients` (Google Vision), `IbanRepair`, `OcrPreprocessor`, `ImageOrientationFixer`, `ExtractionService`/`DirectExtractionService` | Backend `Modules/Members` | Removed |
| `LLM_PROVIDER`, `LLM_API_KEY`, `LLM_MODEL`, `LLM_THINKING_BUDGET`, `GCLOUD_VISION_API` | Env vars, `config.php` `llm`/`vision` sections, installer wizard fields | Removed |
| Member onboarding | Scan pre-fill with mandatory review, or manual entry | Manual entry only |

Mandate *document* retention is untouched: [ADR-0037](./0037-mandate-documents-not-retained.md)'s decision — no document storage, the treasurer's archived paper original is the Beleg — stands. This ADR only removes the extraction call that used to read a scan before discarding it.

## Consequences

### Positive

- ✅ No member PII (name, IBAN, signature) leaves the system to a third-party LLM or Vision API at any point in the member lifecycle.
- ✅ Removes the exact failure mode the confidence-badge UI existed to mitigate: a field trusted because it "came from the scan" and checked less carefully than free-typed input.
- ✅ One fewer external dependency and failure mode (API keys, provider outages, rate limits, thinking-budget tuning) for a small club to operate and pay for.
- ✅ Simpler onboarding code path — no OCR pipeline, no MOD-97 lookalike-character repair, no ambiguous-candidate UI to maintain or test.

### Negative

- ❌ Onboarding many members by hand is slower than scanning — this trades that convenience against both the Datenschutz exposure and the admin's own attention budget for reviewing scan output.
- ❌ Deployments with `LLM_API_KEY` / `GCLOUD_VISION_API` set must remove them; left in place after upgrading they are simply ignored (the routes and code reading them no longer exist), so no forced migration step is required.

## Related Decisions

- [ADR-0037](./0037-mandate-documents-not-retained.md) — amended: the stateless extraction endpoint it kept alive is now removed; document non-retention is unaffected
- [ADR-0036](./0036-iban-encryption-sealed-box.md) — the encryption-at-rest boundary this feature's external API call bypassed at the point of entry
