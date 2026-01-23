# ADR-0007: Organization-Level SEPA Configuration Storage

**Status**: Accepted

**Date**: 2025-01-23

**Deciders**: Architecture Team

---

## Context

SEPA Direct Debit exports require organization-level information that applies to all settlements:

- **Gläubiger-ID (Creditor ID)**: Unique 18-character identifier assigned by the Bundesbank
- **Organization name**: Must match bank records (max 70 characters per SEPA standard)
- **Organization IBAN**: The organization's bank account for receiving payments
- **Address fields**: Street, city, postal code, country code (for SEPA XML)

These settings are:

1. **Immutable per settlement**: Once set, they apply to all future settlements until changed
2. **Admin-configurable**: Can be updated via admin panel
3. **Required for SEPA export**: Missing or invalid settings should prevent settlement
4. **Audit-sensitive**: Changes should be logged (who changed what, when)
5. **Deployment-specific**: Different organizations/deployments have different IDs

### Storage Options

Three viable approaches:

1. **Dedicated `sepa_config` table**: Single row, all SEPA settings
2. **Generic `settings` table**: Key-value store for all org settings
3. **Environment variables**: Configuration in `.env` file (no database)

---

## Decision

**Organization SEPA settings are stored in a dedicated `sepa_config` table with a single row. This table is admin-configurable via API and UI. Settings are cached in application (with short TTL) to avoid repeated database queries. Changes are audit-logged.**

### Core Principles

1. **Single-row configuration table**: Explicit design for organization-wide settings
2. **Admin-editable via UI**: Form in settings panel to manage SEPA data
3. **Immutable Gläubiger-ID**: Once set, cannot be changed (business requirement)
4. **Validation on save**: IBAN checksum, Gläubiger-ID format, character length limits
5. **Audit logging**: All changes tracked with before/after values
6. **Fallback defaults**: System has sensible defaults (allow override)

### Database Schema

#### SEPA Configuration Table

```sql
CREATE TABLE sepa_config (
  id INT PRIMARY KEY DEFAULT 1,  -- Single row

  -- Creditor Identification
  creditor_id VARCHAR(35) NOT NULL UNIQUE,
    -- Gläubiger-ID (German: Creditor ID)
    -- Format: Country code (2) + ZZZ + Bank code + unique ref = 18 chars total
    -- Example: DE98ZZZ09999999999
    -- Immutable after first set (business requirement)

  creditor_name VARCHAR(70) NOT NULL,
    -- Organization name for SEPA (max 70 chars per SEPA standard)
    -- Example: FRGS Frankfurter Rudergesellschaft Sachsenhausen

  creditor_iban VARCHAR(34) NOT NULL,
    -- Organization's bank account for receiving payments
    -- Example: DE89370400440532013000
    -- Validated on save (checksum)

  creditor_address_street VARCHAR(70) NOT NULL,
    -- Street and house number
    -- Example: Mainufer 34

  creditor_address_city VARCHAR(70) NOT NULL,
    -- Postal code and city
    -- Example: 60311 Frankfurt am Main

  creditor_address_country VARCHAR(2) NOT NULL DEFAULT 'DE',
    -- ISO 3166-1 country code
    -- Example: DE

  -- Metadata
  created_at DATETIME NOT NULL DEFAULT NOW(),
  updated_at DATETIME NOT NULL DEFAULT NOW() ON UPDATE NOW(),
  updated_by_admin_id BINARY(16),
    -- Admin who last modified (for audit trail)

  -- Constraint: prevent accidental insertion of multiple rows
  CONSTRAINT single_row CHECK (id = 1)
) ENGINE=InnoDB;

-- Insert default/empty row (admins fill in later)
INSERT INTO sepa_config (id, creditor_id, creditor_name, creditor_iban,
  creditor_address_street, creditor_address_city)
VALUES (1, '', '', '', '', '')
ON DUPLICATE KEY UPDATE id = 1;
```

#### Audit Logging

```sql
-- Existing audit_log table tracks changes
-- SEPA config changes logged with:
-- - action: 'update'
-- - entity_type: 'sepa_config'
-- - entity_id: 1 (the single row)
-- - changes_json: { 'field_name': { 'old': '...', 'new': '...' } }
-- - admin_user_id: who made the change
-- - created_at: when
```

### API Implementation

#### GET SEPA Configuration

```php
<?php
/**
 * GET /api/admin/sepa-config
 * Return current organization SEPA settings
 *
 * Public info (non-sensitive):
 * - creditor_name
 * - creditor_address_*
 * - creditor_country
 *
 * Masked (for admin-only display):
 * - creditor_id: Show first 6 + last 4 chars only
 * - creditor_iban: Show only last 4 chars
 */
function getSEPAConfig() {
    $config = $db->selectOne('sepa_config', '*', ['id' => 1]);

    if (!$config) {
        throw new Exception('SEPA configuration not found');
    }

    return [
        'creditor_id' => maskCreditorId($config['creditor_id']),
        'creditor_name' => $config['creditor_name'],
        'creditor_iban' => maskIban($config['creditor_iban']),
        'creditor_address_street' => $config['creditor_address_street'],
        'creditor_address_city' => $config['creditor_address_city'],
        'creditor_address_country' => $config['creditor_address_country'],
        'updated_at' => $config['updated_at']
    ];
}

function maskCreditorId($creditorId) {
    if (!$creditorId) return null;
    return substr($creditorId, 0, 6) . '...' . substr($creditorId, -4);
}

function maskIban($iban) {
    if (!$iban) return null;
    return substr($iban, 0, 2) . '...' . substr($iban, -4);
}
```

#### PATCH SEPA Configuration

```php
<?php
/**
 * PATCH /api/admin/sepa-config
 * Update organization SEPA settings (admin-only)
 *
 * Restrictions:
 * - creditor_id: Immutable after initial set (if already has value, reject change)
 * - All other fields: Can be updated
 */
function updateSEPAConfig($request, $adminId) {
    // Authorization check
    if ($admin['role'] !== 'admin') {
        throw new ForbiddenException('Only admins can modify SEPA config');
    }

    $currentConfig = $db->selectOne('sepa_config', '*', ['id' => 1]);
    $newData = $request->json();

    // Validate: creditor_id immutability
    if (isset($newData['creditor_id']) && $currentConfig['creditor_id'] &&
        $newData['creditor_id'] !== $currentConfig['creditor_id']) {
        throw new BadRequestException('creditor_id is immutable; cannot change');
    }

    // Validate: creditor_id format
    if (isset($newData['creditor_id']) && !isValidCreditorId($newData['creditor_id'])) {
        throw new BadRequestException('Invalid creditor_id format');
    }

    // Validate: IBAN checksum
    if (isset($newData['creditor_iban']) && !isValidIBAN($newData['creditor_iban'])) {
        throw new BadRequestException('Invalid creditor IBAN');
    }

    // Validate: field lengths (SEPA limits)
    if (isset($newData['creditor_name']) && strlen($newData['creditor_name']) > 70) {
        throw new BadRequestException('creditor_name exceeds 70 characters');
    }

    // Validate: SEPA-compliant characters only (Latin alphabet, no special chars)
    if (isset($newData['creditor_name']) && !isValidSEPAString($newData['creditor_name'])) {
        throw new BadRequestException('creditor_name contains invalid SEPA characters');
    }

    // Prepare update data (only allow specific fields)
    $allowedFields = [
        'creditor_id', 'creditor_name', 'creditor_iban',
        'creditor_address_street', 'creditor_address_city', 'creditor_address_country'
    ];

    $updateData = array_intersect_key($newData, array_flip($allowedFields));
    $updateData['updated_by_admin_id'] = $adminId;
    $updateData['updated_at'] = date('c');

    // Record changes for audit log
    $changes = [];
    foreach ($updateData as $field => $value) {
        if ($field !== 'updated_by_admin_id' && $field !== 'updated_at') {
            $oldValue = $currentConfig[$field] ?? null;
            if ($oldValue !== $value) {
                $changes[$field] = [
                    'old' => maskSensitiveField($field, $oldValue),
                    'new' => maskSensitiveField($field, $value)
                ];
            }
        }
    }

    // Update database
    $db->update('sepa_config', $updateData, ['id' => 1]);

    // Audit log
    if (!empty($changes)) {
        $db->insert('audit_log', [
            'admin_user_id' => $adminId,
            'action' => 'update',
            'entity_type' => 'sepa_config',
            'entity_id' => 1,
            'changes_json' => json_encode($changes),
            'ip_address' => $_SERVER['REMOTE_ADDR'],
            'created_at' => date('c')
        ]);
    }

    return $db->selectOne('sepa_config', '*', ['id' => 1]);
}

function maskSensitiveField($field, $value) {
    if ($field === 'creditor_id') return maskCreditorId($value);
    if ($field === 'creditor_iban') return maskIban($value);
    return $value;
}

function isValidCreditorId($creditorId) {
    // Format: DE + ZZZ + 11 digits = 18 chars (for Germany)
    // Regex: ^[A-Z]{2}[0-9A-Z]{3}[0-9]{10,}$
    return preg_match('/^[A-Z]{2}[0-9A-Z]{3}[0-9]{10,}$/', $creditorId) === 1;
}

function isValidSEPAString($str) {
    // SEPA allows: a-z A-Z 0-9 space / - ? : ( ) . , '
    // For creditor_name: restrict to Latin only (no umlauts, etc.)
    return preg_match('/^[a-zA-Z0-9\s\/\-?\:().,\']+$/', $str) === 1;
}
```

#### SEPA Export Uses Configuration

```php
<?php
/**
 * Generate SEPA XML using creditor settings
 */
function generateSEPAXml($settlementId) {
    $config = getSEPAConfig();
    $settlement = $db->selectOne('settlements', '*', ['id' => $settlementId]);

    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Document xmlns="urn:iso:std:iso:20022:tech:xsd:pain.008.001.02">
  <CstmrDrctDbtInitn>
    <GrpHdr>
      <MsgId>MSG{$settlementId}</MsgId>
      <CreDtTm>{date('c')}</CreDtTm>
      <NbOfTxs>42</NbOfTxs>
      <CtrlSum>1847.50</CtrlSum>
      <InitgPty>
        <Nm>{$config['creditor_name']}</Nm>
      </InitgPty>
    </GrpHdr>
    <PmtInf>
      <PmtInfId>SETL{$settlementId}</PmtInfId>
      <Cdtr>
        <Nm>{$config['creditor_name']}</Nm>
        <PstlAdr>
          <StrtNm>{$config['creditor_address_street']}</StrtNm>
          <BldgNb></BldgNb>
          <PstCd></PstCd>
          <TwnNm>{$config['creditor_address_city']}</TwnNm>
          <Ctry>{$config['creditor_address_country']}</Ctry>
        </PstlAdr>
      </Cdtr>
      <CdtrAcct>
        <Id>
          <IBAN>{str_replace(' ', '', $config['creditor_iban'])}</IBAN>
        </Id>
      </CdtrAcct>
      <CdtrSchmeId>
        <Id>
          <PrvtId>
            <Othr>
              <Id>{$config['creditor_id']}</Id>
              <Issr>DE</Issr>
            </Othr>
          </PrvtId>
        </Id>
      </CdtrSchmeId>
      <!-- Transaction details follow... -->
    </PmtInf>
  </CstmrDrctDbtInitn>
</Document>
XML;

    return $xml;
}
```

### Admin UI Implementation

#### SEPA Configuration Form

```jsx
// Admin panel: Settings → SEPA Configuration
function SEPAConfigForm() {
  const [config, setConfig] = useState(null);
  const [isEditing, setIsEditing] = useState(false);
  const [errors, setErrors] = useState({});

  const form = useForm({
    initialValues: config || {}
  });

  const handleSave = async (formData) => {
    try {
      const response = await api.patch('/api/admin/sepa-config', formData);
      toast.success('SEPA configuration updated');
      setConfig(response);
      setIsEditing(false);
    } catch (err) {
      setErrors(err.fieldErrors || {});
      toast.error('Failed to save SEPA configuration');
    }
  };

  return (
    <div className="sepa-config-form">
      <h2>SEPA Configuration</h2>

      {!isEditing && config ? (
        <div className="display-view">
          <Field label="Gläubiger-ID">
            <strong>{config.creditor_id}</strong>
            <small>(Immutable after set)</small>
          </Field>

          <Field label="Organization Name">
            <strong>{config.creditor_name}</strong>
          </Field>

          <Field label="Organization IBAN">
            <strong>{config.creditor_iban}</strong>
          </Field>

          <Field label="Address">
            {config.creditor_address_street}
            <br />
            {config.creditor_address_city}
            <br />
            {config.creditor_address_country}
          </Field>

          <Field label="Last Updated">
            {new Date(config.updated_at).toLocaleString()}
          </Field>

          <Button onClick={() => setIsEditing(true)}>Edit</Button>
        </div>
      ) : (
        <form onSubmit={form.handleSubmit(handleSave)}>
          <TextInput
            label="Gläubiger-ID"
            placeholder="DE98ZZZ09999999999"
            disabled={!!config?.creditor_id}  {/* Immutable */}
            error={errors.creditor_id}
            {...form.register('creditor_id', {
              required: 'Gläubiger-ID required',
              validate: {
                format: (v) => /^[A-Z]{2}[0-9A-Z]{3}[0-9]{10,}$/.test(v) || 'Invalid format'
              }
            })}
            description="Request at: https://www.glaeubiger-id.bundesbank.de"
          />

          <TextInput
            label="Organization Name"
            maxLength={70}
            error={errors.creditor_name}
            {...form.register('creditor_name', {
              required: 'Name required',
              maxLength: { value: 70, message: 'Max 70 characters' }
            })}
          />

          <TextInput
            label="Organization IBAN"
            placeholder="DE89370400440532013000"
            error={errors.creditor_iban}
            {...form.register('creditor_iban', {
              required: 'IBAN required',
              validate: (v) => isValidIBAN(v) || 'Invalid IBAN'
            })}
          />

          <TextInput
            label="Street Address"
            error={errors.creditor_address_street}
            {...form.register('creditor_address_street', {
              required: 'Street required'
            })}
          />

          <TextInput
            label="City/Postal Code"
            error={errors.creditor_address_city}
            {...form.register('creditor_address_city', {
              required: 'City required'
            })}
          />

          <Select
            label="Country"
            data={[{ label: 'Germany', value: 'DE' }]}
            defaultValue="DE"
            {...form.register('creditor_address_country')}
          />

          <Button type="submit">Save Configuration</Button>
          <Button onClick={() => setIsEditing(false)}>Cancel</Button>
        </form>
      )}
    </div>
  );
}
```

---

## Consequences

### Positive

✅ **Explicit design**: Dedicated table makes SEPA settings intent clear
✅ **Single row guarantee**: `CHECK (id = 1)` prevents accidental duplication
✅ **Immutable creditor_id**: Once set, can't accidentally change (business requirement)
✅ **Admin UI control**: Non-technical admins can update settings
✅ **Audit trail**: All changes logged with before/after values
✅ **Validation**: Format/length checks before save prevent bank rejections
✅ **Masking**: Sensitive data masked in logs and API responses

### Negative

❌ **Inflexible schema**: Adding new SEPA fields requires ALTER TABLE
❌ **Single row limitation**: Doesn't support multi-organization deployments
❌ **Immutability enforcement**: Requires application logic (can't rely on DB alone)
❌ **Address parsing**: No structured address fields (street vs city separation)

### Mitigations

1. **Schema planning**: Anticipate future SEPA fields (COR1 support, contact info, etc.)
2. **Multi-tenant consideration**: If needed later, add organization_id; create separate tables
3. **Validation**: Comprehensive input validation + DB constraints
4. **Address fields**: Separate street, number, postal code, city if needed later

---

## Alternatives Considered

### Alternative 1: Generic Settings Table (Key-Value)

```sql
CREATE TABLE settings (
  key VARCHAR(100) PRIMARY KEY,
  value JSON,
  updated_at DATETIME
);

INSERT INTO settings VALUES
  ('sepa_creditor_id', '"DE98ZZZ..."', NOW()),
  ('sepa_creditor_name', '"FRGS..."', NOW()),
  ...
```

**Pros**: Flexible for adding any setting later
**Cons**:
- Less explicit (type checking harder)
- No schema validation
- Complex queries (JOINs for related settings)
- Harder to reason about relationships

**Rejected**: Too flexible; SEPA settings are cohesive and need validation.

### Alternative 2: Environment Variables Only

```bash
# .env file
SEPA_CREDITOR_ID=DE98ZZZ09999999999
SEPA_CREDITOR_NAME="FRGS Frankfurter Rudergesellschaft"
SEPA_CREDITOR_IBAN=DE89370400440532013000
```

**Pros**: Simple, no database changes needed
**Cons**:
- Can't update without redeployment
- Admin UI can't modify (must edit .env directly)
- No audit trail
- Not suitable for multi-organization setups
- Secrets in version control risk

**Rejected**: Admins need to manage SEPA config without code deployment.

### Alternative 3: Multiple Rows with Organization ID

```sql
CREATE TABLE sepa_config (
  id INT PRIMARY KEY AUTO_INCREMENT,
  organization_id BINARY(16) NOT NULL,
  creditor_id VARCHAR(35) NOT NULL,
  ...
  UNIQUE KEY unique_org (organization_id)
);
```

**Pros**: Supports multi-tenant/multi-organization deployments
**Cons**: Over-engineered for single-org use case; adds complexity
- Need organization context in every query
- Migration path unclear
- Rare requirement for small deployments

**Rejected**: Single-organization design sufficient; can add tenancy later if needed.

### Alternative 4: Store Raw JSON

```sql
CREATE TABLE sepa_config (
  id INT PRIMARY KEY DEFAULT 1,
  config JSON NOT NULL
);

INSERT INTO sepa_config VALUES (1, JSON_OBJECT(
  'creditor_id', 'DE98ZZZ...',
  'creditor_name', 'FRGS...',
  ...
));
```

**Pros**: Flexible schema
**Cons**:
- Type validation harder
- No automatic escaping/masking
- Complex to update individual fields
- Schema evolution unclear

**Rejected**: Structured columns better for validation and audit trail.

---

## Implementation Checklist

### Database

- [ ] Create `sepa_config` table with single-row constraint
- [ ] Insert default empty row
- [ ] Verify CHECK constraint prevents duplicate rows
- [ ] Create indexes if needed (unlikely for single row)

### Backend API

- [ ] GET /api/admin/sepa-config endpoint (with masking)
- [ ] PATCH /api/admin/sepa-config endpoint (with validation)
- [ ] Creditor-ID immutability validation
- [ ] IBAN checksum validation
- [ ] SEPA character set validation
- [ ] Audit logging for all changes
- [ ] Authorization check (admin-only)

### Admin UI

- [ ] SEPA Configuration form in Settings
- [ ] Display current config (with masking)
- [ ] Edit mode for each field
- [ ] Immutability indicator on creditor_id
- [ ] Real-time validation (IBAN, format)
- [ ] Success/error toasts
- [ ] Help text with references (Bundesbank, SEPA rules)

### Settlement Export

- [ ] Load SEPA config at export time
- [ ] Validate all required fields present before export
- [ ] Reject export if config incomplete (validation error)
- [ ] Use config in CSV/XML generation
- [ ] Log config version/timestamp with export (audit trail)

### Testing

- [ ] Create SEPA config successfully
- [ ] Update individual fields
- [ ] Creditor-ID immutability enforced
- [ ] Invalid IBAN rejected
- [ ] Invalid creditor-ID rejected
- [ ] Character validation (SEPA compliant)
- [ ] Audit log tracks all changes
- [ ] Cache invalidation works
- [ ] CSV/XML exports use correct config

### Documentation

- [ ] Update CLAUDE.md: Add SEPA config table to schema
- [ ] Admin guide: How to set up SEPA configuration
- [ ] API docs: GET/PATCH SEPA config endpoints
- [ ] Deployment guide: Creditor-ID request process
- [ ] Cross-reference to ADRs: 0005, 0006, 0008, 0009

---

## Related Decisions

- [ADR-0005: IBAN Storage and Validation](./0005-iban-storage-and-validation.md) - Member IBAN validation
- [ADR-0006: SEPA Mandate Reference Strategy](./0006-sepa-mandate-reference-strategy.md) - Mandate fields
- [ADR-0008: SEPA XML Export Format Selection](./0008-sepa-xml-export-format-selection.md) - Uses creditor config for XML
- [ADR-0009: Settlement Lead Times and Bank Working Days](./0009-settlement-lead-times-bank-working-days.md) - Validation before export

---

## References

- **SEPA Standards**:
  - [EPC SEPA Rulebook](https://www.europeanpaymentscouncil.eu/) - Official rules
  - [Bundesbank Creditor ID Application](https://www.glaeubiger-id.bundesbank.de) - Apply for Gläubiger-ID

- **Configuration Management**:
  - Single-row pattern for global settings
  - Application-level caching best practices

---

## Approval

- **Decided by**: Architecture Team
- **Rationale**: Dedicated table ensures type safety and audit trail; single-row constraint prevents accidents
- **Implementation start**: Phase 2 (SEPA settlement)
- **Review date**: 2025-04-23 (after first SEPA settlement)
- **Sign-off**:
  - Backend Lead: _________________ Date: _______
  - Operations/Deployment Lead: _________________ Date: _______
  - Security Officer: _________________ Date: _______

---

## Post-Implementation Monitoring

- [ ] Verify creditor-ID immutability enforced
- [ ] Monitor SEPA config changes (audit log)
- [ ] Verify masking in logs (no plaintext sensitive data)
- [ ] User feedback: Is config UI intuitive?
- [ ] Settlement generation: Are exports using correct config?
- [ ] Bank acceptance: No rejections due to config data?
