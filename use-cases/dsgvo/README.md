# DSGVO (GDPR) Use Cases

This directory contains GDPR-specific use cases for the Club Bar system. Each use case is documented in a separate file for clarity and traceability.

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
| Member master data | Contract fulfillment | [Art. 6(1)(b)](https://gdpr-info.eu/art-6-gdpr/) |
| Transaction records | Contract fulfillment | [Art. 6(1)(b)](https://gdpr-info.eu/art-6-gdpr/) |
| IBAN for SEPA | Contract fulfillment | [Art. 6(1)(b)](https://gdpr-info.eu/art-6-gdpr/) |
| Audit logging | Legitimate interest | [Art. 6(1)(f)](https://gdpr-info.eu/art-6-gdpr/) |
| 10-year retention | Legal obligation (§ 147 AO) | [Art. 6(1)(c)](https://gdpr-info.eu/art-6-gdpr/) |

## Retention Periods

| Data Category | Retention | Legal Basis |
|---------------|-----------|-------------|
| Transactions | 8 years from year-end | [§ 147(3) AO](https://www.gesetze-im-internet.de/ao_1977/__147.html) (Buchungsbelege) |
| Settlements | 10 years from year-end | [§ 147(3) AO](https://www.gesetze-im-internet.de/ao_1977/__147.html) (Jahresabschlüsse) |
| Audit log | 10 years | [§ 147 AO](https://www.gesetze-im-internet.de/ao_1977/__147.html), [Art. 30 GDPR](https://gdpr-info.eu/art-30-gdpr/) |
| Anonymized member records | Until last transaction + 8 years | Referential integrity |
| Admin sessions | 24 hours | No retention requirement |

## Key GDPR Articles Referenced

| Article | Name | Relevance |
|---------|------|-----------|
| [Art. 4(5)](https://gdpr-info.eu/art-4-gdpr/) | Definition of pseudonymization | Pseudonymized data is still personal data; only truly anonymous data falls outside GDPR |
| [Art. 5(1)(e)](https://gdpr-info.eu/art-5-gdpr/) | Storage limitation | Identifiable data kept no longer than necessary |
| [Art. 5(2)](https://gdpr-info.eu/art-5-gdpr/) | Accountability | Controller must demonstrate compliance |
| [Art. 17(1)](https://gdpr-info.eu/art-17-gdpr/) | Right to erasure | Erase personal data on request or when purpose lapses |
| [Art. 17(3)(b)](https://gdpr-info.eu/art-17-gdpr/) | Legal obligation exception | Retention permitted where law mandates it |
| [Art. 30](https://gdpr-info.eu/art-30-gdpr/) | Records of processing | Document all processing purposes and retention |
| [Recital 26](https://gdpr-info.eu/recitals/no-26/) | Scope exclusion | Truly anonymous data is outside GDPR scope |

## German Law References

| Law | Section | Relevance | URL |
|-----|---------|-----------|-----|
| Abgabenordnung (AO) | [§ 147](https://www.gesetze-im-internet.de/ao_1977/__147.html) | Retention of business records: 8 years for booking vouchers, 10 years for annual statements | [gesetze-im-internet.de](https://www.gesetze-im-internet.de/ao_1977/__147.html) |
| Handelsgesetzbuch (HGB) | [§ 257](https://www.gesetze-im-internet.de/hgb/__257.html) | Commercial retention obligations (same periods) | [gesetze-im-internet.de](https://www.gesetze-im-internet.de/hgb/__257.html) |

## Related Documentation

- [ADR-0004](../../adr/0004-immutable-transaction-storage.md) - Immutable transactions (retained per Art. 17(3)(b))
- [ADR-0005](../../adr/0005-iban-storage-and-validation.md) - IBAN handling
- [ADR-0013](../../adr/0013-audit-logging.md) - Audit logging (includes scrubbing during anonymization)
