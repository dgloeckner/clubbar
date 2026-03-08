# GDPR Anonymization Fix

**Created**: 2026-03-08
**Status**: Complete
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

- [x] **1.1** Update `MembersRepository::anonymize()`:
  - Set `first_name`, `last_name`, `email`, `phone`, `iban`, `account_holder_name`, `mandate_reference` to NULL
  - Set `card_uid` to `'ANON-' + truncated UUID` (20 chars max for VARCHAR(20))
  - Set `is_active` = 0, `deleted_at` = NOW(), `updated_at` = NOW()

- [x] **1.2** Fix `MembersService::getMember()`: Remove fragile `first_name === 'DELETED'` check

**Test**: Create member, anonymize, verify all PII fields are NULL and `card_uid` starts with `"ANON-"`

### Milestone 2: Add Audit Log Scrubbing

- [x] **2.1** Add `scrubByEntityId(string $entityType, string $entityId)` method to `AuditLogRepository`

- [x] **2.2** Update `MembersService::anonymizeMember()`:
  1. Call `auditLogRepository->scrubByEntityId('member', $memberId)` before creating new audit entry
  2. Change anonymization audit entry: `old_values = null`, `new_values = ['deleted_at' => timestamp]` (no PII)

- [x] **2.3** Inject `AuditLogRepository` into `MembersService` (add to constructor + ServiceFactory)

**Test**: Create member, update member (generates audit entries with PII), anonymize, verify all historical audit `old_values`/`new_values` are NULL

### Milestone 3: Add Pre-Deletion Checks

- [x] **3.1** Add outstanding balance check via `TransactionsRepository::getUnsettledMemberBalanceCents()`

- [x] **3.2** Add pending settlement check via `TransactionsRepository::hasMemberInActiveSettlement()`

- [x] **3.3** Add already-anonymized check: reject if `deleted_at IS NOT NULL`

- [x] **3.4** Return proper error responses (409 via BusinessRuleException) with descriptive messages

**Test**: Create member with transactions, attempt anonymize → blocked. Settle transactions, retry → success.

### Milestone 4: E2E Tests — Complete Scrubbing Verification

- [x] **4.1** Test: Anonymize member with zero balance → all PII fields NULL
- [x] **4.2** Test: Historical audit entries scrubbed
- [x] **4.3** Test: Anonymization audit entry contains no PII
- [x] **4.4** Test: Pre-deletion check — outstanding balance blocks anonymization
- [x] **4.5** Test: Pre-deletion check — pending settlement blocks anonymization
- [x] **4.6** Test: Pre-deletion check — already anonymized rejects second attempt
- [x] **4.7** Test: Transactions preserved after anonymization
- [x] **4.8** Test: No PII reconstructable from any table

All 8 tests pass with 4 workers (3.2s).

## Files Modified

| File | Change |
|------|--------|
| `backend/src/Modules/Members/Repositories/MembersRepository.php` | Fix `anonymize()` — NULL instead of DELETED, card_uid truncated |
| `backend/src/Modules/Members/Services/MembersService.php` | Add pre-checks, audit scrubbing, fix `getMember()` |
| `backend/src/Modules/Members/DTOs/MemberAdminDto.php` | Make firstName, lastName, email nullable |
| `backend/src/Modules/Members/DTOs/MemberDto.php` | Make firstName, lastName nullable |
| `backend/src/Modules/AuditLog/Repositories/AuditLogRepository.php` | Add `scrubByEntityId()` |
| `backend/src/Modules/Transactions/Repositories/TransactionsRepository.php` | Add `getUnsettledMemberBalanceCents()`, `hasMemberInActiveSettlement()` |
| `backend/src/ServiceFactory.php` | Wire `AuditLogRepository` into `MembersService` |
| `e2etests/tests/api/anonymize-member.spec.ts` | New test file — 8 E2E tests |

## Success Criteria

1. ✅ After anonymization, **zero PII** exists for the member in any database table
2. ✅ Transactions and settlements remain intact (financial records preserved)
3. ✅ Audit log proves anonymization happened (who, when) without storing what was deleted
4. ✅ Pre-deletion checks prevent anonymization with outstanding balance or pending settlements
5. ✅ All 8 E2E tests pass with 4 workers
