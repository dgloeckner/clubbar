# Architecture Decision Records (ADRs)

This directory contains Architecture Decision Records (ADRs) for the Club Bar project.

## What is an ADR?

An ADR is a document that captures an important architectural decision made by the team, along with the context, alternatives considered, and consequences. ADRs help:
- **Document rationale**: Why a decision was made, not just what was decided
- **Preserve knowledge**: Future developers understand the "why" behind design choices
- **Facilitate discussion**: Alternatives and trade-offs are explicit
- **Enable evolution**: Decisions can be revisited and superseded when conditions change

## ADR Format

Each ADR follows this structure:

- **Status**: Proposed, Accepted, Deprecated, Superseded
- **Context**: The problem or situation that prompted the decision
- **Decision**: What was decided
- **Consequences**: Positive outcomes and trade-offs
- **Alternatives**: Other options considered and why they were rejected
- **Related Decisions**: Links to other ADRs

## ADRs

| # | Title | Status | Date |
|---|-------|--------|------|
| [0001](./0001-monetary-values-as-integer-cents.md) | Store Monetary Values as Integer Cents | Accepted | 2025-01-23 |
| [0002](./0002-product-internationalization.md) | Product Internationalization (i18n) Strategy | Accepted | 2025-01-23 |
| [0003](./0003-gzip-compression-http.md) | Enable GZIP Compression for Frontend/Backend Communication | Accepted | 2025-01-23 |
| [0004](./0004-immutable-transaction-storage.md) | Immutable Storage of Purchase Transactions | Accepted | 2025-01-23 |
| [0005](./0005-iban-storage-and-validation.md) | IBAN Storage and Validation | Accepted (amended by 0036: at-rest encryption rejection revoked) | 2025-01-23 |
| [0006](./0006-sepa-mandate-reference-strategy.md) | SEPA Mandate Reference Strategy | Accepted | 2025-01-23 |
| [0007](./0007-organization-sepa-configuration-storage.md) | Organization-Level SEPA Configuration Storage | Accepted | 2025-01-23 |
| [0008](./0008-sepa-xml-export-format-selection.md) | SEPA XML Export Format Selection (pain.008.001.02) | Accepted | 2025-01-23 |
| [0009](./0009-settlement-lead-times-bank-working-days.md) | Settlement Lead Times and Bank Working Days | Accepted | 2025-01-23 |
| [0010](./0010-mandate-lifecycle-and-retention.md) | Mandate Lifecycle and Retention | Superseded | 2025-01-23 |
| [0011](./0011-sepa-configuration-management-admin-frontend.md) | SEPA Configuration Management in Admin Frontend | Accepted | 2025-01-23 |
| [0012](./0012-eventual-consistency-frontend-caching.md) | Eventual Consistency and Frontend Caching | Accepted | 2025-01-23 |
| [0013](./0013-audit-logging.md) | Audit Logging | Accepted | 2025-01-23 |
| [0014](./0014-rfid-scanning-integration.md) | RFID Scanning Integration | Accepted | 2025-01-23 |
| [0015](./0015-authentication-and-authorization-strategy.md) | Authentication and Authorization Strategy | Accepted (amended 2026-08-09) | 2025-01-23 |
| [0016](./0016-transport-security.md) | Transport Security (HTTPS and TLS) | Accepted | 2025-01-23 |
| [0017](./0017-input-validation-injection-prevention.md) | Input Validation and Injection Prevention | Accepted | 2025-01-23 |
| [0018](./0018-modular-admin-interface-architecture.md) | Modular Admin Interface Architecture | Accepted | 2025-01-23 |
| [0019](./0019-frontend-access-token-configuration.md) | Frontend Access Token Configuration | Accepted | 2025-01-23 |
| [0020](./0020-sepa-mandate-requirement-terminal-access.md) | SEPA Mandate Requirement for Terminal Access | Accepted | 2025-01-23 |
| [0021](./0021-rfid-card-assignment-workflow.md) | RFID Card Assignment Workflow | Accepted | 2025-01-23 |
| [0022](./0022-test-strategy-and-automation.md) | Test Strategy and Automation | Accepted | 2025-01-23 |
| [0023](./0023-terminal-balance-state-management.md) | Terminal Balance State Management | Accepted | 2025-01-25 |
| [0024](./0024-transaction-history-retrieval-terminal.md) | Transaction History Retrieval in Terminal | Accepted | 2025-01-25 |
| [0025](./0025-session-fixation-protection.md) | Session Fixation Protection on Login | Accepted | 2026-03-17 |
| [0026](./0026-mandatory-totp-two-factor-authentication.md) | Mandatory TOTP Two-Factor Authentication for Admin Panel | Accepted | 2026-03-22 |
| [0027](./0027-terminal-session-lifecycle.md) | Terminal Session Lifecycle and Cart Ownership | Accepted | 2026-08-05 |
| [0028](./0028-legal-constraints-on-money-handling.md) | Legal and Regulatory Constraints on Money Handling | Accepted | 2026-08-07 |
| [0029](./0029-two-tier-retention-and-erasure.md) | Two-Tier Retention and Erasure | Accepted (amended by 0037: mandate document dropped from the retention tier; by 0038: `mail_outbox.recipient` joins the operational tier; by 0039: outbox retention varies by message kind) | 2026-08-07 |
| [0030](./0030-settlement-selection-is-a-member-picker.md) | Settlement Selection Is a Member Picker | Accepted | 2026-08-08 |
| [0031](./0031-production-hardening-on-shared-hosting.md) | Production Hardening on Shared Hosting | Accepted (amended by 0038: mail/scheduler layers, first hard host dependency; by 0039: the scheduler's interval is a declared, verified host fact and weekly-only hosts are refused) | 2026-08-09 |
| [0032](./0032-settlement-lifecycle.md) | Settlement Lifecycle | Accepted (amended by 0038: announcement enqueue is part of the create transaction) | 2026-08-09 |
| [0033](./0033-terminal-sync-contract.md) | Terminal Sync Contract | Accepted | 2026-08-09 |
| [0034](./0034-instance-branding-configuration.md) | Instance Branding Configuration | Accepted | 2026-08-11 |
| [0035](./0035-terminal-backend-instance-pairing.md) | Terminal-Backend Instance Pairing | Accepted | 2026-08-12 |
| [0036](./0036-iban-encryption-sealed-box.md) | IBAN Encryption at Rest with libsodium Sealed Boxes | Accepted | 2026-08-13 |
| [0037](./0037-mandate-documents-not-retained.md) | Mandate Documents Are Not Retained in the System | Accepted (amended by 0040: stateless extraction endpoint removed; document non-retention unaffected) | 2026-08-13 |
| [0038](./0038-transactional-mail-outbox-on-shared-hosting.md) | Transactional Mail Outbox on Shared Hosting | Accepted (amended by 0039: time-triggered enqueue, rule 5's justification, the stall threshold, per-kind retention) | 2026-08-14 |
| [0039](./0039-periodic-deckel-statement.md) | The Periodic Deckelauszug | Accepted | 2026-08-15 |
| [0040](./0040-remove-mandate-scan-extraction.md) | Remove Mandate Scan Extraction | Accepted | 2026-08-15 |
| [0041](./0041-terminal-credential-anomaly-detection.md) | Terminal Credential Anomaly Detection | Accepted | 2026-08-15 |
| [0042](./0042-colour-semantics-for-monetary-values.md) | Colour Semantics for Monetary Values | Accepted | 2026-08-16 |
| [0043](./0043-terminal-credential-issuance-is-announced.md) | Terminal Credential Issuance Is Announced, Not Gated Further | Accepted | 2026-08-16 |
| [0044](./0044-tiered-admin-roles.md) | Tiered Admin Roles — `admin`, `Kassenwart`, `Getränkewart` | Accepted | 2026-08-17 |
| [0045](./0045-age-restricted-products.md) | Age-Restricted Products — the Terminal Refuses, the Server Records | Accepted | 2026-08-20 |

## Creating a New ADR

1. **Check existing decisions**: Browse this directory to see if your concern is already addressed
2. **Copy template**: Use the format from [0001](./0001-monetary-values-as-integer-cents.md)
3. **Assign next number**: Find the highest ADR number and increment by 1
4. **Discuss**: Share the ADR draft with the team for feedback
5. **Finalize**: Update status to "Accepted" and merge to main branch
6. **Update this README**: Add the new ADR to the table above

## Naming Convention

File naming: `NNNN-short-title.md`
- `NNNN`: 4-digit number (0001, 0002, etc.)
- `short-title`: Hyphen-separated, lowercase (e.g., `monetary-values-as-integer-cents`)

## Status Definitions

- **Proposed**: Suggested but not yet approved; awaiting team discussion
- **Accepted**: Approved and adopted; implementation should follow this decision
- **Deprecated**: Previously accepted but no longer recommended; will be superseded
- **Superseded**: Replaced by a newer ADR; reference the new ADR

## Guidelines

- Keep ADRs concise but complete (aim for 2-5 pages)
- Use code examples to illustrate implementation
- Include real consequences, not just rosy predictions
- Be honest about trade-offs; every decision has downsides
- Link to related ADRs and external references
- Use plain language; avoid jargon

## References

- [Lightweight Architecture Decision Records](https://adr.github.io/) - Original ADR format
- [Building Microservices](https://samnewman.io/books/building_microservices_2nd_edition/) - Sam Newman on ADRs
- [Architectural Decision Records](https://cognitect.com/blog/2011/11/15/documenting-architecture-decisions.html) - Cognitect article
