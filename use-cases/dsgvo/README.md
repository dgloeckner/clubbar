# DSGVO (GDPR) Use Cases

This directory contains GDPR-specific use cases for the Member Bar system. Each use case is documented in a separate file for clarity and traceability.

## Use Case Index

| ID | Article | Title | File |
|----|---------|-------|------|
| UC-DSGVO-01 | Art. 15 | Right to Access (Auskunftsrecht) | [uc-dsgvo-01-right-to-access.md](./uc-dsgvo-01-right-to-access.md) |
| UC-DSGVO-02 | Art. 17 | Right to Erasure (Löschungsrecht) | [uc-dsgvo-02-right-to-erasure.md](./uc-dsgvo-02-right-to-erasure.md) |
| UC-DSGVO-03 | Art. 16 | Right to Rectification (Berichtigungsrecht) | [uc-dsgvo-03-right-to-rectification.md](./uc-dsgvo-03-right-to-rectification.md) |
| UC-DSGVO-04 | Art. 20 | Right to Data Portability (Datenportabilität) | [uc-dsgvo-04-right-to-portability.md](./uc-dsgvo-04-right-to-portability.md) |
| UC-DSGVO-05 | Art. 18 | Right to Restriction (Einschränkung) | [uc-dsgvo-05-right-to-restriction.md](./uc-dsgvo-05-right-to-restriction.md) |
| UC-DSGVO-06 | Art. 30 | Audit Log Access (Verarbeitungsverzeichnis) | [uc-dsgvo-06-audit-log-access.md](./uc-dsgvo-06-audit-log-access.md) |

## Legal Basis

| Processing Activity | Legal Basis | GDPR Article |
|---------------------|-------------|--------------|
| Member master data | Contract fulfillment | Art. 6(1)(b) |
| Transaction records | Contract fulfillment | Art. 6(1)(b) |
| IBAN/BIC for SEPA | Contract fulfillment | Art. 6(1)(b) |
| Audit logging | Legitimate interest | Art. 6(1)(f) |
| 10-year retention | Legal obligation (§ 147 AO) | Art. 6(1)(c) |

## Retention Periods

| Data Category | Retention | Legal Basis |
|---------------|-----------|-------------|
| Transactions | 10 years from year-end | § 147 AO, § 257 HGB |
| Settlements | 10 years from year-end | § 147 AO, § 257 HGB |
| Audit log | 10 years | § 147 AO, Art. 30 |
| Anonymized member records | Until last transaction + 10 years | Referential integrity |
| Admin sessions | 24 hours | No retention requirement |

## Related Documentation

- [ADR-0004](../../adr/0004-immutable-transaction-storage.md) - Immutable transactions
- [ADR-0005](../../adr/0005-iban-storage-and-validation.md) - IBAN handling
- [ADR-0013](../../adr/0013-audit-logging.md) - Audit logging