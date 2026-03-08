# GDPR Anonymization Fix

**Created**: 2026-03-08
**Status**: Not started
**Use Case**: [UC-DSGVO-02](../use-cases/dsgvo/uc-dsgvo-02-right-to-erasure.md)
**ADRs**: [ADR-0004](../adr/0004-immutable-transaction-storage.md), [ADR-0013](../adr/0013-audit-logging.md)

## Problem

Current anonymization implementation has GDPR compliance issues:
1. Uses `"DELETED"` strings instead of NULL for PII fields — data is replaced, not erased
2. Uses `"deleted@example.com"` for email — still identifiable pattern
3. Sets `card_uid` to NULL instead of `"ANON-{uuid}"` — breaks UNIQUE constraint on multiple anonymizations
4. Stores PII in anonymization audit entry (`old_values` contains name, masked IBAN)
5. Does not scrub historical audit log entries — PII reconstructable from edit history
6. No pre-deletion checks (balance, pending settlements)
7. `MembersService::getMember()` checks for `first_name === 'DELETED'` to identify anonymized members — fragile

## Milestones

### Milestone 1: Fix Repository Anonymization (NULL instead of DELETED)

- [ ] **1.1** Update `MembersRepository::anonymize()`:
  - Set `first_name`, `last_name`, `email`, `phone`, `iban`, `account_holder_name`, `mandate_reference` to NULL
  - Set `card_uid` to `CONCAT('ANON-', UUID())` (not NULL, to preserve UNIQUE constraint)
  - Set `is_active` = 0, `deleted_at` = NOW(), `updated_at` = NOW()

- [ ] **1.2** Fix `MembersService::getMember()`: Replace `first_name === 'DELETED'` check with `deleted_at !== null` to identify anonymized members

**Test**: Create member, anonymize, verify all PII fields are NULL and `card_uid` starts with `"ANON-"`

### Milestone 2: Add Audit Log Scrubbing

- [ ] **2.1** Add `scrubByEntityId(string $entityType, string $entityId)` method to `AuditLogRepository`:
  ```sql
  UPDATE audit_log SET old_values = NULL, new_values = NULL
  WHERE entity_type = ? AND entity_id = ?
  ```

- [ ] **2.2** Update `MembersService::anonymizeMember()`:
  1. Call `auditLogRepository->scrubByEntityId('member', $memberId)` before creating new audit entry
  2. Change anonymization audit entry: `old_values = null`, `new_values = ['deleted_at' => timestamp]` (no PII)

- [ ] **2.3** Inject `AuditLogRepository` into `MembersService` (add to constructor + service provider)

**Test**: Create member, update member (generates audit entries with PII), anonymize, verify all historical audit `old_values`/`new_values` are NULL

### Milestone 3: Add Pre-Deletion Checks

- [ ] **3.1** Add outstanding balance check: query unsettled transactions for member, sum `amount_cents`, reject if != 0
  - Use existing `TransactionsRepository` or add `getOutstandingBalanceCents(string $memberId): int`

- [ ] **3.2** Add pending settlement check: query `settlement_items` joined with `settlements` where `is_cancelled = 0` for this member
  - Add `hasPendingSettlement(string $memberId): bool` to relevant repository

- [ ] **3.3** Add already-anonymized check: reject if `deleted_at IS NOT NULL`

- [ ] **3.4** Return proper error responses (422) with descriptive messages:
  - `"Cannot anonymize: outstanding balance of €X.XX"`
  - `"Cannot anonymize: member included in active settlement"`
  - `"Member already anonymized"`

**Test**: Create member with transactions, attempt anonymize → blocked. Settle transactions, retry → success.

### Milestone 4: E2E Tests — Complete Scrubbing Verification

- [ ] **4.1** Test: Anonymize member with zero balance → all PII fields NULL
  - Create member with full data (name, email, phone, IBAN, mandate, card_uid)
  - Anonymize via API
  - GET member → verify all PII fields are NULL, `card_uid` starts with `"ANON-"`, `deleted_at` set

- [ ] **4.2** Test: Historical audit entries scrubbed
  - Create member (generates audit entry with PII)
  - Update member name (generates audit entry with old/new name)
  - Anonymize member
  - GET audit log filtered by entity_id → all entries have `old_values = null` and `new_values = null` EXCEPT the anonymize entry which has `new_values = { deleted_at: ... }`

- [ ] **4.3** Test: Anonymization audit entry contains no PII
  - Anonymize member
  - GET audit log filtered by entity_id and action=anonymize
  - Verify `old_values` is null
  - Verify `new_values` contains only `deleted_at`, no name/email/iban

- [ ] **4.4** Test: Pre-deletion check — outstanding balance blocks anonymization
  - Create member, create transaction (positive balance)
  - POST anonymize → 422 with balance error message
  - Member still has PII (not anonymized)

- [ ] **4.5** Test: Pre-deletion check — pending settlement blocks anonymization
  - Create member, create transaction, create settlement including member
  - POST anonymize → 422 with settlement error message

- [ ] **4.6** Test: Pre-deletion check — already anonymized rejects second attempt
  - Create member, anonymize
  - POST anonymize again → 404 or 422

- [ ] **4.7** Test: Transactions preserved after anonymization
  - Create member, create transactions
  - Anonymize member
  - GET transactions for member → transactions still exist with amounts intact

- [ ] **4.8** Test: No PII reconstructable from any table
  - Create member with all fields populated
  - Update member multiple times (generates audit trail)
  - Anonymize
  - Search across members table AND audit_log for any trace of original name/email/IBAN
  - Verify: zero results

## Files to Modify

| File | Change |
|------|--------|
| `backend/src/Modules/Members/Repositories/MembersRepository.php` | Fix `anonymize()` — NULL instead of DELETED |
| `backend/src/Modules/Members/Services/MembersService.php` | Add pre-checks, audit scrubbing, fix `getMember()` |
| `backend/src/Modules/AuditLog/Repositories/AuditLogRepository.php` | Add `scrubByEntityId()` |
| `backend/src/Shared/Services/AuditService.php` | Add `scrubEntityAuditHistory()` (optional convenience method) |
| `backend/src/Providers/AppServiceProvider.php` | Wire `AuditLogRepository` into `MembersService` |
| `e2etests/tests/api/anonymize-member.spec.ts` | New test file — all E2E tests |

## Success Criteria

1. After anonymization, **zero PII** exists for the member in any database table
2. Transactions and settlements remain intact (financial records preserved)
3. Audit log proves anonymization happened (who, when) without storing what was deleted
4. Pre-deletion checks prevent anonymization with outstanding balance or pending settlements
5. All E2E tests pass with 4 workers
