# ADR-0037: Mandate Documents Are Not Retained in the System

**Status**: Accepted

**Date**: 2026-08-13

**Amends**: [ADR-0029](./0029-two-tier-retention-and-erasure.md) (removes "the mandate document" from the system's retention tier)

---

## Context

Scanned SEPA mandates — each one a member's name, full IBAN and handwritten signature — are stored as PDFs under `storage/mandates/{memberId}.pdf` (0700 directory, `mandate_documents` metadata table). They exist to (a) feed the IBAN/name extraction when creating a member from a scan and (b) serve as the retained Beleg per ADR-0029's retention tier.

With ADR-0036 the database copy of every IBAN becomes ciphertext the server cannot read — while the scan directory would keep a plaintext copy of exactly the same data, plus a signature, protected only by file modes. Encrypting the scans too would drag the whole document pipeline (upload, dompdf conversion, streaming download, LLM extraction) into the key flow.

The club decided the simpler, older answer instead: the **signed paper original, archived by the treasurer outside the system, is the Beleg**. Superseded ADR-0010 already reached the same conclusion for mandate compliance in general ("document retention is performed outside the system"); #164 later pulled a *minimal* mandate record (reference, IBAN, signature date) back in — but never the document itself as a load-bearing artifact. The stateless extraction endpoint (`POST /admin/mandate-document/extract`) already exists and stores nothing.

## Decision

**The system does not retain mandate documents. Extraction from a scan remains available but is stateless: bytes in, extracted fields out, nothing written to disk or database. The treasurer archives the signed paper original outside the system; retention obligations attach to that original.**

| Aspect | Before | After |
|---|---|---|
| Upload & store per member | `POST /members/{id}/mandate-document` | removed |
| Download / delete stored scan | `GET`/`DELETE /members/{id}/mandate-document` | removed |
| Extraction for form pre-fill | on upload, and stateless endpoint (JPEG/PNG) | stateless endpoint only, now also accepting PDF |
| `mandate_documents` table, `mandates.document_id` | populated / schema-only | dropped |
| Existing files in `storage/mandates/` | kept indefinitely | deleted by a one-time migration step |
| Beleg (§ 147 AO / EPC rulebook proof) | scan in system + paper | paper original in the club archive |

The deletion migration is **destructive and announced**: upgrade notes instruct the club to download and archive any stored scans before upgrading; a `SecuritySelfCheck` finding flags leftover files afterwards. Erasure semantics simplify: anonymization no longer has a document to remove; the ADR-0029 restriction rules continue to apply to the mandate *record* (reference, ciphertext, signature date).

## Consequences

### Positive

- ✅ The highest-value single file class on the host (IBAN + signature per member) ceases to exist server-side — no encryption pipeline needed for it.
- ✅ Erasure and anonymization flows lose a moving part (no post-commit file unlink coordination).
- ✅ Consistent with the club's actual practice: the paper mandate is what the bank would ever ask for.
- ✅ Extraction (the genuinely useful part) stays, and gains PDF support.

### Negative

- ❌ Retention duty shifts entirely to an organizational procedure — the system can no longer prove mandate possession; the treasurer's archive must be reliable (aligned with the annual review in the retention process).
- ❌ Upgrading destroys stored scans; a club that skipped the pre-upgrade download loses them (mitigation: explicit upgrade warning, self-check finding, and the paper originals still exist).
- ❌ Re-extraction of a past mandate requires re-scanning the paper original.

## Related Decisions

- [ADR-0010](./0010-mandate-lifecycle-and-retention.md) — superseded ADR that already placed document retention outside the system
- [ADR-0029](./0029-two-tier-retention-and-erasure.md) — amended: mandate *record* stays in the retention tier, the document leaves it
- [ADR-0036](./0036-iban-encryption-sealed-box.md) — companion decision for the database copy of IBANs
- #360 — the blank mandate template is likewise moving out of the app
