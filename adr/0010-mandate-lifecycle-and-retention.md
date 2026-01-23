# ADR-0010: Mandate Lifecycle and Retention

**Status**: Superseded

**Date**: 2025-01-23

**Deciders**: Architecture Team

**Superseded by**: ADR-0006 (simplified mandate reference strategy - no lifecycle tracking)

---

## Summary

This ADR is superseded. Mandate lifecycle management (expiry, revocation, signature dates) is OUT OF SCOPE. The system now only tracks a simple editable `mandate_reference` field per member. All mandate-related compliance and lifecycle management is performed outside the system.

---

## Context

SEPA mandates have a defined lifecycle governed by both SEPA rules and legal requirements:

### SEPA Mandate Rules

- **Duration**: Active for up to 36 months (3 years)
- **Expiry**: Mandate expires if not used for 36 consecutive months
- **Revocation**: Debtor (member) can revoke anytime
- **Signature date**: Immutable; must not be in future

### Retention Requirements

| Document | Retention Period | Basis |
|----------|------------------|-------|
| **Original mandate** | 14 months after last use | PSD2 (Payment Services Directive 2) |
| **Settlement records** | 10 years | § 147 AO (German tax code) |
| **Audit logs** | 10 years | § 147 AO + GDPR |
| **Bank confirmations** | As per bank | Bank-specific |

### Compliance Concerns

1. **Mandate expiry**: If mandate unused for 36 months, becomes invalid
2. **First-use tracking**: Need to know when mandate was first used (for 14-month retention)
3. **Revocation handling**: If member revokes, prevent future collections
4. **GDPR erasure**: When member deleted, handle mandate according to retention rules
5. **Dormant members**: Mandate expires silently if member hasn't had collections in 36 months
6. **Record keeping**: Audit trail must show mandate history for compliance

---

## Decision

**Member records track: mandate_date (signature), mandate_active (revocation flag), and mandate_first_used_at (first collection timestamp). System monitors mandate expiry (36 months of inactivity) and prevents collections on expired mandates. Original mandate documents are retained 14 months after last use; records retained 10 years per German tax code. Revoked mandates are marked inactive immediately and cannot be used for collections.**

### Core Principles

1. **Mandate_date immutable**: Cannot change after first set (signature date is permanent)
2. **Mandate_active flag**: Allows quick revocation (soft-delete, not hard-delete)
3. **First-use tracking**: mandate_first_used_at marks when FRST collection occurred
4. **36-month expiry rule**: Automatic (checked at settlement time)
5. **14-month document retention**: Original mandate kept 14 months from last use
6. **10-year record retention**: Settlement records, audit logs retained per German law
7. **GDPR compliance**: Anonymization clears mandate fields but retains transaction records
8. **Audit trail**: All state changes logged (activation, first use, revocation)

### Database Schema

#### Members Table - Mandate Fields

```sql
-- Already defined in ADR-0006, but reviewing here for clarity:
ALTER TABLE members ADD COLUMN mandate_date DATE;
ALTER TABLE members ADD COLUMN mandate_active BOOLEAN DEFAULT TRUE;
ALTER TABLE members ADD COLUMN mandate_first_used_at TIMESTAMP NULL DEFAULT NULL;
ALTER TABLE members ADD COLUMN mandate_revoked_at TIMESTAMP NULL DEFAULT NULL;
  -- Timestamp when mandate was revoked (for audit trail)
ALTER TABLE members ADD COLUMN mandate_expiry_calculated_at TIMESTAMP NULL DEFAULT NULL;
  -- Last time we checked if mandate expired (avoid repeated checks)

CREATE INDEX idx_mandate_active ON members(mandate_active);
CREATE INDEX idx_mandate_first_used_at ON members(mandate_first_used_at);
CREATE INDEX idx_mandate_expiry_check ON members(mandate_date, mandate_first_used_at);
```

#### Mandate Lifecycle States

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                                                                             │
│  MANDATE LIFECYCLE                                                          │
│                                                                             │
│  1. CREATED (mandate_date set, mandate_active=true, mandate_first_used_at=NULL)
│     └─► Pending first collection
│
│  2. ACTIVE (mandate_first_used_at set, mandate_active=true)
│     └─► Used for FRST/RCUR collections
│     └─► Expires after 36 months of inactivity
│
│  3. EXPIRED (mandate_first_used_at + 36 months < now, mandate_active=true)
│     └─► No longer valid; cannot use for collections
│     └─► Member must provide new mandate or sign addendum
│
│  4. REVOKED (mandate_revoked_at set, mandate_active=false)
│     └─► Member or org requested revocation
│     └─► Cannot use for collections
│     └─► Retain 14 months for compliance
│
│  5. ARCHIVED (> 14 months after last use)
│     └─► Document destroyed per PSD2 retention requirements
│     └─► Record kept in audit log (immutable)
│
└─────────────────────────────────────────────────────────────────────────────┘
```

### Mandate Status Determination

```php
<?php
/**
 * Determine mandate status at given point in time
 */
class MandateStatus {
    const CREATED = 'created';      // Never used
    const ACTIVE = 'active';        // Used, within 36 months
    const EXPIRED = 'expired';      // Unused for 36+ months
    const REVOKED = 'revoked';      // Member/org revoked
    const ARCHIVED = 'archived';    // Document destroyed (record only)

    public static function getStatus($member) {
        // If revoked, always revoked
        if (!$member['mandate_active']) {
            return self::REVOKED;
        }

        // If never used, still in creation phase
        if ($member['mandate_first_used_at'] === null) {
            return self::CREATED;
        }

        // Check if expired (36 months of inactivity)
        $lastUsed = new DateTime($member['mandate_first_used_at']);
        $expiryDate = $lastUsed->add(new DateInterval('P36M'));

        if (new DateTime() > $expiryDate) {
            return self::EXPIRED;
        }

        // Active and in use
        return self::ACTIVE;
    }

    /**
     * Can mandate be used for collection?
     */
    public static function canUseForCollection($member) {
        $status = self::getStatus($member);
        return in_array($status, [self::ACTIVE, self::CREATED]);
    }

    /**
     * Days until mandate expires (or negative if expired)
     */
    public static function daysUntilExpiry($member) {
        if ($member['mandate_first_used_at'] === null) {
            return PHP_INT_MAX;  // Never expires if never used
        }

        $lastUsed = new DateTime($member['mandate_first_used_at']);
        $expiryDate = $lastUsed->add(new DateInterval('P36M'));
        $daysLeft = $expiryDate->diff(new DateTime())->days;

        return $expiryDate > new DateTime() ? $daysLeft : -$daysLeft;
    }

    /**
     * When should original mandate document be archived?
     */
    public static function getArchiveDate($member) {
        // 14 months after first use
        $lastUsed = new DateTime($member['mandate_first_used_at']);
        return $lastUsed->add(new DateInterval('P14M'))->format('Y-m-d');
    }
}

// Usage
$status = MandateStatus::getStatus($member);
echo "Mandate status: $status\n";

if (!MandateStatus::canUseForCollection($member)) {
    throw new Exception('Mandate cannot be used for collection');
}

$daysLeft = MandateStatus::daysUntilExpiry($member);
if ($daysLeft < 30) {
    warning("Mandate expires in $daysLeft days; member should sign addendum");
}
```

### Settlement Validation

```php
<?php
/**
 * Pre-settlement: Check all mandates are valid
 */
function validateMandatesForSettlement($settlementId) {
    $members = $db->select('members', '*', [
        'id' => ['IN', getSettlementMembers($settlementId)]
    ]);

    $issues = [];
    $warnings = [];

    foreach ($members as $member) {
        $status = MandateStatus::getStatus($member);

        if ($status === MandateStatus::REVOKED) {
            $issues[] = [
                'member_id' => $member['id'],
                'issue' => 'Mandate revoked',
                'action' => 'Remove from settlement'
            ];
        } elseif ($status === MandateStatus::EXPIRED) {
            $issues[] = [
                'member_id' => $member['id'],
                'issue' => 'Mandate expired (36+ months unused)',
                'action' => 'Member must sign new mandate'
            ];
        } elseif ($status === MandateStatus::CREATED) {
            $daysOld = (new DateTime())->diff(new DateTime($member['mandate_date']))->days;
            if ($daysOld > 365) {
                $warnings[] = [
                    'member_id' => $member['id'],
                    'warning' => 'Mandate never used; over 1 year old',
                    'suggestion' => 'May need to refresh with member'
                ];
            }
        } elseif ($status === MandateStatus::ACTIVE) {
            $daysLeft = MandateStatus::daysUntilExpiry($member);
            if ($daysLeft < 90) {
                $warnings[] = [
                    'member_id' => $member['id'],
                    'warning' => "Mandate expires in $daysLeft days",
                    'suggestion' => 'Contact member to sign addendum'
                ];
            }
        }
    }

    return [$issues, $warnings];
}

// Settlement preview
[$issues, $warnings] = validateMandatesForSettlement($settlementId);

if (!empty($issues)) {
    throw new ValidationException('Settlement contains invalid mandates', $issues);
}

if (!empty($warnings)) {
    $admin->notify('Settlement warnings: ' . count($warnings) . ' mandates near expiry');
}
```

### Mandate Revocation

```php
<?php
/**
 * Revoke member's mandate (member or admin initiated)
 */
function revokeMandateForMember($memberId, $reason, $requestedBy) {
    $member = $db->selectOne('members', '*', ['id' => $memberId]);

    if ($member['mandate_active'] === false) {
        throw new Exception('Mandate already revoked');
    }

    // Perform revocation
    $db->update('members', [
        'mandate_active' => false,
        'mandate_revoked_at' => date('c')
    ], ['id' => $memberId]);

    // Audit log
    $db->insert('audit_log', [
        'admin_user_id' => $requestedBy,
        'action' => 'revoke_mandate',
        'entity_type' => 'member',
        'entity_id' => $memberId,
        'changes_json' => json_encode([
            'mandate_active' => ['old' => true, 'new' => false],
            'mandate_revoked_at' => ['old' => null, 'new' => date('c')],
            'reason' => $reason
        ]),
        'created_at' => date('c')
    ]);

    // Notification
    sendEmail($member['email'], 'Mandate Revoked', [
        'reason' => $reason,
        'date' => date('d.m.Y'),
        'action' => 'To use SEPA collections, please provide new mandate'
    ]);
}
```

### First-Use Tracking (After Settlement)

```php
<?php
/**
 * After successful settlement finalization:
 * Mark FRST mandates as "first used"
 */
function markMandatesAsUsed($settlementId) {
    $transactions = $db->select('settlement_items', 't.*', [
        'settlement_id' => $settlementId
    ]);

    foreach ($transactions as $txn) {
        $member = $db->selectOne('members', '*', ['id' => $txn['member_id']]);

        if ($member['mandate_first_used_at'] === null) {
            // First use of this mandate
            $db->update('members', [
                'mandate_first_used_at' => date('c')
            ], ['id' => $member['id']]);

            // Audit log
            $db->insert('audit_log', [
                'action' => 'mandate_first_used',
                'entity_type' => 'member',
                'entity_id' => $member['id'],
                'changes_json' => json_encode([
                    'mandate_first_used_at' => ['old' => null, 'new' => date('c')],
                    'settlement_id' => $settlementId
                ]),
                'created_at' => date('c')
            ]);
        }
    }
}
```

### GDPR Anonymization (Mandate Handling)

```php
<?php
/**
 * When member is anonymized (GDPR Art. 17):
 * Clear mandate data, but retain history
 */
function anonymizeMemberMandateData($memberId, $adminId) {
    $member = $db->selectOne('members', '*', ['id' => $memberId]);

    // Record mandate history before clearing (for audit trail)
    $db->insert('audit_log', [
        'admin_user_id' => $adminId,
        'action' => 'anonymize_member',
        'entity_type' => 'member',
        'entity_id' => $memberId,
        'changes_json' => json_encode([
            'mandate_date' => ['old' => $member['mandate_date'], 'new' => null],
            'mandate_active' => ['old' => $member['mandate_active'], 'new' => false],
            'mandate_first_used_at' => ['old' => $member['mandate_first_used_at'], 'new' => null],
            'mandate_revoked_at' => ['old' => $member['mandate_revoked_at'], 'new' => date('c')],
            'reason' => 'GDPR anonymization (member requested deletion)'
        ]),
        'created_at' => date('c')
    ]);

    // Clear mandate fields
    $db->update('members', [
        'mandate_date' => null,
        'mandate_active' => false,
        'mandate_first_used_at' => null,
        'mandate_revoked_at' => date('c')
    ], ['id' => $memberId]);
}
```

### Admin Dashboard - Mandate Monitoring

```php
<?php
/**
 * Dashboard widget: Show mandate health
 */
function getMandateHealthStats() {
    $stats = [
        'total_members' => 0,
        'mandates_active' => 0,
        'mandates_expired' => 0,
        'mandates_revoked' => 0,
        'mandates_expiring_soon' => 0  // < 90 days
    ];

    $members = $db->select('members', '*', ['is_active' => true]);

    foreach ($members as $member) {
        $stats['total_members']++;

        $status = MandateStatus::getStatus($member);

        switch ($status) {
            case MandateStatus::ACTIVE:
                $stats['mandates_active']++;

                $daysLeft = MandateStatus::daysUntilExpiry($member);
                if ($daysLeft < 90) {
                    $stats['mandates_expiring_soon']++;
                }
                break;

            case MandateStatus::EXPIRED:
                $stats['mandates_expired']++;
                break;

            case MandateStatus::REVOKED:
                $stats['mandates_revoked']++;
                break;
        }
    }

    return $stats;
}

// Admin UI displays:
// - "123 members | 95 active mandates | 5 expired | 3 revoked | 8 expiring soon"
```

---

## Consequences

### Positive

✅ **Compliance ready**: Meets PSD2 (14-month) and German tax law (10-year) requirements
✅ **Automatic expiry detection**: 36-month rule enforced; prevents stale mandates
✅ **Clear revocation**: Soft-delete (mandate_active) allows undo if needed
✅ **Audit trail**: Full history of mandate lifecycle preserved
✅ **GDPR compatible**: Anonymization handles mandate data correctly
✅ **User-friendly**: Warnings/suggestions before expiry
✅ **Tax-compliant**: Record retention meets German § 147 AO

### Negative

❌ **Complex logic**: Multiple states (created, active, expired, revoked, archived)
❌ **Manual monitoring**: Admin must respond to expiry warnings
❌ **Document management**: Original physical mandates must be tracked/destroyed separately
❌ **No auto-renewal**: Cannot auto-extend; member must sign new mandate
❌ **Expiry surprise**: Silent expiry after 36 months (if no collections)

### Mitigations

1. **Dashboard alerts**: Clear visibility of mandate health
2. **Pre-settlement warnings**: Show expiring mandates before settlement
3. **Email reminders**: Notify members 90/30 days before expiry
4. **Admin UI**: Show mandate status prominently
5. **Audit logging**: Complete history for compliance audits
6. **Document tracking**: Spreadsheet/system to track original mandates (separate from app)

---

## Alternatives Considered

### Alternative 1: Auto-Extend Mandates

Automatically extend mandate by 36 months on each collection.

**Pros**: Never expires (as long as collecting)
**Cons**:
- Not SEPA-compliant (mandates should have defined duration)
- Legal uncertainty (violates PSD2 rules)
- Potentially problematic for compliance audits

**Rejected**: Violates SEPA mandate rules.

### Alternative 2: No Expiry Tracking

Don't track 36-month rule; let bank reject if expired.

**Pros**: Simpler implementation
**Cons**:
- Bad UX (settlement fails unexpectedly)
- Bank rejection unclear
- Compliance risk (ignorance isn't defense)
- Member frustration

**Rejected**: Proactive checking better.

### Alternative 3: Hard-Delete Revoked Mandates

Delete record entirely when revoked.

**Pros**: Cleaner data model
**Cons**:
- Audit trail lost
- Cannot undo revocation
- Compliance unclear (what about retention?)
- PSD2 might require evidence mandate existed

**Rejected**: Soft-delete with audit trail better.

### Alternative 4: Separate Mandate Table

Store mandate metadata in separate table.

```sql
CREATE TABLE sepa_mandates (
  id INT PRIMARY KEY AUTO_INCREMENT,
  member_id BINARY(16),
  mandate_date DATE,
  mandate_active BOOLEAN,
  mandate_first_used_at TIMESTAMP,
  ...
);
```

**Pros**: Cleaner separation of concerns
**Cons**:
- Additional JOIN overhead
- 1:1 relationship with members (unnecessary)
- Doesn't simplify code

**Rejected**: Denormalization in members table acceptable.

---

## Implementation Checklist

### Database

- [ ] Add mandate fields to members table (if not already from ADR-0006)
- [ ] Add mandate_revoked_at and mandate_expiry_calculated_at columns
- [ ] Create indexes on mandate fields
- [ ] Write migration script (set mandate_date = null for existing members)

### Backend

- [ ] Implement `MandateStatus` enum/class
- [ ] Implement `getStatus()` method
- [ ] Implement `canUseForCollection()` check
- [ ] Implement `daysUntilExpiry()` calculation
- [ ] Pre-settlement validation (check all mandates are valid)
- [ ] Revocation endpoint: `PATCH /api/members/{id}/revoke-mandate`
- [ ] First-use tracking after settlement finalization
- [ ] GDPR anonymization integration (clear mandate data)
- [ ] Dashboard stats: mandate health overview
- [ ] Audit logging for all mandate state changes

### Admin UI

- [ ] Show mandate status in member detail view
- [ ] Revocation button (with confirmation)
- [ ] Mandate expiry warning (yellow/red alerts)
- [ ] Dashboard widget: mandate health stats
- [ ] Settlement preview: show mandate issues/warnings
- [ ] Member list: filter by mandate status

### Background Jobs (Optional)

- [ ] Daily: Check for expiring mandates (< 90 days) → notify admin
- [ ] Weekly: Summarize mandate health for reporting
- [ ] Monthly: Archive completed documents (reference table)

### Testing

- [ ] Mandate created → status = CREATED
- [ ] Mandate first used → status = ACTIVE, mandate_first_used_at set
- [ ] Mandate unused 36+ months → status = EXPIRED
- [ ] Mandate revoked → status = REVOKED, mandate_active = false
- [ ] Settlement validation rejects expired/revoked mandates
- [ ] Expiry warnings shown < 90 days
- [ ] GDPR anonymization clears mandate data
- [ ] Audit log captures all state transitions

### Documentation

- [ ] Update CLAUDE.md: Add mandate lifecycle
- [ ] Admin guide: Mandate management (revocation, warnings)
- [ ] Compliance docs: SEPA/PSD2/German tax requirements
- [ ] Architecture docs: Mandate states and transitions
- [ ] User guide: How to handle mandate expiry (for members)

---

## Related Decisions

- [ADR-0006: SEPA Mandate Reference Strategy](./0006-sepa-mandate-reference-strategy.md) - Mandate fields
- [ADR-0004: Immutable Transaction Storage](./0004-immutable-transaction-storage.md) - Settlement workflow

---

## References

- **SEPA Standards**:
  - [EPC SEPA Rulebook](https://www.europeanpaymentscouncil.eu/) - Mandate validity (36 months)
  - [PSD2 Directive (2015/2366/EU)](https://ec.europa.eu/finance_tenders/fin_rules_regs/libertas_docs/2015/2015_2366/en_20151203_adoption_en.pdf) - 14-month retention

- **German Law**:
  - [§ 147 AO (German Tax Code)](https://www.gesetze-im-internet.de/ao_1977/__147.html) - 10-year retention of accounting records
  - [SEPA-Lastschrift Mandate Rules](https://www.bundesbank.de/) - German banking standards

- **Compliance**:
  - [GDPR Article 17 (Right to Erasure)](https://gdpr-info.eu/art-17-gdpr/) - Data deletion requirements with exceptions

---

## Approval

- **Decided by**: Architecture Team
- **Rationale**: Mandate lifecycle ensures SEPA/PSD2 compliance; retention requirements met; audit trail complete
- **Implementation start**: Phase 2 (SEPA settlement)
- **Review date**: 2025-04-23 (after first mandate expiry scenario)
- **Sign-off**:
  - Backend Lead: _________________ Date: _______
  - Compliance Officer: _________________ Date: _______
  - Legal Advisor: _________________ Date: _______

---

## Post-Implementation Monitoring

- [ ] Track mandate state transitions (how many CREATED → ACTIVE → EXPIRED?)
- [ ] Monitor expiry warnings (are they reaching admins?)
- [ ] Verify no failed collections due to expired mandates
- [ ] Audit log accuracy (all state changes logged?)
- [ ] GDPR anonymization: mandate data properly cleared?
- [ ] Settlement validation: are invalid mandates caught?
- [ ] Document retention: track original mandates separately
- [ ] User feedback: Are mandate warnings understood?
- [ ] Compliance audits: can we prove mandate validity?
