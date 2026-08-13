# ADR-0005: IBAN Storage and Validation

**Status**: Accepted (amended by [ADR-0035](./0035-iban-encryption-sealed-box.md): IBANs are now encrypted at rest; this ADR's rejection of at-rest encryption is revoked. Validation and audit-masking decisions stand. The schema below predates the move to the `mandates` table.)

**Date**: 2025-01-23

---

## Context

Members may have bank accounts (IBANs) for payment collection purposes. Each IBAN is:

- **Unique per member**: One IBAN per member account
- **Mutable**: May change if account closes or consolidates
- **Sensitive**: Banking information requiring protection and audit trails
- **Optional**: Not all members may have IBANs (e.g., non-payment organizations)
- **Standardized**: International Bank Account Number (IBAN) follows ISO 13616 standard
- **Validated**: Checksum verification prevents data entry errors

Key considerations:

- **Storage location**: IBAN belongs in members table (not separate table)
- **BIC not needed**: Modern banking derives BIC from IBAN automatically
- **Audit trail**: All changes must be logged (who, when, old vs new, with masked values)
- **GDPR compliance**: IBAN is personal financial data; anonymization must clear it

---

## Decision

**IBAN is stored in the members table as a mutable, nullable VARCHAR(34) field. Admin UI provides a form to enter/edit member IBAN with checksum validation. All changes are audit-logged with values masked in logs. Member anonymization clears IBAN (set to NULL). BIC is not stored (banks derive it from IBAN).**

### Core Principles

1. **Single IBAN per member**: Mutable field in members table
2. **Admin editable**: Member edit form includes IBAN field with validation
3. **Checksum validated**: IBAN validation via mod-97 algorithm before save
4. **Audit logged**: All IBAN changes logged with masked old/new values (show first 2 + last 4 chars only)
5. **BIC not stored**: No BIC duplication; banks handle derivation
6. **Nullable**: Optional field; some organizations don't require IBANs
7. **Anonymization**: GDPR deletion clears IBAN (set to NULL)

### Data Structures

#### Members Table Schema

| Column | Type | Description |
|--------|------|-------------|
| id | BINARY(16) | Unique member identifier |
| iban | VARCHAR(34) | Bank account (ISO 13616 standard; nullable; optional) |
| card_uid | VARCHAR(50) | RFID/NFC identifier |
| first_name | VARCHAR(100) | Given name (NULL if anonymized) |
| last_name | VARCHAR(100) | Family name (NULL if anonymized) |
| is_active | BOOLEAN | Active member flag |
| deleted_at | DATETIME | Soft-delete timestamp (GDPR anonymization) |
| created_at | DATETIME | Record creation timestamp |
| updated_at | DATETIME | Record last update timestamp |

**Indexes**:
- `idx_members_iban`: Helps with payment export lookups

**Example IBAN formats** (all valid per ISO 13616):
- Germany: `DE89370400440532013000`
- France: `FR1420041010050500013M02606`
- Austria: `AT611904300234573201`

### IBAN Validation Algorithm

**Pseudocode: Mod-97 Checksum Validation**

```
Algorithm: ValidateIBAN(input_string)
1. Normalize: Remove spaces, convert to uppercase
2. Check length: 14 ≤ length ≤ 34
3. Verify format: First 2 chars = letters (country code), next 2 = digits (checksum)
4. Rearrange: Move first 4 characters to end
5. Convert letters to numbers: A→10, B→11, ..., Z→35
6. Compute: mod 97 of resulting numeric string
7. Valid if: remainder = 1
```

---

## Consequences

### Positive

✅ **Simple storage**: IBAN in members table; mutable, matches real-world changes
✅ **No BIC complexity**: BIC derivable from IBAN; no duplication
✅ **Admin control**: Admins can update IBANs via UI without code changes
✅ **Validation prevents errors**: Checksum validation catches typos before save
✅ **Audit trail**: All changes logged and traceable (with masked values)
✅ **GDPR compliant**: Anonymization clears IBAN (personal financial data)
✅ **Optional**: Nullable field; works for organizations without payment needs
✅ **Standardized**: IBAN validation per ISO 13616

### Negative

❌ **Sensitive data**: IBAN is financial information (requires protection)
❌ **Data security responsibility**: Organization must ensure HTTPS, access control, backups
❌ **Audit log overhead**: Every IBAN change creates audit entry
❌ **Manual entry**: Admin must type/paste IBAN correctly (validation helps)

### Mitigations

1. **HTTPS enforcement**: All IBAN transmission encrypted (TLS 1.2+)
2. **Access control**: Only authenticated admins can view/edit IBANs
3. **Audit masking**: Logs show only first 2 + last 4 chars; full IBAN never logged
4. **Validation**: Checksum validation prevents typos before save
5. **Backups**: Database backups encrypted at rest
6. **Data minimization**: Only store IBAN when needed for payments

---

## Alternatives Considered

### Alternative 1: Encrypt IBAN at Rest

Store IBAN encrypted with database-level encryption or application encryption.

**Pros**: Extra security layer; IBAN not readable in database dumps
**Cons**:
- Adds complexity (encryption/decryption on every query)
- Key management overhead (where to store encryption key?)
- Not necessary if database access controlled via HTTPS + authentication
- Typical deployments don't require field-level encryption

**Rejected**: HTTPS + access control sufficient. Additional complexity not justified.

> **Amended 2026-08-13**: This rejection is revoked by [ADR-0035](./0035-iban-encryption-sealed-box.md). IBANs are encrypted at rest with libsodium sealed boxes; the key-management objection is resolved by keeping the private key offline with the club rather than on the server.

### Alternative 2: Store IBAN in Separate Table

Create dedicated banking table (1:1 with members).

**Pros**: Separates sensitive data; structured banking info
**Cons**:
- Extra table and JOIN complexity
- No benefit over members table (IBAN is 1:1 with member)
- Complicates migrations and queries

**Rejected**: IBAN better stored directly in members table.

### Alternative 3: Validate IBAN via External Service

Call API to validate IBAN (e.g., IBAN checker service).

**Pros**: Offload validation; detect invalid accounts early
**Cons**:
- Additional latency (API call on every edit)
- Network dependency
- Cost (per-validation fees)
- Offline-first design prefers local validation

**Rejected**: Local checksum validation sufficient and offline-capable.

### Alternative 4: Store BIC Explicitly

Store BIC in members table alongside IBAN.

**Pros**: Explicit BIC field; supports legacy systems
**Cons**:
- BIC redundant (modern SEPA derives from IBAN)
- Admin must maintain two fields
- Legacy systems rare; modern SEPA handles BIC derivation

**Rejected**: BIC not needed; unnecessary duplication.

---

## Related Decisions

- [ADR-0004: Immutable Transaction Storage](./0004-immutable-transaction-storage.md) - GDPR anonymization workflow
- [ADR-0006: SEPA Mandate Reference Strategy](./0006-sepa-mandate-reference-strategy.md) - Mandate reference storage
- [ADR-0008: SEPA XML Export Format Selection](./0008-sepa-xml-export-format-selection.md) - Uses IBAN in exports

---

## References

- **IBAN Standards**:
  - [IBAN Registry - SWIFT](https://www.swift.com/standards/data-standards/iban) - Official IBAN country codes and lengths
  - [ISO 13616 IBAN Standard](https://www.iso.org/standard/61391.html) - IBAN specification
  - [IBAN Validation Algorithm](https://en.wikipedia.org/wiki/International_Bank_Account_Number#Validating_the_IBAN) - Mod-97 checksum

- **GDPR**:
  - [GDPR Art. 17 Right to Erasure](https://gdpr-info.eu/art-17-gdpr/) - Personal data deletion

---

## Consequences

### Positive

✅ **Simple storage**: IBAN in members table; mutable, matches real-world changes
✅ **No BIC complexity**: BIC derivable from IBAN; no duplication
✅ **Admin control**: Admins can update IBANs via UI without code changes
✅ **Validation prevents errors**: Checksum validation catches typos before save
✅ **Audit trail**: All changes logged and traceable
✅ **GDPR compliant**: Anonymization clears IBAN (personal financial data)
✅ **Optional**: Nullable field; works for orgs without payment needs
✅ **Standardized**: IBAN validation per ISO 13616

### Negative

❌ **Sensitive data**: IBAN is financial information (requires protection)
❌ **Data security responsibility**: Org must ensure HTTPS, access control, backups
❌ **Audit log overhead**: Every IBAN change creates audit entry
❌ **Manual entry**: Admin must type/paste IBAN correctly (validation helps)
❌ **No automated BIC lookup**: If org needs BIC, must integrate external service

### Mitigations

1. **HTTPS enforcement**: All IBAN transmission encrypted (TLS 1.2+)
2. **Access control**: Only authenticated admins can view/edit IBANs
3. **Audit masking**: Logs show only first 2 + last 4 chars; full IBAN never logged
4. **Validation**: Checksum validation prevents typos before save
5. **Backups**: Database backups encrypted at rest
6. **Data minimization**: Only store IBAN when needed for payments

---

## Alternatives Considered

### Alternative 1: Encrypt IBAN at Rest

Store IBAN encrypted with database-level encryption or application encryption.

```sql
UPDATE members SET iban = AES_ENCRYPT('DE89...', 'encryption_key');
```

**Pros**: Extra security layer; IBAN not readable in database dumps
**Cons**:
- Adds complexity (encryption/decryption on every query)
- Key management overhead (where to store encryption key?)
- Not necessary if database access controlled via HTTPS + authentication
- Typical deployments don't require field-level encryption

**Rejected**: HTTPS + access control sufficient. Additional complexity not justified.

> **Amended 2026-08-13**: This rejection is revoked by [ADR-0035](./0035-iban-encryption-sealed-box.md).

### Alternative 2: Store IBAN in Separate Table

```sql
CREATE TABLE member_banking (
  member_id BINARY(16) PRIMARY KEY,
  iban VARCHAR(34),
  bic VARCHAR(11),
  created_at DATETIME,
  updated_at DATETIME
);
```

**Pros**: Separates sensitive data; structured banking info
**Cons**:
- Extra table and JOIN complexity
- No benefit over members table (IBAN is 1:1 with member)
- Complicates migrations and queries

**Rejected**: IBAN better stored directly in members table.

### Alternative 3: Validate IBAN via External Service

Call API to validate IBAN (e.g., IBAN checker service).

**Pros**: Offload validation; detect invalid accounts early
**Cons**:
- Additional latency (API call on every edit)
- Network dependency
- Cost (per-validation fees)
- Offline-first design prefers local validation

**Rejected**: Local checksum validation sufficient and offline-capable.

### Alternative 4: BIC Storage

Store BIC explicitly in members table.

```sql
ALTER TABLE members ADD COLUMN bic VARCHAR(11);
```

**Pros**: Explicit BIC field; supports legacy systems
**Cons**:
- BIC redundant (modern SEPA derives from IBAN)
- Admin must maintain two fields
- Legacy systems rare; modern SEPA handles BIC derivation

**Rejected**: BIC not needed; unnecessary duplication.

---

## Related Decisions

- [ADR-0004: Immutable Transaction Storage](./0004-immutable-transaction-storage.md) - GDPR anonymization workflow
- [ADR-0006: SEPA Mandate Reference Strategy](./0006-sepa-mandate-reference-strategy.md) - Mandate reference storage
- [ADR-0008: SEPA XML Export Format Selection](./0008-sepa-xml-export-format-selection.md) - Uses IBAN in exports

---

## References

- **IBAN Standards**:
  - [IBAN Registry - SWIFT](https://www.swift.com/standards/data-standards/iban) - Official IBAN country codes and lengths
  - [ISO 13616 IBAN Standard](https://www.iso.org/standard/61391.html) - IBAN specification
  - [IBAN Validation Algorithm](https://en.wikipedia.org/wiki/International_Bank_Account_Number#Validating_the_IBAN) - Mod-97 checksum

- **GDPR**:
  - [GDPR Art. 17 Right to Erasure](https://gdpr-info.eu/art-17-gdpr/) - Personal data deletion

---

