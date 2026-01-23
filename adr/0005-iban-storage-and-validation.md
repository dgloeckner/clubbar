# ADR-0005: IBAN Storage and Validation

**Status**: Accepted

**Date**: 2025-01-23

**Deciders**: Architecture Team

---

## Context

Members may have bank accounts (IBANs) for payment collection purposes. Each IBAN is:

- **Unique per member**: One IBAN per member account
- **Mutable**: May change if account closes or consolidates
- **Sensitive**: Banking information requiring protection and audit trails
- **Optional**: Not all members may have IBANs (e.g., non-payment orgs)
- **Standardized**: International Bank Account Number (IBAN) follows ISO 13616 standard
- **Validated**: Checksum verification prevents data entry errors

Key considerations:

- **Storage location**: IBAN belongs in members table (not separate table)
- **BIC not needed**: Modern banking derives BIC from IBAN automatically
- **Audit trail**: All changes must be logged (who, when, old vs new)
- **GDPR compliance**: IBAN is personal financial data; anonymization must clear it

---

## Decision

**IBAN is stored in the members table as a mutable, nullable VARCHAR(34) field. Admin UI provides a form to enter/edit member IBAN with checksum validation. All changes are audit-logged with values masked in logs. Member anonymization clears IBAN (set to NULL). BIC is not stored (banks derive it from IBAN).**

### Core Principles

1. **Single IBAN per member**: Mutable field in members table
2. **Admin editable**: Member edit form includes IBAN field with validation UI
3. **Checksum validated**: IBAN validation via mod-97 algorithm before save
4. **Audit logged**: All IBAN changes logged with masked old/new values
5. **BIC not stored**: No BIC duplication; banks handle derivation
6. **Nullable**: Optional field; some orgs don't need IBANs
7. **Anonymization**: GDPR deletion clears IBAN (set to NULL)

### Database Schema

#### Members Table - IBAN Column

```sql
ALTER TABLE members ADD COLUMN iban VARCHAR(34) NULLABLE DEFAULT NULL;

-- Index for lookups (helps with payment exports)
CREATE INDEX idx_members_iban ON members(iban);

-- Example: Add IBAN for a member
UPDATE members SET iban = 'DE89370400440532013000'
WHERE id = '550e8400-e29b-41d4-a716-446655440000';
```

**IBAN Field Details:**
- **Type**: VARCHAR(34) - IBAN standard max length
- **Nullable**: Optional; set to NULL if member has no IBAN
- **Format**: 2-letter country code + 2-digit checksum + alphanumeric account identifier
- **Examples**:
  - Germany: `DE89370400440532013000`
  - France: `FR1420041010050500013M02606`
  - Austria: `AT611904300234573201`

#### Members Table Schema (Full Example)

```sql
CREATE TABLE members (
  id BINARY(16) PRIMARY KEY,
  card_uid VARCHAR(50) NOT NULL UNIQUE,
  first_name VARCHAR(100),                    -- NULL if anonymized
  last_name VARCHAR(100),                     -- NULL if anonymized
  preferred_language VARCHAR(10) DEFAULT 'en', -- ISO 639-1 code
  iban VARCHAR(34),                           -- Bank account (nullable)
  mandate_reference VARCHAR(35),              -- SEPA mandate reference
  is_active BOOLEAN DEFAULT TRUE,
  deleted_at DATETIME,                        -- Soft-delete flag
  created_at DATETIME NOT NULL DEFAULT NOW(),
  updated_at DATETIME NOT NULL DEFAULT NOW() ON UPDATE NOW(),

  INDEX idx_members_card_uid (card_uid),
  INDEX idx_members_created_at (created_at),
  INDEX idx_members_iban (iban)
) ENGINE=InnoDB;
```

### Admin UI Implementation

#### Member Edit Form - IBAN Field

```jsx
// Admin panel: Member edit form
<Form onSubmit={handleSave}>
  {/* Existing fields */}
  <TextInput
    label="First Name"
    {...register('first_name')}
  />
  <TextInput
    label="Last Name"
    {...register('last_name')}
  />

  {/* IBAN Field (NEW) */}
  <TextInput
    label="Bank Account (IBAN)"
    placeholder="DE89 3704 0044 0532 0130 00"
    description="International Bank Account Number. Used for bank transfers and payment exports. Optional."
    {...register('iban', {
      validate: {
        ibanFormat: (value) => {
          if (!value) return true;  // Optional field
          return isValidIBAN(value) || 'Invalid IBAN (bad checksum or format)';
        }
      }
    })}
  />

  <Button type="submit">Save Member</Button>
</Form>
```

### IBAN Validation

#### Implementation

```javascript
/**
 * Validate IBAN using mod-97 checksum algorithm
 * Per IBAN standard: move first 4 chars to end, replace letters with numbers,
 * compute mod 97. Result must be 1.
 */
function isValidIBAN(iban) {
  // Remove spaces and normalize to uppercase
  const normalized = iban.toUpperCase().replace(/\s+/g, '');

  // Check length (14-34 characters per IBAN standard)
  if (normalized.length < 14 || normalized.length > 34) {
    return false;
  }

  // Verify country code + checksum (first 4 chars)
  // Country code: 2 letters
  // Check digits: 2 digits
  if (!/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/.test(normalized)) {
    return false;
  }

  // Move first 4 characters to end: CCCCXXX... → XXXCCCC...
  const rearranged = normalized.slice(4) + normalized.slice(0, 4);

  // Replace letters with numbers (A=10, B=11, ..., Z=35)
  const numeric = rearranged.replace(/[A-Z]/g, (char) => {
    return (char.charCodeAt(0) - 55).toString();
  });

  // Compute mod 97; valid IBAN has remainder of 1
  let checksum = 0;
  for (let i = 0; i < numeric.length; i++) {
    checksum = (checksum * 10 + parseInt(numeric[i])) % 97;
  }

  return checksum === 1;
}

// Test examples
console.log(isValidIBAN('DE89370400440532013000'));  // true
console.log(isValidIBAN('DE89370400440532013001'));  // false (bad checksum)
console.log(isValidIBAN('FR1420041010050500013M02606')); // true
console.log(isValidIBAN('INVALID'));                  // false
```

### Audit Logging

All IBAN changes are logged in the audit_log table with masked values:

```php
<?php
// When admin updates member IBAN
$oldMember = $db->selectOne('members', '*', ['id' => $memberId]);
$oldIban = $oldMember['iban'];
$newIban = $request->input('iban');

// Perform update
$db->update('members', ['iban' => $newIban], ['id' => $memberId]);

// Log the change (mask IBAN in log: show first 2 + last 4 chars)
$auditEntry = [
    'admin_user_id' => $adminId,
    'action' => 'update',
    'entity_type' => 'member',
    'entity_id' => $memberId,
    'changes_json' => json_encode([
        'iban' => [
            'old' => maskIban($oldIban),        // e.g., "DE...3000"
            'new' => maskIban($newIban)
        ]
    ]),
    'ip_address' => $_SERVER['REMOTE_ADDR'],
    'user_agent' => $_SERVER['HTTP_USER_AGENT'],
    'created_at' => date('c')
];

$db->insert('audit_log', $auditEntry);

function maskIban($iban) {
    if (!$iban) return null;
    // Show first 2 chars (country) and last 4 chars; mask middle
    return substr($iban, 0, 2) . '...' . substr($iban, -4);
}
```

### GDPR Anonymization

When a member is anonymized (deleted per [ADR-0004](./0004-immutable-transaction-storage.md)):

```php
<?php
// Anonymize member: clear IBAN and personal data
$db->update('members', [
    'first_name' => null,
    'last_name' => null,
    'iban' => null,                    // Clear banking info
    'card_uid' => 'ANONYMOUS-' . $memberId,
    'is_active' => false,
    'deleted_at' => date('c')
], ['id' => $memberId]);

// Audit log (with masked values)
$db->insert('audit_log', [
    'admin_user_id' => $adminId,
    'action' => 'anonymize',
    'entity_type' => 'member',
    'entity_id' => $memberId,
    'changes_json' => json_encode([
        'first_name' => ['old' => '***', 'new' => null],
        'last_name' => ['old' => '***', 'new' => null],
        'iban' => ['old' => '***', 'new' => null],
        'deleted_at' => ['old' => null, 'new' => date('c')]
    ]),
    'ip_address' => $_SERVER['REMOTE_ADDR'],
    'created_at' => date('c')
]);
```

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
2. **Access control**: Only admins can view/edit IBANs (role-based)
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

## Implementation Checklist

### Database

- [ ] Add `iban VARCHAR(34)` column to members table (nullable, default NULL)
- [ ] Create index: `CREATE INDEX idx_members_iban ON members(iban)`
- [ ] No migration data needed (all IBANs initially NULL)
- [ ] Verify NOT NULL constraints allow NULL (should be nullable)

### Backend API

- [ ] `PATCH /api/members/{id}`: Accept IBAN in request body
- [ ] IBAN validation: Checksum verification on save (reject 400 Bad Request if invalid)
- [ ] Audit logging: Record IBAN changes (old/new, masked in logs)
- [ ] GET /api/members/{id}: Return IBAN in response
- [ ] Error handling: Return clear error message for invalid IBAN

### Admin UI

- [ ] Add IBAN field to member edit form
- [ ] Real-time validation: Checksum check with error message
- [ ] Placeholder: Show formatted example (e.g., `DE89 3704 0044 0532 0130 00`)
- [ ] Help text: "Optional. Used for payment exports."
- [ ] Optional: Copy/format button (auto-remove spaces, uppercase)

### Security & Compliance

- [ ] HTTPS enforcement: All IBAN transmission encrypted
- [ ] Access control: Only admin role can view/edit IBAN
- [ ] Audit masking: Logs show `XX...XXXX` (first 2 + last 4 chars)
- [ ] GDPR anonymization: Clears IBAN on member deletion
- [ ] Backup encryption: Database backups encrypted at rest

### Testing

- [ ] Valid IBAN: `DE89370400440532013000` (test multiple countries)
- [ ] Invalid checksum: `DE89370400440532013001` (should reject)
- [ ] Invalid format: `INVALID`, `ABC123` (should reject)
- [ ] Empty/null: Optional field (should allow)
- [ ] Length: Min 14, max 34 (validate boundaries)
- [ ] Spaces: Handle removal gracefully (`DE89 3704 0044...` → valid)
- [ ] Case insensitivity: Accept lowercase and uppercase
- [ ] Audit log: IBAN change creates entry with masked values
- [ ] Anonymization: Clears IBAN when member deleted
- [ ] Admin API: PATCH request updates IBAN, returns in response

### Documentation

- [ ] Update CLAUDE.md: Add IBAN to member data description
- [ ] Admin API spec: PATCH /api/members/{id} with IBAN field
- [ ] Admin guide: How to enter/edit member IBAN
- [ ] Deployment guide: Note IBAN is sensitive; ensure HTTPS, access control
- [ ] Cross-reference: Link to ADR-0004, ADR-0006

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

## Approval

- **Decided by**: Architecture Team
- **Rationale**: IBAN storage in members table is simple, mutable, validated, and audited; meets payment and GDPR requirements
- **Implementation start**: Phase 2 (after settlement core)
- **Review date**: 2025-04-23 (after first payment export)
- **Sign-off**:
  - Backend Lead: _________________ Date: _______
  - Security/Compliance Officer: _________________ Date: _______
  - Admin UI Lead: _________________ Date: _______

---

## Post-Implementation Monitoring

- [ ] Track IBAN completion rate (% members with IBAN entered)
- [ ] Monitor validation error rate (which IBANs rejected?)
- [ ] Verify audit log entries for IBAN changes (test & production)
- [ ] Confirm no IBANs logged in unmasked form (security audit)
- [ ] User feedback: Is IBAN entry workflow clear?
- [ ] Performance: Any slowdown from index on iban column?
