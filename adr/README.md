# Architecture Decision Records (ADRs)

This directory contains Architecture Decision Records (ADRs) for the Member Bar project.

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
| [0005](./0005-iban-storage-and-validation.md) | IBAN Storage and Validation | Accepted | 2025-01-23 |
| [0006](./0006-sepa-mandate-reference-strategy.md) | SEPA Mandate Reference Strategy | Accepted | 2025-01-23 |
| [0007](./0007-organization-sepa-configuration-storage.md) | Organization-Level SEPA Configuration Storage | Accepted | 2025-01-23 |
| [0008](./0008-sepa-xml-export-format-selection.md) | SEPA XML Export Format Selection (pain.008.001.02) | Accepted | 2025-01-23 |
| [0009](./0009-settlement-lead-times-bank-working-days.md) | Settlement Lead Times and Bank Working Days | Accepted | 2025-01-23 |
| [0010](./0010-mandate-lifecycle-and-retention.md) | Mandate Lifecycle and Retention | Accepted | 2025-01-23 |
| [0011](./0011-sepa-configuration-management-admin-frontend.md) | SEPA Configuration Management in Admin Frontend | Accepted | 2025-01-23 |

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
