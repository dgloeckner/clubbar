# ADR-0006: SEPA Mandate Reference Strategy

**Status**: Accepted

**Date**: 2025-01-23

**Deciders**: Architecture Team

---

## Context

SEPA Direct Debit transfers require a mandate reference for each debtor (member). The mandate reference, together with the creditor ID (Gläubiger-ID), uniquely identifies the mandate within the SEPA system.

Key considerations:

- **Uniqueness**: Mandate reference must be unique per member (per creditor)
- **Maximum length**: 35 characters (SEPA standard)
- **Default generation**: Automatically generate from member UUID
- **Editability**: Allow admins to override reference if existing mandate has different reference
- **External mandate management**: The system does NOT track mandate lifecycle (expiry, revocation, signature dates) - mandates are managed outside the system on paper

The system stores a single editable mandate_reference field per member. All mandate-related data (signatures, dates, revocations, expiry tracking) is managed outside the system.

---

## Decision

**Mandate reference is stored as an editable field in the members table, initialized with member UUID with hyphens removed (32 characters) at member creation time. Admins can override this reference if an existing mandate has a different reference. The system does not track mandate metadata (signature dates, revocation status, lifecycle states) - all mandate management is performed outside the system.**

### Core Principles

1. **Direct initialization**: Initialize with `str_replace('-', '', $memberId)` on member creation
2. **Automatic uniqueness**: UUID ensures uniqueness by design
3. **Admin editable**: Can override if needed for existing mandates
4. **Simple field**: Just store the reference; no lifecycle tracking or helper functions
5. **Lightweight**: No mandate date, active flag, or expiry logic in system
6. **SEPA compliant**: Reference format: max 35 chars, allowed chars: `0-9 a-z A-Z + ? / - : ( ) . , '`

### Database Schema

#### Members Table - Mandate Reference Field

```sql
-- Add mandate_reference column
ALTER TABLE members ADD COLUMN mandate_reference VARCHAR(35) NULLABLE DEFAULT NULL;

-- Create index for SEPA export lookups
CREATE INDEX idx_members_mandate_reference ON members(mandate_reference);

-- Migration: Initialize mandate_reference for existing members
-- (Remove hyphens from UUID to create default reference)
UPDATE members SET mandate_reference = REPLACE(id, '-', '')
WHERE mandate_reference IS NULL;

-- Admin override: If member has existing mandate with different reference
UPDATE members SET mandate_reference = 'CUSTOM-REF-12345'
WHERE id = '550e8400-e29b-41d4-a716-446655440000';
```

**Field Details:**
- **mandate_reference**: VARCHAR(35), nullable (SEPA standard max length)
- **Default value**: Member UUID with hyphens removed (32 chars)
- **Editable**: Admin can change if member has existing mandate with different reference
- **Format**: Only allowed chars: `0-9 a-z A-Z + ? / - : ( ) . , '`

#### Member Creation - Initialize Mandate Reference

Initialize mandate_reference directly when creating a member:

```php
<?php
// When creating new member, initialize mandate_reference with trimmed UUID
// memberId example: 550e8400-e29b-41d4-a716-446655440000 (36 chars with hyphens)
// mandate_reference: 550e8400e29b41d4a716446655440000 (32 chars, no hyphens)

$db->insert('members', [
    'id' => $newMemberId,
    'first_name' => 'Max',
    'last_name' => 'Mustermann',
    'card_uid' => 'RF12345678',
    'mandate_reference' => str_replace('-', '', $newMemberId),  // Initialize with trimmed UUID
    'is_active' => true,
    'created_at' => date('Y-m-d H:i:s')
]);
```

#### SEPA XML Usage

The mandate reference is used in SEPA XML export as-is:

```xml
<!-- Mandate Information in SEPA XML -->
<DrctDbtTx>
    <MndtRltdInf>
        <MndtId>550e8400e29b41d4a716446655440000</MndtId>  <!-- Mandate reference -->
    </MndtRltdInf>
</DrctDbtTx>
```

### Admin UI Implementation

#### Member Edit Form - Mandate Reference

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

  {/* Mandate Reference Field (NEW) */}
  <TextInput
    label="Mandate Reference"
    placeholder="550e8400e29b41d4a716446655440000"
    description="SEPA mandate identifier. Default is member UUID without hyphens. Override if member has existing mandate with different reference."
    {...register('mandate_reference', {
      validate: {
        format: (value) => {
          if (!value) return true;  // Optional field
          return /^[0-9a-zA-Z+?/:\-().,\\']{1,35}$/.test(value) || 'Invalid format (max 35 chars, allowed: 0-9 a-z A-Z + ? / - : ( ) . , \')';
        }
      }
    })}
  />

  <Button type="submit">Save Member</Button>
</Form>
```

#### Settlement CSV Export

The mandate reference is included in CSV export for bank processing:

```
Name,IBAN,Amount EUR,Mandate Reference
Max Mustermann,DE89370400440532013000,25.50,550e8400e29b41d4a716446655440000
Erika Müller,DE89370400440532013001,12.00,8a1c9012f45c48d9b872336e50f41c8e
```

### Pre-Settlement Validation

```php
<?php
/**
 * Validate member has required SEPA data for collection
 * (Simplified: only check IBAN and mandate reference present)
 */
function canCollectSEPA($member) {
    $errors = [];

    // Check IBAN exists and is valid
    if (!$member['iban']) {
        $errors[] = 'IBAN missing';
    } elseif (!isValidIBAN($member['iban'])) {
        $errors[] = 'IBAN invalid checksum';
    }

    // Check mandate reference is set
    if (!$member['mandate_reference']) {
        $errors[] = 'Mandate reference missing';
    }

    return empty($errors) ? [true, []] : [false, $errors];
}

// Pre-settlement validation
$members = $db->select('members', '*', ['is_active' => true]);
$validMembers = [];
$skippedMembers = [];

foreach ($members as $member) {
    [$canCollect, $errors] = canCollectSEPA($member);

    if ($canCollect) {
        $validMembers[] = $member;
    } else {
        $skippedMembers[] = [
            'member_id' => $member['id'],
            'first_name' => $member['first_name'],
            'errors' => $errors
        ];
    }
}

// Report: "3 members skipped due to missing SEPA data"
```

---

## Consequences

### Positive

✅ **Simple**: Single editable field; no helper functions or lifecycle tracking
✅ **Direct initialization**: mandate_reference set directly with `str_replace('-', '', $memberId)` on member creation
✅ **Flexible**: Admin can override reference if member has existing mandate with different reference
✅ **Lightweight**: No mandate date, active flag, or expiry logic in system
✅ **SEPA compliant**: Reference format (UUID without hyphens) valid for SEPA XML export
✅ **Audit trail**: Changes to mandate_reference logged via standard audit system
✅ **Low maintenance**: Mandate management handled outside system (on paper)
✅ **Separation of concerns**: System focus is transactions/settlements, not mandate admin

### Negative

❌ **Manual mandate management**: Admins must manage actual mandates outside the system
❌ **No lifecycle validation**: System cannot check if mandate is expired or revoked
❌ **No sequence type tracking**: Cannot determine FRST vs RCUR automatically
❌ **Admin responsibility**: Must ensure real-world mandates match system records

### Mitigations

1. **Admin documentation**: Clear guide on mandate management procedures
2. **Pre-settlement checklist**: Remind admins to verify mandates offline before settlement
3. **Optional notes field**: Allow admin to add mandate expiry date or notes (free-text, not enforced)
4. **Audit trail**: Log all mandate_reference changes for compliance
5. **Partner integration**: Consider future integration with external mandate management service

---

## Alternatives Considered

### Alternative 1: Mandate Management in System

Track full mandate lifecycle: signature date, revocation status, expiry, first-use timestamp.

**Pros**: Complete data model; system can enforce rules
**Cons**:
- Complex: requires mandate date validation, revocation logic, expiry checks
- Burden: admins must manually enter all mandate metadata
- Inflexible: cannot handle edge cases (mandate restored, customer disputes, etc.)
- Over-engineered: for small organizations with few members, external management simpler

**Rejected**: Mandates are legal/compliance matter best handled outside system on paper.

### Alternative 2: Helper Function with Caching

Create `getDefaultMandateReference()` helper function that calculates UUID-derived reference on demand.

**Pros**: Centralized logic; easy to change generation algorithm
**Cons**:
- Unnecessary indirection (just a string replacement)
- Extra function call on every member creation
- Still requires manual invocation in code

**Rejected**: Direct initialization simpler; no need for helper function.

### Alternative 4: Store Mandate in Separate Table

```sql
CREATE TABLE sepa_mandates (
  id INT PRIMARY KEY AUTO_INCREMENT,
  member_id BINARY(16),
  mandate_reference VARCHAR(35),
  mandate_date DATE,
  mandate_active BOOLEAN,
  mandate_first_used_at TIMESTAMP,
  created_at DATETIME
);
```

**Pros**: Separates mandate data from member data
**Cons**:
- Additional table and JOINs
- No benefit over member table (mandate is 1:1 with member)
- Complicates queries and migration

**Rejected**: Mandate reference better stored directly in members table.

---

## Implementation Checklist

### Database

- [ ] Add `mandate_reference VARCHAR(35) NULLABLE DEFAULT NULL` to members table
- [ ] Create index: `CREATE INDEX idx_members_mandate_reference ON members(mandate_reference)`
- [ ] Migration: Set default mandate_reference for existing members (UUID without hyphens)

### Backend API

- [ ] `POST /api/members`: Initialize mandate_reference with `str_replace('-', '', $newMemberId)` on member creation
- [ ] `PATCH /api/members/{id}`: Accept mandate_reference in request for editing
- [ ] Mandate reference validation: Max 35 chars, allowed chars only (0-9 a-z A-Z + ? / - : ( ) . , ')
- [ ] `GET /api/members/{id}`: Return mandate_reference
- [ ] Audit logging: Log mandate_reference changes

### Admin UI

- [ ] Member edit form: Add mandate_reference field (text input)
- [ ] Placeholder: Show UUID-derived example (e.g., `550e8400e29b41d4a716446655440000`)
- [ ] Help text: Explain that field is pre-filled with member UUID and can be edited if needed
- [ ] Real-time validation: Check format (max 35 chars, allowed chars)
- [ ] Read-only display: Show current value when member is created

### Settlement Export

- [ ] Validate all members have IBAN and mandate_reference present
- [ ] Include mandate_reference in CSV export
- [ ] Include mandate_reference in SEPA XML export
- [ ] Report: List any members skipped due to missing SEPA data

### Testing

- [ ] New member: mandate_reference initialized with UUID without hyphens
- [ ] Member without mandate_reference: rejected from settlement export
- [ ] Member with custom mandate_reference: correctly included in export
- [ ] Default reference validates as SEPA-compliant
- [ ] Custom reference validates format rules (max 35 chars, allowed chars only)
- [ ] Invalid characters rejected with validation error
- [ ] Max 35 character limit enforced
- [ ] CSV export includes mandate_reference field
- [ ] SEPA XML export includes mandate_reference in MndtId field
- [ ] Audit log tracks mandate_reference changes (creation and edits)

### Documentation

- [ ] Update CLAUDE.md: Add mandate_reference to members schema
- [ ] Admin guide: Mandate reference management (defaults and overrides)
- [ ] Note: Mandate management is external; system only stores reference
- [ ] SEPA procedure: Mention that admins responsible for actual mandate paperwork

---

## Related Decisions

- [ADR-0004: Immutable Transaction Storage](./0004-immutable-transaction-storage.md) - Settlement workflow
- [ADR-0005: IBAN Storage and Validation](./0005-iban-storage-and-validation.md) - IBAN validation (for SEPA collections)
- [ADR-0008: SEPA XML Export Format Selection](./0008-sepa-xml-export-format-selection.md) - Mandate reference used in SEPA XML

---

## References

- **SEPA Standards**:
  - [EPC SEPA Rulebook](https://www.europeanpaymentscouncil.eu/document-library) - Official SEPA Direct Debit rules
  - [ISO 20022 XML Standard](https://www.iso20022.org/) - XML message format (pain.008)

- **Mandate Reference**:
  - Max 35 characters per SEPA standard
  - Allowed characters: `0-9 a-z A-Z + ? / - : ( ) . , '`
  - Unique per mandate per creditor (per Gläubiger-ID)

---

## Approval

- **Decided by**: Architecture Team
- **Rationale**: Simple single-field design with direct UUID-derived initialization ensures uniqueness, simplicity, and SEPA compliance. No helper functions or lifecycle tracking needed.
- **Implementation start**: Phase 2 (SEPA settlement)
- **Review date**: 2025-04-23 (after first SEPA settlement)
- **Sign-off**:
  - Backend Lead: _________________ Date: _______
  - Payment Processing Lead: _________________ Date: _______
  - Admin UI Lead: _________________ Date: _______

---

## Post-Implementation Monitoring

- [ ] Verify mandate_reference initialized correctly for all new members (UUID without hyphens)
- [ ] Audit log: Verify mandate_reference changes logged when admin edits
- [ ] CSV export: Verify mandate_reference field included and matches database
- [ ] SEPA XML export: Verify mandate_reference in MndtId field matches database
- [ ] Bank processing: Any rejections due to invalid mandate references?
- [ ] Settlement validation: Verify members without mandate_reference rejected from export
- [ ] User feedback: Is mandate_reference field intuitive for admins?
