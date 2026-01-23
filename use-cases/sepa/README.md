# SEPA Use Cases

This directory contains SEPA Direct Debit-specific use cases for the Member Bar system. Each use case is documented in a separate file for clarity and traceability.

## Use Case Index

| ID | Category | Title | File |
|----|----------|-------|------|
| UC-SEPA-01 | Configuration | SEPA Configuration Setup | [uc-sepa-01-config-setup.md](./uc-sepa-01-config-setup.md) |
| UC-SEPA-02 | Configuration | SEPA Configuration Update | [uc-sepa-02-config-update.md](./uc-sepa-02-config-update.md) |
| UC-SEPA-03 | Member Data | Member IBAN Management | [uc-sepa-03-member-iban.md](./uc-sepa-03-member-iban.md) |
| UC-SEPA-04 | Member Data | Member Mandate Reference | [uc-sepa-04-mandate-reference.md](./uc-sepa-04-mandate-reference.md) |
| UC-SEPA-05 | Settlement | Settlement Creation | [uc-sepa-05-settlement-create.md](./uc-sepa-05-settlement-create.md) |
| UC-SEPA-06 | Settlement | Settlement Preview | [uc-sepa-06-settlement-preview.md](./uc-sepa-06-settlement-preview.md) |
| UC-SEPA-07 | Settlement | Settlement Finalization | [uc-sepa-07-settlement-finalize.md](./uc-sepa-07-settlement-finalize.md) |
| UC-SEPA-08 | Export | SEPA XML Export | [uc-sepa-08-xml-export.md](./uc-sepa-08-xml-export.md) |
| UC-SEPA-09 | Export | CSV Export | [uc-sepa-09-csv-export.md](./uc-sepa-09-csv-export.md) |

## SEPA Prerequisites (Outside System)

| Requirement | Description | Where to Obtain |
|-------------|-------------|-----------------|
| Gläubiger-ID | Creditor identifier for SEPA direct debits | https://www.glaeubiger-id.bundesbank.de |
| Bank Account | SEPA-enabled business account with collection rights | Organization's bank |
| Member Mandates | Signed SEPA mandate forms from each member | Collected by organization |

## Key Rules

| Rule | Value | Reference |
|------|-------|-----------|
| Minimum lead time | TODAY + 7 calendar days | ADR-0009 |
| Sequence type | Always RCUR (recurring) | ADR-0008 |
| XML format | pain.008.001.02 | ADR-0008 |
| BIC handling | Not stored; derived from IBAN by bank | ADR-0005 |
| IBAN validation | ISO 13616 mod-97 checksum | ADR-0005 |
| Mandate reference | UUID without hyphens (default) | ADR-0006 |

## Data Flow

```
Member Signs Mandate → Admin Enters IBAN/Reference → Settlement Created → XML Exported → Bank Upload → Debit Executed
        ↓                        ↓                         ↓                  ↓              ↓
   (outside system)         (UC-SEPA-03/04)           (UC-SEPA-05-07)    (UC-SEPA-08)   (outside system)
```

## Retention Requirements

| Data | Retention | Legal Basis |
|------|-----------|-------------|
| Settlement records | 10 years | § 147 AO |
| SEPA XML files | 10 years | § 147 AO |
| Transaction history | 10 years | § 147 AO |
| Original mandates | 14 months after last use | PSD2 (outside system) |

## Related Documentation

- [ADR-0005](../../adr/0005-iban-storage-and-validation.md) - IBAN Storage and Validation
- [ADR-0006](../../adr/0006-sepa-mandate-reference-strategy.md) - SEPA Mandate Reference Strategy
- [ADR-0007](../../adr/0007-organization-sepa-configuration-storage.md) - Organization SEPA Configuration
- [ADR-0008](../../adr/0008-sepa-xml-export-format-selection.md) - SEPA XML Export Format
- [ADR-0009](../../adr/0009-settlement-lead-times-bank-working-days.md) - Settlement Lead Times
- [ADR-0011](../../adr/0011-sepa-configuration-management-admin-frontend.md) - SEPA Config Admin UI
- [CLAUDE.md](../../CLAUDE.md) - SEPA Settlement Workflow section
